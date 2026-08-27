# Simulateur RGA

Simulateur d'exposition au **retrait-gonflement des argiles**, embarquable sur
le site d'un bureau d'études par une balise `<script>`. Le visiteur saisit
l'adresse de son terrain, obtient le niveau d'exposition officiel, l'obligation
réglementaire qui en découle, et un lien vers un formulaire de devis pré-rempli
avec ce contexte.

- **Spécification** : [`SPEC.md`](SPEC.md) — la référence, ce README en est la vue d'ensemble.
- **La donnée** : [`docs/donnees-rga.md`](docs/donnees-rga.md) — millésime, lien de téléchargement, relevé du shapefile.
- **L'exploitation** : [`docs/exploitation.md`](docs/exploitation.md) — déploiement, sauvegarde, incidents.

---

## 1. Vue d'ensemble

```mermaid
flowchart LR
    subgraph hote["Site du partenaire"]
        W["widget.js<br/>(chargeur, &lt; 5 Ko)"]
        IF["iframe /embed"]
    end

    subgraph nav["Navigateur du visiteur"]
        BAN["api-adresse.data.gouv.fr<br/>(Base Adresse Nationale)"]
    end

    subgraph srv["api.zonage.sallakane.cloud"]
        API["Symfony 7 / FrankenPHP"]
        PG[("PostgreSQL 16<br/>+ PostGIS 3.4")]
    end

    FORM["Formulaire de devis<br/>DU partenaire"]

    W -->|"injecte"| IF
    IF -->|"1. autocomplétion d'adresse"| BAN
    IF -->|"2. GET /api/v1/zonage?lat&amp;lon&amp;key&amp;insee"| API
    API -->|"3. point-dans-polygone"| PG
    IF -->|"4. lien pré-rempli, target=_top"| FORM
    IF -.->|"sendBeacon /api/v1/conversion"| API
```

Trois décisions structurent tout le reste :

| Décision | Pourquoi |
|---|---|
| **La carte est hébergée chez nous**, pas interrogée chez Géorisques | L'API Géorisques répond en ~10 s. Ici c'est quelques millisecondes, sans dépendance externe, et on garantit quel millésime répond (SPEC §3). |
| **Le géocodage reste externe et côté navigateur** | La BAN est gratuite, sans clé, et l'adresse ne passe jamais par nos journaux. |
| **Le lead ne transite pas par ce serveur** | Le visiteur remplit le formulaire du partenaire, sur le domaine du partenaire. Aucune donnée personnelle ne nous touche (SPEC §9). |

---

## 2. La carte en local : pourquoi et comment

### 2.1 Ce qu'on télécharge

Géorisques publie le zonage sous forme d'un **shapefile national** : un ZIP de
440 Mo, 402 Mo décompressés, **121 399 polygones** en projection Lambert 93
(EPSG:2154), sous licence Etalab 2.0.

Le lien n'est pas devinable — la page de téléchargement est une interface
JavaScript. Il se demande à l'API de Géorisques :

```bash
# 1. La clé de la base, dans l'attribut data-js-base-key de la page
curl -s https://www.georisques.gouv.fr/donnees/bases-de-donnees/retrait-gonflement-des-argiles-version-2026 \
  | grep -o 'data-js-base-key="[^"]*"'          # -> alearg_25

# 2. Le lien du fichier, échelle nationale
curl -s 'https://www.georisques.gouv.fr/webappReport/ws/telechargements/formats/zip/alearg_25?echelle=nationale'
# -> https://files.georisques.fr/argiles/2025/AleaRG_2025_Fxx_L93.zip
```

Le fichier se dépose dans `data/`, **gitignoré** : plusieurs centaines de Mo
n'ont rien à faire dans un dépôt. Sa somme de contrôle sha256 est dans
[`docs/donnees-rga.md`](docs/donnees-rga.md) — et elle sert : une URL très
proche (`/argiles/AleaRG_Fxx_L93.zip`, sans le `2025/`) répond 200 avec
l'**ancien** millésime de 2021.

### 2.2 Du ZIP à la table PostGIS

