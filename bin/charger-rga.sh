#!/usr/bin/env bash
# Chargement du zonage RGA dans PostGIS — SPEC §4, rejouable à l'identique sur
# le poste et sur le VPS. C'est ce qui permettra de rejouer l'opération au
# prochain millésime : si une commande n'est pas ici, elle sera perdue.
#
#   ./bin/charger-rga.sh                       # dev, millésime 2026
#   ./bin/charger-rga.sh --prod --switch       # VPS, puis bascule de la vue
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
    --points)     POINTS=1; shift ;;
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
say "Chargement (ogr2ogr $S_SRS → EPSG:4326)…"
"${COMPOSE[@]}" run --rm \
  -e TABLE="$TABLE" -e SHPFILE="/data/$BASE" -e ENC="$ENCODING" -e SSRS="$S_SRS" \
  gis sh -c 'ogr2ogr -f PostgreSQL \
      PG:"host=database port=5432 dbname=$POSTGRES_DB user=$POSTGRES_USER password=$POSTGRES_PASSWORD" \
      "$SHPFILE" \
      -s_srs "$SSRS" -t_srs EPSG:4326 \
      -nln "$TABLE" -nlt MULTIPOLYGON -overwrite \
      -lco GEOMETRY_NAME=geom -lco FID=id -lco SPATIAL_INDEX=GIST \
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

say "Index GIST + statistiques…"
psql_ <<SQL
CREATE INDEX IF NOT EXISTS idx_${TABLE}_geom ON $TABLE USING GIST(geom);
ANALYZE $TABLE;
SQL

# ── 3. Vérification (§4.4, §10) ──────────────────────────────────────────────
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
  say "Bascule de rga_zone_courante sur $TABLE"
  # L'ancienne table est CONSERVÉE (traçabilité §4.4) : on ne bascule qu'une vue.
  psql_ -c "CREATE OR REPLACE VIEW rga_zone_courante AS SELECT * FROM $TABLE;"
else
  echo
  echo "Vue rga_zone_courante inchangée. Après vérification ET passage du jeu de"
  echo "test de non-régression (§10) : ./bin/charger-rga.sh --switch --millesime $MILLESIME"
fi

if [ "$POINTS" = 1 ]; then
  # Points de référence pour les tests : extraits de la donnée elle-même, jamais
  # devinés. ST_PointOnSurface garantit un point DANS le polygone, contrairement
  # au centroïde qui tombe dehors sur une forme concave (§10).
  say "Points de référence → tests/fixtures/points-reference.json"
  mkdir -p tests/fixtures
  psql_ -At <<SQL > tests/fixtures/points-reference.json
SELECT json_agg(p)::text FROM (
  SELECT DISTINCT ON (niveau_code)
         niveau_code,
         round(ST_Y(ST_PointOnSurface(geom))::numeric, 6) AS lat,
         round(ST_X(ST_PointOnSurface(geom))::numeric, 6) AS lon,
         '$MILLESIME' AS millesime
  FROM $TABLE
  WHERE niveau_code IS NOT NULL
  ORDER BY niveau_code, ST_Area(geom) DESC
) p;
SQL
fi

say "Terminé."
