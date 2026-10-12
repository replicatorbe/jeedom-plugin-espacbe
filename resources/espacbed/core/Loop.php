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
 * Boucle principale du démon espacbed.
 *
 * Un seul fil, jamais endormi plus de 50 ms : stream_select sur la socket de
 * commande et la liaison MQTT, puis traitement MQTT, envoi des lots à Jeedom,
 * reconnexion au broker avec recul progressif.
 */
class EspacbeLoop {

    const SELECT_TIMEOUT_US = 50000;
    const RETRY_BASE        = 2;
    const RETRY_MAX         = 60;
    const CONNECTION_STABLE = 60;

    private $config;
    private $transport;
    private $link;
    private $commands;
    private $pidFile;

    private $running  = true;
    private $exitCode = 0;
    private $brokerOk = false;
    private $brokerAnnounced = false;
    private $attempts  = 0;
    private $nextRetry = 0.0;
    private $waitingForConfig = true;
    private $subscribed = array();

    /* Derniers messages reçus par module : rejoués à la demande de Jeedom
     * (ordre « replay ») quand il vient de créer l'équipement. */
    private $last = array();
    private $received = 0;

    public function __construct(EspacbeConfig $_config, EspacbeTransport $_transport,
                                EspacbeJeedomLink $_link, EspacbeCommandSocket $_commands, $_pidFile) {
        $this->config    = $_config;
        $this->transport = $_transport;
        $this->link      = $_link;
        $this->commands  = $_commands;
        $this->pidFile   = (string) $_pidFile;
        $this->transport->onMessage(array($this, 'onMessage'));
        $this->commands->onCommand(array($this, 'onCommand'));
    }

    public function stop() {
        $this->running = false;
    }

