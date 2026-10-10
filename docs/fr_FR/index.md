# Plugin ESPAC Airton (ESP32)

Pilote les climatiseurs **Airton** équipés d'un module **ESP32 ESPAC** par MQTT,
en local et sans cloud.

Le module ESP32 remplace le module wifi Airton (ACW02) et se branche sur le port
série de l'unité intérieure. Il ne fait aucune régulation : c'est une passerelle
qui reflète l'état réel de la clim et exécute les commandes. Toute la logique
(scénarios, thermostat…) reste dans Jeedom.

Basé sur le projet original [ESPAC](https://github.com/git-greatguy/espac) de
dOHMotik (édition Home Assistant), porté vers Jeedom par le firmware
`espac-jeedom`.

## Prérequis

- Jeedom 4.4 ou plus récent ;
- un broker MQTT joignable par Jeedom et par les modules : en général Mosquitto
  installé sur la box Jeedom (`127.0.0.1:1883`) ;
- un module ESP32 flashé avec le firmware `espac-jeedom`.

Le plugin n'installe aucune dépendance : le client MQTT (php-mqtt/client) est
embarqué.

## Câblage (résumé)

Connecteur JST 4 broches (pas 2,54 mm) du port wifi de l'unité intérieure,
UART 9600 8N1 :

| ESP32 (DevKit 30 broches) | Signal | Fil côté clim |
|---|---|---|
| `VIN` (5 V) | alimentation fournie par la clim | rouge |
| `GND` | masse | violet |
| `GPIO16` | TX de l'ESP vers la clim | bleu |
| `GPIO17` | RX de l'ESP depuis la clim | vert |

L'ESP32 ne tolère pas le 5 V sur ses entrées : mesurez le fil vert au repos et,
s'il est à 5 V, intercalez un pont diviseur (1 kΩ / 2 kΩ). **Coupez le
disjoncteur avant d'ouvrir la clim (230 V).**

Le détail (variantes ESP32, pont diviseur, LED) est dans le `firmware/README.md`
du dépôt du firmware `espac-jeedom`.

## Flash du module

Le firmware est un projet ESPHome. Sa configuration (wifi, broker, nom du
module), sa compilation et son flash sont décrits dans le `firmware/README.md`
du dépôt `espac-jeedom`. Points à retenir pour Jeedom :

- `device_name` : nom du module **et** de ses topics (`espac/<device_name>/…`),
  en `[a-z0-9-]`, un par clim (`espac-salon`, `espac-chambre`…). C'est
  l'identifiant de l'équipement dans Jeedom ;
- `mqtt_broker` : l'adresse de votre broker (celle de la box Jeedom).

## Configuration du plugin

Plugins → Gestion des plugins → ESPAC Airton (ESP32) :

| Réglage | Défaut | Rôle |
|---|---|---|
| Adresse du broker | `127.0.0.1` | broker MQTT |
| Port | `1883` | |
| Utilisateur / mot de passe | vides | si le broker exige une authentification (mot de passe chiffré en base) |
| Préfixe des topics | `espac` | doit correspondre au firmware |
| Créer automatiquement les équipements | oui | un équipement par module découvert |
| Port local du démon | `55140` | 127.0.0.1 seulement, ordres Jeedom → démon |

Le niveau de journalisation se règle dans la section « Logs » de la page du
plugin. Deux journaux : `espacbe` (plugin) et `espacbed` (démon).

Démarrez ensuite le démon (gestion automatique conseillée).

## Découverte et équipements

Le démon s'abonne à `<préfixe>/+/status`, `<préfixe>/+/info` et
`<préfixe>/+/state/#`. Dès qu'un module publie son statut ou ses informations,
l'équipement est créé (identifiant logique = `<dev>`), avec toutes ses
commandes, puis rempli avec les états retenus sur le broker.

La page de l'équipement affiche l'état du module (en ligne / hors ligne), son
nom ESPHome, son adresse IP, son adresse MAC, la version du firmware, les topics
et la date du dernier message reçu. Le bouton « Resynchroniser » demande au
module de republier tous ses états.

Un ajout manuel reste possible : créez l'équipement et saisissez
l'« Identifiant du module » (le `device_name` du firmware).

## Commandes

Infos (identifiant logique = clé du contrat) :

| Nom | Clé | Type | Visible |
|---|---|---|---|
| Etat connexion | `status` | binaire (1 = online) | oui |
| Lien clim | `unit_link` | binaire | oui |
| Marche | `power` | binaire | non |
| Mode | `mode` | texte (`auto`, `cool`, `dry`, `fan_only`, `heat`) | non |
| Consigne | `target_temperature` | numérique °C, 16–31 | non |
| Ventilation | `fan` | texte | non |
| Volet vertical | `swing_v` | texte | non |
| Volet horizontal | `swing_h` | binaire | non |
| Affichage | `display` | binaire | non |
| Bip coupé | `mute` | binaire (1 = bip coupé) | non |
| Nettoyage | `clean` | binaire | non |
| Température pièce | `room_temperature` | numérique °C, historisée | oui |
| RSSI | `rssi` | numérique dBm | non |
| Uptime | `uptime` | numérique s | non |
| Dernière trame | `last_frame` | texte (hex, diagnostic) | non |

Les infos masquées sont représentées sur la tuile par l'action qui leur est
liée (interrupteur, liste, consigne).

Actions (publiées sur `<préfixe>/<dev>/set/<clé>`) :

| Nom | Identifiant logique | Publie |
|---|---|---|
| Allumer / Eteindre | `set_power_on` / `set_power_off` | `power` = `ON` / `OFF` |
| Choisir le mode | `set_mode` | `mode` : Auto, Froid, Déshumidification, Ventilation, Chaud |
| Régler la consigne | `set_target_temperature` | `target_temperature`, 16 à 31, pas de 1 |
| Choisir la ventilation | `set_fan` | `fan` : Auto, Très lente, Lente, Moyenne, Rapide, Très rapide, Humidité, Turbo |
| Choisir le volet vertical | `set_swing_v` | `swing_v` : Fixe, Balayage, Très haut, Haut, Milieu, Bas, Très bas |
| Volet horizontal On / Off | `set_swing_h_on` / `_off` | `swing_h` |
| Affichage On / Off | `set_display_on` / `_off` | `display` |
| Couper le bip / Activer le bip | `set_mute_on` / `_off` | `mute` = `ON` / `OFF` |
| Nettoyage On / Off | `set_clean_on` / `_off` | `clean` |
| Resynchroniser | `set_resync` | `resync` |

Les valeurs publiées sont exactement celles du contrat ; les libellés français
ne servent qu'à l'affichage. Une valeur hors contrat est refusée par le plugin
avant publication. Après une commande, le module republie l'état
correspondant : c'est ce retour qui met l'info à jour dans Jeedom.

À savoir (firmware) : le bip n'est pas relu sur la clim, son état est la
dernière commande ; les commandes reçues avant la première trame de la clim
sont ignorées par le module (« Lien clim » à 0).

## Contrat MQTT

Le contrat complet (topics, clés, valeurs, protocole série) est dans
[contrat-mqtt.md](contrat-mqtt.md). Il est la source de vérité partagée entre
le firmware et ce plugin.

## Dépannage

- **Aucun équipement n'apparaît** : vérifiez que le démon est démarré et
  connecté (page Santé), puis écoutez le broker :
  `mosquitto_sub -h 127.0.0.1 -t 'espac/#' -v`.
- **Une commande ne fait rien** : journal `espacbe` en debug (la publication y
  est tracée), puis journal du module. « Lien clim » à 0 signifie que le module
  ne reçoit pas de trame de la clim (câblage).
- **Enregistrement qui ne fait rien** : consultez `/var/www/html/log/http.error`.
