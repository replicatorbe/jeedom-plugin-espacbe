# Changelog

## 0.4.2

- The endpoint called by the daemon now only accepts the plugin's API key:
  a Jeedom user's personal key used to be accepted as well.
- info.json now reports the right version (it was stuck at 0.1.0).

## 0.4.1

- License changed from AGPL v3 to **GPL-3.0-only**, the license chosen by the
  author of [ESPAC](https://github.com/git-greatguy/espac). NOTICE file added.
- Log: state topics outside the contract (firmware diagnostics such as `rx_bytes`) are now
  ignored silently (debug only) instead of raising a warning every 10 s.

## 0.1.0

- First release: bundled MQTT daemon, automatic discovery of ESPAC modules, all
  commands of the MQTT contract v1, device page showing the module information.
