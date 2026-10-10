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

require_once __DIR__ . '/../../../../core/php/core.inc.php';
/* L'autochargeur de Jeedom ne résout que la classe portant le nom du plugin :
 * les classes annexes sont incluses explicitement. */
require_once __DIR__ . '/espacbeContract.class.php';
require_once __DIR__ . '/espacbeDaemon.class.php';

/*
 * Climatiseurs Airton équipés d'un module ESP32 ESPAC, pilotés par MQTT.
 *
 * Un équipement par module : son identifiant logique est le <dev> des topics
 * (nom ESPHome du module). Le démon s'abonne aux topics du contrat et pousse
 * ce qu'il reçoit ; les actions publient sur <prefix>/<dev>/set/<clé>.
 *
 * Rappel des deux pièges du cœur : toute propriété d'instance commence par un
 * souligné, et aucune méthode ne s'appelle set + une clé de formulaire
 * (jamais setCmd()).
 */
class espacbe extends eqLogic {

    /* config::save() chiffre en base les clés listées ici. */
    public static $_encryptConfigKey = array('broker::password');

    /* Champs du topic info recopiés dans la configuration de l'équipement. */
    const INFO_FIELDS = array('name' => 'esp_name', 'mac' => 'mac', 'ip' => 'ip', 'version' => 'version', 'fw' => 'fw');

    /* ------------------------------------------------------ rappels du cœur */

    public static function deamon_info() {
        return espacbeDaemon::info();
    }

    public static function deamon_start() {
        return espacbeDaemon::start();
    }

    public static function deamon_stop() {
        return espacbeDaemon::stop();
    }

    /* Chien de garde : fait dire la vérité à deamon_info(). */
    public static function cron() {
        if (!espacbeDaemon::check()) {
            return;
        }
        espacbeDaemon::syncLogLevel();
    }

    public static function health() {
        $host = trim((string) config::byKey('broker::host', 'espacbe', '127.0.0.1'));
        $port = (int) config::byKey('broker::port', 'espacbe', 1883);
        $daemonUp = espacbeDaemon::alive();
        $brokerOk = espacbeDaemon::brokerState() == 'ok';
        $modules = self::byType('espacbe', true);
        $online = 0;
        foreach ($modules as $eqLogic) {
            if ((int) $eqLogic->getCmdValue('status') === 1) {
                $online++;
            }
        }
        return array(
            array('test' => __('Broker', __FILE__), 'result' => $host . ':' . $port, 'advice' => '', 'state' => $host !== ''),
            array('test' => __('Démon', __FILE__),
                  'result' => $daemonUp ? __('En fonctionnement', __FILE__) : __('Arrêté', __FILE__),
                  'advice' => $daemonUp ? '' : __('Démarrez le démon depuis la configuration du plugin', __FILE__),
                  'state' => $daemonUp),
            array('test' => __('Connexion au broker', __FILE__),
                  'result' => $brokerOk ? __('Établie', __FILE__) : __('Absente', __FILE__),
                  'advice' => $brokerOk ? '' : __('Consultez le journal espacbed', __FILE__),
                  'state' => $brokerOk),
            array('test' => __('Modules en ligne', __FILE__),
                  'result' => $online . ' / ' . count($modules), 'advice' => '',
                  'state' => count($modules) === 0 || $online === count($modules)),
        );
    }

    /* Un changement de réglage du broker part tout de suite vers le démon. */
    public static function postConfig_broker_host($_value) {
        self::configChanged();
    }

    public static function postConfig_broker_port($_value) {
        self::configChanged();
    }

    public static function postConfig_broker_username($_value) {
        self::configChanged();
    }

    public static function postConfig_broker_password($_value) {
        self::configChanged();
    }

    public static function postConfig_topic_prefix($_value) {
        self::configChanged();
    }

    /* Une seule expédition par requête, même si cinq clés changent. */
    private static $_configScheduled = false;

