#!/bin/bash
# ─── Party Fotos – Update auf dem Pi ─────────────────────────────────────────
# Ausführen mit: bash update-pi.sh
# Oder per SSH: ssh pi@192.168.1.210 "cd /var/www/party && sudo git pull origin main"

APP_DIR="/var/www/party"

echo "=== Code aktualisieren ==="
cd "$APP_DIR"
sudo git pull origin main

echo "=== Berechtigungen sicherstellen ==="
sudo chown -R www-data:www-data "$APP_DIR"
sudo chmod -R 775 "$APP_DIR/uploads" "$APP_DIR/data"

echo "✅ Update fertig!"
