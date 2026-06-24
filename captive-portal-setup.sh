#!/bin/bash
# ─── Captive-Portal-Setup für Party Fotos ────────────────────────────────────
# Einmalig auf dem Pi ausführen: bash captive-portal-setup.sh
#
# Voraussetzung: Der Router (z.B. TP-Link TL-WR841N) auf 192.168.2.1 muss in
# den DHCP-Einstellungen den "Primären DNS-Server" auf 192.168.2.2 (diesen Pi)
# umstellen. Das macht alle WLAN-Gäste automatisch zu DNS-Sinkhole-Clients.
set -e

PI_IP="192.168.2.2"

echo "=== 1. dnsmasq installieren ==="
sudo apt-get update -q
sudo apt-get install -y dnsmasq

echo "=== 2. dnsmasq als DNS-Sinkhole konfigurieren ==="
sudo tee /etc/dnsmasq.d/captive-portal.conf > /dev/null <<EOF
# Beantwortet JEDE Domain-Anfrage mit der Pi-IP — DNS-Sinkhole für Captive Portal
no-resolv
no-poll
address=/#/${PI_IP}
# Kein DHCP durch dnsmasq, das macht weiterhin der Router
EOF

sudo systemctl enable dnsmasq
sudo systemctl restart dnsmasq

echo "=== 3. nginx: Captive-Portal-Probe-URLs umleiten ==="
NGINX_CONF="/etc/nginx/sites-available/party"

if ! grep -q "hotspot-detect.html" "$NGINX_CONF"; then
    sudo sed -i "/location \/uploads\/ {/i\\
    # Captive-Portal-Erkennung: Apple/Android/Windows-Probes auf Startseite umleiten\\
    location = /hotspot-detect.html { return 302 http://${PI_IP}/; }\\
    location = /library/test/success.html { return 302 http://${PI_IP}/; }\\
    location = /generate_204 { return 302 http://${PI_IP}/; }\\
    location = /gen_204 { return 302 http://${PI_IP}/; }\\
    location = /connecttest.txt { return 302 http://${PI_IP}/; }\\
    location = /ncsi.txt { return 302 http://${PI_IP}/; }\\
" "$NGINX_CONF"
fi

sudo nginx -t && sudo systemctl reload nginx

echo ""
echo "✅ Captive-Portal-Setup fertig."
echo ""
echo "⚠️  Letzter Schritt (manuell im Router-Webinterface http://192.168.2.1):"
echo "    DHCP-Einstellungen → Primärer DNS-Server → ${PI_IP}"
