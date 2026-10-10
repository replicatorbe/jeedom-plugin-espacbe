<?php
/* This file is part of the espacbe plugin for Jeedom.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/*
 * Le contrat MQTT ESPAC v1, traduit en PHP.
 *
 * Source de vérité : docs/fr_FR/contrat-mqtt.md. Tout ce qui touche aux topics,
 * aux clés et à leurs valeurs passe par ici, et nulle part ailleurs : le jour
 * où le firmware évolue, c'est le seul fichier à reprendre.
 *
 * Aucune dépendance au cœur de Jeedom : la classe est chargée telle quelle par
 * le jeu d'essai hors ligne (tests/run.php).
 */
class espacbeContract {

    const TARGET_MIN = 16;
    const TARGET_MAX = 31;

    /* Valeurs des clés énumérées, dans l'ordre du contrat, avec leur libellé. */
    const MODES = array(
        'auto'     => 'Auto',
        'cool'     => 'Froid',
        'dry'      => 'Déshumidification',
        'fan_only' => 'Ventilation',
        'heat'     => 'Chaud',
    );

    // Ordre du cycle de la touche ventilation de la télécommande Airton.
    const FANS = array(
        'auto'      => 'Auto',
        'quiet'     => 'Silence',
        'very_low'  => 'Vitesse 1',
        'low'       => 'Vitesse 2',
        'medium'    => 'Vitesse 3',
        'high'      => 'Vitesse 4',
        'very_high' => 'Ventilation max',
        'turbo'     => 'Méga-turbo',
        'humidity'  => 'Humidité',
    );

    const SWINGS_V = array(
        'off'       => 'Fixe',
        'auto'      => 'Balayage',
        'very_high' => 'Très haut',
        'high'      => 'Haut',
        'medium'    => 'Milieu',
        'low'       => 'Bas',
        'very_low'  => 'Très bas',
    );

    /*
     * Les clés du contrat.
     *   kind : onoff (ON/OFF), enum, int, number, string ;
     *   set  : la clé s'écrit par <prefix>/<dev>/set/<clé>.
     */
    const KEYS = array(
        'power'              => array('kind' => 'onoff',  'set' => true),
        'mode'               => array('kind' => 'enum',   'set' => true, 'values' => 'MODES'),
        'target_temperature' => array('kind' => 'int',    'set' => true),
        'fan'                => array('kind' => 'enum',   'set' => true, 'values' => 'FANS'),
        'swing_v'            => array('kind' => 'enum',   'set' => true, 'values' => 'SWINGS_V'),
        'swing_h'            => array('kind' => 'onoff',  'set' => true),
        'display'            => array('kind' => 'onoff',  'set' => true),
        'mute'               => array('kind' => 'onoff',  'set' => true),
        'clean'              => array('kind' => 'onoff',  'set' => true),
        'room_temperature'   => array('kind' => 'number', 'set' => false),
        'unit_link'          => array('kind' => 'onoff',  'set' => false),
        'last_frame'         => array('kind' => 'string', 'set' => false),
        'rssi'               => array('kind' => 'number', 'set' => false),
        'uptime'             => array('kind' => 'number', 'set' => false),
        'cmd_status'         => array('kind' => 'enum',   'set' => false, 'values' => 'CMD_STATUSES'),
        'state_doubt'        => array('kind' => 'onoff',  'set' => false),
        'link_quality'       => array('kind' => 'int',    'set' => false),
    );

    /* Accusé de la dernière commande (firmware ≥ 0.5.0). */
    const CMD_STATUSES = array(
        'pending' => 'En attente',
        'ok'      => 'Confirmée',
        'failed'  => 'Non confirmée',
        'refused' => 'Refusée (état incertain)',
    );

    /*
     * Anciens noms par défaut (versions précédentes) => renommés à l'enregistrement
     * de l'équipement, seulement si l'utilisateur ne les a pas personnalisés.
     * Les paires d'actions doivent finir par on/off ou marche/arrêt pour que le
     * widget core::binarySwitch n'affiche qu'un seul interrupteur.
     */
    const LEGACY_NAMES = array(
        'power'         => array('Marche'),
        'set_power_on'  => array('Allumer'),
        'set_power_off' => array('Eteindre'),
        'set_mute_on'   => array('Couper le bip'),
        'set_mute_off'  => array('Activer le bip'),
    );

