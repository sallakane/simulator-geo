# Simulateur RGA — Spécification technique

> Document de référence du projet. À placer à la racine du dépôt sous le nom
> `SPEC.md`, et à référencer depuis `CLAUDE.md`.

---

## 1. Contexte et objectif

### Le besoin

Atlantis Géotechnique est un bureau d'études géotechnique (Ris-Orangis, 91),
qualifié OPQIBI et certifié ISO 9001. Son site reçoit aujourd'hui 4 à 5
demandes de devis par mois via un formulaire générique : le visiteur laisse un
message libre, et il faut le rappeler pour savoir où se trouve le terrain et
quelle mission lui est applicable.

L'arrêté du 9 janvier 2026 a actualisé la carte d'exposition au
retrait-gonflement des argiles (RGA). Le nouveau zonage s'applique depuis le
1er juillet 2026 aux promesses et actes de vente de terrains non bâtis
constructibles ainsi qu'aux contrats de construction de maison individuelle.
Les zones d'exposition moyenne et forte passent de 48 % à 55 % du territoire.

### Ce que fait le produit

Le visiteur saisit l'adresse de son terrain. Il obtient immédiatement :

1. le niveau d'exposition RGA officiel de la parcelle ;
2. l'obligation réglementaire qui en découle, s'il y en a une ;
3. la mission normalisée applicable (NF P 94-500) ;
4. un formulaire de devis **pré-rempli avec ce contexte**.

Le lead arrive donc qualifié : adresse géocodée, coordonnées, zone, mission.
Le devis peut être préparé sans rappel préalable — ce qui rend tenable la
promesse « devis sous 24 h » qui est déjà l'avantage concurrentiel de
l'entreprise.

### Principe directeur

**Aucune réponse ne doit être un cul-de-sac.**

| Niveau d'exposition | Message | Appel à l'action |
|---|---|---|
| Fort / Moyen | Étude G1 obligatoire avant la vente | Devis G1 |
| Faible | Pas d'obligation RGA, mais G2 nécessaire pour construire | Étude de projet |
| Nul | Pas de risque argile ; autres reconnaissances utiles | Conseil |
| Hors périmètre (Paris, DOM) | Message dédié, jamais une erreur | Contact |

Un simulateur qui répond « vous n'êtes pas concerné » et s'arrête là gaspille
la majorité de son trafic.

### Positionnement du dépôt

Le produit est **autonome**, hébergé sur `api.zonage.sallakane.cloud`.
Atlantis est le premier client, pas le propriétaire de l'infrastructure. Le
code ne doit contenir **aucune référence en dur à Atlantis** : tout ce qui est
spécifique au client passe par la table `partner` (voir §5).

> Note : le sous-domaine sera probablement renommé plus tard. Ne jamais écrire
> l'URL de base en dur, ni côté serveur, ni dans le widget — toujours via une
> variable d'environnement ou une valeur déduite à l'exécution.

---

## 2. Stack

| Composant | Choix | Justification |
|---|---|---|
| Langage | PHP 8.3+ | |
| Framework | Symfony 7.x | |
| Base | PostgreSQL 16 + PostGIS 3.4 | Requête point-dans-polygone |
| ORM | Doctrine, + SQL natif pour le spatial | Doctrine ne gère pas PostGIS nativement |
| File d'attente | Symfony Messenger (transport Doctrine) | Relais de leads avec réessai |
| Front widget | JavaScript sans framework | Poids < 15 Ko, zéro dépendance |
| Runtime HTTP | FrankenPHP (Caddy + PHP embarqués) | Une image, un process ; même binaire en dev et en prod |
| Conteneurisation | Docker Compose, en dev **et** en prod | PostGIS ne s'installe pas proprement à la main ; parité dev/prod |
| Entrée publique | Caddy mutualisé du VPS | TLS Let's Encrypt automatique, déjà en place pour les voisins |
| Tests | PHPUnit | |

**Pas d'API Platform.** Deux endpoints publics ne justifient pas cette couche ;
des contrôleurs simples sont plus lisibles et plus rapides.

**Pas de framework JS dans le widget.** Il s'exécute sur des sites tiers dont
on ne maîtrise pas l'environnement. Chaque kilo-octet et chaque dépendance est
un risque.

**Pas de Nginx + PHP-FPM.** Le VPS cible est mutualisé : un Caddy déjà en place
termine le TLS et proxifie chaque domaine vers un port de loopback. Ajouter
Nginx mettrait trois serveurs web dans la chaîne. FrankenPHP sert en HTTP clair
sur `:80` dans le conteneur, le Caddy de l'hôte fait le reste. Conséquences,
assumées : les en-têtes de sécurité et la CSP vivent dans le bloc Caddy de
l'hôte (§8), et la **limitation de débit se fait dans Symfony** — Caddy n'en a
pas nativement, et on ne va pas installer un module sur un serveur partagé.

**Tout tourne dans Docker, y compris en production.** PostGIS 3.4 et GDAL
installés à la main sur le VPS, ce sont des paquets à surveiller et une
divergence garantie avec le poste de développement. C'est aussi la convention
déjà retenue pour les autres projets Symfony du même VPS.

---

## 3. Décision d'architecture : la donnée est hébergée

**Ne pas interroger l'API Géorisques sur le chemin critique.**

Trois raisons :

