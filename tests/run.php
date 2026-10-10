<?php
/* Jeu d'essai hors ligne du plugin espacbe.
 *
 *   php tests/run.php
 *
 * Ni Jeedom, ni base, ni broker : le contrat MQTT (espacbeContract), le
 * découpage des topics côté démon (EspacbeConfig) et la cohérence des
 * commandes déclarées. L'essai de bout en bout se fait avec le simulateur
 * tests/simulate_espac.php (voir docs/fr_FR/index.md).
 *
 * Code de retour non nul dès qu'un contrôle échoue. */

require_once __DIR__ . '/../core/class/espacbeContract.class.php';
require_once __DIR__ . '/../resources/espacbed/core/Config.php';

$passed = 0;
$failed = 0;

function check($_label, $_actual, $_expected) {
    global $passed, $failed;
    if ($_actual === $_expected) {
        $passed++;
        printf("  ok    %-64s %s\n", $_label, var_export($_actual, true));
    } else {
        $failed++;
        printf("  ECHEC %-64s obtenu %s, attendu %s\n", $_label, var_export($_actual, true), var_export($_expected, true));
    }
}

function throws($_label, $_callback) {
    global $passed, $failed;
    try {
        $_callback();
        $failed++;
        printf("  ECHEC %-64s aucune exception\n", $_label);
    } catch (InvalidArgumentException $e) {
        $passed++;
        printf("  ok    %-64s %s\n", $_label, $e->getMessage());
    }
}

function section($_title) {
    echo "\n" . $_title . "\n" . str_repeat('-', mb_strlen($_title)) . "\n";
}

section('Topics');
check('status', espacbeContract::parseTopic('espac', 'espac/espac-salon/status'), array('dev' => 'espac-salon', 'kind' => 'status', 'key' => ''));
check('info', espacbeContract::parseTopic('espac', 'espac/espac-salon/info')['kind'], 'info');
check('state/target_temperature', espacbeContract::parseTopic('espac', 'espac/espac-salon/state/target_temperature')['key'], 'target_temperature');
check('state inconnu refusé', espacbeContract::parseTopic('espac', 'espac/espac-salon/state/foo'), null);
check('set/ ignoré (jamais relayé)', espacbeContract::parseTopic('espac', 'espac/espac-salon/set/power'), null);
check('dev en majuscules refusé', espacbeContract::parseTopic('espac', 'espac/Espac/status'), null);
check('autre préfixe refusé', espacbeContract::parseTopic('espac', 'shellies/x/status'), null);
check('préfixe personnalisé', espacbeContract::parseTopic('maison/clim/', 'maison/clim/a1/status')['dev'], 'a1');
check('abonnements', espacbeContract::subscriptions('espac'), array('espac/+/status', 'espac/+/info', 'espac/+/state/#'));
check('topic de commande', espacbeContract::setTopic('espac', 'espac-test', 'mode'), 'espac/espac-test/set/mode');

section('Démon : même découpage que Jeedom');
$config = new EspacbeConfig();
$config->apply(array('host' => '127.0.0.1', 'prefix' => 'espac'));
foreach (array('espac/a/status', 'espac/a/info', 'espac/a/state/power', 'espac/a/set/power', 'espac/A/status', 'espac/a/b/c', 'x/a/status') as $topic) {
    $jeedom = espacbeContract::parseTopic('espac', $topic);
    $daemon = $config->parseTopic($topic);
    check('accord sur ' . $topic, $daemon === null ? null : $daemon['kind'], $jeedom === null ? null : $jeedom['kind']);
}
check('abonnements du démon', $config->subscriptions(), espacbeContract::subscriptions('espac'));
$config->apply(array('prefix' => '#'));
check('préfixe joker remplacé', $config->prefix(), 'espac');

section('États reçus');
check('status online', espacbeContract::statusValue('online'), 1);
check('status offline', espacbeContract::statusValue('offline'), 0);
check('status hors contrat', espacbeContract::statusValue('up'), null);
check('power ON', espacbeContract::stateValue('power', 'ON'), 1);
check('swing_h OFF', espacbeContract::stateValue('swing_h', 'OFF'), 0);
check('binaire hors contrat', espacbeContract::stateValue('display', 'yes'), null);
check('mode heat', espacbeContract::stateValue('mode', 'heat'), 'heat');
check('mode inconnu', espacbeContract::stateValue('mode', 'hot'), null);
check('fan turbo', espacbeContract::stateValue('fan', 'turbo'), 'turbo');
check('swing_v very_low', espacbeContract::stateValue('swing_v', 'very_low'), 'very_low');
check('consigne 24', espacbeContract::stateValue('target_temperature', '24'), 24);
check('température pièce entière', espacbeContract::stateValue('room_temperature', '23'), 23);
check('température pièce décimale tolérée', espacbeContract::stateValue('room_temperature', '22.5'), 22.5);
check('rssi négatif', espacbeContract::stateValue('rssi', '-61'), -61);
check('uptime', espacbeContract::stateValue('uptime', '3600'), 3600);
check('last_frame brut', espacbeContract::stateValue('last_frame', 'BBAA00'), 'BBAA00');
check('clé inconnue', espacbeContract::stateValue('foo', '1'), null);

