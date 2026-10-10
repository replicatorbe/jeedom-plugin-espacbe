<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('espacbe');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
$prefix = espacbe::topicPrefix();
?>

<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor eqLogicAction logoSecondary" data-action="add">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>

		<legend><i class="fas fa-snowflake"></i> {{Mes climatiseurs ESPAC}}</legend>
		<?php
		if (count($eqLogics) == 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '{{Aucun module pour le moment. Les modules ESPAC sont découverts automatiquement : démarrez le démon, puis branchez le module. Il apparaîtra ici dès qu\'il publiera sur}} <code>' . htmlspecialchars($prefix) . '/&lt;module&gt;/status</code>.';
			echo '</div>';
		}
		echo '<div class="input-group" style="margin:5px;">';
		echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
		echo '<div class="input-group-btn">';
		echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
		echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
		echo '</div>';
		echo '</div>';
		echo '<div class="eqLogicThumbnailContainer">';
		foreach ($eqLogics as $eqLogic) {
			$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
			$online = (int) $eqLogic->getCmdValue('status') === 1;
			echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
			echo '<img src="' . $plugin->getPathImgIcon() . '">';
			echo '<br>';
			echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
			echo '<span class="hiddenAsCard displayTableRight hidden">';
			echo '<span class="label label-' . ($online ? 'success' : 'danger') . '">' . ($online ? '{{En ligne}}' : '{{Hors ligne}}') . '</span> ';
			echo '<span class="label label-info">' . htmlspecialchars((string) $eqLogic->getLogicalId()) . '</span> ';
			echo '<span class="label label-default">' . htmlspecialchars((string) $eqLogic->getConfiguration('ip', '')) . '</span> ';
			echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
			echo '</span>';
			echo '</div>';
		}
		echo '</div>';
		?>
	</div>

	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
				<a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
				<a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i><span class="hidden-xs"> {{Équipement}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tag"></i> {{Général}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Nom}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Climatisation salon}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Objet parent}}</label>
								<div class="col-sm-6">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										foreach ((jeeObject::buildTree(null, false)) as $object) {
											echo '<option value="' . $object->getId() . '">' . $object->getHumanName(true, true) . '</option>';
										}
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Catégorie}}</label>
								<div class="col-sm-8">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Options}}</label>
								<div class="col-sm-8">
									<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>{{Activer}}</label>
									<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>{{Visible}}</label>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Identifiant du module}}</label>
								<div class="col-sm-6">
									<span class="eqLogicAttr label label-info" data-l1key="logicalId" id="span_espacbeDev"></span>
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="dev" id="in_espacbeDev" placeholder="espac-salon" style="display:none;">
									<span class="help-block">{{Nom ESPHome du module, tel qu'il apparaît dans les topics. Rempli par la découverte ; à saisir seulement pour un ajout manuel.}}</span>
								</div>
							</div>
						</fieldset>
					</form>
				</div>

				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-microchip"></i> {{Module ESPAC}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{État}}</label>
								<div class="col-sm-8"><span id="span_espacbeOnline" class="label label-default">-</span></div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Nom ESPHome}}</label>
								<div class="col-sm-8"><span class="espacbeInfo" data-info="esp_name">-</span></div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Adresse IP}}</label>
								<div class="col-sm-8"><span class="espacbeInfo" data-info="ip">-</span></div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Adresse MAC}}</label>
								<div class="col-sm-8"><span class="espacbeInfo" data-info="mac">-</span></div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Firmware}}</label>
								<div class="col-sm-8"><span class="espacbeInfo" data-info="fw">-</span> <span class="espacbeInfo" data-info="version"></span></div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Topics}}</label>
								<div class="col-sm-8"><code class="espacbeInfo" data-info="topic">-</code></div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Dernier message}}</label>
								<div class="col-sm-8"><span class="espacbeInfo" data-info="lastMessage">-</span></div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label"></label>
								<div class="col-sm-8">
									<a class="btn btn-default btn-sm" id="bt_espacbeResync"><i class="fas fa-sync"></i> {{Resynchroniser}}</a>
									<a class="btn btn-default btn-sm" id="bt_espacbeRefreshInfo"><i class="fas fa-redo"></i> {{Actualiser}}</a>
								</div>
							</div>
						</fieldset>
					</form>
				</div>
			</div>

			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<div class="col-xs-12">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th style="width:300px;">{{Nom}}</th>
								<th style="width:130px;">{{Type}}</th>
								<th>{{Paramètres}}</th>
								<th style="width:200px;">{{Valeur}}</th>
								<th style="width:120px;">{{Actions}}</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>

<?php include_file('desktop', 'espacbe', 'js', 'espacbe'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
