<?php
/* This file is part of the espacbe plugin for Jeedom.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3 of the License only.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

/*
 * Simulateur d'un module ESPAC : se comporte comme l'ESP32 du contrat MQTT v1.
 *
 *   php tests/simulate_espac.php <dev> [--host 127.0.0.1] [--port 1883]
 *       [--user u --pass p] [--prefix espac] [--duration 0] [--clear]
 *
 *   - publie status=online (testament offline), info et tous les state, retenus ;
 *   - écoute <prefix>/<dev>/set/#, valide la valeur comme l'ESP, applique, et
 *     republie le state correspondant (une valeur hors contrat est ignorée) ;
 *   - fait varier room_temperature (entier) vers la consigne quand la clim
 *     tourne, met à jour rssi et uptime, publie last_frame (non retenu) ;
 *   - --duration N : s'arrête seul après N secondes (0 = jamais) ;
 *   - --clear : efface les messages retenus du module (payloads vides) et sort.
 *
 * Arrêt par Ctrl-C ou SIGTERM : publie status=offline avant de partir.
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../resources/espacbed/lib/autoload.php';
require_once __DIR__ . '/../core/class/espacbeContract.class.php';

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

$args = array_slice($argv, 1);
$dev = null;
$opt = array('host' => '127.0.0.1', 'port' => 1883, 'user' => '', 'pass' => '', 'prefix' => 'espac', 'duration' => 0, 'clear' => false);
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--clear') {
        $opt['clear'] = true;
    } elseif (strpos($args[$i], '--') === 0 && isset($args[$i + 1])) {
        $opt[substr($args[$i], 2)] = $args[++$i];
    } else {
        $dev = $args[$i];
    }
}
if ($dev === null || !espacbeContract::isValidDevice($dev)) {
    fwrite(STDERR, "Usage : php tests/simulate_espac.php <dev [a-z0-9-]> [--host h] [--port p] [--user u --pass p] [--prefix espac] [--duration s] [--clear]\n");
    exit(2);
}

/* Heure locale du système, comme le journal de Jeedom. */
$tz = is_readable('/etc/timezone') ? trim((string) file_get_contents('/etc/timezone')) : '';
if ($tz === '' && is_link('/etc/localtime') && preg_match('#zoneinfo/(.+)$#', (string) readlink('/etc/localtime'), $m)) {
    $tz = $m[1];
}
if (in_array($tz, timezone_identifiers_list(), true)) {
    date_default_timezone_set($tz);
}

function simLog($_msg) {
    echo '[' . date('H:i:s') . '] ' . $_msg . PHP_EOL;
}

$base = trim($opt['prefix'], '/') . '/' . $dev;
$state = array(
    'power' => 'OFF', 'mode' => 'cool', 'target_temperature' => '22', 'fan' => 'auto',
    'swing_v' => 'off', 'swing_h' => 'OFF', 'display' => 'ON', 'mute' => 'ON', 'clean' => 'OFF',
    'room_temperature' => '25', 'unit_link' => 'ON', 'rssi' => '-58', 'uptime' => '0',
);

$settings = (new ConnectionSettings())
    ->setConnectTimeout(5)
    ->setKeepAliveInterval(30)
    ->setLastWillTopic($base . '/status')
    ->setLastWillMessage('offline')
    ->setRetainLastWill(true);
if ($opt['user'] !== '') {
    $settings = $settings->setUsername($opt['user'])->setPassword($opt['pass']);
}
$client = new MqttClient($opt['host'], (int) $opt['port'], 'espac-sim-' . $dev . '-' . getmypid(), MqttClient::MQTT_3_1_1);
$client->connect($settings, true);
simLog('connecté à ' . $opt['host'] . ':' . $opt['port'] . ' comme ' . $base);

