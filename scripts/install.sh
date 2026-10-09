#!/usr/bin/env bash
# Installs the example server from this checkout. Safe to run again.
# Usage (inside DwemerDistro WSL): sudo bash scripts/install.sh
# - Creates config/config.php with a random token only if it is missing, and sets the config
#   folder, file and lock to WEB_USER and the checkout owner's group (content is kept).
# - Creates the configured database only if it is missing.
# - Applies any new migrations. Never deletes data and changes no Apache settings.
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG="$APP_DIR/config/config.php"
DB_OWNER="${DB_OWNER:-dwemer}"      # existing distro database role
WEB_USER="${WEB_USER:-www-data}"    # Apache user
CONFIG_DIR="$APP_DIR/config"
APP_OWNER="$(stat -c %U "$APP_DIR")"
cd /

if [ "$(id -u)" -ne 0 ]; then
    echo "Run with sudo: sudo bash scripts/install.sh" >&2
    exit 1
fi

# Validate accounts and paths before changing anything.
if ! id -u "$WEB_USER" >/dev/null 2>&1 || ! id -u "$APP_OWNER" >/dev/null 2>&1; then
    echo "ERROR: user $WEB_USER or $APP_OWNER does not exist." >&2
    exit 1
fi
APP_GROUP="$(id -gn "$APP_OWNER")"   # checkout owner's primary group
if ! getent group "$APP_GROUP" >/dev/null; then
    echo "ERROR: group $APP_GROUP does not exist." >&2
    exit 1
fi
for path in "$CONFIG_DIR" "$CONFIG" "$CONFIG.lock"; do
    if [ -L "$path" ]; then
        echo "ERROR: $path is a symbolic link. Refusing to change it." >&2
        exit 1
    fi
done
if [ ! -d "$CONFIG_DIR" ] || { [ -e "$CONFIG" ] && [ ! -f "$CONFIG" ]; } \
    || { [ -e "$CONFIG.lock" ] && [ ! -f "$CONFIG.lock" ]; }; then
    echo "ERROR: $CONFIG_DIR, $CONFIG or $CONFIG.lock is not the expected folder or regular file." >&2
    exit 1
fi

# 1. Private config. The web server owns the file so the dashboard can replace it atomically;
# the checkout owner reads and writes it through its primary group. New files in the setgid
# folder inherit that group. Only the folder, config and lock are changed, never their content.
chown "$WEB_USER:$APP_GROUP" "$CONFIG_DIR"
chmod 2770 "$CONFIG_DIR"
if [ -f "$CONFIG" ]; then
    echo "Keeping existing config: $CONFIG"
else
    token="$(php -r 'echo bin2hex(random_bytes(24));')"
    (umask 077 && sed "s/CHANGE_ME_TOKEN/$token/" "$APP_DIR/config/config.example.php" > "$CONFIG")
    echo "Created $CONFIG with a new token."
fi
chown "$WEB_USER:$APP_GROUP" "$CONFIG"
chmod 660 "$CONFIG"
if [ -f "$CONFIG.lock" ]; then
    # Repaired in place; the lock is never replaced while a save might hold it.
    chown "$WEB_USER:$APP_GROUP" "$CONFIG.lock"
    chmod 660 "$CONFIG.lock"
fi

# Verify access before creating the database or applying migrations.
for user in "$APP_OWNER" "$WEB_USER"; do
    if ! sudo -u "$user" test -r "$CONFIG" || ! sudo -u "$user" test -w "$CONFIG"; then
        echo "ERROR: $user cannot read and write $CONFIG. Check directory access and config ownership/mode." >&2
        exit 1
    fi
    sudo -u "$user" php -l "$CONFIG"
    sudo -u "$user" php -r 'require $argv[1]; load_config();' "$APP_DIR/lib/app.php"
done

# Validate the configured name before any database side effect.
DB_NAME="$(sudo -u "$APP_OWNER" php "$APP_DIR/scripts/database-name.php")"

# 2. Database, owned by the existing role. No new roles or global grants.
DB_EXISTS="$(sudo -u postgres psql -XAt -v ON_ERROR_STOP=1 -v db_name="$DB_NAME" <<'SQL'
SELECT 1 FROM pg_database WHERE datname = :'db_name';
SQL
)"
if [ "$DB_EXISTS" = 1 ]; then
    echo "Database $DB_NAME already exists."
else
    sudo -u postgres createdb --owner="$DB_OWNER" --encoding=UTF8 --template=template0 "$DB_NAME"
    echo "Created database $DB_NAME."
fi

# 3. Migrations, run as the checkout owner with the same config Apache uses.
sudo -u "$APP_OWNER" php "$APP_DIR/scripts/migrate.php"

echo "Done. Check: curl http://127.0.0.1:8081/$(basename "$APP_DIR")/health.php"