1. **Latence** — l'endpoint `resultats_rapport_risque` est signalé à ~10 s de
   temps de réponse moyen avec des paramètres `latlon` + rayon. Incompatible
   avec une promesse de réponse immédiate.
2. **Dépendance** — si Géorisques est indisponible, le tunnel de conversion du
   client s'arrête.
3. **Version des données** — on doit garantir que c'est bien le zonage de
   l'arrêté du 9 janvier 2026 qui répond.

**À la place :** le shapefile officiel est chargé dans PostGIS. La requête
point-dans-polygone répond en quelques millisecondes, sans limite d'appel et
sans dépendance externe.

Source : Géorisques, « Retrait gonflement des argiles — Carte d'exposition
(2026) », shapefile Lambert 93 (EPSG:2154), licence Etalab 2.0 (réutilisation
libre y compris commerciale, avec mention de la source).

**Le géocodage reste externe** : `api-adresse.data.gouv.fr` (Base Adresse
Nationale). Gratuit, sans clé, rapide, conçu pour l'appel navigateur. Il est
appelé **depuis le navigateur**, pas depuis le serveur — moins de latence, et
la charge ne pèse pas sur l'API.

### Périmètre de la donnée — à traiter explicitement

- La carte couvre la France métropolitaine **hors ville de Paris**.
  Une adresse parisienne ne renvoie aucun polygone : ce n'est pas une erreur,
  c'est un cas fonctionnel qui a son propre message.
- Vérifier la couverture de la Corse lors du chargement.
- DOM-TOM hors périmètre.

**La carte ne dessine que les zones exposées.** Relevé sur le millésime 2026
(voir `docs/donnees-rga.md`) : trois valeurs seulement — 1, 2, 3 — et **aucun
polygone de niveau 0**, pour 395 455 km² couverts sur ~551 700 km² de
métropole. En France métropolitaine et hors Paris, l'absence de polygone est
donc une **réponse** : « exposition nulle », avec son propre appel à l'action.
Elle n'est un hors-périmètre qu'en dehors de la métropole, ou sur Paris.

Cette lecture a un coût : une donnée partiellement chargée ferait répondre
« pas de risque » sur des régions entières, en HTTP 200, sans rien signaler.
D'où le contrôle de complétude au chargement (§4.2) — et il n'est pas
facultatif.

---

## 4. Pipeline de données

### 4.1 Inspection préalable — obligatoire

Les noms de champs du shapefile 2026 **doivent être vérifiés avant tout
développement**. Ne pas les supposer.

```bash
# Le shapefile est déposé dans data/ (gitignoré), monté dans les conteneurs.
docker compose run --rm gis ogrinfo -so -al /data/ExpoArgile_Fr_metro_L93.shp
```

Le service `gis` (profil `tools`, image GDAL) n'existe que pour ça : il n'est
pas démarré par `make up`.

Relever : le nom du champ portant le niveau d'exposition, ses valeurs
distinctes exactes (casse comprise), et le SRID. Consigner le résultat dans
`docs/donnees-rga.md` — c'est ce qui permettra de rejouer le chargement lors de
la prochaine mise à jour du zonage.

### 4.2 Chargement

Le chargement se fait avec **`ogr2ogr`**, dans le même conteneur `gis` que
l'inspection — et non avec `shp2pgsql` : l'image `postgis/postgis` ne le
contient pas (vérifié). GDAL fait le même travail, reprojection et index GIST
compris.

```bash
# Reprojection L93 → WGS84 au chargement, création de l'index GIST
ogr2ogr -f PostgreSQL PG:"host=database dbname=… user=… password=…" \
    /data/ExpoArgile_Fr_metro_L93.shp \
    -s_srs EPSG:2154 -t_srs EPSG:4326 \
    -nln rga_zone_2026 -nlt MULTIPOLYGON -overwrite \
    -lco GEOMETRY_NAME=geom -lco FID=id -lco SPATIAL_INDEX=GIST \
    --config SHAPE_ENCODING LATIN1
```

`SHAPE_ENCODING` dépend de l'encodage réel du `.dbf`, et `-s_srs` du SRID
annoncé par le `.prj` — les deux sont relevés à l'inspection (§4.1), pas
supposés. Pour le millésime 2026, le `.cpg` déclare **UTF-8** et non LATIN1.

`-lco PRECISION=NO` n'est pas cosmétique : les largeurs déclarées dans le `.dbf`
sont fantaisistes (`surf_m2` en `Real(24.15)`, dont GDAL déduit un
`NUMERIC(23,15)` incapable de stocker 1,4 milliard de m²). Sans cette option, le
chargement s'arrête à 80 %.

**Contrôle de complétude, obligatoire.** Le script compare le nombre de
polygones chargés à celui du shapefile et **refuse de basculer la vue** en cas
d'écart. C'est le seul garde-fou contre un chargement partiel, qui serait
invisible autrement (§3).

Cette commande n'est jamais tapée telle quelle : elle vit dans
`bin/charger-rga.sh`, qui prend un `--prod` pour viser `compose.prod.yaml`. La
même procédure doit tourner à l'identique sur le poste et sur le VPS, sinon
elle ne sera pas rejouable au prochain millésime (§4.4). L'image GDAL y est
**figée sur une version précise** : le tag `latest` est une compilation
quotidienne, et un pipeline dont l'outil change tout seul n'est pas rejouable.

