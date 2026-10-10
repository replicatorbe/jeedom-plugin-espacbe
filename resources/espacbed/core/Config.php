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
 * Configuration du broker, reçue de Jeedom par l'ordre « setBroker ». Le démon
 * ne charge pas le cœur : il ne sait que ce qu'on lui envoie.
 */
class EspacbeConfig {

    const DEFAULT_PORT = 1883;

    private $host = '';
    private $port = self::DEFAULT_PORT;
    private $username = '';
    private $password = '';
    private $clientId = '';
    private $prefix = 'espac';

    /* Applique un jeu de réglages ; rend vrai si la connexion doit être refaite. */
    public function apply($_values) {
        if (!is_array($_values)) {
            return false;
        }
        $before = $this->signature();
        if (array_key_exists('host', $_values)) {
            $this->host = trim((string) $_values['host']);
        }
        if (array_key_exists('port', $_values)) {
            $port = (int) $_values['port'];
            $this->port = ($port > 0 && $port <= 65535) ? $port : self::DEFAULT_PORT;
        }
        if (array_key_exists('username', $_values)) {
            $this->username = (string) $_values['username'];
        }
        if (array_key_exists('password', $_values)) {
            $this->password = (string) $_values['password'];
        }
        if (array_key_exists('clientId', $_values)) {
            $this->clientId = trim((string) $_values['clientId']);
        }
        if (array_key_exists('prefix', $_values)) {
            $prefix = trim(trim((string) $_values['prefix']), '/');
            $this->prefix = ($prefix === '' || strpbrk($prefix, '+#') !== false) ? 'espac' : $prefix;
        }
        if ($this->clientId === '') {
            $this->clientId = 'espacbe-' . substr(md5(gethostname() . '|' . getmypid()), 0, 8);
        }
        return $before !== $this->signature();
    }

    private function signature() {
        return array($this->host, $this->port, $this->username, $this->password, $this->clientId, $this->prefix);
    }

    public function isUsable()  { return $this->host !== ''; }
    public function host()      { return $this->host; }
    public function port()      { return $this->port; }
    public function username()  { return $this->username; }
    public function password()  { return $this->password; }
    public function clientId()  { return $this->clientId; }
    public function keepalive() { return 60; }
    public function prefix()    { return $this->prefix; }

    public function describe() {
        return 'mqtt://' . $this->host . ':' . $this->port . ' (client ' . $this->clientId
             . ($this->username !== '' ? ', utilisateur ' . $this->username : '') . ', préfixe ' . $this->prefix . ')';
    }

    /* Filtres d'abonnement du contrat ESPAC. */
    public function subscriptions() {
        return array($this->prefix . '/+/status', $this->prefix . '/+/info', $this->prefix . '/+/state/#');
    }

    /*
     * Découpe un topic reçu : array('dev', 'kind', 'key') ou null.
     * Même règle que espacbeContract::parseTopic(), côté Jeedom.
     */
    public function parseTopic($_topic) {
        $topic = (string) $_topic;
        if (strpos($topic, $this->prefix . '/') !== 0) {
            return null;
        }
        $parts = explode('/', substr($topic, strlen($this->prefix) + 1));
        if (count($parts) < 2 || preg_match('/^[a-z0-9-]{1,64}$/', $parts[0]) !== 1) {
            return null;
        }
        if (count($parts) === 2 && ($parts[1] === 'status' || $parts[1] === 'info')) {
            return array('dev' => $parts[0], 'kind' => $parts[1], 'key' => '');
        }
        if (count($parts) === 3 && $parts[1] === 'state' && preg_match('/^[a-z0-9_]+$/', $parts[2]) === 1) {
            return array('dev' => $parts[0], 'kind' => 'state', 'key' => $parts[2]);
        }
        return null;
    }
}
