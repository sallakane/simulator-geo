#!/usr/bin/env bash
# Chargement du zonage RGA dans PostGIS — SPEC §4, rejouable à l'identique sur
# le poste et sur le VPS. C'est ce qui permettra de rejouer l'opération au
# prochain millésime : si une commande n'est pas ici, elle sera perdue.
#
#   ./bin/charger-rga.sh                       # dev, millésime 2026
#   ./bin/charger-rga.sh --prod --switch       # VPS, puis bascule de la vue
#   ./bin/charger-rga.sh --bascule             # met en service, sans recharger
#   ./bin/charger-rga.sh --generaliser         # rejoue les niveaux de généralisation
#   ./bin/charger-rga.sh --points              # regénère le jeu de test (§10)
#   ./bin/charger-rga.sh --millesime 2029 --shp data/Expo_2029_L93.shp --srs EPSG:2154
#
# Le chargement se fait dans rga_zone_<millesime>. La bascule de la vue
# rga_zone_courante — la seule table que le code interroge — est un geste
# SÉPARÉ (--switch), à ne faire qu'après vérification. C'est ce qui rend la
# mise à jour du zonage possible sans interruption de service (§4.4).
set -euo pipefail
cd "$(dirname "$0")/.."

MILLESIME=2026
SHP=""
ENCODING="${SHP_ENCODING:-LATIN1}"   # dépend du .dbf réel — relevé en §4.1
S_SRS="${SHP_SRS:-EPSG:2154}"        # Lambert 93 annoncé par la source — à confirmer en §4.1
SWITCH=0
BASCULE_SEULE=0
GENERALISER_SEULE=0
POINTS=0
COMPOSE=(docker compose)

while [ $# -gt 0 ]; do
  case "$1" in
    --prod)       COMPOSE=(docker compose -f compose.prod.yaml); shift ;;
    --millesime)  MILLESIME="$2"; shift 2 ;;
    --shp)        SHP="$2"; shift 2 ;;
    --encoding)   ENCODING="$2"; shift 2 ;;
    --srs)        S_SRS="$2"; shift 2 ;;
    --switch)     SWITCH=1; shift ;;
    --bascule)    SWITCH=1; BASCULE_SEULE=1; shift ;;
    --generaliser) GENERALISER_SEULE=1; shift ;;  # action autonome : rejoue §2.5 sans recharger
    --points)     POINTS=1; shift ;;   # action autonome : lit rga_zone_courante
    -h|--help)    sed -n '2,20p' "$0"; exit 0 ;;
    *) echo "Option inconnue : $1" >&2; exit 2 ;;
  esac
done

# Les secrets viennent de .env.local, y compris sur le VPS : le chargement se
# fait à la main (pas via systemd), et ogr2ogr a besoin du mot de passe pour
# écrire dans la base. `run` et `exec` ne recréent aucun conteneur de service —
# la pile en cours n'est pas touchée.
if [ -f .env.local ]; then
  set -a; . ./.env.local; set +a
fi

TABLE="rga_zone_${MILLESIME}"
PGUSER_="${POSTGRES_USER:-app}"
PGDB_="${POSTGRES_DB:-zonage}"

psql_() { "${COMPOSE[@]}" exec -T database psql -U "$PGUSER_" -d "$PGDB_" -v ON_ERROR_STOP=1 "$@"; }
say()   { printf '\n\033[1m→ %s\033[0m\n' "$*"; }

# Met le millésime en service. L'ancienne table est CONSERVÉE (traçabilité §4.4) :
# on ne bascule qu'une vue.
#
# DROP + CREATE dans UNE transaction : atomique, donc sans fenêtre où la vue
# n'existerait pas — le service continue de répondre pendant la bascule.
# (CREATE OR REPLACE ne suffit pas : il refuse tout changement de type de
# colonne, ce qui arrive dès que le millésime change de schéma.)
#
# Projection EXPLICITE et non SELECT * : c'est le contrat entre la donnée et le
# code. Le shapefile peut changer de colonnes d'un millésime à l'autre, cette
# vue ne doit pas.
# Points de référence pour les tests : extraits de la donnée elle-même, jamais
# devinés. ST_PointOnSurface garantit un point DANS le polygone, contrairement
# au centroïde qui tombe dehors sur une forme concave (§10).
#
# Lecture sur la VUE : ce sont les points du millésime EN SERVICE qui font
# référence, pas ceux d'une table chargée mais pas encore basculée.
extraire_les_points() {
  say "Points de référence → tests/fixtures/points-reference.json"
  mkdir -p tests/fixtures
  psql_ -At <<SQL > tests/fixtures/points-reference.json
SELECT jsonb_pretty(jsonb_agg(p)) FROM (
  SELECT DISTINCT ON (niveau_code)
         niveau_code,
         round(ST_Y(ST_PointOnSurface(geom))::numeric, 6) AS lat,
         round(ST_X(ST_PointOnSurface(geom))::numeric, 6) AS lon,
         millesime
  FROM rga_zone_courante
  WHERE niveau_code IS NOT NULL
  ORDER BY niveau_code, ST_Area(geom) DESC
) p;
SQL
}

