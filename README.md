# Plugin Jeedom — ESPAC Airton (ESP32)

Pilote les **climatiseurs Airton** équipés d'un module **ESP32 ESPAC** par MQTT,
en local et sans cloud.

- **Découverte automatique** : chaque module qui s'annonce sur le broker devient
  un équipement, avec toutes ses commandes.
- **Commandes** : marche/arrêt, mode, consigne 16–31 °C, ventilation, volets
  vertical et horizontal, affichage, bip, nettoyage, température de la pièce,
  lien avec la clim, RSSI, uptime.
- **Démon PHP** avec client MQTT embarqué : aucune dépendance à installer.
- **Contrat MQTT** partagé avec le firmware : [docs/fr_FR/contrat-mqtt.md](docs/fr_FR/contrat-mqtt.md).

Documentation : [docs/fr_FR/index.md](docs/fr_FR/index.md).

Basé sur le projet original [ESPAC](https://github.com/git-greatguy/espac) de
dOHMotik. Ce plugin n'est pas affilié à Airton.

## Développement

```bash
php tests/run.php                                # contrôles hors ligne
php tests/simulate_espac.php espac-test          # simule un module sur le broker local
php tests/simulate_espac.php espac-test --clear  # efface ses messages retenus
```
