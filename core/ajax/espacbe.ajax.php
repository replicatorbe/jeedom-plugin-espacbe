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

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }
    ajax::init();

    function espacbeAjaxEq() {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'espacbe') {
            throw new Exception(__('Équipement introuvable :', __FILE__) . ' ' . init('id'));
        }
        return $eqLogic;
    }

    /* Informations du module (topic info, dernier message) pour la page. */
    if (init('action') == 'moduleInfo') {
        ajax::success(espacbeAjaxEq()->moduleInfo());
    }

    /* Demande au module de republier tous ses états. */
    if (init('action') == 'resync') {
        unautorizedInDemo();
        espacbeAjaxEq()->publishCmd('resync', '1');
        ajax::success();
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));
} catch (Throwable $e) {
    ajax::error(displayException($e), $e->getCode());
}
