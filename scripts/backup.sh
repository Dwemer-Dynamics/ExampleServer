#!/usr/bin/env bash
# Backs up the example database and config to a private folder outside the web root.
# Usage: sudo bash scripts/backup.sh
# Restore: sudo -u postgres pg_restore --clean --if-exists -d YOUR_DATABASE < /path/to/backup/YOUR_DATABASE.dump
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_OWNER="$(stat -c %U "$APP_DIR")"
OWNER_HOME="$(getent passwd "$APP_OWNER" | cut -d: -f6)"
BACKUP_ROOT="${BACKUP_ROOT:-$OWNER_HOME/example-ai-server-backups}"
DEST="$BACKUP_ROOT/$(date +%Y%m%d-%H%M%S)"
cd /
umask 077

if [ "$(id -u)" -ne 0 ]; then
    echo "Run with sudo: sudo bash scripts/backup.sh" >&2
    exit 1
fi

DB_NAME="$(sudo -u "$APP_OWNER" php "$APP_DIR/scripts/database-name.php")"

install -d -m 700 -o "$APP_OWNER" "$BACKUP_ROOT" "$DEST"
sudo -u postgres pg_dump --format=custom "$DB_NAME" > "$DEST/$DB_NAME.dump"
if [ -f "$APP_DIR/config/config.php" ]; then
    cp "$APP_DIR/config/config.php" "$DEST/config.php"
fi
chown -R "$APP_OWNER" "$DEST"
echo "Backup written to $DEST"
