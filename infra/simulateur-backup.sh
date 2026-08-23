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
# Ce qu'on sauvegarde vraiment, ce sont `partner`, `simulation` et `lead` :
# aucune source externe ne permettrait de les reconstituer. Les tables
# `rga_zone*`, elles, se rechargent depuis le shapefile (SPEC §4) — le dump
# n'est qu'un confort, et c'est ce qui explique sa taille.
set -eu

PROJECT_DIR=/var/www/simulateur
BACKUP_DIR=/var/backups/simulateur
RETENTION_DAYS=14
COMPOSE="docker compose -f compose.prod.yaml"
STAMP=$(date +%F-%H%M%S)
OUT="$BACKUP_DIR/simulateur-$STAMP.sql.gz"

mkdir -p "$BACKUP_DIR"
cd "$PROJECT_DIR"

# pg_dump via le conteneur database ; `exec` ne recrée rien, donc pas besoin
# des secrets d'interpolation (cf. compose.prod.yaml).
if $COMPOSE exec -T database pg_dump -U "${POSTGRES_USER:-app}" "${POSTGRES_DB:-zonage}" | gzip > "$OUT"; then
    echo "$(date -Is) OK  $OUT ($(du -h "$OUT" | cut -f1))"
else
    echo "$(date -Is) ECHEC de la sauvegarde" >&2
    rm -f "$OUT"
    exit 1
fi

# Rotation.
find "$BACKUP_DIR" -name 'simulateur-*.sql.gz' -mtime +"$RETENTION_DAYS" -delete

# Restauration (à TESTER avant la mise en production — SPEC §15) :
#   gunzip -c /var/backups/simulateur/simulateur-<horodatage>.sql.gz \
#     | docker compose -f compose.prod.yaml exec -T database \
#         psql -U app -d zonage
