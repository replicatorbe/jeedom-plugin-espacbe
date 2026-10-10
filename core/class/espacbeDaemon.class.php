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
 * Cycle de vie du démon et dialogue avec lui (même architecture que mqttbe).
 *
 *   - démon vers Jeedom : POST HTTP par lots sur core/php/callback.php, clé
 *     d'API dans l'en-tête X-Espacbe-Apikey ;
 *   - Jeedom vers démon : socket TCP sur 127.0.0.1, un ordre JSON par
 *     connexion, réponse {"state":"ok"} ou {"state":"error","result":…}.
 *
 * L'état du démon vit dans le cache, partagé entre les processus Apache ; le
 * fichier PID reste la référence pour savoir si le processus vit vraiment.
 */
class espacbeDaemon {

    const CACHE_UID      = 'espacbe::daemonUid';
    const CACHE_PORT     = 'espacbe::daemonPort';
    const CACHE_LAST_RCV = 'espacbe::lastRcv';
    const CACHE_LAST_SND = 'espacbe::lastSnd';
    const CACHE_BROKER   = 'espacbe::brokerState';
    const CACHE_LOGLEVEL = 'espacbe::logLevel';

    /* Le démon envoie un battement toutes les 45 s : au-delà de 300 s de
     * silence, il est considéré comme mort et relancé par le cœur. */
    const TIMEOUT_RCV     = 300;
    const DELAY_HEARTBEAT = 45;

    const DEFAULT_SOCKET_PORT = 55140;

    public static function getCallbackUrl() {
        return network::getNetworkAccess('internal', 'http:127.0.0.1:port:comp')
             . '/plugins/espacbe/core/php/callback.php';
    }

    public static function getSocketPort() {
        $port = (int) config::byKey('daemon::socketport', 'espacbe', self::DEFAULT_SOCKET_PORT);
        return ($port > 1024 && $port < 65536) ? $port : self::DEFAULT_SOCKET_PORT;
    }

    public static function pidFile() {
        return jeedom::getTmpFolder('espacbe') . '/espacbed.pid';
    }

    /* Préfixe des topics, nettoyé : sans barre oblique en bord, jamais vide. */
    public static function prefix() {
        $prefix = trim(trim((string) config::byKey('topic::prefix', 'espacbe', 'espac')), '/');
        return ($prefix === '' || strpbrk($prefix, '+#') !== false) ? 'espac' : $prefix;
    }

    /*
     * Configuration envoyée au démon. Elle est autosuffisante : le démon ne
     * charge pas le cœur et ne peut rien relire lui-même.
     */
    public static function getBrokerConfig() {
        return array(
            'host'     => trim((string) config::byKey('broker::host', 'espacbe', '127.0.0.1')),
            'port'     => (int) config::byKey('broker::port', 'espacbe', 1883),
            'username' => (string) config::byKey('broker::username', 'espacbe', ''),
            'password' => (string) config::byKey('broker::password', 'espacbe', ''),
            'clientId' => self::getClientId(),
            'prefix'   => self::prefix(),
            'timezone' => date_default_timezone_get(),
        );
    }

    /* Identifiant client MQTT, propre à l'installation et stable. */
    public static function getClientId() {
        $clientId = trim((string) config::byKey('broker::clientId', 'espacbe', ''));
        if ($clientId === '') {
            $clientId = 'jeedom-espacbe-' . substr(md5(uniqid('', true)), 0, 8);
            config::save('broker::clientId', $clientId, 'espacbe');
        }
        return $clientId;
    }

    private static function cachedValue($_key, $_default) {
        try {
            return cache::byKey($_key)->getValue($_default);
        } catch (Throwable $e) {
            return $_default;
        }
    }

    /* Le démon a-t-il annoncé sa présence ? (cache seul) */
    public static function state() {
        return self::cachedValue(self::CACHE_UID, '0:0') !== '0:0';
    }

    /* Le démon vit-il ? (cache + processus) */
    public static function alive() {
        $cuid = self::cachedValue(self::CACHE_UID, '0:0');
        if ($cuid === '0:0') {
            return false;
        }
        list($cpid, ) = array_map('intval', explode(':', $cuid));
        return $cpid > 0 && @posix_getsid($cpid) !== false;
    }

    public static function brokerState() {
        return self::cachedValue(self::CACHE_BROKER, 'nok');
    }

    /* Rend null tant qu'aucun démon ne s'est annoncé, sinon vrai/faux. */
    public static function validUid($_uid) {
        $cuid = self::cachedValue(self::CACHE_UID, '0:0');
        if ($cuid === '0:0') {
            return null;
        }
        return $cuid === $_uid;
    }