Le chargement vise `rga_zone_<millesime>`, jamais `rga_zone_courante` : la
bascule de la vue est un geste séparé (`--switch`), après vérification.

### 4.3 Post-traitement

```sql
-- Géométries valides (les shapefiles officiels en contiennent souvent d'invalides)
UPDATE rga_zone SET geom = ST_MakeValid(geom) WHERE NOT ST_IsValid(geom);

-- Colonne normalisée : le code applicatif ne dépend pas du libellé source
ALTER TABLE rga_zone ADD COLUMN niveau_code SMALLINT;
-- 0 = nul, 1 = faible, 2 = moyen, 3 = fort
-- UPDATE à écrire à partir des valeurs relevées en 4.1

CREATE INDEX idx_rga_geom ON rga_zone USING GIST(geom);
ANALYZE rga_zone;
```

Le mapping libellé source → `niveau_code` vit dans un script de migration
versionné, pas dans le code applicatif.

### 4.4 Procédure de mise à jour

Le zonage a changé au 1er juillet 2026 ; il rechangera. La procédure doit être
rejouable et documentée dans `docs/mise-a-jour-zonage.md` :

```bash
# 1. Relever les champs du nouveau millésime — ne jamais supposer (§4.1)
make ogrinfo
$EDITOR docs/donnees-rga.md migrations/rga/<annee>-niveaux.sql

# 2. Charger dans rga_zone_<annee>. Le service continue de répondre :
#    la vue pointe toujours sur l'ancien millésime.
./bin/charger-rga.sh --millesime <annee> --shp data/<fichier>.shp --encoding <ENC>

# 3. Mettre en service, puis rejouer le jeu de référence
./bin/charger-rga.sh --millesime <annee> --bascule
make points                              # régénère les points depuis la donnée servie
make verifier                            # les rejoue — doit passer intégralement
```

Le chargement (étape 2) et la mise en service (étape 3) sont **séparés
volontairement** : on vérifie avant de servir. L'ancienne table est conservée
pour la traçabilité, et la bascule est transactionnelle — pas de fenêtre pendant
laquelle la vue n'existe pas.

Le code interroge **toujours** `rga_zone_courante`, jamais une table millésimée
directement. Cette vue expose une projection explicite
(`id, geom, niveau_code, niveau_libelle, millesime`) : c'est le contrat entre la
donnée et le code, et il ne doit pas bouger quand le shapefile change de
colonnes.

---

## 5. Modèle de données

### `rga_zone` — lecture seule, issue du shapefile

```
id              bigserial PK
geom            geometry(MultiPolygon, 4326)   -- index GIST
niveau_code     smallint                        -- 0..3, normalisé
niveau_libelle  varchar(50)                     -- valeur source, traçabilité
millesime       varchar(10)                     -- '2026'
```

### `partner` — un enregistrement par intégration

```
id                  serial PK
public_key          varchar(40) UNIQUE   -- exposée côté client, non secrète
nom                 varchar(120)
actif               boolean
origines_autorisees jsonb                -- ["https://atlantis-geotechnique.fr"]
theme               varchar(40)
lead_endpoint       varchar(255)         -- URL du webform destinataire
lead_email          varchar(180)         -- copie de secours
created_at          timestamptz
```

C'est cette table qui rend le produit revendable. Aucune valeur spécifique à un
client ne doit exister ailleurs.

### `simulation` — mesure, sans donnée personnelle

```
id            bigserial PK
partner_id    int FK
lat, lon      numeric(9,6)      -- arrondies à 4 décimales (~11 m)
code_insee    varchar(5)
niveau_code   smallint
converti      boolean           -- une demande a-t-elle suivi ?
created_at    timestamptz
```

Aucun nom, aucun e-mail, aucune adresse complète. Permet de mesurer le taux de
conversion par zone et par partenaire.

### `lead` — rétention minimale

```
id             bigserial PK
partner_id     int FK
simulation_id  bigint FK
payload        jsonb              -- chiffré au repos
statut         varchar(20)        -- pending | delivered | failed
tentatives     smallint
delivered_at   timestamptz
created_at     timestamptz
```

**Purge automatique à J+30 après livraison réussie** (commande planifiée). Le
destinataire final des données est le partenaire ; on ne fait que relayer.

---

## 6. Contrat d'API

Base : `https://api.zonage.sallakane.cloud`

### `GET /api/v1/zonage`

| Paramètre | Type | Requis | Notes |
|---|---|---|---|
| `lat` | float | oui | WGS84, -90..90 |
| `lon` | float | oui | WGS84, -180..180 |
| `key` | string | oui | `partner.public_key` |
| `insee` | string | non | Code commune (`citycode` de la BAN). Rend la détection de Paris exacte ; mal formé, il est **ignoré**, jamais rejeté. |

**200 — zone trouvée**

```json
{
  "statut": "ok",
  "zone": {
    "code": 3,
    "cle": "fort",
    "libelle": "Exposition forte"
  },
  "obligation": {
    "applicable": true,
    "mission": "G1 PGC",
    "norme": "NF P 94-500",
    "validite_annees": 30,
    "resume": "Pour la vente d'un terrain non bâti constructible, l'étude géotechnique préalable G1 doit être annexée à la promesse ou à l'acte de vente."
  },
  "millesime": "2026",
  "simulation_id": 48213
}
```

**200 — hors périmètre** (Paris, DOM, hors métropole)

