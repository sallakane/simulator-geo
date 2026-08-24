#!/bin/sh
# Sauvegarde quotidienne de la base (partenaires, simulations, leads en transit).
# Dump compressé + rotation 14 jours. Installation :
#   sudo cp infra/simulateur-backup.sh /usr/local/bin/simulateur-backup
#   sudo chmod +x /usr/local/bin/simulateur-backup
#   sudo cp infra/simulateur-backup.cron /etc/cron.d/simulateur-backup
# Prérequis (droits pour l'utilisateur `deploy`) :
#   sudo mkdir -p /var/backups/simulateur && sudo chown deploy:deploy /var/backups/simulateur
#   sudo touch /var/log/simulateur-backup.log && sudo chown deploy:deploy /var/log/simulateur-backup.log
#
# Ce qu'on sauvegarde, c'est ce qu'on ne peut PAS reconstituer : `partner` et
# `simulation`. Le zonage (`rga_zone*`), lui, se recharge depuis le shapefile
# officiel, dont l'URL et la somme de contrôle sont dans docs/donnees-rga.md.
#
# La différence n'est pas théorique : mesuré sur le millésime 2026, un dump
# complet pèse 383 Mo et prend 36 s ; le même dump sans le zonage pèse 9,6 Ko
# et prend 0,2 s. Sur 14 jours de rétention, c'est 5,4 Go de sauvegardes contre
# 140 Ko — sur un VPS partagé avec d'autres projets.
#
# ⚠️ Conséquence sur la restauration : elle se fait en DEUX temps. Ce dump ne
# remet pas le zonage. Voir docs/exploitation.md.
set -eu

# Surchargeables pour pouvoir RÉPÉTER la sauvegarde ailleurs qu'en production —
# une procédure de restauration qu'on n'a jamais jouée n'est pas une procédure
# (SPEC §15). Les valeurs par défaut sont celles du VPS.
PROJECT_DIR=${PROJECT_DIR:-/var/www/simulateur}
BACKUP_DIR=${BACKUP_DIR:-/var/backups/simulateur}
RETENTION_DAYS=${RETENTION_DAYS:-14}
COMPOSE="docker compose -f compose.prod.yaml"

# -Fc : format personnalisé. Restauration sélective possible (une seule table),
# et pg_restore parallélisable — sur 120 000 polygones, la différence compte.
# -Fc : format personnalisé (restauration sélective, pg_restore parallélisable).
# -T  : le zonage est exclu — il se recharge depuis la source.
DUMP_OPTS="-Fc -T rga_zone*"
STAMP=$(date +%F-%H%M%S)
OUT="$BACKUP_DIR/simulateur-$STAMP.dump"

mkdir -p "$BACKUP_DIR"
cd "$PROJECT_DIR"

# pg_dump via le conteneur database ; `exec` ne recrée rien, donc pas besoin
# des secrets d'interpolation (cf. compose.prod.yaml).
if $COMPOSE exec -T database pg_dump $DUMP_OPTS -U "${POSTGRES_USER:-app}" "${POSTGRES_DB:-zonage}" > "$OUT"; then
    echo "$(date -Is) OK  $OUT ($(du -h "$OUT" | cut -f1))"
else
    echo "$(date -Is) ECHEC de la sauvegarde" >&2
    rm -f "$OUT"
    exit 1
fi

# Rotation.
find "$BACKUP_DIR" -name 'simulateur-*.dump' -mtime +"$RETENTION_DAYS" -delete

# Restauration (procédure jouée, cf. docs/exploitation.md) :
#   docker compose -f compose.prod.yaml exec -T database \
#       pg_restore -U app -d zonage --clean --if-exists --no-owner \
#     < /var/backups/simulateur/simulateur-<horodatage>.dump
#
#   PAS de -j : « parallel restore from standard input is not supported ».
#   Sans importance ici, le dump pèse quelques kilo-octets.
#   Puis RECHARGER LE ZONAGE, que ce dump ne contient pas (cf. en-tête).
