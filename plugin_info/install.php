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

require_once __DIR__ . '/../../../core/php/core.inc.php';

/* L'API du plugin n'est appelée que par son démon, en local. */
function espacbe_install() {
    config::save('api::espacbe::mode', 'localhost', 'core');
}

function espacbe_update() {
    try {
        config::save('api::espacbe::mode', 'localhost', 'core');
        /* Commandes ajoutées par une nouvelle version : créées sur les
         * équipements existants, sans toucher aux autres. */
        foreach (eqLogic::byType('espacbe') as $eqLogic) {
            $eqLogic->createCommands();
        }
    } catch (Throwable $e) {
        log::add('espacbe', 'error', __('Mise à jour du plugin :', __FILE__) . ' ' . $e->getMessage());
    }
}

function espacbe_remove() {
    try {
        if (class_exists('espacbe')) {
            espacbe::deamon_stop();
        }
        message::removeAll('espacbe');
    } catch (Throwable $e) {
        log::add('espacbe', 'error', __('Désinstallation :', __FILE__) . ' ' . $e->getMessage());
    }
}