```json
{
  "statut": "hors_perimetre",
  "motif": "paris",
  "message": "La carte d'exposition ne couvre pas la ville de Paris.",
  "simulation_id": 48214
}
```

Ce cas renvoie **200, pas 404** : c'est une réponse fonctionnelle valide.
Le widget doit l'afficher comme une information, jamais comme une panne.

**400** paramètres invalides · **403** clé inconnue, inactive ou origine non
autorisée · **429** quota dépassé.

Toutes les erreurs suivent RFC 7807 (`application/problem+json`).

### `POST /api/v1/lead`

```json
{
  "key": "pk_...",
  "simulation_id": 48213,
  "nom": "…", "telephone": "…", "email": "…",
  "type_projet": "vente_terrain",
  "adresse": "12 rue des Vignes, 91130 Ris-Orangis",
  "message": "…"
}
```

Réponse **202 Accepted** immédiate. Le relais vers le webform du partenaire est
asynchrone (Messenger), avec réessai exponentiel et repli e-mail après trois
échecs.

Validation : au moins un moyen de contact (téléphone **ou** e-mail), pot de
miel + horodatage de formulaire contre les robots. Pas de captcha au premier
jour : mesurer d'abord le volume de spam réel.

### `GET /api/v1/health`

`{"status":"ok","rga_millesime":"2026","polygones":123456}` — supervision.

### `GET /widget.js?key=…`

Le chargeur. Voir §7.

### `GET /embed?key=…`

L'application affichée dans l'iframe.

---

## 7. Widget

### Intégration côté site hôte

```html
<div id="zonage-widget">
  <!-- Repli statique : reste visible si le widget ne se charge pas -->
  <p>Vous vendez un terrain ou vous construisez ?
     <a href="/demande-devis">Demandez votre devis d'étude de sol</a>.</p>
</div>
<script src="https://api.zonage.sallakane.cloud/widget.js?key=pk_xxx" async></script>
```

### Règles non négociables

**Dégradation gracieuse.** Si l'API ne répond pas, si le script ne charge pas,
si le réseau échoue : **on ne vide jamais le conteneur**. Le repli statique
reste affiché. Le site hôte doit continuer à convertir sans le simulateur.
C'est vrai pour Atlantis, ce sera vital chez un partenaire dont on casserait
sinon la page.

**Iframe, pas d'injection directe.** Isolation CSS totale, aucun conflit de
librairie, un seul point de mise à jour pour tout le parc. Hauteur ajustée via
`postMessage`.

**Timeout de 3 secondes** sur tout appel réseau, avec message de repli.

**Aucun cookie, aucun traceur** dans le widget. Il doit pouvoir fonctionner
avant tout consentement, sur n'importe quel site.

**Accessibilité** : navigation clavier complète sur l'autocomplétion (flèches,
Entrée, Échap), `aria-live` sur le résultat, focus visible,
`prefers-reduced-motion` respecté.

### Séquence

```
Saisie (≥ 4 caractères)
   └─> BAN /search  (navigateur, débounce 220 ms)
        └─> Sélection d'une adresse
             └─> API /zonage?lat&lon&key&insee
                  └─> Affichage du verdict + formulaire contextualisé
                       └─> API /lead  →  202
```

### Protocole entre l'iframe et la page hôte

Trois messages, tous émis par l'iframe :

| Message | Rôle |
|---|---|
| `zonage:pret` | Le simulateur est monté. **Tant qu'il n'arrive pas, le repli statique reste affiché** — c'est là que se joue la dégradation gracieuse. |
| `zonage:hauteur` | Hauteur à appliquer à l'iframe, à chaque changement de contenu. |
| `zonage:conversion` | L'utilisateur a cliqué l'appel à l'action. Le site hôte peut y brancher son propre parcours. |

Le chargeur n'accepte que les messages venant de l'origine du service **et** de
sa propre iframe : une page hôte peut contenir d'autres cadres, et n'importe
qui peut poster un message.

### Ce qui reste au lot 3

Le formulaire de devis pré-rempli et son envoi (`POST /api/v1/lead`). D'ici là,
l'appel à l'action émet `zonage:conversion` : le parcours ne s'arrête donc pas
sur un bouton mort, et le site hôte garde la main.

---

## 8. Sécurité

### Caddy (hôte)

Bloc à ajouter au Caddyfile mutualisé du VPS ; la version de référence est
versionnée dans `infra/Caddyfile.snippet`.

```caddyfile
api.zonage.sallakane.cloud {
    encode zstd gzip
    header {
        # NE PAS mettre X-Frame-Options: DENY — on veut être embarqué.
        Content-Security-Policy "frame-ancestors https://atlantis-geotechnique.fr https://*.atlantis-geotechnique.fr"
        X-Robots-Tag           "noindex, nofollow"
        X-Content-Type-Options "nosniff"
        Referrer-Policy        "strict-origin-when-cross-origin"
        Strict-Transport-Security "max-age=31536000; includeSubDomains"
    }
    # FrankenPHP écoute sur 127.0.0.1:8085 (compose.prod.yaml, APP_HTTP_PORT).
    reverse_proxy localhost:8085
}
```

**`/embed` pose désormais sa propre directive `frame-ancestors`, calculée depuis
`partner.origines_autorisees`.** Ajouter un partenaire n'impose plus de toucher
au Caddyfile du VPS. Un partenaire sans origine déclarée reçoit
`frame-ancestors 'none'` : le défaut sûr, et il se voit tout de suite.

