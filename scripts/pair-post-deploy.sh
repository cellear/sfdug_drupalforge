#!/bin/sh
#
# Post-deploy fixups for the pair Networks host. Run from the project root
# AFTER any composer install/update:
#
#   sh scripts/pair-post-deploy.sh
#
# Composer's drupal-scaffold rewrites web/.htaccess whenever it runs, which
# drops pair's PHP selector and leaves Apache running Drupal 11 under the
# default PHP 8.2. Drupal 11 needs 8.3+, so every page 500s.
#
# These directives are pair-specific: /fcgi-bin/php83_wrapper.sh does not
# exist in DDEV, and adding them locally 500s the local site. That is why they
# live here rather than in composer.json's scaffold file-mapping.
#
# Better, if pair's control panel allows it: set the domain's PHP version
# there instead. Then nothing in the docroot needs patching and this script
# becomes unnecessary.

set -e

HTACCESS="web/.htaccess"
MARKER="x-httpd-php83"

if [ ! -f "$HTACCESS" ]; then
  echo "error: $HTACCESS not found — run this from the project root." >&2
  exit 1
fi

if grep -q "$MARKER" "$HTACCESS"; then
  echo "PHP 8.3 handler already present in $HTACCESS — nothing to do."
  exit 0
fi

cat >> "$HTACCESS" <<'EOF'

# pair Networks: serve this site with PHP 8.3. Drupal 11 requires >= 8.3 and
# pair's default CLI/web PHP is 8.2. Re-applied by scripts/pair-post-deploy.sh
# because composer's scaffolding rewrites this file.
AddType application/x-httpd-php83 .php
Action application/x-httpd-php83 /fcgi-bin/php83_wrapper.sh
EOF

echo "Added the PHP 8.3 handler to $HTACCESS."
echo "Verify with: curl -sI https://sfdug.org/ | head -1"