section('Commandes envoyées');
check('power 1 → ON', espacbeContract::setPayload('power', '1'), 'ON');
check('power OFF', espacbeContract::setPayload('power', 'OFF'), 'OFF');
check('mode heat', espacbeContract::setPayload('mode', 'heat'), 'heat');
check('consigne 24', espacbeContract::setPayload('target_temperature', '24'), '24');
check('consigne 21.6 arrondie', espacbeContract::setPayload('target_temperature', 21.6), '22');
check('consigne 40 bornée', espacbeContract::setPayload('target_temperature', 40), '31');
check('consigne 5 bornée', espacbeContract::setPayload('target_temperature', 5), '16');
check('resync', espacbeContract::setPayload('resync', ''), '1');
throws('mode hors contrat refusé', function () { espacbeContract::setPayload('mode', 'hot'); });
throws('room_temperature non réglable', function () { espacbeContract::setPayload('room_temperature', '20'); });
throws('consigne non numérique', function () { espacbeContract::setPayload('target_temperature', 'abc'); });
throws('clé inconnue', function () { espacbeContract::setPayload('foo', 'ON'); });

section('Libellés');
check('listValue mode (énoncé)', espacbeContract::listValue('mode'), 'auto|Auto;cool|Froid;dry|Déshumidification;fan_only|Ventilation;heat|Chaud');
check('8 vitesses de ventilation', count(espacbeContract::values('fan')), 8);
check('7 positions de volet vertical', count(espacbeContract::values('swing_v')), 7);

section('Commandes déclarées');
$cmds = espacbeContract::commands();
$names = array();
$ids = array();
$infos = array();
foreach ($cmds as $def) {
    $names[] = $def['name'];
    $ids[] = $def['logicalId'];
    if ($def['type'] === 'info') {
        $infos[$def['logicalId']] = $def;
    }
}
check('noms uniques', count(array_unique($names)), count($names));
check('identifiants logiques uniques', count(array_unique($ids)), count($ids));
check('une info par clé du contrat + status', count($infos), count(espacbeContract::KEYS) + 1);
foreach (array_keys(espacbeContract::KEYS) as $key) {
    check('info présente : ' . $key, isset($infos[$key]), true);
}
$problems = array();
foreach ($cmds as $def) {
    if (preg_match('/[\'&#\[\]%\/"*]/', $def['name'])) {
        $problems[] = 'caractère retiré par le cœur dans « ' . $def['name'] . ' »';
    }
    if (mb_strlen($def['name']) > 45) {
        $problems[] = 'nom trop long : ' . $def['name'];
    }
    if ($def['type'] !== 'action') {
        continue;
    }
    if (isset($def['value']) && !isset($infos[$def['value']])) {
        $problems[] = $def['logicalId'] . ' liée à une info absente';
    }
    if ($def['key'] !== 'resync' && empty(espacbeContract::KEYS[$def['key']]['set'])) {
        $problems[] = $def['logicalId'] . ' publie sur une clé non réglable';
    }
    if (isset($def['listValue']) && $def['listValue'] !== espacbeContract::listValue($def['key'])) {
        $problems[] = $def['logicalId'] . ' : listValue différente du contrat';
    }
    if ($def['payload'] !== '#select#' && $def['payload'] !== '#slider#') {
        try {
            espacbeContract::setPayload($def['key'], $def['payload']);
        } catch (InvalidArgumentException $e) {
            $problems[] = $def['logicalId'] . ' : ' . $e->getMessage();
        }
    }
}
check('actions cohérentes avec le contrat', $problems, array());
check('température pièce historisée', isset($infos['room_temperature']['historized']) && $infos['room_temperature']['historized'] === 1, true);
check('dernière trame masquée', $infos['last_frame']['visible'], 0);

section('Fichiers');
$ini = parse_ini_file(__DIR__ . '/../core/config/espacbe.config.ini', true);
check('config.ini : section [espacbe]', is_array($ini) && isset($ini['espacbe']), true);
check('config.ini : broker par défaut', $ini['espacbe']['broker::host'], '127.0.0.1');
check('config.ini : préfixe par défaut', $ini['espacbe']['topic::prefix'], 'espac');
$info = json_decode(file_get_contents(__DIR__ . '/../plugin_info/info.json'), true);
check('info.json : id', $info['id'], 'espacbe');
check('info.json : description ≥ 80 caractères', mb_strlen($info['description']['fr_FR']) >= 80 && mb_strlen($info['description']['en_US']) >= 80, true);
check('i18n en_US : JSON valide', is_array(json_decode(file_get_contents(__DIR__ . '/../core/i18n/en_US.json'), true)), true);
check('contrat recopié dans la doc', is_file(__DIR__ . '/../docs/fr_FR/contrat-mqtt.md'), true);

printf("\n  ==> %d réussi(s), %d échec(s)\n\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