Tout le pipeline tient dans un script rejouable : [`bin/charger-rga.sh`](bin/charger-rga.sh).

```mermaid
flowchart TD
    Z["data/AleaRG_2025_Fxx_L93.zip<br/>440 Mo, sha256 vérifiée"]
    S["data/*.shp .dbf .shx .prj .cpg<br/>402 Mo, Lambert 93"]
    I["make ogrinfo<br/>relever champs, SRID, valeurs"]
    D["docs/donnees-rga.md<br/>+ migrations/rga/2026-niveaux.sql"]
    O["ogr2ogr — conteneur gis (GDAL)<br/>EPSG:2154 → EPSG:4326<br/>Polygon → MultiPolygon<br/>index GIST"]
    T[("rga_zone_2026<br/>121 399 lignes, 916 Mo")]
    P["Post-traitement SQL<br/>ST_MakeValid · niveau_code · millesime"]
    C{"Complétude<br/>shapefile == base ?"}
    V["Vérification<br/>Corse &gt; 0 · Paris == 0"]
    B["--bascule<br/>vue rga_zone_courante"]
    STOP["✗ arrêt — la vue n'est PAS basculée"]

    Z -->|unzip| S
    S --> I --> D
    S --> O --> T --> P --> C
    D -.->|mapping appliqué| P
    C -->|non| STOP
    C -->|oui| V --> B
```

En pratique, sur un poste ou sur le VPS :

```bash
cd data && unzip AleaRG_2025_Fxx_L93.zip && cd ..

make ogrinfo                                          # NE RIEN SUPPOSER : relever
./bin/charger-rga.sh --millesime 2026 \
    --shp data/AleaRG_2025_Fxx_L93.shp --encoding UTF-8
# ~35 s de chargement, puis quelques minutes de ST_MakeValid

./bin/charger-rga.sh --millesime 2026 --bascule       # mise en service
```

Ajouter `--prod` sur le VPS : la seule différence est le fichier Compose visé.

### 2.3 Les six choix qui font que ça marche

| Étape | Choix | Ce qu'il évite |
|---|---|---|
| Outil | **`ogr2ogr`** (image `ghcr.io/osgeo/gdal`, version **figée**) et non `shp2pgsql` | L'image `postgis/postgis` ne contient pas `shp2pgsql`. Et un `latest` de GDAL est une compilation quotidienne : un pipeline qui change de version tout seul n'est plus rejouable. |
| Projection | **Reprojection L93 → WGS84 au chargement** | Un `ST_Transform` dans la requête chaude rendrait l'index GIST inutilisable — c'est la seule performance qui compte ici. |
| Géométrie | `-nlt MULTIPOLYGON` | Les shapefiles officiels mélangent les deux types. |
| Largeurs | `-lco PRECISION=NO` | Le `.dbf` annonce `surf_m2` en `Real(24.15)` → GDAL en déduit un `NUMERIC(23,15)`, incapable de stocker 1 474 098 685 m². Sans l'option, le chargement s'arrête à 80 %. |
| Validité | `ST_MakeValid` sur les géométries invalides | `ST_Intersects` sur une géométrie invalide peut renvoyer n'importe quoi. |
| Sémantique | `niveau_code` alimenté par [`migrations/rga/2026-niveaux.sql`](migrations/rga/2026-niveaux.sql), versionné | Le code applicatif ne dépend jamais du libellé source. Le fichier lève une exception si une valeur sort du mapping. |

### 2.4 Le contrôle qui n'est pas facultatif

**La carte ne dessine que les zones exposées** : trois valeurs (1 faible,
2 moyen, 3 fort) et **aucun polygone de niveau 0**, pour 395 455 km² couverts
sur ~551 700 km² de métropole.

Conséquence : en France métropolitaine hors Paris, **l'absence de polygone est
une réponse** — « exposition nulle » — pas un trou dans la donnée.

Conséquence de la conséquence : une donnée à moitié chargée répondrait « pas de
risque » sur des régions entières, **en HTTP 200, sans rien signaler**. C'est la
panne la plus coûteuse de ce produit et la plus silencieuse. Le script compare
donc systématiquement le nombre de polygones chargés à celui du shapefile, et
**refuse de basculer la vue** en cas d'écart.

