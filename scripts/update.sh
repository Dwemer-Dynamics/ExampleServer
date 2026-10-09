#!/usr/bin/env bash
# Updates this git checkout: refuse local edits, back up, fast-forward, migrate.
# Usage: sudo bash scripts/update.sh
# config/config.php is untracked, so git never touches it; an update that would start
# tracking it, or any other file you have locally but git does not track, is refused.
# Exit codes: 0 updated, 1 refused or failed before anything changed, 3 code updated but
# migrations failed (see the printed backup and recovery steps).
set -euo pipefail

# Everything runs inside main() so bash has read the whole script before git changes it.
main() {
    local app_dir app_owner upstream incoming path backup_line backup_dir status
    app_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
    app_owner="$(stat -c %U "$app_dir")"
    cd /
    as_owner() { sudo -u "$app_owner" "$@"; }

    if [ "$(id -u)" -ne 0 ]; then
        echo "Run with sudo: sudo bash scripts/update.sh" >&2
        exit 1
    fi
    if [ -n "$(as_owner git -C "$app_dir" status --porcelain --untracked-files=no)" ]; then
        echo "Refusing to update: tracked files in $app_dir have local changes." >&2
        echo "Keep your changes in your own fork, and settings in config/config.php." >&2
        exit 1
    fi
    if ! upstream="$(as_owner git -C "$app_dir" rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null)"; then
        echo "Refusing to update: this branch has no upstream to update from." >&2
        exit 1
    fi
    as_owner git -C "$app_dir" fetch --quiet
    if ! as_owner git -C "$app_dir" merge-base --is-ancestor HEAD "$upstream"; then
        echo "Refusing to update: $upstream is not a fast-forward of this checkout. Merge by hand." >&2
        exit 1
    fi
    # Files the update would add or change that exist here untracked or ignored (such as
    # config/config.php) would be overwritten without asking. Never let that happen.
    incoming="$(as_owner git -C "$app_dir" diff --name-only --no-renames --diff-filter=AMT HEAD "$upstream")"
    while IFS= read -r path; do
        [ -n "$path" ] || continue
        if { [ -e "$app_dir/$path" ] || [ -L "$app_dir/$path" ] ; } \
            && ! as_owner git -C "$app_dir" ls-files --error-unmatch -- "$path" >/dev/null 2>&1; then
            echo "Refusing to update: $upstream would track $path, which exists here as a private or untracked file." >&2
            echo "Move it aside by hand only if you are sure, or ask the upstream to stop tracking it." >&2
            exit 1
        fi
    done <<< "$incoming"
    if [ "$(as_owner git -C "$app_dir" rev-parse HEAD)" = "$(as_owner git -C "$app_dir" rev-parse "$upstream")" ]; then
        echo "Already up to date; checking migrations."
    fi

    # Back up first; stop if the backup fails.
    backup_line="$(bash "$app_dir/scripts/backup.sh" | tail -n 1)"
    backup_dir="${backup_line#Backup written to }"
    if [ "$backup_dir" = "$backup_line" ] || [ ! -d "$backup_dir" ]; then
        echo "Backup did not finish; nothing was updated." >&2
        exit 1
    fi
    echo "Backup written to $backup_dir"

    as_owner git -C "$app_dir" merge --ff-only --quiet "$upstream"
    status=0
    as_owner php "$app_dir/scripts/migrate.php" || status=$?
    if [ "$status" -ne 0 ]; then
        echo "Code updated to $(as_owner git -C "$app_dir" rev-parse --short HEAD), but migrations failed (migrate.php exit $status)." >&2
        echo "The failed migration was rolled back; earlier ones stay applied. Nothing was restored automatically." >&2
        echo "Backup from before the update: $backup_dir" >&2
        echo "Fix the cause and run: sudo -u $app_owner php $app_dir/scripts/migrate.php" >&2
        echo "Or restore the backup by hand as SETUP.md section 6 describes." >&2
        exit 3
    fi
    echo "Update finished."
}

main "$@"