# ── Généralisation cartographique ────────────────────────────────────────────
# La carte du widget doit se dézoomer jusqu'à la France entière. Or une tuile
# vectorielle z=6 construite sur la géométrie exacte pèse 5,2 Mo et coûte 6,4 s
# (mesuré sur le millésime 2026) : 25,8 millions de sommets dont l'écrasante
# majorité tient dans moins d'un pixel.
#
# D'où deux tables dérivées, construites UNE fois au chargement plutôt qu'à
# chaque tuile. Elles ne servent qu'à DESSINER : la réponse d'exposition, elle,
# ne sort jamais d'ailleurs que de rga_zone_courante (§3). Une frontière
# simplifiée ne doit jamais devenir une frontière juridique.
#
#   _g   tolérance 0,0015° ≈ 110 m  → z 8-11   53 Mo   2,25 M sommets
#   _gg  tolérance 0,03°   ≈ 2,2 km → z 5-7    surfaces > 20 km² seulement
#
# Les tolérances sont calées sur la taille du pixel : 110 m vaut moins d'un
# pixel à z10, 2,2 km moins d'un pixel à z5. Ce qui disparaît est ce qui
# n'était de toute façon pas visible — la France dessinée aux deux tolérances
# est indiscernable à l'échelle nationale (comparaison faite, cf.
# docs/donnees-rga.md), pour 30 % de poids en moins sur les tuiles les plus
# lourdes du service.
#
# Dissoudre par niveau (ST_Union) a été essayé et écarté : le poids ne bouge
# pas — ce sont les sommets qui pèsent, pas le nombre d'objets — et découper
# trois multipolygones géants coûte 14 s par tuile au lieu de 0,5 s.
#
# ST_SimplifyPreserveTopology et non ST_Simplify : ce dernier peut produire des
# polygones qui se croisent, et ST_AsMVTGeom les rejetterait silencieusement —
# des trous dans la carte, sans la moindre erreur.
generaliser() {
  say "Généralisation cartographique de $TABLE (§2.5)"
  # CASCADE : si ce millésime est DÉJÀ en service, les vues rga_zone_courante_g
  # et _gg s'appuient sur ces tables. Elles sont recréées plus bas — et elles
  # sont le seul objet que ce CASCADE puisse emporter, puisque rien d'autre ne
  # dépend de ces deux tables.
  psql_ <<SQL
DROP TABLE IF EXISTS ${TABLE}_g, ${TABLE}_gg CASCADE;

-- ST_CollectionExtract(...,3) : ST_MakeValid peut renvoyer une collection
-- mêlant lignes et polygones sur une géométrie dégénérée. Seuls les polygones
-- se dessinent.
CREATE TABLE ${TABLE}_g AS
SELECT niveau_code,
       ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_SimplifyPreserveTopology(geom, 0.0015)), 3))::geometry(MultiPolygon, 4326) AS geom,
       ST_Area(geom::geography) / 1e6 AS km2
  FROM $TABLE
 WHERE niveau_code IS NOT NULL;

CREATE TABLE ${TABLE}_gg AS
SELECT niveau_code,
       ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_SimplifyPreserveTopology(geom, 0.03)), 3))::geometry(MultiPolygon, 4326) AS geom
  FROM ${TABLE}_g
 WHERE km2 > 20;

CREATE INDEX ON ${TABLE}_g  USING GIST(geom);
CREATE INDEX ON ${TABLE}_gg USING GIST(geom);
ANALYZE ${TABLE}_g;
ANALYZE ${TABLE}_gg;

\\echo '-- Poids des niveaux de généralisation'
SELECT 'exact' AS niveau, count(*) AS objets, pg_size_pretty(pg_total_relation_size('$TABLE')) AS taille FROM $TABLE
UNION ALL SELECT 'g  (110 m)', count(*), pg_size_pretty(pg_total_relation_size('${TABLE}_g'))  FROM ${TABLE}_g
UNION ALL SELECT 'gg (2,2 km)', count(*), pg_size_pretty(pg_total_relation_size('${TABLE}_gg')) FROM ${TABLE}_gg;
SQL

  # Ce millésime était-il en service ? Alors le CASCADE ci-dessus vient de
  # retirer ses deux vues cartographiques : la carte répondrait 503 jusqu'à la
  # prochaine bascule. On les remet, sans toucher à rga_zone_courante.
  if [ "$(psql_ -At -c "SELECT count(*) FROM pg_views WHERE viewname = 'rga_zone_courante' AND definition LIKE '%${TABLE}%'")" != "0" ]; then
    say "Millésime en service : les vues cartographiques sont recréées"
    psql_ <<SQL