    /* Identifiant de module : le nom ESPHome, [a-z0-9-]. */
    public static function isValidDevice($_dev) {
        return is_string($_dev) && preg_match('/^[a-z0-9-]{1,64}$/', $_dev) === 1;
    }

    /* Valeurs admises d'une clé énumérée (tableau valeur => libellé). */
    public static function values($_key) {
        if (!isset(self::KEYS[$_key]['values'])) {
            return array();
        }
        return constant('self::' . self::KEYS[$_key]['values']);
    }

    /* listValue Jeedom d'une clé énumérée : « valeur|Libellé;… ». */
    public static function listValue($_key) {
        $parts = array();
        foreach (self::values($_key) as $value => $label) {
            $parts[] = $value . '|' . $label;
        }
        return implode(';', $parts);
    }

    /*
     * Découpe un topic reçu.
     *
     * Rend array('dev', 'kind' => status|info|state, 'key') ou null si le topic
     * n'appartient pas au contrat sous ce préfixe.
     */
    public static function parseTopic($_prefix, $_topic) {
        $prefix = trim((string) $_prefix, '/');
        $topic = (string) $_topic;
        if ($prefix === '' || strpos($topic, $prefix . '/') !== 0) {
            return null;
        }
        $parts = explode('/', substr($topic, strlen($prefix) + 1));
        if (count($parts) < 2 || !self::isValidDevice($parts[0])) {
            return null;
        }
        if (count($parts) === 2 && ($parts[1] === 'status' || $parts[1] === 'info')) {
            return array('dev' => $parts[0], 'kind' => $parts[1], 'key' => '');
        }
        if (count($parts) === 3 && $parts[1] === 'state' && isset(self::KEYS[$parts[2]])) {
            return array('dev' => $parts[0], 'kind' => 'state', 'key' => $parts[2]);
        }
        return null;
    }

    /*
     * Valeur Jeedom d'un état reçu, ou null si la valeur est hors contrat.
     * Binaire : 1/0 ; énumération : la valeur du contrat telle quelle ;
     * nombres : entier ou flottant ; chaîne : telle quelle.
     */
    public static function stateValue($_key, $_payload) {
        if (!isset(self::KEYS[$_key])) {
            return null;
        }
        $payload = trim((string) $_payload);
        switch (self::KEYS[$_key]['kind']) {
            case 'onoff':
                $upper = strtoupper($payload);
                if ($upper === 'ON') {
                    return 1;
                }
                return $upper === 'OFF' ? 0 : null;
            case 'enum':
                return array_key_exists($payload, self::values($_key)) ? $payload : null;
            case 'int':
                if (!is_numeric($payload)) {
                    return null;
                }
                return (int) round((float) $payload);
            case 'number':
                if (!is_numeric($payload)) {
                    return null;
                }
                $number = (float) $payload;
                return (floor($number) == $number && abs($number) < PHP_INT_MAX) ? (int) $number : $number;
            default:
                return $payload;
        }
    }

    /* Statut de connexion du module (LWT) : 1 en ligne, 0 hors ligne. */
    public static function statusValue($_payload) {
        $payload = strtolower(trim((string) $_payload));
        if ($payload === 'online') {
            return 1;
        }
        return $payload === 'offline' ? 0 : null;
    }

    /*
     * Charge utile à publier sur set/<clé>, validée contre le contrat.
     * Lève une exception sur une valeur que le module ignorerait.
     */
    public static function setPayload($_key, $_value) {
        if ($_key === 'resync') {
            return '1';
        }
        if (!isset(self::KEYS[$_key]) || !self::KEYS[$_key]['set']) {
            throw new InvalidArgumentException('clé non réglable : ' . $_key);
        }
        $value = trim((string) $_value);
        switch (self::KEYS[$_key]['kind']) {
            case 'onoff':
                $upper = strtoupper($value);
                if ($upper === '1' || $upper === 'ON') {
                    return 'ON';
                }
                if ($upper === '0' || $upper === 'OFF') {
                    return 'OFF';
                }
                break;
            case 'enum':
                if (array_key_exists($value, self::values($_key))) {
                    return $value;
                }
                break;
            case 'int':
                if (is_numeric($value)) {
                    $int = (int) round((float) $value);
                    return (string) max(self::TARGET_MIN, min(self::TARGET_MAX, $int));
                }
                break;
        }
        throw new InvalidArgumentException('valeur « ' . $value . ' » hors contrat pour ' . $_key);
    }