    private static function configChanged() {
        if (self::$_configScheduled || !espacbeDaemon::state()) {
            return;
        }
        self::$_configScheduled = true;
        register_shutdown_function(function () {
            try {
                espacbeDaemon::sendBrokerConfig();
            } catch (Throwable $e) {
                log::add('espacbe', 'error', __('Envoi de la configuration au démon :', __FILE__) . ' ' . $e->getMessage());
            }
        });
    }

    /* --------------------------------------------------- messages du démon */

    /*
     * Messages MQTT relayés par le démon, déjà découpés selon le contrat :
     * array('dev', 'kind' => status|info|state, 'key', 'payload', 'retained').
     */
    public static function onMessages($_items) {
        $autoCreate = config::byKey('discovery::autoCreate', 'espacbe', 1) == 1;
        $created = array();
        $withState = array();
        $cache = array();
        foreach ($_items as $item) {
            if (!is_array($item) || !isset($item['dev'], $item['kind'])) {
                continue;
            }
            $dev = (string) $item['dev'];
            $kind = (string) $item['kind'];
            $key = isset($item['key']) ? (string) $item['key'] : '';
            $payload = isset($item['payload']) ? (string) $item['payload'] : '';
            if (!espacbeContract::isValidDevice($dev)) {
                continue;
            }
            /* Un message retenu vide efface le topic : rien à poser. */
            if ($payload === '') {
                continue;
            }
            if (!isset($cache[$dev])) {
                $cache[$dev] = self::byLogicalId($dev, 'espacbe');
            }
            $eqLogic = $cache[$dev];
            if (!is_object($eqLogic)) {
                if (!$autoCreate || ($kind !== 'info' && $kind !== 'status')) {
                    log::add('espacbe', 'debug', sprintf(__('Message de %1$s ignoré (module inconnu) : %2$s', __FILE__), $dev, $kind . ($key !== '' ? '/' . $key : '')));
                    continue;
                }
                $eqLogic = self::createModule($dev, $kind === 'info' ? $payload : '');
                $cache[$dev] = $eqLogic;
                $created[] = $dev;
            }
            if (!$eqLogic->getIsEnable()) {
                continue;
            }
            if ($kind === 'state') {
                $withState[$dev] = true;
            }
            try {
                $eqLogic->ingest($kind, $key, $payload);
            } catch (Throwable $e) {
                log::add('espacbe', 'error', sprintf(__('Traitement de %1$s/%2$s : %3$s', __FILE__), $dev, $kind, $e->getMessage()));
            }
        }
        /* Les états retenus arrivés avant la création sont redemandés au démon,
         * sauf s'ils sont arrivés dans ce même lot. */
        foreach ($created as $dev) {
            if (isset($withState[$dev])) {
                continue;
            }
            espacbeDaemon::replay($dev);
        }
    }

    /* Création d'un équipement découvert. */
    public static function createModule($_dev, $_infoPayload = '') {
        $name = $_dev;
        $info = json_decode((string) $_infoPayload, true);
        if (is_array($info) && isset($info['name']) && trim((string) $info['name']) !== '') {
            $name = trim((string) $info['name']);
        }
        /* Unicité (nom, objet) : un suffixe plutôt qu'un échec. */
        $base = $name;
        for ($i = 2; self::nameTaken($name); $i++) {
            $name = $base . ' ' . $i;
        }
        $eqLogic = new espacbe();
        $eqLogic->setName($name);
        $eqLogic->setLogicalId($_dev);
        $eqLogic->setEqType_name('espacbe');
        $eqLogic->setIsEnable(1);
        $eqLogic->setIsVisible(1);
        $eqLogic->setCategory('heating', 1);
        $eqLogic->save();
        log::add('espacbe', 'info', sprintf(__('Module découvert et créé : %1$s (%2$s)', __FILE__), $name, $_dev));
        return $eqLogic;
    }

    private static function nameTaken($_name) {
        foreach (eqLogic::byObjectId(null, false) as $eqLogic) {
            if ($eqLogic->getName() === $_name) {
                return true;
            }
        }
        return false;
    }

