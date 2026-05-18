#!/bin/bash
# ─── Party Fotos – Einmal-Setup auf dem Raspberry Pi ─────────────────────────
# Ausführen mit: bash setup-pi.sh
set -e

APP_DIR="/var/www/party"
REPO_URL="https://github.com/holgergaw/party-fotos.git"

echo "=== 1. PHP-Erweiterungen installieren ==="
sudo apt-get update -q
sudo apt-get install -y php8.2-gd php8.2-zip git

echo "=== 2. Repo klonen ==="
sudo mkdir -p "$APP_DIR"
sudo git clone "$REPO_URL" "$APP_DIR"

echo "=== 3. Verzeichnisse anlegen ==="
sudo mkdir -p "$APP_DIR/uploads"
sudo mkdir -p "$APP_DIR/data"

echo "=== 4. Basis-Konfiguration anlegen ==="
# Passwort-Hash für 'Holger123' generieren
HASH=$(php -r "echo password_hash('Holger123', PASSWORD_BCRYPT);")
sudo tee "$APP_DIR/data/config.json" > /dev/null <<EOF
{
  "title": "Silberhochzeit",
  "tagline": "Teile deine schönsten Aufnahmen",
  "watermark_text": "Silberhochzeit",
  "pw_hash": "$HASH"
}
EOF
sudo tee "$APP_DIR/data/metadata.json" > /dev/null <<EOF
{}
EOF

echo "=== 5. Berechtigungen setzen ==="
sudo chown -R www-data:www-data "$APP_DIR"
sudo chmod -R 755 "$APP_DIR"
sudo chmod -R 775 "$APP_DIR/uploads" "$APP_DIR/data"

echo "=== 6. nginx konfigurieren ==="
sudo tee /etc/nginx/sites-available/party > /dev/null <<'NGINX'
server {
    listen 80 default_server;
    listen [::]:80 default_server;

    root /var/www/party;
    index index.html;

    server_name _;

    # Uploads direkt ausliefern (kein PHP)
    location /uploads/ {
        expires 1h;
        add_header Cache-Control "public";
    }

    # data/ komplett sperren
    location ^~ /data/ {
        deny all;
        return 403;
    }

    # PHP-Dateien
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Alles andere: statische Dateien
    location / {
        try_files $uri $uri/ =404;
    }
}
NGINX

sudo ln -sf /etc/nginx/sites-available/party /etc/nginx/sites-enabled/party
sudo rm -f /etc/nginx/sites-enabled/default

echo "=== 7. nginx + PHP-FPM neu starten ==="
sudo nginx -t && sudo systemctl reload nginx
sudo systemctl enable php8.2-fpm
sudo systemctl restart php8.2-fpm

echo ""
echo "✅ Setup fertig! App erreichbar unter: http://$(hostname -I | awk '{print $1}')"
echo "   Admin: http://$(hostname -I | awk '{print $1}')/admin.html"
