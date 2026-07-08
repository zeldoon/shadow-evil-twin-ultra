# shadow-evil-twin-ultra
Shadow Evil Twin Ultra - RPi5 Kali Linux Evil Twin + Captive Portal Suite

## CYD Standalone (ESP32-3248S035R) — NEW

Full standalone evil twin that runs directly on the Cheap Yellow Display board. No Pi required for basic operation.

### Quick Start for CYD
1. Open `scripts/esp32/firmware.ino` (or generate with `./scripts/esp32/generate.sh --standalone-cyd`)
2. Install libs: TFT_eSPI (configure for your CYD), XPT2046_Touchscreen, painlessMesh, ESPAsyncWebServer + AsyncTCP
3. Flash to the ESP32-3248S035R board.
4. It boots as its own rogue AP + DNS captive portal + serves the victim frontend.
5. Use touch screen for live controls (cycle SSID, mesh commands, clear stats).
6. Harvests appear on Serial and TFT dashboard.

Run with generator for quick export of standalone-cyd.ino

This is the version ready to burn and test standalone.

---

(Full original README content preserved in local copies)