Le bloc Caddy ci-dessus reste en place comme borne extérieure (les deux CSP
s'appliquent par intersection). Le supprimer un jour est une décision à prendre
sciemment, pas un nettoyage.

Le Caddyfile est **partagé avec les autres projets du VPS** : le valider avant
de recharger, et recharger plutôt que redémarrer.

```bash
sudo caddy validate --config /etc/caddy/Caddyfile
sudo systemctl reload caddy
```

Symfony doit faire confiance au proxy (`SYMFONY_TRUSTED_PROXIES=private_ranges`),
sinon il se croit en HTTP et génère des URL absolues en clair — le widget étant
chargé depuis une page HTTPS, le navigateur les bloquerait.

### CORS

Liste blanche explicite issue de `partner.origines_autorisees`. **Jamais `*`.**

### Limitation de débit

Composant `RateLimiter` de Symfony, par IP **et** par clé partenaire.
Cible : 30 requêtes / minute / IP sur `/api/v1/zonage`, 5 / minute sur
`/api/v1/lead`.

C'est le **seul** rempart : il n'y a pas de `limit_req` en amont (§2). L'IP
réelle vient de `X-Forwarded-For`, donc elle ne vaut que si les proxys de
confiance sont configurés — un `RateLimiter` qui compte l'IP du conteneur Caddy
limiterait tout le trafic à un seul compteur. À vérifier par un test.

### TLS

Obligatoire — le site hôte est en HTTPS, un script en HTTP serait bloqué par le
navigateur. Let's Encrypt, renouvellement automatique **vérifié** (une
supervision sur la date d'expiration, pas seulement le cron).

---

## 9. RGPD

Les demandes de devis transitent par ce serveur : l'éditeur est **sous-traitant**
du partenaire au sens de l'article 28. Un contrat de sous-traitance est requis
avant la mise en production.

Mesures techniques attendues :

- **VPS hébergé dans l'UE** — à vérifier avant d'écrire du code.
- Données personnelles **relayées, pas conservées** : purge à J+30 après
  livraison. Seule la table `simulation` (sans donnée personnelle) est
  conservée pour la mesure.
- `payload` chiffré au repos.
- Journaux : IP tronquée, pas de donnée personnelle en clair.
- Endpoint interne de suppression sur demande.

---

## 10. Tests

### Jeu de test spatial — généré depuis la donnée

Ne pas coder en dur des adresses supposées être dans telle ou telle zone. Les
extraire de la donnée elle-même :

```sql
SELECT DISTINCT ON (niveau_code)
       niveau_code,
       ST_Y(ST_PointOnSurface(geom)) AS lat,
       ST_X(ST_PointOnSurface(geom)) AS lon
FROM rga_zone_courante
ORDER BY niveau_code, ST_Area(geom) DESC;
```

`ST_PointOnSurface` garantit un point à l'intérieur du polygone, contrairement
au centroïde qui peut tomber en dehors sur une forme concave.

Restreindre à la zone de chalandise pour les tests métier :

```sql
WHERE ST_Intersects(geom, ST_MakeEnvelope(1.4, 48.1, 3.6, 49.3, 4326))
```

Résultat figé dans `tests/fixtures/points-reference.json`, rejoué à chaque mise
à jour du zonage.

### Cas limites obligatoires

| Cas | Attendu |
|---|---|
| Paris intra-muros | `hors_perimetre`, motif `paris` |
| Corse | Vérifier la couverture du shapefile |
| DOM-TOM | `hors_perimetre` |
| Coordonnées inversées (lon/lat) | Détecté et rejeté en 400 |
| Point sur une frontière de polygones | Un seul résultat (`LIMIT 1`) |
| Adresse sans numéro (lieu-dit) | Géocodage approximatif signalé à l'utilisateur |
| Clé partenaire inconnue / inactive | 403 |
| Origine non autorisée | 403 |
| API indisponible | Widget : repli statique conservé |
| Point en métropole sans polygone | `zone.cle = nul`, **pas** `hors_perimetre` |
| Zonage absent en base | 503, jamais un « hors périmètre » massif |
| Chargement partiel | Refus de basculer `rga_zone_courante` (§4.2) |

> Piège récurrent : l'API Géorisques attend `latlon=lon,lat` — **longitude
> d'abord**. La BAN renvoie `geometry.coordinates = [lon, lat]`. Écrire un test
> qui verrouille l'ordre.

### Couverture

Priorité au service de résolution de zone et à la validation des entrées.
Tests d'intégration sur les endpoints. Pas de test unitaire sur le widget ;
tests manuels sur la matrice de cas limites ci-dessus.

---

## 11. Structure du dépôt

```
src/
  Controller/
    ZonageController.php
    LeadController.php        # lot 3
    WidgetController.php      # /embed : valide la clé, pose la CSP, injecte la config
    HealthController.php
  Service/
    ZonageResolver.php        # SQL natif PostGIS, cœur métier
    ObligationMapper.php      # niveau_code → mission + textes réglementaires
    PartnerResolver.php       # résolution de la clé publique
  Model/
    Coordonnees.php           # validation + détection de permutation lat/lon
    ZonageResult.php
  Exception/
    ApiProblemException.php   # erreurs RFC 7807 (§6)
  EventListener/
    ApiProblemListener.php
  Command/
    CreatePartnerCommand.php  # app:partner:create — seule porte d'entrée client
  Message/
    RelayLead.php             # lot 3
  MessageHandler/
    RelayLeadHandler.php      # lot 3
  Entity/                     # Partner, Simulation, Lead
  Security/
    OriginValidator.php
public/
  widget.js                   # chargeur, < 5 Ko (budget vérifié par un test)
  exemple-integration.html    # page de recette : parcours + dégradation
templates/
  embed.html                  # application iframe (HTML/CSS/JS, zéro dépendance)
bin/
  charger-rga.sh              # pipeline §4, rejouable (--prod pour le VPS)
docs/
  donnees-rga.md              # champs relevés en 4.1
  mise-a-jour-zonage.md       # procédure §4.4
  exploitation.md             # accès, déploiement, restauration
tests/
  fixtures/points-reference.json     # extrait de la donnée réelle (§10)
  fixtures/zonage-synthetique.sql    # zonage de test, sans la donnée réelle
migrations/
  rga/2026-niveaux.sql        # mapping libellé source → niveau_code (§4.3)
data/                         # shapefiles — gitignoré, plusieurs centaines de Mo

Dockerfile                    # multi-stage FrankenPHP : base / dev / prod
compose.yaml                  # services communs
compose.override.yaml         # dev : bind-mount, ports exposés, Mailpit
compose.prod.yaml             # prod : image buildée, worker, pas de bind-mount
Makefile                      # raccourcis dev (§12)
frankenphp/
  Caddyfile                   # interne au conteneur (php_server)
  conf.d/                     # ini dev / prod
  docker-entrypoint.sh        # attente DB puis migrations si RUN_MIGRATIONS=1
infra/
  simulateur.service          # unité systemd (wrapper docker compose)
  Caddyfile.snippet           # bloc à coller dans le Caddyfile mutualisé (§8)
  simulateur-backup.sh
  simulateur-backup.cron
  simulateur-purge.cron       # purge des leads J+30 (§5)
.env                          # versionné, sans secret
.env.local.example            # gabarit des secrets
```

---

## 12. Développement local (Docker)

Tout tourne dans des conteneurs, PHP compris : c'est la seule façon d'avoir la
même version de PostGIS que la production, et le shapefile ne se charge pas
sans `shp2pgsql`.

### Services

| Service | Image | Rôle | Port hôte (dev) |
|---|---|---|---|
| `app` | build local, cible `frankenphp_dev` | Symfony + FrankenPHP | `127.0.0.1:8085` → 8080 |
| `database` | `postgis/postgis:16-3.4` | Postgres + PostGIS | `5435` → 5432 |
| `worker` | même image que `app` | `messenger:consume` | — |
| `mailer` | `axllent/mailpit` | repli e-mail des leads, en bac à sable | `8028` (UI), `1028` (SMTP) |
| `gis` | GDAL, version figée, profil `tools` | `ogrinfo` (§4.1) et `ogr2ogr` (§4.2) | — |

Les ports hôtes sont choisis pour ne pas heurter les autres projets du poste
(`maisonbrute-app` occupe 5434 et 8027/1027).

### Démarrage

```bash
cp .env.local.example .env.local     # APP_SECRET, POSTGRES_PASSWORD, MAILER_DSN
make up                              # build + up + migrations
make zonage-demo                     # zonage synthétique : quatre carrés en Essonne
docker compose exec app php bin/console app:partner:create "Mon client" \
    --origine https://exemple.fr
curl -s "localhost:8085/api/v1/zonage?key=pk_…&lat=48.65&lon=2.40"
```

`make zonage-demo` permet de développer **sans** les 600 Mo de donnée
officielle. Le vrai millésime se charge avec `make rga` (§4) et remplace la
vue ; les deux ne cohabitent pas.

Cibles du `Makefile` : `up`, `down`, `fresh`, `build`, `logs`, `sh`, `psql`,
`migrate`, `composer`, `test`, `rga`, `zonage-demo`, `points` (régénère les
points de référence de §10), `ogrinfo`.

### La donnée n'est pas dans le dépôt

`data/` est gitignoré. Le lien de téléchargement du millésime en cours et sa
somme de contrôle vivent dans `docs/donnees-rga.md`. Sans shapefile chargé,
l'application démarre normalement et `/api/v1/health` renvoie `polygones: 0` —
c'est exactement le signal attendu, et c'est aussi ce qu'il faut superviser en
production (§13).

### Ce qui diffère de la production

| | Dev | Prod |
|---|---|---|
| Code | bind-mount `./:/app` | copié dans l'image au build |
| `APP_ENV` | `dev`, profiler actif | `prod`, cache warmé au build |
| Messenger | conteneur `worker` (comme en prod) | idem |
| E-mails | Mailpit | SMTP réel via `MAILER_DSN` |
| TLS | aucun, HTTP clair sur 8085 | Caddy mutualisé du VPS |
| Migrations | `make migrate`, à la demande | entrypoint, `RUN_MIGRATIONS=1` sur `app` seul |

Le worker tourne aussi en dev : un relais de lead qui ne marche qu'en
production est un relais qu'on découvre cassé en production.

### Pièges connus

- **VPN et réseau Docker.** NordVPN (et consorts) capture le sous-réseau des
  conteneurs : `app` ne joint plus `database`, on obtient un *timeout* et non un
  refus de connexion. Autoriser `172.16.0.0/12` dans la liste blanche du client
  VPN.
- **`POSTGRES_PASSWORD` n'est lu qu'à l'initialisation du volume.** Le changer
  ensuite ne change rien ; il faut `docker compose down -v` (perte des données
  locales) ou un `ALTER USER`. Le définir **avant** le premier démarrage.
- **Reprojection au chargement, jamais à la lecture.** Un `ST_Transform` dans la
  requête chaude rendrait l'index GIST inutilisable — c'est le seul point de
  performance qui compte dans ce produit.
- **Interpolation des secrets par Compose.** Compose ne lit que `.env` pour
  résoudre les `${...}` ; `make up` passe donc explicitement
  `--env-file .env.local`. Même piège en prod, où c'est systemd qui s'en charge
  (§13).
- **Les conteneurs de dev tournent sous l'UID du poste** (`HOST_UID`/`HOST_GID`,
  exportés par `make`). Sinon tout ce qu'ils écrivent dans le bind-mount —
  `var/cache`, un `composer require` lancé dedans — appartient à root, et le
  poste ne peut plus y toucher. Conséquence : FrankenPHP écoute sur `:8080` en
  dev, un process non-root ne pouvant pas ouvrir un port privilégié. En
  production le conteneur tourne en root sur `:80`.