Le millésime lui-même se contrôle : moyen + fort = 301 592 km², soit **54,7 %**
du territoire — l'arrêté du 9 janvier 2026 annonce le passage de 48 % à 55 %.
C'est bien le bon.

---

## 3. Comment la réponse se calcule, concrètement

Le code n'interroge **jamais** une table millésimée. Il interroge une vue,
`rga_zone_courante` :

```sql
SELECT niveau_code, millesime
  FROM rga_zone_courante
 WHERE ST_Intersects(geom, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326))
 ORDER BY niveau_code DESC
 LIMIT 1
```

Trois détails portent tout le comportement ([`src/Service/ZonageResolver.php`](src/Service/ZonageResolver.php)) :

- **SQL natif, pas Doctrine ORM** — Doctrine ne gère pas PostGIS, et c'est la
  seule requête où la performance compte. Elle doit rester une recherche sur
  index GIST.
- **`ORDER BY niveau_code DESC`** — sur une frontière, le point appartient à
  deux polygones. On retient le plus exposé : se tromper vers l'obligation
  d'étude coûte un devis, se tromper dans l'autre sens coûte un vice caché.
- **`LIMIT 1`**, mais l'absence de ligne n'est pas une erreur : elle est
  interprétée.

```mermaid
flowchart TD
    Q["ST_Intersects sur rga_zone_courante"]
    HIT{"un polygone ?"}
    Z["niveau 1 / 2 / 3<br/>→ obligation + mission NF P 94-500"]
    META{"dans la métropole ?"}
    HM["hors périmètre<br/>motif: hors_metropole"]
    INSEE{"code INSEE<br/>751xx ou 75056 ?"}
    PARIS["hors périmètre<br/>motif: paris"]
    NUL["niveau 0 — exposition nulle<br/>→ conseil, pas un cul-de-sac"]

    Q --> HIT
    HIT -->|oui| Z
    HIT -->|non| META
    META -->|non| HM
    META -->|oui| INSEE
    INSEE -->|oui| PARIS
    INSEE -->|non| NUL
```