    /*
     * Contrôle de la minute : un démon dont le processus a disparu, ou qui ne
     * parle plus, est arrêté pour que le cœur le relance.
     */
    public static function check() {
        $cuid = self::cachedValue(self::CACHE_UID, '0:0');
        if ($cuid === '0:0') {
            return false;
        }
        list($cpid, $cport) = array_map('intval', explode(':', $cuid));
        if ($cpid <= 0 || !@posix_getsid($cpid)) {
            log::add('espacbe', 'debug', __('Démon déclaré vivant mais son processus a disparu', __FILE__));
            self::stop();
            return false;
        }
        $deltaRx = time() - (int) self::cachedValue(self::CACHE_LAST_RCV, time());
        if ($deltaRx > self::TIMEOUT_RCV) {
            log::add('espacbe', 'warning', sprintf(__('Aucune nouvelle du démon depuis %ss : il est relancé', __FILE__), $deltaRx));
            self::stop();
            return false;
        }
        $deltaTx = time() - (int) self::cachedValue(self::CACHE_LAST_SND, 0);
        if ($deltaTx > self::DELAY_HEARTBEAT) {
            self::send(array('cmd' => 'hb'), false);
        }
        return true;
    }

    /* Rappel du cœur : lecture seule, appelée à chaque affichage de la page. */
    public static function info() {
        $return = array(
            'log'        => 'espacbed',
            'state'      => self::alive() ? 'ok' : 'nok',
            'launchable' => 'ok',
        );
        if (trim((string) config::byKey('broker::host', 'espacbe', '127.0.0.1')) === '') {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __("Renseignez l'adresse du broker MQTT", __FILE__);
        }
        return $return;
    }

    public static function start() {
        self::stop();
        $info = self::info();
        if ($info['launchable'] != 'ok') {
            throw new Exception(__('Le démon ne peut pas être lancé :', __FILE__) . ' '
                              . (isset($info['launchable_message']) ? $info['launchable_message'] : ''));
        }
        cache::set(self::CACHE_LAST_RCV, time());
        cache::set(self::CACHE_LAST_SND, time());

        $daemon = realpath(__DIR__ . '/../../resources/espacbed/espacbed.php');
        if ($daemon === false) {
            throw new Exception(__('Le démon est introuvable dans resources/espacbed/', __FILE__));
        }
        $level = log::convertLogLevel(log::getLogLevel('espacbe'));
        $cmd  = 'php ' . escapeshellarg($daemon);
        $cmd .= ' --callback '   . escapeshellarg(self::getCallbackUrl());
        $cmd .= ' --pid '        . escapeshellarg(self::pidFile());
        $cmd .= ' --socketport ' . escapeshellarg(self::getSocketPort());
        $cmd .= ' --loglevel '   . escapeshellarg($level);
        /* La clé d'API passe par l'entrée standard : `ps` est lisible par tous. */
        $full = 'echo ' . escapeshellarg(jeedom::getApiKey('espacbe')) . ' | ' . $cmd
              . ' >> ' . log::getPathToLog('espacbed') . ' 2>&1 &';

        log::add('espacbe', 'info', __('Lancement du démon', __FILE__));
        exec($full);

        for ($i = 1; $i <= 30; $i++) {
            if (self::state()) {
                log::add('espacbe', 'info', __('Démon démarré', __FILE__));
                message::removeAll('espacbe', 'unableStartDeamon');
                cache::set(self::CACHE_LOGLEVEL, $level);
                self::sendBrokerConfig();
                return true;
            }
            usleep(500000);
        }
        log::add('espacbe', 'error', __("Le démon n'a pas démarré. Consultez le journal espacbed.", __FILE__));
        message::add('espacbe', __("Le démon n'a pas démarré. Consultez le journal espacbed.", __FILE__), null, 'unableStartDeamon');
        return false;
    }

    public static function stop() {
        $cuid = self::cachedValue(self::CACHE_UID, '0:0');
        list($cpid, ) = array_map('intval', explode(':', $cuid));
        if ($cpid <= 0 && file_exists(self::pidFile())) {
            $cpid = (int) trim((string) file_get_contents(self::pidFile()));
        }
        /* Un fichier PID survit à une coupure : on ne signale que notre démon. */
        if ($cpid > 0 && !self::isOurDaemon($cpid)) {
            $cpid = 0;
        }
        if ($cpid > 0 && @posix_getsid($cpid)) {
            log::add('espacbe', 'info', __('Arrêt du démon', __FILE__));
            /* D'abord poliment : le démon ferme sa session MQTT proprement. */
            if (self::send(array('cmd' => 'stop'), false)) {
                for ($i = 1; $i <= 12 && @posix_getsid($cpid); $i++) {
                    usleep(250000);
                }
            }
            if (@posix_getsid($cpid)) {
                posix_kill($cpid, 15);
                for ($i = 1; $i <= 20 && @posix_getsid($cpid); $i++) {
                    usleep(250000);
                }
                if (@posix_getsid($cpid)) {
                    posix_kill($cpid, 9);
                }
            }
        }
        self::cleanup();
        return true;
    }