    /* Topic de commande d'une clé. */
    public static function setTopic($_prefix, $_dev, $_key) {
        return trim((string) $_prefix, '/') . '/' . $_dev . '/set/' . $_key;
    }

    /* Filtres d'abonnement du démon. */
    public static function subscriptions($_prefix) {
        $prefix = trim((string) $_prefix, '/');
        return array($prefix . '/+/status', $prefix . '/+/info', $prefix . '/+/state/#');
    }

    /*
     * Commandes Jeedom d'un équipement, dans l'ordre d'affichage.
     *
     * Les infos portent la clé du contrat comme identifiant logique (« status »
     * pour le testament MQTT). Les actions portent « set_<clé> », suffixé de
     * _on/_off pour les paires : une info et une action ne peuvent pas partager
     * le même identifiant logique sans que cmd::byEqLogicIdAndLogicalId() ne
     * rende l'une pour l'autre.
     *
     * Les noms évitent l'apostrophe et les caractères que le cœur retire.
     */
    public static function commands() {
        $t = array(self::TARGET_MIN, self::TARGET_MAX);
        return array(
            /* ---------------------------------------------------------- infos */
            array('logicalId' => 'status', 'name' => 'Etat connexion', 'type' => 'info', 'subType' => 'binary',
                  'visible' => 1, 'generic' => 'ONLINE'),
            array('logicalId' => 'unit_link', 'name' => 'Lien clim', 'type' => 'info', 'subType' => 'binary', 'visible' => 1),
            array('logicalId' => 'power', 'name' => 'Etat marche', 'type' => 'info', 'subType' => 'binary',
                  'visible' => 0, 'generic' => 'ENERGY_STATE'),
            array('logicalId' => 'mode', 'name' => 'Mode', 'type' => 'info', 'subType' => 'string',
                  'visible' => 0, 'generic' => 'THERMOSTAT_MODE'),
            array('logicalId' => 'target_temperature', 'name' => 'Consigne', 'type' => 'info', 'subType' => 'numeric',
                  'visible' => 0, 'unit' => '°C', 'min' => $t[0], 'max' => $t[1], 'generic' => 'THERMOSTAT_SETPOINT'),
            array('logicalId' => 'fan', 'name' => 'Ventilation', 'type' => 'info', 'subType' => 'string', 'visible' => 0),
            array('logicalId' => 'swing_v', 'name' => 'Volet vertical', 'type' => 'info', 'subType' => 'string', 'visible' => 0),
            array('logicalId' => 'swing_h', 'name' => 'Volet horizontal', 'type' => 'info', 'subType' => 'binary', 'visible' => 0),
            array('logicalId' => 'display', 'name' => 'Affichage', 'type' => 'info', 'subType' => 'binary', 'visible' => 0),
            array('logicalId' => 'mute', 'name' => 'Bip coupé', 'type' => 'info', 'subType' => 'binary', 'visible' => 0),
            array('logicalId' => 'clean', 'name' => 'Nettoyage', 'type' => 'info', 'subType' => 'binary', 'visible' => 0),
            array('logicalId' => 'room_temperature', 'name' => 'Température pièce', 'type' => 'info', 'subType' => 'numeric',
                  'visible' => 1, 'unit' => '°C', 'historized' => 1, 'generic' => 'THERMOSTAT_TEMPERATURE'),
            array('logicalId' => 'rssi', 'name' => 'RSSI', 'type' => 'info', 'subType' => 'numeric', 'visible' => 0, 'unit' => 'dBm'),
            array('logicalId' => 'uptime', 'name' => 'Uptime', 'type' => 'info', 'subType' => 'numeric', 'visible' => 0, 'unit' => 's'),
            array('logicalId' => 'last_frame', 'name' => 'Dernière trame', 'type' => 'info', 'subType' => 'string', 'visible' => 0),
            array('logicalId' => 'cmd_status', 'name' => 'Statut commande', 'type' => 'info', 'subType' => 'string', 'visible' => 1),
            array('logicalId' => 'state_doubt', 'name' => 'Etat incertain', 'type' => 'info', 'subType' => 'binary', 'visible' => 1),
            array('logicalId' => 'link_quality', 'name' => 'Qualité liaison', 'type' => 'info', 'subType' => 'numeric',
                  'visible' => 1, 'unit' => '%', 'historized' => 1),

            /* -------------------------------------------------------- actions */
            array('logicalId' => 'set_power_on', 'name' => 'Marche', 'type' => 'action', 'subType' => 'other',
                  'key' => 'power', 'payload' => 'ON', 'value' => 'power', 'visible' => 1,
                  'template' => 'core::binarySwitch', 'generic' => 'ENERGY_ON'),
            array('logicalId' => 'set_power_off', 'name' => 'Arrêt', 'type' => 'action', 'subType' => 'other',
                  'key' => 'power', 'payload' => 'OFF', 'value' => 'power', 'visible' => 1,
                  'template' => 'core::binarySwitch', 'generic' => 'ENERGY_OFF'),
            array('logicalId' => 'set_mode', 'name' => 'Choisir le mode', 'type' => 'action', 'subType' => 'select',
                  'key' => 'mode', 'payload' => '#select#', 'value' => 'mode', 'visible' => 1,
                  'listValue' => self::listValue('mode'), 'generic' => 'THERMOSTAT_SET_MODE'),
            array('logicalId' => 'set_target_temperature', 'name' => 'Régler la consigne', 'type' => 'action', 'subType' => 'slider',
                  'key' => 'target_temperature', 'payload' => '#slider#', 'value' => 'target_temperature', 'visible' => 1,
                  'unit' => '°C', 'min' => $t[0], 'max' => $t[1], 'step' => 1,
                  'template' => 'core::button', 'generic' => 'THERMOSTAT_SET_SETPOINT'),
            array('logicalId' => 'set_fan', 'name' => 'Choisir la ventilation', 'type' => 'action', 'subType' => 'select',
                  'key' => 'fan', 'payload' => '#select#', 'value' => 'fan', 'visible' => 1, 'listValue' => self::listValue('fan')),
            array('logicalId' => 'set_swing_v', 'name' => 'Choisir le volet vertical', 'type' => 'action', 'subType' => 'select',
                  'key' => 'swing_v', 'payload' => '#select#', 'value' => 'swing_v', 'visible' => 1, 'listValue' => self::listValue('swing_v')),
            array('logicalId' => 'set_swing_h_on', 'name' => 'Volet horizontal On', 'type' => 'action', 'subType' => 'other',
                  'key' => 'swing_h', 'payload' => 'ON', 'value' => 'swing_h', 'visible' => 1, 'template' => 'core::binarySwitch'),
            array('logicalId' => 'set_swing_h_off', 'name' => 'Volet horizontal Off', 'type' => 'action', 'subType' => 'other',
                  'key' => 'swing_h', 'payload' => 'OFF', 'value' => 'swing_h', 'visible' => 1, 'template' => 'core::binarySwitch'),
            array('logicalId' => 'set_display_on', 'name' => 'Affichage On', 'type' => 'action', 'subType' => 'other',
                  'key' => 'display', 'payload' => 'ON', 'value' => 'display', 'visible' => 1, 'template' => 'core::binarySwitch'),
            array('logicalId' => 'set_display_off', 'name' => 'Affichage Off', 'type' => 'action', 'subType' => 'other',
                  'key' => 'display', 'payload' => 'OFF', 'value' => 'display', 'visible' => 1, 'template' => 'core::binarySwitch'),
            array('logicalId' => 'set_mute_on', 'name' => 'Bip coupé On', 'type' => 'action', 'subType' => 'other',
                  'key' => 'mute', 'payload' => 'ON', 'value' => 'mute', 'visible' => 1, 'template' => 'core::binarySwitch'),
            array('logicalId' => 'set_mute_off', 'name' => 'Bip coupé Off', 'type' => 'action', 'subType' => 'other',
                  'key' => 'mute', 'payload' => 'OFF', 'value' => 'mute', 'visible' => 1, 'template' => 'core::binarySwitch'),
            array('logicalId' => 'set_clean_on', 'name' => 'Nettoyage On', 'type' => 'action', 'subType' => 'other',
                  'key' => 'clean', 'payload' => 'ON', 'value' => 'clean', 'visible' => 1, 'template' => 'core::binarySwitch'),
            array('logicalId' => 'set_clean_off', 'name' => 'Nettoyage Off', 'type' => 'action', 'subType' => 'other',
                  'key' => 'clean', 'payload' => 'OFF', 'value' => 'clean', 'visible' => 1, 'template' => 'core::binarySwitch'),
            array('logicalId' => 'set_resync', 'name' => 'Resynchroniser', 'type' => 'action', 'subType' => 'other',
                  'key' => 'resync', 'payload' => '1', 'visible' => 0),
        );
    }
}