    public function run() {
        if (!$this->commands->listen()) {
            $this->cleanup();
            return 1;
        }
        if (!$this->link->sendNow(array('cmd' => 'daemonUp'))) {
            EspacbeLog::error('Jeedom n\'a pas accepté l\'annonce de démarrage : arrêt. Vérifier l\'URL de rappel et la clé d\'API.');
            $this->cleanup();
            return 1;
        }
        EspacbeLog::info('démon prêt (pid ' . getmypid() . ', journal ' . EspacbeLog::level() . ')');
        while ($this->running) {
            try {
                $this->iterate();
            } catch (Throwable $e) {
                EspacbeLog::error('erreur dans la boucle : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
                usleep(200000);
            }
        }
        $this->shutdown();
        return $this->exitCode;
    }

    private function iterate() {
        $this->connectBroker();
        $read = $this->commands->streams();
        $brokerStream = $this->transport->stream();
        if (is_resource($brokerStream)) {
            $read[] = $brokerStream;
        }
        if (empty($read)) {
            usleep(self::SELECT_TIMEOUT_US);
        } else {
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, self::SELECT_TIMEOUT_US) > 0) {
                $this->commands->handleReady($read);
            }
        }
        if ($this->transport->isConnected() && !$this->transport->tick()) {
            $this->brokerLost($this->transport->lastError());
        }
        $this->commands->purge();
        $this->link->tick();
        if ($this->link->isDead()) {
            EspacbeLog::error('Jeedom injoignable depuis ' . $this->link->silentFor() . ' s : arrêt du démon');
            $this->exitCode = 1;
            $this->running = false;
        }
    }

    private function connectBroker() {
        if ($this->transport->isConnected()) {
            return;
        }
        if (!$this->config->isUsable()) {
            if ($this->waitingForConfig) {
                EspacbeLog::info('en attente de la configuration du broker (ordre setBroker)');
                $this->waitingForConfig = false;
            }
            return;
        }
        if (microtime(true) < $this->nextRetry) {
            return;
        }
        EspacbeLog::info('connexion au broker ' . $this->config->describe() . '…');
        if ($this->transport->connect()) {
            $this->attempts = 0;
            $this->nextRetry = 0.0;
            EspacbeLog::info('connecté au broker');
            $this->syncSubscriptions();
            if (!$this->brokerOk || !$this->brokerAnnounced) {
                $this->brokerOk = true;
                $this->brokerAnnounced = true;
                $this->link->push(array('cmd' => 'brokerUp'));
            }
            return;
        }
        $error = $this->transport->lastError();
        $delay = $this->scheduleRetry();
        EspacbeLog::error('connexion au broker impossible : ' . $error . ' — nouvel essai dans ' . $delay . ' s');
        if ($this->brokerOk || !$this->brokerAnnounced) {
            $this->brokerOk = false;
            $this->brokerAnnounced = true;
            $this->link->push(array('cmd' => 'brokerDown', 'message' => $error));
        }
    }

    /* Abonnements du contrat pour le préfixe courant ; les anciens sont retirés. */
    private function syncSubscriptions() {
        $wanted = $this->config->subscriptions();
        foreach ($this->subscribed as $topic) {
            if (!in_array($topic, $wanted, true)) {
                $this->transport->unsubscribe($topic);
            }
        }
        foreach ($wanted as $topic) {
            $this->transport->subscribe($topic, 0);
        }
        $this->subscribed = $wanted;
    }

    private function brokerLost($_reason) {
        EspacbeLog::warning('liaison avec le broker perdue : ' . $_reason);
        $since = $this->transport->connectedSince();
        $this->transport->disconnect();
        if ($this->brokerOk || !$this->brokerAnnounced) {
            $this->brokerOk = false;
            $this->brokerAnnounced = true;
            $this->link->push(array('cmd' => 'brokerDown', 'message' => $_reason));
        }
        if ($since > 0 && (time() - $since) >= self::CONNECTION_STABLE) {
            $this->attempts = 0;
        }
        $this->scheduleRetry();
    }

    private function scheduleRetry() {
        $delay = min(self::RETRY_MAX, self::RETRY_BASE * pow(2, min(6, $this->attempts)));
        $delay = (int) max(1, $delay * (0.9 + (mt_rand(0, 200) / 1000)));
        $this->attempts++;
        $this->nextRetry = microtime(true) + $delay;
        return $delay;
    }

    /* Message MQTT reçu : découpé selon le contrat puis mis en lot pour Jeedom. */
    public function onMessage($_topic, $_payload, $_qos, $_retained) {
        $parsed = $this->config->parseTopic($_topic);
        if ($parsed === null) {
            EspacbeLog::debug('topic hors contrat ignoré : ' . $_topic);
            return;
        }
        $this->received++;
        $payload = (string) $_payload;
        if (EspacbeLog::isDebug()) {
            EspacbeLog::debug('reçu ' . $_topic . ($_retained ? ' (retenu)' : '') . ' : '
                            . (strlen($payload) > 200 ? substr($payload, 0, 200) . '…' : $payload));
        }
        $item = $parsed + array('payload' => $payload, 'retained' => (bool) $_retained);
        $dev = $parsed['dev'];
        if ($payload === '') {
            unset($this->last[$dev][$_topic]);
        } else {
            $this->last[$dev][$_topic] = $item;
        }
        $this->link->pushMessage($item);
    }

    public function onCommand($_order) {
        $cmd = isset($_order['cmd']) ? (string) $_order['cmd'] : '';
        EspacbeLog::debug('ordre reçu : ' . $cmd);
        switch ($cmd) {
            case 'hb':
                return array('state' => 'ok', 'result' => array(
                    'broker' => $this->brokerOk ? 'ok' : 'nok',
                    'received' => $this->received,
                    'pending' => $this->link->pending(),
                ));
            case 'loglevel':
                $level = isset($_order['level']) ? $_order['level'] : '';
                if (!EspacbeLog::setLevel($level)) {
                    return array('state' => 'error', 'result' => 'niveau de journal inconnu : ' . $level);
                }
                EspacbeLog::info('niveau de journal porté à ' . EspacbeLog::level());
                return array('state' => 'ok');
            case 'setBroker':
                return $this->applyBroker(isset($_order['config']) ? $_order['config'] : array());
            case 'publish':
                $topic = isset($_order['topic']) ? (string) $_order['topic'] : '';
                if ($topic === '') {
                    return array('state' => 'error', 'result' => 'topic manquant');
                }
                $payload = isset($_order['payload']) ? (string) $_order['payload'] : '';
                $ok = $this->transport->publish($topic, $payload,
                    isset($_order['qos']) ? (int) $_order['qos'] : 0,
                    isset($_order['retain']) ? (bool) $_order['retain'] : false);
                if ($ok) {
                    EspacbeLog::info('publié ' . $topic . ' = ' . $payload);
                    return array('state' => 'ok');
                }
                return array('state' => 'error', 'result' => $this->transport->lastError());
            case 'replay':
                $dev = isset($_order['dev']) ? (string) $_order['dev'] : '';
                $count = 0;
                if (isset($this->last[$dev])) {
                    foreach ($this->last[$dev] as $item) {
                        $this->link->pushMessage($item);
                        $count++;
                    }
                }
                EspacbeLog::debug('rejeu de ' . $count . ' message(s) pour ' . $dev);
                return array('state' => 'ok', 'result' => $count);
            case 'stop':
                EspacbeLog::info('arrêt demandé par Jeedom');
                $this->running = false;
                return array('state' => 'ok');
            default:
                return array('state' => 'error', 'result' => 'commande inconnue : ' . $cmd);
        }
    }

    private function applyBroker($_config) {
        if (!is_array($_config)) {
            return array('state' => 'error', 'result' => 'configuration illisible');
        }
        if (isset($_config['timezone']) && is_string($_config['timezone'])
         && in_array($_config['timezone'], timezone_identifiers_list(), true)) {
            date_default_timezone_set($_config['timezone']);
        }
        if (!$this->config->apply($_config)) {
            if (!$this->transport->isConnected()) {
                $this->nextRetry = 0.0;
            }
            return array('state' => 'ok');
        }
        EspacbeLog::info('nouvelle configuration du broker : ' . $this->config->describe());
        $this->transport->disconnect();
        $this->last = array();
        $this->brokerOk = false;
        $this->brokerAnnounced = false;
        $this->attempts = 0;
        $this->nextRetry = 0.0;
        $this->waitingForConfig = true;
        return array('state' => 'ok');
    }

    private function shutdown() {
        EspacbeLog::info('arrêt du démon');
        $this->transport->disconnect();
        $this->commands->close();
        $this->link->sendNow(array('cmd' => 'daemonDown'));
        $this->link->close();
        $this->cleanup();
    }

    private function cleanup() {
        if ($this->pidFile !== '' && file_exists($this->pidFile)
         && (int) trim((string) @file_get_contents($this->pidFile)) === getmypid()) {
            @unlink($this->pidFile);
        }
    }
}