BEGIN;
CREATE OR REPLACE VIEW rga_zone_courante_g  AS SELECT geom, niveau_code FROM ${TABLE}_g;
CREATE OR REPLACE VIEW rga_zone_courante_gg AS SELECT geom, niveau_code FROM ${TABLE}_gg;
COMMIT;
SQL
  fi
}

basculer_la_vue() {
  say "Bascule de rga_zone_courante sur $TABLE"
  # Les trois vues basculent dans LA MÊME transaction : la carte et le verdict
  # ne doivent jamais, fût-ce une seconde, parler de deux millésimes différents.
  psql_ <<SQL
BEGIN;
DROP VIEW IF EXISTS rga_zone_courante;
DROP VIEW IF EXISTS rga_zone_courante_g;
DROP VIEW IF EXISTS rga_zone_courante_gg;
CREATE VIEW rga_zone_courante AS
    SELECT id, geom, niveau_code, niveau_libelle, millesime FROM $TABLE;
CREATE VIEW rga_zone_courante_g AS
    SELECT geom, niveau_code FROM ${TABLE}_g;
CREATE VIEW rga_zone_courante_gg AS
    SELECT geom, niveau_code FROM ${TABLE}_gg;
COMMIT;
SQL
}

# Bascule seule : la procédure §4.4 sépare volontairement le chargement (qu'on
# vérifie) de la mise en service (qu'on décide). Recharger 121 000 polygones
# pour changer une vue n'aurait aucun sens.
if [ "$BASCULE_SEULE" = 1 ]; then
  basculer_la_vue
  exit 0
fi

if [ "$GENERALISER_SEULE" = 1 ]; then
  generaliser
  exit 0
fi

if [ "$POINTS" = 1 ]; then
  extraire_les_points
  exit 0
fi

# ── 0. Le shapefile ──────────────────────────────────────────────────────────
if [ -z "$SHP" ]; then
  SHP=$(find data -maxdepth 1 -name '*.shp' | head -1 || true)
fi
if [ -z "$SHP" ] || [ ! -f "$SHP" ]; then
  cat >&2 <<'MSG'
Aucun shapefile dans data/.
La donnée n'est pas versionnée (plusieurs centaines de Mo) : lien de
téléchargement et somme de contrôle dans docs/donnees-rga.md.
MSG
  exit 1
fi
BASE=$(basename "$SHP")
say "Shapefile : $BASE  ·  table cible : $TABLE  ·  SRS source : $S_SRS  ·  encodage .dbf : $ENCODING"

# ── 1. Chargement ────────────────────────────────────────────────────────────
# Reprojection L93 (EPSG:2154) → WGS84 (4326) AU CHARGEMENT, jamais à la
# lecture : un ST_Transform dans la requête chaude rendrait l'index GIST
# inutilisable, et c'est la seule performance qui compte ici (§3).
#
# Chargement par ogr2ogr et non shp2pgsql : l'image postgis/postgis ne contient
# pas ce dernier (vérifié), et GDAL fait le même travail — reprojection,
# encodage, index GIST — avec l'outil qui a déjà servi à l'inspection.
#
# PRECISION=NO : les largeurs déclarées dans le .dbf sont fantaisistes. Le
# millésime 2026 annonce `surf_m2` en Real(24.15), dont GDAL déduit un
# NUMERIC(23,15) — incapable de stocker 1 474 098 685 m². Sans cette option, le
# chargement s'arrête à 80 % sur un « numeric field overflow ».
say "Chargement (ogr2ogr $S_SRS → EPSG:4326)…"
"${COMPOSE[@]}" run --rm \
  -e TABLE="$TABLE" -e SHPFILE="/data/$BASE" -e ENC="$ENCODING" -e SSRS="$S_SRS" \
  gis sh -c 'ogr2ogr -f PostgreSQL \
      PG:"host=database port=5432 dbname=$POSTGRES_DB user=$POSTGRES_USER password=$POSTGRES_PASSWORD" \
      "$SHPFILE" \
      -s_srs "$SSRS" -t_srs EPSG:4326 \
      -nln "$TABLE" -nlt MULTIPOLYGON -overwrite \
      -lco GEOMETRY_NAME=geom -lco FID=id -lco SPATIAL_INDEX=GIST \
      -lco PRECISION=NO \
      --config SHAPE_ENCODING "$ENC" \
      -progress'

