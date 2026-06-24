#!/bin/bash
# ─── HTTPS-Setup für Party Fotos (selbstsigniertes Zertifikat) ──────────────
# Einmalig auf dem Pi ausführen: bash https-setup.sh
#
# Warum: Die Web-Share-API (iOS: natives "Bild sichern"-Menü beim Download)
# funktioniert nur über HTTPS. Da die App rein lokal ohne echte Domain läuft,
# wird ein selbstsigniertes Zertifikat verwendet — Geräte zeigen beim ersten
# Besuch eine Sicherheitswarnung, die einmalig bestätigt werden muss.
#
# Port 80 (HTTP) bleibt parallel voll funktionsfähig als Fallback.
set -e

PI_IP="192.168.2.2"
NGINX_CONF="/etc/nginx/sites-available/party"
SSL_DIR="/etc/nginx/ssl"

echo "=== 1. Zertifikat-Verzeichnis anlegen ==="
sudo mkdir -p "$SSL_DIR"

echo "=== 2. Selbstsigniertes Zertifikat erzeugen (gültig 825 Tage, SAN = ${PI_IP}) ==="
sudo openssl req -x509 -nodes -days 825 -newkey rsa:2048 \
  -keyout "$SSL_DIR/party-selfsigned.key" \
  -out "$SSL_DIR/party-selfsigned.crt" \
  -subj "/CN=${PI_IP}" \
  -addext "subjectAltName=IP:${PI_IP}"

sudo chmod 600 "$SSL_DIR/party-selfsigned.key"
sudo chmod 644 "$SSL_DIR/party-selfsigned.crt"

echo "=== 3. nginx: Port 443 (SSL) zum bestehenden Server-Block hinzufügen ==="
if ! grep -q "listen 443 ssl" "$NGINX_CONF"; then
    sudo sed -i '/listen \[::\]:80 default_server;/a\
    listen 443 ssl;\
    listen [::]:443 ssl;\
    ssl_certificate '"$SSL_DIR"'/party-selfsigned.crt;\
    ssl_certificate_key '"$SSL_DIR"'/party-selfsigned.key;' "$NGINX_CONF"
fi

echo "=== 4. Captive-Portal-Probes auf HTTPS umstellen ==="
# Gäste landen nach dem WLAN-Connect direkt auf der HTTPS-Version (Web-Share-API verfügbar).
# Port 80 bleibt für alles andere unverändert als Fallback erreichbar.
sudo sed -i "s#return 302 http://${PI_IP}/;#return 302 https://${PI_IP}/;#g" "$NGINX_CONF"

echo "=== 5. nginx testen + neu laden ==="
sudo nginx -t && sudo systemctl reload nginx

echo ""
echo "✅ HTTPS-Setup fertig."
echo "   App erreichbar unter: https://${PI_IP} (mit Zertifikatswarnung) und http://${PI_IP} (Fallback ohne Warnung, aber ohne Web-Share-API)"
