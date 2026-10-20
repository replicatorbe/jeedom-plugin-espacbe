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
 * Seul point d'entrée du démon vers Jeedom : un POST JSON par lot de messages.
 * Pas de .htaccess « Deny from all » dans ce dossier : le démon appelle cette
 * URL par HTTP.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

/* La clé arrive par en-tête : la chaîne de requête est journalisée par Apache. */
$apikey = isset($_SERVER['HTTP_X_ESPACBE_APIKEY']) ? $_SERVER['HTTP_X_ESPACBE_APIKEY'] : '';

/* La seule clé du plugin : jeedom::apiAccess() accepterait aussi celle de
 * n'importe quel utilisateur. apiAccess() reste appelé pour son contrôle
 * d'origine (accès du plugin limité à localhost). */
if (!is_string($apikey) || !hash_equals(jeedom::getApiKey('espacbe'), $apikey)
    || !jeedom::apiAccess($apikey, 'espacbe')) {
    echo 'Unauthorized access.';
    log::add('espacbe', 'error', sprintf(__('Accès non autorisé depuis %s', __FILE__),
             isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?'));
    die();
}

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    die();
}

require_once __DIR__ . '/../class/espacbe.class.php';

$uid = init('uid');
$entete = __('Démon', __FILE__) . ' [' . $uid . '] : ';
$messages = json_decode(file_get_contents('php://input'), true);
if (!is_array($messages)) {
    log::add('espacbe', 'error', $entete . __('corps de requête illisible', __FILE__));
    die();
}

foreach ($messages as $message) {
    if (!is_array($message) || !isset($message['cmd'])) {
        continue;
    }
    /* Un démon fantôme (relancé entre-temps) ne doit plus rien écrire. */
    if ($message['cmd'] != 'daemonUp' && espacbeDaemon::validUid($uid) !== true) {
        log::add('espacbe', 'debug', $entete . __('message refusé (démon non reconnu) :', __FILE__) . ' ' . $message['cmd']);
        continue;
    }
    try {
        switch ($message['cmd']) {
            case 'messages':
                espacbeDaemon::onHeartbeat();
                if (isset($message['items']) && is_array($message['items'])) {
                    espacbe::onMessages($message['items']);
                }
                break;
            case 'hb':
                espacbeDaemon::onHeartbeat();
                break;
            case 'daemonUp':
                espacbeDaemon::onDaemonUp($uid);
                break;
            case 'daemonDown':
                espacbeDaemon::onDaemonDown();
                break;
            case 'brokerUp':
                espacbeDaemon::onBrokerUp();
                break;
            case 'brokerDown':
                espacbeDaemon::onBrokerDown(isset($message['message']) ? (string) $message['message'] : '');
                break;
            default:
                log::add('espacbe', 'error', $entete . __('commande inconnue :', __FILE__) . ' ' . $message['cmd']);
        }
    } catch (Throwable $e) {
        log::add('espacbe', 'error', $entete . sprintf(__('le traitement de « %1$s » a levé : %2$s', __FILE__),
                 $message['cmd'], $e->getMessage()));
    }
}
echo 'ok';
