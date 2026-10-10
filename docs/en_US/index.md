# ESPAC Airton (ESP32) plugin

Controls **Airton** air conditioners fitted with an **ESPAC ESP32** module over
MQTT, locally and without any cloud. Based on the original
[ESPAC](https://github.com/git-greatguy/espac) project by dOHMotik.

1. Flash the module with the `espac-jeedom` firmware and point it to your MQTT
   broker (see the firmware README).
2. In the plugin configuration, set the broker (default `127.0.0.1:1883`) and the
   topic prefix (default `espac`), then start the daemon.
3. Each module publishing on `espac/<dev>/status` or `espac/<dev>/info` is
   created automatically with all its commands.

Actions publish on `espac/<dev>/set/<key>` with the exact values of the MQTT
contract. The full contract (in French) is in
[../fr_FR/contrat-mqtt.md](../fr_FR/contrat-mqtt.md); the complete documentation
is in [../fr_FR/index.md](../fr_FR/index.md).