if ($opt['clear']) {
    foreach (array_merge(array('status', 'info'), array_map(function ($_k) { return 'state/' . $_k; }, array_keys(espacbeContract::KEYS))) as $suffix) {
        $client->publish($base . '/' . $suffix, '', 0, true);
    }
    $client->disconnect();
    simLog('messages retenus de ' . $base . ' effacés');
    exit(0);
}

$publish = function ($_suffix, $_payload, $_retain = true) use ($client, $base) {
    $client->publish($base . '/' . $_suffix, (string) $_payload, 0, $_retain);
};
$publishState = function ($_key) use ($publish, &$state) {
    $publish('state/' . $_key, $state[$_key]);
};
$publishAll = function () use ($publish, $publishState, &$state) {
    foreach (array_keys($state) as $key) {
        $publishState($key);
    }
    $publish('state/last_frame', strtoupper(bin2hex(random_bytes(34))), false);
};

$publish('status', 'online');
$publish('info', json_encode(array('name' => $dev, 'mac' => '24:0A:C4:00:' . sprintf('%02X:%02X', mt_rand(0, 255), mt_rand(0, 255)),
                                   'ip' => '192.168.0.' . mt_rand(150, 199), 'version' => '0.1.0', 'fw' => 'espac-jeedom')));
$publishAll();
simLog('status, info et ' . count($state) . ' états publiés (retenus)');

$client->subscribe($base . '/set/#', function ($_topic, $_message) use (&$state, $base, $publishState, $publishAll) {
    $key = substr($_topic, strlen($base . '/set/'));
    simLog('reçu set/' . $key . ' = ' . $_message);
    if ($key === 'resync') {
        $publishAll();
        simLog('resync : tous les états republiés');
        return;
    }
    if (!isset(espacbeContract::KEYS[$key]) || !espacbeContract::KEYS[$key]['set']) {
        simLog('WARN clé non réglable ignorée : ' . $key);
        return;
    }
    $value = trim((string) $_message);
    $kind = espacbeContract::KEYS[$key]['kind'];
    $ok = false;
    if ($kind === 'onoff') {
        $ok = in_array($value, array('ON', 'OFF'), true);
    } elseif ($kind === 'enum') {
        $ok = array_key_exists($value, espacbeContract::values($key));
    } elseif ($kind === 'int') {
        $ok = ctype_digit($value) && (int) $value >= espacbeContract::TARGET_MIN && (int) $value <= espacbeContract::TARGET_MAX;
    }
    if (!$ok) {
        simLog('WARN valeur invalide ignorée : ' . $key . ' = ' . $value);
        return;
    }
    $state[$key] = $value;
    $publishState($key);
    simLog('state/' . $key . ' = ' . $value . ' republié');
}, 0);

$running = true;
pcntl_async_signals(true);
$stop = function () use (&$running) { $running = false; };
pcntl_signal(SIGTERM, $stop);
pcntl_signal(SIGINT, $stop);

$start = time();
$nextTick = time() + 10;
while ($running) {
    $client->loopOnce(microtime(true), true, 100000);
    if ((int) $opt['duration'] > 0 && time() - $start >= (int) $opt['duration']) {
        break;
    }
    if (time() >= $nextTick) {
        $nextTick = time() + 10;
        $room = (int) $state['room_temperature'];
        $target = (int) $state['target_temperature'];
        if ($state['power'] === 'ON' && $room !== $target) {
            $room += ($target > $room) ? 1 : -1;
        } elseif ($state['power'] !== 'ON') {
            $room += mt_rand(-1, 1);
            $room = max(18, min(30, $room));
        }
        $state['room_temperature'] = (string) $room;
        $state['rssi'] = (string) mt_rand(-70, -50);
        $state['uptime'] = (string) (time() - $start);
        foreach (array('room_temperature', 'rssi', 'uptime') as $key) {
            $publishState($key);
        }
        $client->publish($base . '/state/last_frame', strtoupper(bin2hex(random_bytes(34))), 0, false);
    }
}

$publish('status', 'offline');
$client->disconnect();
simLog('arrêt : status=offline publié');
