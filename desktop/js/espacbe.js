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

/* Ce qui vient d'un module est du texte, jamais du balisage. */
function espacbeText(_value) {
  return (_value === null || _value === undefined || _value === '') ? '-' : String(_value)
}

function espacbeCurrentId() {
  var id = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  return (id === null) ? '' : id.value
}

function espacbeAjax(_action, _success) {
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/espacbe/core/ajax/espacbe.ajax.php',
    data: { action: _action, id: espacbeCurrentId() },
    dataType: 'json',
    global: false,
    error: function (error) {
      jeedomUtils.showAlert({ message: espacbeText(error && error.result), level: 'danger' })
    },
    success: function (result) {
      if (result.state !== 'ok') {
        jeedomUtils.showAlert({ message: espacbeText(result.result), level: 'danger' })
        return
      }
      _success(result.result)
    }
  })
}

/* Bloc « Module ESPAC » : informations publiées sur le topic info. */
function espacbeShowModule(_info) {
  document.querySelectorAll('.espacbeInfo').forEach(function (_span) {
    var key = _span.getAttribute('data-info')
    _span.textContent = (key === 'version' && _info[key]) ? 'v' + _info[key] : espacbeText(_info[key])
  })
  var online = document.getElementById('span_espacbeOnline')
  if (online !== null) {
    if (_info.online === null || _info.online === '' || _info.online === undefined) {
      online.className = 'label label-default'
      online.textContent = '{{Inconnu}}'
    } else if (parseInt(_info.online) === 1) {
      online.className = 'label label-success'
      online.textContent = '{{En ligne}}'
    } else {
      online.className = 'label label-danger'
      online.textContent = '{{Hors ligne}}'
    }
  }
}

function espacbeLoadModule() {
  if (espacbeCurrentId() === '') { return }
  espacbeAjax('moduleInfo', espacbeShowModule)
}

/* Appelée par le cœur à l'ouverture d'un équipement. */
function printEqLogic(_eqLogic) {
  var hasDev = _eqLogic && _eqLogic.logicalId !== undefined && _eqLogic.logicalId !== ''
  var input = document.getElementById('in_espacbeDev')
  var span = document.getElementById('span_espacbeDev')
  if (input !== null) { input.style.display = hasDev ? 'none' : '' }
  if (span !== null) { span.style.display = hasDev ? '' : 'none' }
  espacbeShowModule({})
  espacbeLoadModule()
}

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }
  var tr = '<td>'
  /* Sans ce champ, chaque enregistrement détruit puis recrée les commandes. */
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  if (init(_cmd.type) === 'info') {
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized">{{Historiser}}</label>'
  }
  tr += '<span class="cmdAttr" data-l1key="unite" style="margin-left:8px;opacity:.7;"></span>'
  tr += '</td>'
  tr += '<td><span class="cmdAttr" data-l1key="htmlstate"></span></td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>'
  }
  tr += '</td>'

  /* createElement + appendChild : insertAdjacentHTML sur une table crée un
     <tbody> par insertion. */
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  newRow.querySelector('td').setAttribute('title', '{{Identifiant logique}} : ' + init(_cmd.logicalId))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* Pages chargées en ajax : un seul écouteur posé sur le document, qui résout
   les fonctions au moment du clic. */
if (!window.espacbeListening) {
  window.espacbeListening = true
  document.addEventListener('click', function (_event) {
    var target = _event.target
    if (target === null || typeof target.closest !== 'function') { return }
    if (target.closest('#bt_espacbeRefreshInfo') !== null) {
      _event.preventDefault()
      espacbeLoadModule()
      return
    }
    if (target.closest('#bt_espacbeResync') !== null) {
      _event.preventDefault()
      espacbeAjax('resync', function () {
        jeedomUtils.showAlert({ message: '{{Resynchronisation demandée au module.}}', level: 'success' })
        setTimeout(espacbeLoadModule, 1500)
      })
    }
  })
}