- **Pas de `VOLUME /app/var/` dans l'image.** Docker y créerait un volume
  anonyme appartenant à root qui masque le `var/` du poste : cache Symfony
  impossible à écrire, conteneur qui sort en erreur au démarrage.
- **Le worker attend que `app` soit *healthy*.** Il consomme un transport
  Doctrine : démarré en premier, il échoue sur `messenger_messages` absente,
  puisque c'est l'entrypoint de `app` qui crée la table.

---

## 13. Déploiement (VPS mutualisé)

Le VPS héberge déjà plusieurs projets derrière un **Caddy mutualisé** qui
termine le TLS et proxifie chaque domaine vers un port de loopback. Le
simulateur suit la convention en place : pile Docker Compose pilotée par une
unité systemd, aucune installation manuelle.

### Réservation de port — à vérifier avant tout

| Port | Projet |
|---|---|
| 8001 | rapport-generator |
| 8009 / 8080 | sunu-cagnotte |
| 8082 | intranet BCEAO |
| 8083 | Keycloak |
| 8084 | Maison Brute |
| **8085** | **simulateur RGA** (`APP_HTTP_PORT`) |

```bash
ss -tlnp | grep 127.0.0.1:80    # qui écoute déjà
docker ps                        # au nom de quel projet
```

Un port occupé par un voisin publierait **son** site sur
`api.zonage.sallakane.cloud`. Vérifier, ne pas supposer.

