#!/usr/bin/env bash
# Builds the two deliverables from the single source in php/:
#   livraison/b2b-wheels-collect-site/   public booking site (garages)
#   livraison/b2b-wheels-collect-admin/  VBM administration panel
# plus a zip of each and the English handover document.
#
# Usage, from the repository root: bash php/packaging/build.sh
# Only files tracked by git are packaged: no config.php, no storage content.

set -euo pipefail
root="$(git rev-parse --show-toplevel)"
out="$root/livraison"
site="$out/b2b-wheels-collect-site"
admin="$out/b2b-wheels-collect-admin"
cd "$root"

rm -rf "$site" "$admin" "$out"/*.zip
mkdir -p "$site/public_html" "$site/app" "$admin/public_html" "$admin/app"

copy() { # copy <source file> <destination file>
  mkdir -p "$(dirname "$2")"
  cp -p "$1" "$2"
}

# --- Site: everything except the administration -------------------------------
git ls-files php/public_html | while read -r f; do
  case "$f" in php/public_html/admin/*) continue ;; esac
  copy "$f" "$site/public_html/${f#php/public_html/}"
done
git ls-files php/app | while read -r f; do
  case "$f" in
    php/app/views/*|php/app/src/Admin/*|php/app/tools/admin.php) continue ;;
  esac
  copy "$f" "$site/app/${f#php/app/}"
done
copy php/dev-router.php "$site/dev-router.php"
copy php/packaging/site-README.md "$site/README.md"

# --- Administration: the admin/ pages at the web root, with their own app/ -----
git ls-files php/public_html/admin | while read -r f; do
  copy "$f" "$admin/public_html/${f#php/public_html/admin/}"
done
copy php/public_html/.htaccess "$admin/public_html/.htaccess"
copy php/public_html/.user.ini "$admin/public_html/.user.ini"
printf 'User-agent: *\nDisallow: /\n' > "$admin/public_html/robots.txt"
cat > "$admin/public_html/_bootstrap.php" <<'PHP'
<?php
/**
 * Emplacement du dossier « app » de l'administration, à garder en dehors de
 * public_html. Par défaut : à côté de public_html. À adapter seulement si
 * app/ est placé ailleurs sur l'hébergement.
 */

require dirname(__DIR__) . '/app/bootstrap.php';
PHP
git ls-files php/app | while read -r f; do
  case "$f" in
    php/app/cron/*|php/app/tools/migrate.php|php/app/tools/seed-demo.php) continue ;;
  esac
  copy "$f" "$admin/app/${f#php/app/}"
done
copy php/dev-router.php "$admin/dev-router.php"
copy php/packaging/admin-README.md "$admin/README.md"

copy php/packaging/HANDOVER.md "$out/HANDOVER.md"

# --- Zips ---------------------------------------------------------------------
(cd "$out" && zip -qr b2b-wheels-collect-site.zip b2b-wheels-collect-site && zip -qr b2b-wheels-collect-admin.zip b2b-wheels-collect-admin)
echo "Built in $out:"
ls -1 "$out"