Le `citycode` de la BAN est transmis par le widget : il rend la détection de
Paris **exacte**, là où une enveloppe rectangulaire déborderait sur la petite
couronne. Sans lui (appel direct à l'API), on retombe sur l'enveloppe.

**Aucune réponse n'est un cul-de-sac** (SPEC §1) — y compris « hors périmètre »,
qui répond en 200 avec son propre message et son propre appel à l'action.

---

## 4. Le millésime : charger d'un côté, servir de l'autre

C'est le rôle de la vue. Elle rend la mise à jour annuelle possible **sans
interruption de service** : le nouveau millésime se charge et se vérifie pendant
que l'ancien continue de répondre, et la bascule est une transaction.

```mermaid
flowchart LR
    subgraph avant["Pendant le chargement"]
        V1(["rga_zone_courante"]) --> T26A[("rga_zone_2026<br/>en service")]
        T27A[("rga_zone_2027<br/>chargée, vérifiée")]
    end

    subgraph apres["Après --bascule"]
        V2(["rga_zone_courante"]) --> T27B[("rga_zone_2027<br/>en service")]
        T26B[("rga_zone_2026<br/>conservée — retour arrière")]
    end

    avant -->|"BEGIN; DROP VIEW; CREATE VIEW; COMMIT;"| apres
```

`DROP` + `CREATE` dans **une** transaction, et non `CREATE OR REPLACE` : ce
dernier refuse tout changement de type de colonne, ce qui arrive dès que le
millésime change de schéma. La vue projette les colonnes **explicitement** —
c'est le contrat entre la donnée et le code : le shapefile peut changer de
colonnes, la vue non.

Revenir en arrière, c'est une bascule dans l'autre sens. Règle de rétention :
garder N et N-1, supprimer N-2 (chaque millésime pèse ~1,7 Go, voir
[`docs/exploitation.md`](docs/exploitation.md) §5).

```bash
make ogrinfo                                          # relever le nouveau millésime
$EDITOR docs/donnees-rga.md migrations/rga/<annee>-niveaux.sql
./bin/charger-rga.sh --prod --millesime <annee> --shp … --encoding …
./bin/charger-rga.sh --prod --millesime <annee> --bascule
docker compose -f compose.prod.yaml exec app php bin/console app:zonage:verifier
```

---

## 5. Développement local

Tout tourne dans Docker, PHP compris : PostGIS 3.4 et GDAL ne s'installent pas
proprement à la main, et c'est la même image qui part en production.

```bash
make up          # crée .env.local au premier appel — renseigner les secrets AVANT
make zonage-demo # zonage synthétique (3 carrés en Essonne) : développer sans les 400 Mo
make test
```

| Service | Image | Rôle | Port hôte |
|---|---|---|---|
| `app` | build local, cible `frankenphp_dev` | Symfony + FrankenPHP | `127.0.0.1:8085` |
| `database` | `postgis/postgis:16-3.4` | Postgres + PostGIS | `5435` |
| `mailer` | `axllent/mailpit` | bac à sable e-mail | `8028` / `1028` |
| `gis` | GDAL, version figée, **profil `tools`** | `ogrinfo` (§2.2) et `ogr2ogr` | — |

Le service `gis` n'est **jamais démarré** par `make up` : il n'existe que le
temps d'un chargement, et il monte `data/` en lecture seule. Rien, à l'exécution,
ne lit `data/`.

Deux pièges qui coûtent une base à recréer :

- **Passer par le `Makefile`**, pas par `docker compose` directement : lui seul
  fournit `--env-file .env.local`. Sans ça, Compose n'interpole les `${...}` que
  depuis `.env`, versionné — donc sans le vrai mot de passe, qui n'est lu qu'à
  **l'initialisation du volume** Postgres.
- `make zonage-demo` refuse de s'exécuter si un millésime officiel est chargé :
  remplacer 121 399 polygones par 3 carrés se verrait mal en production.

### Tests

Le jeu de points de référence est **extrait de la donnée elle-même**, jamais
deviné (`ST_PointOnSurface`, qui garantit un point *dans* le polygone
contrairement au centroïde) :

```bash
make points     # regénère tests/fixtures/points-reference.json depuis la vue en service
make verifier   # rejoue ces points contre le zonage en service
```

---

## 6. Supervision

```bash
curl -s localhost:8085/api/v1/health
# {"status":"ok","rga_millesime":"2026","polygones":121399}
```

Surveiller **les trois champs**, pas le code HTTP seul. Une base vide répond
parfaitement — elle renvoie « hors périmètre » pour la France entière. D'où le
**503** quand `polygones == 0`, plutôt qu'un 200 rassurant.

---

## 7. Où est quoi

```
bin/charger-rga.sh          Le pipeline de la donnée, de bout en bout (§2)
migrations/rga/*.sql        Mapping valeur source → niveau_code, versionné par millésime
data/                       Shapefiles — gitignoré, plusieurs centaines de Mo

src/Service/ZonageResolver.php   Le point-dans-polygone (§3)
src/Service/ObligationMapper.php niveau_code → mission NF P 94-500 + textes
src/Service/LienDevis.php        URL pré-remplie du formulaire du partenaire
src/Controller/                  zonage · conversion · embed · health
public/widget.js                 Chargeur embarqué sur le site hôte, < 5 Ko
templates/embed.html             L'application iframe, zéro dépendance

infra/                      Unité systemd, snippet Caddy, sauvegarde
docs/donnees-rga.md         Millésime, téléchargement, relevé du shapefile
docs/exploitation.md        Déploiement, sauvegarde, empreinte disque, incidents
SPEC.md                     La spécification
```

---

Source de la donnée : **Géorisques — Retrait-gonflement des argiles, carte
d'exposition (2026)**, arrêté du 9 janvier 2026. Licence Etalab 2.0 —
réutilisation libre, **mention de la source obligatoire** et visible dans le
widget.