### Premier déploiement

```bash
# 1. Le code
sudo git clone <dépôt> /var/www/simulateur
sudo chown -R deploy:deploy /var/www/simulateur
cd /var/www/simulateur

# 2. Les secrets — AVANT le premier démarrage
cp .env.local.example .env.local && chmod 600 .env.local
$EDITOR .env.local     # APP_SECRET, POSTGRES_PASSWORD, MAILER_DSN, APP_HTTP_PORT=8085

# 3. Le service (build + up + migrations automatiques)
sudo cp infra/simulateur.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now simulateur

# 4. La donnée RGA — elle n'est pas dans le dépôt
scp ExpoArgile_Fr_metro_L93.* deploy@vps:/var/www/simulateur/data/
./bin/charger-rga.sh --prod

# 5. Vérifier AVANT d'exposer le domaine
docker compose -f compose.prod.yaml ps          # app / worker / database « healthy »
curl -s localhost:8085/api/v1/health            # polygones > 0, millésime attendu
```

Puis le domaine — prérequis DNS : un enregistrement A
`api.zonage.sallakane.cloud` vers l'IP du VPS.

```bash
sudo cp /etc/caddy/Caddyfile /etc/caddy/Caddyfile.bak.$(date +%F-%H%M%S)
sudo $EDITOR /etc/caddy/Caddyfile                    # y coller infra/Caddyfile.snippet (§8)
sudo caddy validate --config /etc/caddy/Caddyfile    # valider AVANT
sudo systemctl reload caddy                          # reload : les voisins ne tombent pas
```

### L'unité systemd

`infra/simulateur.service` — `Type=oneshot`, `RemainAfterExit=true`,
`ExecStart=docker compose -f compose.prod.yaml up -d --build --remove-orphans`,
`EnvironmentFile=/var/www/simulateur/.env.local`.

> ⚠️ **Ne jamais lancer `docker compose up` à la main sur le VPS.** Compose
> n'interpole les `${...}` qu'à partir de `.env` (versionné, donc sans secret)
> ou de son propre shell — **jamais** depuis `.env.local`. Seul
> `EnvironmentFile=` de l'unité systemd les fournit. Un `up` manuel résoudrait
> `${POSTGRES_PASSWORD}` en vide et casserait l'authentification de la base.
> `ps`, `logs`, `exec` ne recréent rien et sont sans risque — c'est ce
> qu'utilisent les crons.

### Mise à jour du code

```bash
cd /var/www/simulateur && git pull
sudo systemctl restart simulateur     # rebuild + migrations
```

Une mise à jour du **zonage** ne passe pas par là : c'est la procédure §4.4,
sans interruption de service (bascule de la vue `rga_zone_courante`).

