# Donnée RGA — relevé du millésime

> À remplir **avant** tout développement, par l'inspection du shapefile
> (SPEC §4.1). Ne rien supposer : c'est ce document qui permettra de rejouer le
> chargement au prochain zonage.

## Source

- Géorisques — « Retrait gonflement des argiles, carte d'exposition (2026) »,
  arrêté du 9 janvier 2026.
- Licence Etalab 2.0 — réutilisation libre, **mention de la source obligatoire**
  et visible dans le widget (SPEC §15).
- Fichier national : `https://files.georisques.fr/argiles/2025/AleaRG_2025_Fxx_L93.zip`
  (≈ 440 Mo, modifié le 28/11/2025).
- Somme de contrôle : `sha256:18e9504365fd390e4d6db1784a89f8ca85ae3a15b5e289637b23f77162bdce87`
  (439 610 008 octets).

La donnée n'est pas versionnée (plusieurs centaines de Mo) : elle se dépose
dans `data/`, gitignoré.

### Comment retrouver ce lien au prochain millésime

La page de téléchargement est une interface JavaScript : le lien du fichier
n'apparaît pas dans le HTML et n'est pas devinable. Il se demande à l'API :

```bash
# 1. La clé de la base est dans l'attribut data-js-base-key de la page
curl -s https://www.georisques.gouv.fr/donnees/bases-de-donnees/retrait-gonflement-des-argiles-version-2026 \
  | grep -o 'data-js-base-key="[^"]*"'          # -> alearg_25

# 2. Les formats disponibles
curl -s 'https://www.georisques.gouv.fr/webappReport/ws/telechargements/formats/alearg_25?echelle=nationale'
# -> {"formats":["zip"]}

# 3. Le lien du fichier (échelle nationale, régionale ou départementale)
curl -s 'https://www.georisques.gouv.fr/webappReport/ws/telechargements/formats/zip/alearg_25?echelle=nationale'
```

### Deux pièges vérifiés

- **`https://files.georisques.fr/argiles/AleaRG_Fxx_L93.zip`** (sans le `2025/`)
  existe toujours et répond 200 : c'est l'**ancien** millésime, daté du
  16/06/2021, 623 Mo. Le charger produirait un simulateur qui répond avec le
  zonage périmé — faux, et invérifiable de l'extérieur.
- Le champ `tailleFichier` renvoyé par l'API annonce 623 357 253 octets, soit la
  taille de l'ANCIEN fichier, alors que `lienFichier` pointe bien vers le
  nouveau (439 610 008 octets). Ne pas se fier à ce champ pour contrôler un
  téléchargement ; utiliser la somme de contrôle ci-dessus.

## Relevé du 2026-08-24 (`make ogrinfo`)

| Élément | Valeur relevée |
|---|---|
| Fichier | `AleaRG_2025_Fxx_L93.shp` (402 Mo décompressé) |
| SRID | EPSG:2154 — `RGF93 v1 / Lambert-93`, conforme à l'attendu |
| Encodage du `.dbf` | **UTF-8**, déclaré dans le `.cpg` — et non LATIN1 |
| Géométrie | `Polygon` (converti en `MultiPolygon` au chargement) |
| Nombre de polygones | **121 399** |
| Champs | `gid` (Integer64), `insee_dep` (String), `niveau` (Real), `surf_m2` (Real) |
| Champ du niveau d'exposition | `niveau` |
| Valeurs distinctes | **1, 2, 3** — et rien d'autre |

Répartition relevée :

| `niveau` | Polygones | Surface | Lecture |
|---|---|---|---|
| 1 | 35 091 | 93 863 km² | faible |
| 2 | 64 039 | 195 913 km² | moyen |
| 3 | 22 269 | 105 679 km² | fort |

**Contrôle de cohérence du millésime** : moyen + fort = 301 592 km², soit
**54,7 %** des ~551 700 km² de France métropolitaine. L'arrêté du 9 janvier 2026
annonce le passage de 48 % à 55 % du territoire (SPEC §1). C'est bien le bon
millésime, et c'est un contrôle à refaire au prochain.

Le mapping « valeur source → `niveau_code` » vit dans
`migrations/rga/2026-niveaux.sql`, pas dans le code applicatif.

### Le point le plus important du relevé

**Il n'existe aucun polygone de niveau 0.** La carte ne dessine que les zones
exposées : 395 455 km² couverts sur ~551 700. En France métropolitaine et hors
Paris, un point sans polygone n'est donc pas « hors périmètre », il est en
**exposition nulle** — c'est une réponse, avec son propre appel à l'action
(SPEC §1). `ZonageResolver` en dépend directement.

Conséquence opérationnelle : un chargement partiel ferait répondre « pas de
risque » sur des régions entières, en HTTP 200, sans rien signaler.
`bin/charger-rga.sh` compare donc systématiquement le nombre de polygones
chargés à celui du shapefile et refuse de basculer la vue en cas d'écart.

### Deux pièges du fichier lui-même

- Les largeurs déclarées dans le `.dbf` sont fantaisistes : `surf_m2` est annoncé
  en `Real(24.15)`, dont GDAL déduit un `NUMERIC(23,15)` incapable de stocker
  1 474 098 685 m². D'où le `-lco PRECISION=NO` du chargement.
- Le fichier s'appelle `2025` (produit en novembre 2025) alors que le zonage est
  celui de l'arrêté du 9 janvier 2026, applicable depuis le 1er juillet 2026.
  La clé de la base côté Géorisques est `alearg_25`. En base, le millésime
  retenu est **`2026`** : c'est la date qui fait foi juridiquement.

## Périmètre à vérifier au chargement (SPEC §3)

- **Paris intra-muros** : aucun polygone attendu. Ce n'est pas une erreur, c'est
  un cas fonctionnel avec son propre message.
- **Corse** : couverture à confirmer.
- **DOM-TOM** : hors périmètre.

`bin/charger-rga.sh` teste les deux premiers points à chaque chargement.
