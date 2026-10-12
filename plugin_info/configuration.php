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
include_file('core', 'authentification', 'php');
if (!isConnect('admin')) {
	include_file('desktop', '404', 'php');
	die();
}
?>
<form class="form-horizontal">
	<fieldset>
		<legend><i class="fas fa-server"></i> {{Broker MQTT}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Adresse du broker}}</label>
			<div class="col-md-3">
				<input class="configKey form-control" data-l1key="broker::host" placeholder="127.0.0.1">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Mosquitto installé sur la box Jeedom : 127.0.0.1.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Port}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="broker::port" placeholder="1883" min="1" max="65535">
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Utilisateur}}</label>
			<div class="col-md-3">
				<input class="configKey form-control" data-l1key="broker::username" autocomplete="off" placeholder="{{facultatif}}">
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Mot de passe}}</label>
			<div class="col-md-3">
				<input type="password" class="configKey form-control" data-l1key="broker::password" autocomplete="new-password" placeholder="{{facultatif}}">
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Préfixe des topics}}</label>
			<div class="col-md-2">
				<input class="configKey form-control" data-l1key="topic::prefix" placeholder="espac">
			</div>
			<div class="col-md-6">
				<span class="help-block" style="margin:0;">{{Les modules publient sur}} <code>&lt;préfixe&gt;/&lt;module&gt;/…</code>. {{Doit correspondre au firmware ESPAC.}}</span>
			</div>
		</div>

		<legend><i class="fas fa-search"></i> {{Découverte}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Créer automatiquement les équipements}}</label>
			<div class="col-md-1">
				<input type="checkbox" class="configKey" data-l1key="discovery::autoCreate">
			</div>
			<div class="col-md-7">
				<span class="help-block" style="margin:0;">{{Un équipement est créé dès qu'un module ESPAC publie son statut ou ses informations sur le broker.}}</span>
			</div>
		</div>

		<legend><i class="fas fa-cogs"></i> {{Démon}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Port local du démon}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="daemon::socketport" placeholder="55140" min="1025" max="65535">
			</div>
			<div class="col-md-6">
				<span class="help-block" style="margin:0;">{{Port 127.0.0.1 sur lequel le démon reçoit les ordres de Jeedom. À changer seulement s'il est déjà pris (redémarrer le démon ensuite).}}</span>
			</div>
		</div>
		<div class="form-group">
			<div class="col-md-offset-4 col-md-8">
				<span class="help-block" style="margin:0;">{{Le niveau de journalisation se règle dans la section « Logs » ci-dessus ; il est transmis au démon dans la minute.}}</span>
			</div>
		</div>
	</fieldset>
</form>