### Sauvegarde et restauration

`infra/simulateur-backup.sh` : `pg_dump` via `docker compose exec -T database`,
compressé dans `/var/backups/simulateur`, rotation 14 jours, lancé à **3 h 40**
(3 h 20 est déjà pris par un voisin).

Deux natures de données, une seule est irremplaçable :

- `rga_zone*` — rechargeable depuis la source (§4). Le dump n'est qu'un confort.
- `partner`, `simulation`, `lead` — aucune source externe. C'est ce qu'on
  sauvegarde vraiment.

Restauration (à tester **avant** la mise en production, cf. §15) :

```bash
gunzip -c /var/backups/simulateur/simulateur-<horodatage>.sql.gz \
  | docker compose -f compose.prod.yaml exec -T database psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"
```

La procédure complète, avec le temps qu'elle a réellement pris lors du test,
est consignée dans `docs/exploitation.md`.

### Tâches planifiées

| Quoi | Quand | Fichier |
|---|---|---|
| Sauvegarde de la base | 3 h 40 | `infra/simulateur-backup.cron` |
| Purge des leads livrés à J+30 (§9) | 4 h 10 | `infra/simulateur-purge.cron` |
| Renouvellement TLS | automatique (Caddy) | supervision de la date d'expiration (§8) |

### Supervision

`/api/v1/health` est surveillé sur trois points : le statut, le millésime
attendu, et **`polygones > 0`**. Une base vide ne provoque aucune erreur HTTP :
l'API répondrait `hors_perimetre` pour la France entière. C'est la panne la plus
coûteuse de ce produit, et la plus silencieuse.

Côté hôte : `docker compose -f compose.prod.yaml ps` et
`... logs -f app` / `... logs -f worker`. Un worker mort ne se voit pas non
plus : les leads s'empilent dans `messenger_messages` sans être relayés.

---

## 14. Découpage

### Lot 1 — Socle données et API

Environnement Docker de développement (§12), inspection du shapefile,
chargement PostGIS, `ZonageResolver`, endpoint `/api/v1/zonage`, jeu de test
spatial.

*Terminé quand :* une requête `curl` sur une coordonnée renvoie le bon niveau,
et que le jeu de test de référence passe intégralement.

### Lot 2 — Simulateur

Widget, autocomplétion BAN, affichage du verdict, parcours de conversion pour
les quatre niveaux, dégradation gracieuse, accessibilité.

*Terminé quand :* le parcours complet fonctionne sur une page de test, et que
le simulateur coupé laisse le repli statique en place.

### Lot 3 — Leads et mesure

`POST /api/v1/lead`, relais Messenger, réessai, repli e-mail, table
`simulation`, tableau de bord de conversion, purge J+30.

*Terminé quand :* une demande envoyée depuis le widget arrive dans le webform
destinataire, et qu'une coupure du destinataire déclenche bien le réessai puis
le repli.

### Lot 4 — Mise en production

Pile `compose.prod.yaml` + unité systemd, bloc Caddy sur le VPS mutualisé (TLS,
CSP, en-têtes), limitation de débit, sauvegarde et purge planifiées,
supervision, intégration au site hôte via un bloc dédié, recette. Détail en §13.

*Terminé quand :* le simulateur est en ligne, supervisé, et que la procédure de
restauration a été testée au moins une fois — pas décrite, testée.

---

## 15. Contraintes transverses

- **Aucun secret dans le dépôt.** Variables d'environnement uniquement :
  `.env` versionné et vide de secrets, `.env.local` sur la machine, en `600`.
- **La production tourne la même image que le développement.** Aucune
  installation manuelle sur le VPS, aucune commande qui ne soit pas dans
  `bin/`, `infra/` ou le `Makefile`.
- **Le VPS est mutualisé.** Toute intervention sur le Caddyfile est validée
  avant rechargement, et `reload` ne se remplace pas par `restart`. Casser ce
  fichier coupe les autres sites.
- **Aucune URL de base en dur**, ni serveur ni widget — le domaine changera.
- **Aucune référence à Atlantis dans le code.** Tout passe par `partner`.
- **Migrations versionnées.** Aucune modification manuelle du schéma.
- **Sauvegarde quotidienne** de la base, et **restauration testée** avant la
  mise en production. Un sauvegarde jamais restaurée n'est pas une sauvegarde.
- **Mention de la source** requise par la licence Etalab : « Source : BRGM /
  Géorisques — carte d'exposition au retrait-gonflement des argiles, arrêté du
  9 janvier 2026 », visible dans le widget.
- **Avertissement légal** dans le widget : outil d'orientation, ne constitue ni
  un état des risques réglementaire ni un avis juridique ; l'obligation dépend
  aussi du caractère non bâti et constructible du terrain et de la nature de
  l'opération.

---

## 16. Données à obtenir du client avant le lot 2

Ces valeurs ne s'inventent pas et bloquent l'écriture des contenus :

1. Prix indicatifs ou fourchettes par mission (G1 PGC, G2 AVP, G5…).
2. Délai d'intervention réellement tenable.
3. Périmètre géographique réel — que répond le simulateur pour un terrain hors
   zone d'intervention ?
4. Circuit du lead : destinataire, traitement, délai de réponse annoncé.

Tant qu'elles manquent, utiliser des valeurs marquées `À DÉFINIR` dans le code,
jamais des valeurs plausibles inventées.
