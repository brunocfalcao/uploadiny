#!/usr/bin/env bash
set -euo pipefail

PHP_INI=/etc/php/8.5/fpm/php.ini
NGINX_CONF=/etc/nginx/sites-available/uploadiny.com
LIMIT=100M
MAX_FILE_UPLOADS=100

echo "==> Patching PHP-FPM ($PHP_INI)"
sudo sed -i "s/^upload_max_filesize = .*/upload_max_filesize = ${LIMIT}/" "$PHP_INI"
sudo sed -i "s/^post_max_size = .*/post_max_size = ${LIMIT}/" "$PHP_INI"
sudo sed -i "s/^max_file_uploads = .*/max_file_uploads = ${MAX_FILE_UPLOADS}/" "$PHP_INI"

echo "==> Patching nginx ($NGINX_CONF)"
sudo sed -i "s/client_max_body_size [0-9]\+M;/client_max_body_size ${LIMIT};/" "$NGINX_CONF"

echo "==> Validating nginx"
sudo nginx -t

echo "==> Reloading nginx + php-fpm"
sudo systemctl reload nginx
sudo systemctl reload php8.5-fpm

echo "==> Verifying"
grep -E "^(upload_max_filesize|post_max_size|max_file_uploads)" "$PHP_INI"
grep "client_max_body_size" "$NGINX_CONF"

echo "Done. Upload limit = ${LIMIT}, max files = ${MAX_FILE_UPLOADS}."