    private static function isOurDaemon($_pid) {
        $cmdline = '/proc/' . ((int) $_pid) . '/cmdline';
        if (!@is_readable($cmdline)) {
            return true;
        }
        return strpos((string) @file_get_contents($cmdline), 'espacbed.php') !== false;
    }

    public static function cleanup() {
        cache::set(self::CACHE_UID, '0:0');
        cache::set(self::CACHE_PORT, 0);
        cache::set(self::CACHE_BROKER, 'nok');
        if (file_exists(self::pidFile())) {
            @unlink(self::pidFile());
        }
    }

    /* ------------------------------------------------------- Jeedom -> démon */

    /*
     * Un ordre au démon : une connexion, un message, une réponse, fermeture.
     * Délai court : une page Jeedom ne doit jamais attendre le démon.
     */
    public static function send($_params, $_throw = true) {
        if (!self::state()) {
            if ($_throw) {
                throw new Exception(__("Le démon n'est pas démarré", __FILE__));
            }
            return false;
        }
        $_params['apikey'] = jeedom::getApiKey('espacbe');
        $port = (int) self::cachedValue(self::CACHE_PORT, self::getSocketPort());
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 2);
        if ($socket === false) {
            log::add('espacbe', 'debug', sprintf(__('Démon injoignable sur le port %1$s : %2$s', __FILE__), $port, $error));
            if ($_throw) {
                throw new Exception(__('Démon injoignable', __FILE__));
            }
            return false;
        }
        stream_set_timeout($socket, 2);
        $written = @fwrite($socket, json_encode($_params));
        $reponse = null;
        if ($written !== false) {
            $brut = @stream_get_contents($socket);
            $reponse = is_string($brut) ? json_decode($brut, true) : null;
        }
        fclose($socket);
        if ($written === false) {
            if ($_throw) {
                throw new Exception(__("Échec de l'envoi d'un ordre au démon", __FILE__));
            }
            return false;
        }
        cache::set(self::CACHE_LAST_SND, time());
        if (is_array($reponse) && isset($reponse['state']) && $reponse['state'] !== 'ok') {
            $motif = isset($reponse['result']) && is_string($reponse['result']) ? $reponse['result'] : __('motif inconnu', __FILE__);
            $texte = sprintf(__('Le démon a refusé la commande « %1$s » : %2$s', __FILE__),
                             isset($_params['cmd']) ? $_params['cmd'] : '?', $motif);
            if ($_throw) {
                throw new Exception($texte);
            }
            log::add('espacbe', 'warning', $texte);
            return false;
        }
        return true;
    }

    public static function sendBrokerConfig() {
        return self::send(array('cmd' => 'setBroker', 'config' => self::getBrokerConfig()), false);
    }

    /* Le niveau de journal est un réglage du cœur : on le suit au fil du cron. */
    public static function syncLogLevel() {
        $niveau = log::convertLogLevel(log::getLogLevel('espacbe'));
        if (self::cachedValue(self::CACHE_LOGLEVEL, '') === $niveau) {
            return;
        }
        if (self::send(array('cmd' => 'loglevel', 'level' => $niveau), false)) {
            cache::set(self::CACHE_LOGLEVEL, $niveau);
        }
    }

    public static function publish($_topic, $_payload, $_retain = false) {
        return self::send(array(
            'cmd'     => 'publish',
            'topic'   => (string) $_topic,
            'payload' => (string) $_payload,
            'qos'     => 0,
            'retain'  => (bool) $_retain,
        ));
    }

    /* Redemande au démon les derniers messages retenus d'un module. */
    public static function replay($_dev) {
        return self::send(array('cmd' => 'replay', 'dev' => (string) $_dev), false);
    }

    /* ------------------------------------------------------- démon -> Jeedom */

    public static function onDaemonUp($_uid) {
        list($pid, $port) = array_map('intval', explode(':', $_uid));
        cache::set(self::CACHE_UID, $_uid);
        cache::set(self::CACHE_PORT, $port);
        cache::set(self::CACHE_LAST_RCV, time());
        log::add('espacbe', 'info', sprintf(__('Démon connecté [pid %1$s, port %2$s]', __FILE__), $pid, $port));
    }

    public static function onHeartbeat() {
        cache::set(self::CACHE_LAST_RCV, time());
    }

    public static function onDaemonDown() {
        log::add('espacbe', 'info', __('Le démon signale son arrêt', __FILE__));
        self::cleanup();
    }

    public static function onBrokerUp() {
        cache::set(self::CACHE_LAST_RCV, time());
        cache::set(self::CACHE_BROKER, 'ok');
        log::add('espacbe', 'info', __('Connecté au broker MQTT', __FILE__));
    }

    public static function onBrokerDown($_message = '') {
        cache::set(self::CACHE_LAST_RCV, time());
        cache::set(self::CACHE_BROKER, 'nok');
        log::add('espacbe', 'warning', __('Déconnecté du broker MQTT', __FILE__) . ($_message !== '' ? ' : ' . $_message : ''));
    }
}