    /* Applique un message à cet équipement. */
    public function ingest($_kind, $_key, $_payload) {
        $this->setCache('lastMessage', date('Y-m-d H:i:s'));
        switch ($_kind) {
            case 'status':
                $value = espacbeContract::statusValue($_payload);
                if ($value === null) {
                    log::add('espacbe', 'warning', sprintf(__('%1$s : statut hors contrat « %2$s »', __FILE__), $this->getLogicalId(), $_payload));
                    return;
                }
                $this->checkAndUpdateCmd('status', $value);
                return;
            case 'info':
                $this->ingestInfo($_payload);
                return;
            case 'state':
                $value = espacbeContract::stateValue($_key, $_payload);
                if ($value === null) {
                    log::add('espacbe', 'warning', sprintf(__('%1$s : valeur hors contrat pour %2$s « %3$s »', __FILE__), $this->getLogicalId(), $_key, $_payload));
                    return;
                }
                log::add('espacbe', 'debug', $this->getLogicalId() . ' ' . $_key . ' = ' . $_payload);
                $this->checkAndUpdateCmd($_key, $value);
                return;
        }
    }

    private function ingestInfo($_payload) {
        $info = json_decode((string) $_payload, true);
        if (!is_array($info)) {
            log::add('espacbe', 'warning', sprintf(__('%1$s : topic info illisible « %2$s »', __FILE__), $this->getLogicalId(), $_payload));
            return;
        }
        $changed = false;
        foreach (self::INFO_FIELDS as $field => $configKey) {
            if (!isset($info[$field]) || !is_scalar($info[$field])) {
                continue;
            }
            $value = (string) $info[$field];
            if ($this->getConfiguration($configKey, '') !== $value) {
                $this->setConfiguration($configKey, $value);
                $changed = true;
            }
        }
        if ($changed) {
            $this->save(true);
            log::add('espacbe', 'info', sprintf(__('%1$s : module %2$s, firmware %3$s, IP %4$s', __FILE__),
                $this->getLogicalId(), $this->getConfiguration('fw', '?'), $this->getConfiguration('version', '?'), $this->getConfiguration('ip', '?')));
        }
    }

    /* Préfixe des topics, pour les pages (la classe annexe n'est pas autochargée). */
    public static function topicPrefix() {
        return espacbeDaemon::prefix();
    }

    /* Valeur courante d'une info, par identifiant logique. */
    public function getCmdValue($_logicalId) {
        $cmd = $this->getCmd('info', $_logicalId);
        return is_object($cmd) ? $cmd->execCmd() : null;
    }

    /* Ce que la page équipement affiche dans « Module ». */
    public function moduleInfo() {
        return array(
            'dev'         => $this->getLogicalId(),
            'esp_name'    => $this->getConfiguration('esp_name', ''),
            'ip'          => $this->getConfiguration('ip', ''),
            'mac'         => $this->getConfiguration('mac', ''),
            'version'     => $this->getConfiguration('version', ''),
            'fw'          => $this->getConfiguration('fw', ''),
            'online'      => $this->getCmdValue('status'),
            'lastMessage' => $this->getCache('lastMessage', ''),
            'topic'       => espacbeDaemon::prefix() . '/' . $this->getLogicalId() . '/',
        );
    }

    /* --------------------------------------------------------- cycle eqLogic */

    public function preSave() {
        /* Jamais d'exception ici : le cœur crée l'équipement avec son seul nom.
         * Le champ « Identifiant du module » d'un ajout manuel devient le
         * logicalId tant que celui-ci est vide. */
        $dev = strtolower(trim((string) $this->getConfiguration('dev', '')));
        if ($this->getLogicalId() == '' && espacbeContract::isValidDevice($dev)) {
            $this->setLogicalId($dev);
        }
    }

    public function postSave() {
        $this->createCommands();
    }