# ── 2. Post-traitement (§4.3) ────────────────────────────────────────────────
say "Géométries valides + colonne normalisée…"
psql_ <<SQL
-- Les shapefiles officiels contiennent régulièrement des polygones invalides ;
-- ST_Intersects sur une géométrie invalide peut renvoyer n'importe quoi.
UPDATE $TABLE SET geom = ST_MakeValid(geom) WHERE NOT ST_IsValid(geom);

-- Le code applicatif ne dépend jamais du libellé source, seulement de ce code.
ALTER TABLE $TABLE ADD COLUMN IF NOT EXISTS niveau_code SMALLINT;   -- 0 nul … 3 fort
ALTER TABLE $TABLE ADD COLUMN IF NOT EXISTS millesime VARCHAR(10);
UPDATE $TABLE SET millesime = '$MILLESIME' WHERE millesime IS NULL;
SQL

# Le mapping libellé source → niveau_code dépend des valeurs relevées en §4.1.
# Il vit dans un fichier versionné, pas dans le code applicatif, pour être
# rejouable et comparable d'un millésime à l'autre.
MAPPING="migrations/rga/${MILLESIME}-niveaux.sql"
if [ -f "$MAPPING" ]; then
  say "Mapping $MAPPING"
  psql_ < "$MAPPING"
else
  cat >&2 <<MSG

⚠ Mapping absent : $MAPPING
  Relever d'abord les valeurs exactes du champ d'exposition (§4.1) :
      make ogrinfo
  puis écrire le UPDATE correspondant dans ce fichier. niveau_code reste NULL
  d'ici là, et la vérification ci-dessous échouera — c'est voulu.
MSG
fi

# ogr2ogr crée un schéma `ogr_system_tables` (métadonnées de couches) via un
# event trigger. On ne s'en sert pas, et Doctrine l'introspecte : sans ce
# nettoyage, `doctrine:migrations:diff` propose de le supprimer à chaque fois.
psql_ -q -c 'DROP SCHEMA IF EXISTS ogr_system_tables CASCADE;'

say "Index GIST + statistiques…"
psql_ <<SQL
CREATE INDEX IF NOT EXISTS idx_${TABLE}_geom ON $TABLE USING GIST(geom);
ANALYZE $TABLE;
SQL

# ── 3. Vérification (§4.4, §10) ──────────────────────────────────────────────
# Complétude : c'est LE contrôle qui compte. En France métropolitaine, un point
# sans polygone vaut « exposition nulle » (la carte ne dessine que les zones
# exposées) : une donnée à moitié chargée répondrait donc « pas de risque » sur
# des régions entières, en 200, sans rien signaler.
ATTENDU=$("${COMPOSE[@]}" run --rm gis sh -c "ogrinfo -ro -so '/data/$BASE' -al 2>/dev/null | sed -n 's/^Feature Count: //p'" | tr -d '\r\n ')
CHARGE=$(psql_ -At -c "SELECT count(*) FROM $TABLE" | tr -d '\r\n ')
if [ -n "$ATTENDU" ] && [ "$ATTENDU" != "$CHARGE" ]; then
  printf '\n\033[1;31m✗ Chargement incomplet : %s polygones dans le shapefile, %s en base.\033[0m\n' "$ATTENDU" "$CHARGE" >&2
  echo "  La vue rga_zone_courante n'est PAS basculée. Relancer le chargement." >&2
  exit 1
fi
say "Complétude : $CHARGE polygones, conforme au shapefile"

# La généralisation vient APRÈS le contrôle de complétude, et pas avant : sur un
# chargement partiel, elle brasserait trois minutes de géométries pour un
# millésime que le script refuse de mettre en service de toute façon.
generaliser

say "Vérification"
psql_ <<SQL
\\echo '-- Répartition par niveau (NULL = mapping manquant ou incomplet)'
SELECT niveau_code, count(*) AS polygones FROM $TABLE GROUP BY 1 ORDER BY 1;

\\echo '-- Couverture attendue : Corse > 0, Paris intra-muros = 0 (§3)'
SELECT
  (SELECT count(*) FROM $TABLE WHERE ST_Intersects(geom, ST_MakeEnvelope(8.5, 41.3, 9.6, 43.1, 4326)))  AS corse,
  (SELECT count(*) FROM $TABLE WHERE ST_Intersects(geom, ST_MakeEnvelope(2.22, 48.81, 2.47, 48.91, 4326))) AS paris;
SQL

if [ "$SWITCH" = 1 ]; then
  basculer_la_vue
else
  echo
  echo "Vue rga_zone_courante inchangée. Après vérification ET passage du jeu de"
  echo "test de non-régression (§10) : ./bin/charger-rga.sh --switch --millesime $MILLESIME"
fi

say "Terminé."