    /* Crée les commandes manquantes. Pour celles qui existent, seule la liste
     * de valeurs (issue du contrat) est resynchronisée : le reste (nom,
     * visibilité, widget) reste celui choisi par l'utilisateur. */
    public function createCommands() {
        $order = 0;
        foreach (espacbeContract::commands() as $def) {
            $order++;
            $cmd = $this->getCmd($def['type'], $def['logicalId']);
            if (is_object($cmd)) {
                $changed = false;
                if (isset($def['listValue']) && $cmd->getConfiguration('listValue') != $def['listValue']) {
                    $cmd->setConfiguration('listValue', $def['listValue']);
                    $changed = true;
                }
                $legacy = espacbeContract::LEGACY_NAMES[$def['logicalId']] ?? array();
                if (in_array($cmd->getName(), $legacy, true)) {
                    $cmd->setName(__($def['name'], __FILE__));
                    $changed = true;
                }
                if ($changed) {
                    $cmd->save();
                }
                continue;
            }
            $cmd = new espacbeCmd();
            $cmd->setEqLogic_id($this->getId());
            $cmd->setLogicalId($def['logicalId']);
            $cmd->setName(__($def['name'], __FILE__));
            $cmd->setType($def['type']);
            $cmd->setSubType($def['subType']);
            $cmd->setOrder($order);
            $cmd->setIsVisible($def['visible']);
            if (isset($def['unit'])) {
                $cmd->setUnite($def['unit']);
            }
            if (isset($def['generic'])) {
                $cmd->setGeneric_type($def['generic']);
            }
            if (isset($def['min'])) {
                $cmd->setConfiguration('minValue', $def['min']);
                $cmd->setConfiguration('maxValue', $def['max']);
            }
            if (isset($def['historized'])) {
                $cmd->setIsHistorized($def['historized']);
            }
            if (isset($def['key'])) {
                $cmd->setConfiguration('key', $def['key']);
                $cmd->setConfiguration('payload', $def['payload']);
            }
            if (isset($def['listValue'])) {
                $cmd->setConfiguration('listValue', $def['listValue']);
            }
            if (isset($def['step'])) {
                $cmd->setDisplay('parameters', array('step' => $def['step']));
            }
            if (isset($def['template'])) {
                $cmd->setTemplate('dashboard', $def['template']);
                $cmd->setTemplate('mobile', $def['template']);
            }
            if (isset($def['value'])) {
                $info = $this->getCmd('info', $def['value']);
                if (is_object($info)) {
                    $cmd->setValue($info->getId());
                }
            }
            $cmd->save();
        }
    }

    /* Publie une commande vers le module. */
    public function publishCmd($_key, $_value) {
        $payload = espacbeContract::setPayload($_key, $_value);
        $topic = espacbeContract::setTopic(espacbeDaemon::prefix(), $this->getLogicalId(), $_key);
        log::add('espacbe', 'debug', sprintf(__('Publication %1$s = %2$s', __FILE__), $topic, $payload));
        espacbeDaemon::publish($topic, $payload, false);
    }
}

/*
 * Classe de commande obligatoire. Aucune méthode set + clé de formulaire.
 */
class espacbeCmd extends cmd {

    public function execute($_options = array()) {
        if ($this->getType() != 'action') {
            return null;
        }
        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic) || !$eqLogic->getIsEnable()) {
            throw new Exception(__('Équipement désactivé', __FILE__));
        }
        if (!espacbeContract::isValidDevice($eqLogic->getLogicalId())) {
            throw new Exception(__("Identifiant du module absent ou invalide : renseignez-le sur l'équipement", __FILE__));
        }
        $key = (string) $this->getConfiguration('key', '');
        $value = (string) $this->getConfiguration('payload', '');
        if ($value === '#select#') {
            $value = isset($_options['select']) ? (string) $_options['select'] : '';
        } elseif ($value === '#slider#') {
            $value = isset($_options['slider']) ? (string) $_options['slider'] : '';
        }
        try {
            $eqLogic->publishCmd($key, $value);
        } catch (InvalidArgumentException $e) {
            throw new Exception(sprintf(__('Commande refusée : %s', __FILE__), $e->getMessage()));
        }
        return null;
    }
}
