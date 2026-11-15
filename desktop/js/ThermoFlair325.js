/* JS du plugin ThermoFlair325 */

var TF_AJAX = 'plugins/ThermoFlair325/core/ajax/ThermoFlair325.ajax.php';
var TF_DAYS = [[1, 'L'], [2, 'Ma'], [3, 'Me'], [4, 'J'], [5, 'V'], [6, 'S'], [7, 'D']];
var TF_ARRAYS = ['vmc_windows', 'vmc_quiet', 'heat_slots', 'notifications', 'report_schedule', 'report_recipients'];
var TF_NOTIF_CATS = ['vmc', 'heat', 'shed', 'filter', 'preheat', 'alert', 'safety'];
var tf_lastValues = {}; // dernières valeurs lues par l'aperçu (clé de config -> valeur)
var tf_charts = [];

// Jeedom ré-exécute ce fichier à chaque ouverture de la page : on retire
// d'abord tous nos gestionnaires (espace de noms .tf) pour ne pas les
// empiler (leçon LotoStat).
$(document).off('.tf');

/* ------------------------------------------------------------------ */
/* Outils                                                               */
/* ------------------------------------------------------------------ */
function tf_alert(message, level) {
    if (window.jeedomUtils && jeedomUtils.showAlert) {
        jeedomUtils.showAlert({ message: message, level: level || 'info' });
    } else if ($.fn.showAlert) {
        $.fn.showAlert({ message: message, level: level || 'info' });
    }
}

function tf_ajax(data, success, silent) {
    data.apikey = ThermoFlair325Apikey;
    $.ajax({
        type: 'POST',
        url: TF_AJAX,
        data: data,
        dataType: 'json',
        error: function (request, status, error) {
            if (!silent) {
                handleAjaxError(request, status, error);
            }
        },
        success: function (res) {
            if (res.state != 'ok') {
                // Toujours afficher le vrai message du serveur (leçon RoboProg).
                if (!silent) {
                    tf_alert(res.result || '{{Erreur inconnue}}', 'danger');
                }
                return;
            }
            success(res.result);
        }
    });
}

function tf_esc(s) {
    return $('<div>').text(s === null || s === undefined ? '' : String(s)).html();
}

function tf_eqId() {
    return $('.eqLogicAttr[data-l1key=id]').val();
}

function tf_field(key) {
    return $('.eqLogicAttr[data-l1key=configuration][data-l2key="' + key + '"]');
}

function tf_val(key) {
    var $f = tf_field(key);
    if (!$f.length) {
        return undefined;
    }
    if ($f.is(':checkbox')) {
        return $f.is(':checked') ? '1' : '0';
    }
    return $f.val();
}

function tf_num(key) {
    var v = parseFloat(String(tf_val(key)).replace(',', '.'));
    return isNaN(v) ? null : v;
}

function tf_fmt(v, dec) {
    if (v === null || v === undefined || isNaN(v)) {
        return '?';
    }
    var p = Math.pow(10, dec === undefined ? 1 : dec);
    return String(Math.round(v * p) / p).replace('.', ',');
}

function tf_hm2min(s) {
    var m = String(s || '').match(/^(\d{1,2})[:hH](\d{2})$/);
    if (!m) {
        return null;
    }
    var h = parseInt(m[1], 10), mi = parseInt(m[2], 10);
    return (h > 23 || mi > 59) ? null : h * 60 + mi;
}

function tf_batName(b) {
    return tf_val(b + '_name') || (b == 'b1' ? 'Bas' : 'Haut');
}

function tf_daysHtml(selected) {
    selected = (selected || []).map(String);
    var h = '';
    TF_DAYS.forEach(function (d) {
        h += '<label title="' + d[1] + '"><input type="checkbox" class="d_day" value="' + d[0] + '"' + (selected.indexOf(String(d[0])) >= 0 ? ' checked' : '') + '> ' + d[1] + '</label>';
    });
    return h;
}

function tf_checked($tr, cls) {
    var out = [];
    $tr.find('.' + cls + ':checked').each(function () {
        out.push($(this).val());
    });
    return out;
}

/* ------------------------------------------------------------------ */
/* Chargement d'un équipement (appelé par le core)                      */
/* ------------------------------------------------------------------ */
function printEqLogic(_eqLogic) {
    _eqLogic = _eqLogic || {};
    var conf = _eqLogic.configuration || {};
    $.each(ThermoFlair325Defaults, function (key, def) {
        if (TF_ARRAYS.indexOf(key) >= 0) {
            return;
        }
        var cur = conf[key];
        if (cur === undefined || cur === null || cur === '') {
            var $f = tf_field(key);
            if ($f.is(':checkbox')) {
                $f.prop('checked', def == 1);
            } else {
                $f.val(def);
            }
        }
    });
    $('#table_tf_boosts tbody, #table_tf_windows tbody, #table_tf_quiet tbody, #table_tf_slots tbody, #table_tf_notifs tbody, #table_tf_rsched tbody, #table_tf_rrec tbody').empty();
    $('#tf_report_preview').hide().empty();
    var arr = function (k) {
        return Array.isArray(conf[k]) ? conf[k] : (conf[k] === undefined ? ThermoFlair325Defaults[k] : []);
    };
    var boosts = Array.isArray(conf.vmc_boosts) ? conf.vmc_boosts : null;
    if (boosts === null) {
        // Équipement d'avant le tableau : boosts 1-3 et vitesses temporaires.
        boosts = JSON.parse(JSON.stringify(ThermoFlair325Defaults.vmc_boosts));
        boosts.forEach(function (b) {
            var i = b.id.slice(-1);
            if (b.id.indexOf('boost') === 0) {
                b.speed = '4';
                if (conf['boost' + i + '_min'] !== undefined && conf['boost' + i + '_min'] !== '') { b.minutes = String(conf['boost' + i + '_min']); }
                if (i == '3' && conf.boost3_min === undefined) { b.minutes = '60'; }
            } else if (conf.force_duration !== undefined && conf.force_duration !== '') {
                b.minutes = String(conf.force_duration);
            }
        });
    }
    boosts.forEach(tf_addBoost);
    arr('vmc_quiet').forEach(tf_addQuiet);
    arr('vmc_windows').forEach(tf_addWindow);
    arr('heat_slots').forEach(tf_addSlot);
    arr('notifications').forEach(tf_addNotif);
    arr('report_schedule').forEach(tf_addRSched);
    arr('report_recipients').forEach(tf_addRRec);
    tf_lastValues = {};
    tf_refreshAll();
    try {
        if (typeof jeedomUtils !== 'undefined' && typeof jeedomUtils.initTooltips === 'function') {
            jeedomUtils.initTooltips($('#div_tf'));
        } else if (typeof initTooltips === 'function') {
            initTooltips($('#div_tf'));
        }
    } catch (e) { }
    tf_checkConfig(false);
    if ($('#diagtab').hasClass('active') || $('#filtertab').hasClass('active')) {
        tf_loadDiag(false);
    }
    if ($('#statstab').hasClass('active')) {
        tf_loadStats();
    }
}

function saveEqLogic(_eqLogic) {
    if (!_eqLogic.configuration) {
        _eqLogic.configuration = {};
    }
    $.extend(_eqLogic.configuration, tf_arraysConfig());
    var bad = tf_validateNumbers();
    if (bad > 0) {
        tf_alert(bad + ' {{réglage(s) en rouge hors des limites : le module concerné ne pilotera rien tant que ce ne sera pas corrigé (voir le bandeau).}}', 'warning');
    }
    return _eqLogic;
}

function tf_arraysConfig() {
    var quiet = [], windows = [], slots = [], notifs = [], boosts = [];
    $('#table_tf_boosts tbody tr').each(function () {
        var $tr = $(this);
        boosts.push({ id: $tr.find('.b_id').val(), name: $tr.find('.b_name').val().trim(), speed: $tr.find('.b_speed').val(), minutes: String($tr.find('.b_min').val()).trim(), widget: $tr.find('.b_widget').is(':checked') ? '1' : '0' });
    });
    $('#table_tf_quiet tbody tr').each(function () {
        var $tr = $(this);
        quiet.push({ label: $tr.find('.q_label').val(), days: tf_checked($tr, 'd_day'), start: $tr.find('.q_start').val(), end: $tr.find('.q_end').val(), max: $tr.find('.q_max').val() });
    });
    $('#table_tf_windows tbody tr').each(function () {
        var $tr = $(this);
        windows.push({
            label: $tr.find('.w_label').val(), season: $tr.find('.w_season').val(), days: tf_checked($tr, 'd_day'),
            start: $tr.find('.w_start').val(), end: $tr.find('.w_end').val(), speed: $tr.find('.w_speed').val(),
            skip_hot: $tr.find('.w_skip_hot').is(':checked') ? '1' : '0'
        });
    });
    $('#table_tf_slots tbody tr').each(function () {
        var $tr = $(this);
        slots.push({
            label: $tr.find('.s_label').val(), batteries: tf_checked($tr, 's_bat'), days: tf_checked($tr, 'd_day'),
            start: $tr.find('.s_start').val(), end: $tr.find('.s_end').val(), mode: $tr.find('.s_mode').val(), consigne: $tr.find('.s_consigne').val()
        });
    });
    $('#table_tf_notifs tbody tr').each(function () {
        var $tr = $(this);
        var cmd = ($tr.find('.n_cmd').val() || '').trim();
        if (cmd === '') {
            return;
        }
        var n = { cmd_id: cmd, title: $tr.find('.n_title').val(), html: $tr.find('.n_html').is(':checked') ? '1' : '0', n_all: $tr.find('.n_all').is(':checked') ? '1' : '0', rest: $tr.find('.n_rest').is(':checked') ? '1' : '0' };
        TF_NOTIF_CATS.forEach(function (c) {
            n['n_' + c] = $tr.find('.n_' + c).is(':checked') ? '1' : '0';
        });
        notifs.push(n);
    });
    var rsched = [], rrec = [];
    $('#table_tf_rsched tbody tr').each(function () {
        var $tr = $(this);
        rsched.push({ days: tf_checked($tr, 'd_day'), time: $tr.find('.r_time').val() });
    });
    $('#table_tf_rrec tbody tr').each(function () {
        var $tr = $(this);
        var cmd = ($tr.find('.r_cmd').val() || '').trim();
        if (cmd !== '') {
            rrec.push({ cmd_id: cmd, title: $tr.find('.r_title').val(), html: $tr.find('.r_html').is(':checked') ? '1' : '0', files: $tr.find('.r_files').is(':checked') ? '1' : '0' });
        }
    });
    return { vmc_boosts: boosts, vmc_quiet: quiet, vmc_windows: windows, heat_slots: slots, notifications: notifs, report_schedule: rsched, report_recipients: rrec };
}

function tf_formConfig() {
    var conf = {};
    $('.eqLogicAttr[data-l1key=configuration]').each(function () {
        var key = $(this).attr('data-l2key');
        conf[key] = $(this).is(':checkbox') ? ($(this).is(':checked') ? '1' : '0') : $(this).val();
    });
    return $.extend(conf, tf_arraysConfig());
}

function tf_validateNumbers() {
    var bad = 0;
    $('#div_tf input[type=number].eqLogicAttr').each(function () {
        var $i = $(this), v = $i.val();
        var ok = true;
        if (v !== '') {
            var n = parseFloat(v);
            ok = !isNaN(n) && (this.min === '' || n >= parseFloat(this.min)) && (this.max === '' || n <= parseFloat(this.max));
        }
        $i.closest('.form-group, td').toggleClass('has-error', !ok);
        if (!ok) {
            bad++;
        }
    });
    return bad;
}

/* ------------------------------------------------------------------ */
/* Tableaux                                                              */
/* ------------------------------------------------------------------ */
function tf_addRSched(r) {
    r = r || { days: ['3', '6'], time: '08:00' };
    var $tr = $('.tfRSchedTemplate').clone().removeClass('tfRSchedTemplate');
    $tr.find('.tf-days').html(tf_daysHtml(r.days));
    $tr.find('.r_time').val(r.time || '');
    $('#table_tf_rsched tbody').append($tr);
}

function tf_addRRec(n) {
    n = n || { cmd_id: '', title: '', html: '1', files: '1' };
    var $tr = $('.tfRRecTemplate').clone().removeClass('tfRRecTemplate');
    $tr.find('.r_cmd').val(n.cmd_id || '');
    $tr.find('.r_title').val(n.title || '');
    $tr.find('.r_html').prop('checked', n.html == '1');
    $tr.find('.r_files').prop('checked', n.files === undefined || n.files == '1');
    $('#table_tf_rrec tbody').append($tr);
    if (n.cmd_id) {
        tf_preview($tr.find('.r_cmd'));
    }
}

$(document).on('click.tf', '#bt_tfAddRSched', function () { tf_addRSched(); });
$(document).on('click.tf', '#bt_tfAddRRec', function () { tf_addRRec(); });
$(document).on('click.tf', '#bt_tfReportPreview', function () {
    if (!tf_eqId()) {
        tf_alert('{{Sauvegardez d\'abord l\'équipement.}}', 'warning');
        return;
    }
    $('#tf_report_preview').show().html('<i class="fas fa-spinner fa-spin"></i> {{Préparation du rapport...}}');
    tf_ajax({ action: 'reportPreview', id: tf_eqId() }, function (r) {
        var h = '<div style="max-height:600px; overflow:auto;">' + r.html + '</div>';
        if (r.images && r.images.length) {
            h += '<div>' + r.images.map(function (im) { return '<div style="margin:6px 0;"><div class="text-muted">' + tf_esc(im.name) + '</div><img src="' + im.src + '" style="max-width:100%; border:1px solid rgba(128,128,128,.3); border-radius:4px;"></div>'; }).join('') + '</div>';
        } else if (!r.gd) {
            h += '<div class="tf-warn">{{Graphiques indisponibles : extension PHP GD absente.}}</div>';
        }
        $('#tf_report_preview').html(h);
        $('#tf_report_next').text(r.next ? '{{Prochain envoi}} : ' + r.next : '');
    });
});
$(document).on('click.tf', '#bt_tfReportSend', function () {
    if (!tf_eqId()) {
        tf_alert('{{Sauvegardez d\'abord l\'équipement.}}', 'warning');
        return;
    }
    var rec = tf_arraysConfig().report_recipients;
    if (!rec.length) {
        tf_alert('{{Ajoutez au moins un destinataire (une commande mail par exemple).}}', 'warning');
        return;
    }
    tf_alert('{{Envoi du rapport...}}', 'info');
    tf_ajax({ action: 'reportSend', id: tf_eqId(), recipients: JSON.stringify(rec) }, function (r) {
        tf_alert('{{Rapport envoyé à}} ' + r.sent + ' {{destinataire(s)}}' + (r.files ? ' {{avec}} ' + r.files + ' {{graphique(s)}}' : '') + '.' + (r.detail ? ' ' + r.detail : ''), r.detail ? 'warning' : 'success');
    });
});

function tf_addQuiet(q) {
    q = q || { label: '', days: ['1', '2', '3', '4', '5', '6', '7'], start: '23:00', end: '07:00', max: '2' };
    var $tr = $('.tfQuietTemplate').clone().removeClass('tfQuietTemplate');
    $tr.find('.q_label').val(q.label || '');
    $tr.find('.tf-days').html(tf_daysHtml(q.days));
    $tr.find('.q_start').val(q.start || '');
    $tr.find('.q_end').val(q.end || '');
    $tr.find('.q_max').val(String(q.max || '2'));
    $('#table_tf_quiet tbody').append($tr);
}

function tf_addBoost(b) {
    b = b || { id: '', name: '', speed: '4', minutes: '10', widget: '1' };
    var $tr = $('.tfBoostTemplate').clone().removeClass('tfBoostTemplate');
    $tr.find('.b_id').val(b.id || ('boost_' + Math.random().toString(36).slice(2, 8)));
    $tr.find('.b_name').val(b.name || '');
    $tr.find('.b_speed').val(String(b.speed || '4'));
    $tr.find('.b_min').val(b.minutes || '');
    $tr.find('.b_widget').prop('checked', b.widget == '1');
    $('#table_tf_boosts tbody').append($tr);
    tf_boostWarn($tr);
}

// Avertissement à côté de la ligne : vitesse 4 au-delà de 15 min, durée invalide.
var TF_BOOST_V4_ADVISED = 15;
function tf_boostWarn($tr) {
    var sp = parseInt($tr.find('.b_speed').val(), 10), m = parseFloat($tr.find('.b_min').val()), h = '';
    if (isNaN(m) || m < 1 || m > 1440 || Math.round(m) != m) {
        h = '<span class="tf-ko">{{Durée : 1 à 1440 min}}</span>';
    } else if (sp == 4 && m > TF_BOOST_V4_ADVISED) {
        h = '<span style="color:#e67e22;" title="{{La vitesse 4 est bruyante, assèche l\'air et, en hiver, fait perdre de la chaleur. Pour aérer plus longtemps, préférez la vitesse 3.}}"><i class="fas fa-exclamation-triangle"></i> {{Vitesse 4 : pas plus de}} ' + TF_BOOST_V4_ADVISED + ' {{min conseillé}}</span>';
    }
    if ($tr.find('.b_name').val().trim() === '') {
        h = '<span class="tf-ko">{{Nom obligatoire}}</span> ' + h;
    }
    $tr.find('.b_warn').html(h);
}

function tf_fmtMin(m) {
    if (m < 60) { return m + ' min'; }
    var hh = Math.floor(m / 60), mm = m % 60;
    return hh + ' h' + (mm ? (mm < 10 ? '0' : '') + mm : '');
}

function tf_addWindow(w) {
    w = w || { label: '', season: 'all', days: ['1', '2', '3', '4', '5', '6', '7'], start: '12:00', end: '16:00', speed: '2', skip_hot: '1' };
    var $tr = $('.tfWindowTemplate').clone().removeClass('tfWindowTemplate');
    $tr.find('.w_label').val(w.label || '');
    $tr.find('.w_season').val(w.season || 'all');
    $tr.find('.tf-days').html(tf_daysHtml(w.days));
    $tr.find('.w_start').val(w.start || '');
    $tr.find('.w_end').val(w.end || '');
    $tr.find('.w_speed').val(String(w.speed || '2'));
    $tr.find('.w_skip_hot').prop('checked', w.skip_hot == '1');
    $('#table_tf_windows tbody').append($tr);
}

function tf_addSlot(s) {
    s = s || { label: '', batteries: ['b1'], days: ['1', '2', '3', '4', '5', '6', '7'], start: '', end: '', mode: 'normal', consigne: '' };
    var $tr = $('.tfSlotTemplate').clone().removeClass('tfSlotTemplate');
    $tr.find('.s_label').val(s.label || '');
    (s.batteries || []).forEach(function (b) {
        $tr.find('.s_bat[value=' + b + ']').prop('checked', true);
    });
    $tr.find('.tf-days').html(tf_daysHtml(s.days));
    $tr.find('.s_start').val(s.start || '');
    $tr.find('.s_end').val(s.end || '');
    $tr.find('.s_mode').val((!s.mode || s.mode == 'comfort') ? 'normal' : s.mode);
    $tr.find('.s_consigne').val(s.consigne || '');
    tf_slotModeChanged($tr);
    $('#table_tf_slots tbody').append($tr);
    tf_batteryNames();
}

function tf_slotModeChanged($tr) {
    var isC = $tr.find('.s_mode').val() == 'consigne';
    $tr.find('.s_consigne').prop('disabled', !isC).attr('placeholder', isC ? '20' : '—');
}

function tf_addNotif(n) {
    n = n || { cmd_id: '', title: '', html: '0', n_all: '0', n_vmc: '1', n_heat: '1', n_shed: '1', n_filter: '1', n_preheat: '1', n_alert: '1', n_safety: '1', rest: '1' };
    // Anciennes lignes sans « Sécurité » : comme « Alertes ».
    if (n.n_safety === undefined) {
        n.n_safety = n.n_alert || '0';
    }
    // Anciennes lignes sans « Préchauffage » : comme « VMC ».
    if (n.n_preheat === undefined) {
        n.n_preheat = n.n_vmc || '0';
    }
    var $tr = $('.tfNotifTemplate').clone().removeClass('tfNotifTemplate');
    $tr.find('.n_cmd').val(n.cmd_id || '');
    $tr.find('.n_title').val(n.title || '');
    $tr.find('.n_html').prop('checked', n.html == '1');
    $tr.find('.n_all').prop('checked', n.n_all == '1');
    $tr.find('.n_rest').prop('checked', n.rest == '1');
    TF_NOTIF_CATS.forEach(function (c) {
        $tr.find('.n_' + c).prop('checked', n['n_' + c] == '1');
    });
    tf_notifAllChanged($tr);
    $('#table_tf_notifs tbody').append($tr);
    tf_preview($tr.find('.n_cmd'));
}

// "Toutes les actions" coche (et grise) toutes les catégories.
function tf_notifAllChanged($tr) {
    var all = $tr.find('.n_all').is(':checked');
    $tr.find('.n_cat').prop('disabled', all);
    if (all) {
        $tr.find('.n_cat').prop('checked', true);
    }
}

$(document).on('click.tf', '#bt_tfAddQuiet', function () { tf_addQuiet(); tf_refreshLive(); tf_renderWeek(); });
$(document).on('click.tf', '#bt_tfAddWindow', function () { tf_addWindow(); tf_renderWeek(); });
$(document).on('click.tf', '#bt_tfAddBoost', function () { tf_addBoost(); });
$(document).on('change.tf input.tf', '#table_tf_boosts input, #table_tf_boosts select', function () { tf_boostWarn($(this).closest('tr')); });
$(document).on('click.tf', '#bt_tfAddSlot', function () { tf_addSlot(); tf_renderWeek(); });
$(document).on('click.tf', '#bt_tfAddNotif', function () { tf_addNotif(); });
$(document).on('click.tf', '.bt_tfRemoveRow', function () {
    $(this).closest('tr').remove();
    tf_renderWeek();
    tf_refreshLive();
});
$(document).on('change.tf', '.s_mode', function () {
    tf_slotModeChanged($(this).closest('tr'));
});
$(document).on('change.tf', '.n_all', function () {
    tf_notifAllChanged($(this).closest('tr'));
});
$(document).on('change.tf input.tf', '#table_tf_slots input, #table_tf_slots select, #table_tf_windows input, #table_tf_windows select, #table_tf_quiet input, #table_tf_quiet select', function () {
    tf_renderWeek();
    tf_refreshLive();
});

$(document).on('click.tf', '.bt_tfTestNotif', function () {
    var $tr = $(this).closest('tr');
    var cmd = ($tr.find('.n_cmd').val() || '').trim();
    if (!cmd) {
        tf_alert('{{Choisissez d\'abord une commande.}}', 'warning');
        return;
    }
    if (!tf_eqId()) {
        tf_alert('{{Sauvegardez d\'abord l\'équipement.}}', 'warning');
        return;
    }
    var cats = [];
    ['all', 'vmc', 'heat', 'shed', 'filter', 'preheat', 'alert', 'safety'].forEach(function (c) {
        if ($tr.find('.n_' + c).is(':checked')) {
            cats.push(c);
        }
    });
    if (!cats.length) {
        tf_alert('{{Aucune case cochée pour ce destinataire : il ne recevra rien. Cochez au moins un sujet (ou « Toutes les actions ») avant de tester.}}', 'warning');
        return;
    }
    tf_ajax({ action: 'testNotification', id: tf_eqId(), cmd_id: cmd, title: $tr.find('.n_title').val(), html: $tr.find('.n_html').is(':checked') ? '1' : '0', cats: cats.join(',') }, function (r) {
        tf_alert(r.sent + ' {{message(s) de test envoyé(s)}} : ' + r.cats.join(', ') + '.' + (r.detail ? ' ' + r.detail : ''), r.detail ? 'warning' : 'success');
    });
});

function tf_batteryNames() {
    $('.s_bat_name').each(function () {
        $(this).text(tf_batName($(this).attr('data-bat')));
    });
}

/* ------------------------------------------------------------------ */
/* Plages de silence vs vitesse minimum des batteries                   */
/* ------------------------------------------------------------------ */
// Segments [début, fin] en minutes, par jour (1..7), d'une liste de plages.
function tf_segments(rows) {
    var segs = {};
    for (var d = 1; d <= 7; d++) {
        segs[d] = [];
    }
    rows.forEach(function (r, idx) {
        var a = tf_hm2min(r.start), b = tf_hm2min(r.end);
        if (a === null || b === null || a == b) {
            return;
        }
        (r.days || []).forEach(function (dd) {
            dd = parseInt(dd, 10);
            if (a < b) {
                segs[dd].push([a, b, r, idx]);
            } else {
                segs[dd].push([a, 1440, r, idx]);
                segs[dd == 7 ? 1 : dd + 1].push([0, b, r, idx]);
            }
        });
    });
    return segs;
}

function tf_conflicts() {
    var cfg = tf_arraysConfig();
    var out = [];
    if (tf_val('mod_heat') != '1') {
        return out;
    }
    ['b1', 'b2'].forEach(function (b) {
        if (tf_val(b + '_enabled') != '1') {
            return;
        }
        var need = parseInt(tf_val(b + '_min_speed'), 10);
        cfg.vmc_quiet.forEach(function (q) {
            if (parseInt(q.max, 10) < need) {
                out.push({ b: b, q: q, need: need });
            }
        });
    });
    return out;
}

/* ------------------------------------------------------------------ */
/* Aperçu de la semaine                                                  */
/* ------------------------------------------------------------------ */
function tf_renderWeek() {
    var $w = $('#tf_week');
    if (!$w.length) {
        return;
    }
    var cfg = tf_arraysConfig();
    var slotSegs = tf_segments(cfg.heat_slots);
    var winSegs = tf_segments(cfg.vmc_windows);
    var quietSegs = tf_segments(cfg.vmc_quiet);
    var cN = tf_val('heat_consigne'), cE = tf_val('heat_eco');
    var modeLabel = { normal: '{{Normal}} (' + cN + ' °C)', comfort: '{{Normal}} (' + cN + ' °C)', eco: '{{ECO}} (' + cE + ' °C)', consigne: '{{consigne}}', forced: '{{marche forcée}}', off: '{{arrêt}}' };
    var html = '<div class="tf-legend">'
        + '<span><i class="tf-seg-normal"></i>{{Normal}}</span><span><i class="tf-seg-eco"></i>{{ECO}}</span><span><i class="tf-seg-consigne"></i>{{consigne fixe}}</span>'
        + '<span><i class="tf-seg-forced"></i>{{marche forcée}}</span><span><i class="tf-seg-off"></i>{{arrêt}}</span>'
        + '<span><i class="tf-seg-outside"></i>{{hors plage (ECO / Normal)}}</span>'
        + '<span><i class="tf-seg-vmc"></i>{{plage VMC}}</span><span><i class="tf-seg-quiet"></i>{{silence VMC}}</span>'
        + '<span><i class="tf-seg-consigne tf-seg-conflict"></i>{{bloquée par le silence}}</span></div>';
    var names = { 1: 'L', 2: 'Ma', 3: 'Me', 4: 'J', 5: 'V', 6: 'S', 7: 'D' };
    var overlapsQuiet = function (day, a, b, need) {
        return quietSegs[day].some(function (q) {
            return parseInt(q[2].max, 10) < need && q[0] < b && q[1] > a;
        });
    };
    for (var day = 1; day <= 7; day++) {
        html += '<div class="tf-week-row"><div class="tf-week-day">' + names[day] + '</div><div class="tf-week-bars">';
        ['b1', 'b2'].forEach(function (b) {
            var outside = tf_val(b + '_outside');
            var need = parseInt(tf_val(b + '_min_speed'), 10);
            html += '<div class="tf-week-bar' + (outside != 'off' ? ' tf-seg-outside' : '') + '" title="' + tf_esc(tf_batName(b)) + (outside != 'off' ? ' — {{hors plage}} : ' + (outside == 'eco' ? '{{ECO}} ' + cE + ' °C' : '{{Normal}} ' + cN + ' °C') : '') + '">';
            slotSegs[day].forEach(function (s) {
                if ((s[2].batteries || []).indexOf(b) < 0) {
                    return;
                }
                var conflict = s[2].mode != 'off' && overlapsQuiet(day, s[0], s[1], need);
                var t = tf_batName(b) + ' — ' + (s[2].label || '') + ' ' + s[2].start + ' → ' + s[2].end + ' : ' + modeLabel[s[2].mode] + (s[2].mode == 'consigne' ? ' ' + s[2].consigne + ' °C' : '') + (conflict ? ' — {{en partie bloquée par une plage de silence}}' : '');
                html += '<div class="tf-week-seg tf-seg-' + (s[2].mode == 'comfort' ? 'normal' : s[2].mode) + (conflict ? ' tf-seg-conflict' : '') + '" style="left:' + (s[0] / 14.4) + '%;width:' + ((s[1] - s[0]) / 14.4) + '%;" title="' + tf_esc(t) + '"></div>';
            });
            html += '</div>';
        });
        html += '<div class="tf-week-bar" title="VMC">';
        quietSegs[day].forEach(function (s) {
            html += '<div class="tf-week-seg tf-seg-quiet" style="left:' + (s[0] / 14.4) + '%;width:' + ((s[1] - s[0]) / 14.4) + '%;" title="' + tf_esc((s[2].label || '{{Silence}}') + ' ' + s[2].start + ' → ' + s[2].end + ' : VMC max V' + s[2].max) + '"></div>';
        });
        winSegs[day].forEach(function (s) {
            html += '<div class="tf-week-seg tf-seg-vmc" style="left:' + (s[0] / 14.4) + '%;width:' + ((s[1] - s[0]) / 14.4) + '%;" title="' + tf_esc((s[2].label || 'VMC') + ' ' + s[2].start + ' → ' + s[2].end + ' : V' + s[2].speed) + '"></div>';
        });
        html += '</div></div></div>';
    }
    html += '<div class="tf-week-scale"><span>0h</span><span>6h</span><span>12h</span><span>18h</span><span>24h</span></div>';
    html += '<div class="help-block">{{Chaque jour : ligne 1 =}} « ' + tf_esc(tf_batName('b1')) + ' », {{ligne 2 =}} « ' + tf_esc(tf_batName('b2')) + ' », {{ligne 3 = VMC (silence / plages). Survolez un bloc pour le détail.}}</div>';
    $w.html(html);
}

/* ------------------------------------------------------------------ */
/* Aperçus en direct des commandes liées (✅ / ❌)                       */
/* ------------------------------------------------------------------ */
function tf_previewTarget($input) {
    var key = $input.attr('data-l2key');
    if (key) {
        return $('.tf-preview[data-for="' + key + '"]');
    }
    return $input.closest('td').find('.tf-preview');
}

function tf_preview($input) {
    var $p = tf_previewTarget($input);
    var raw = ($input.val() || '').trim();
    var key = $input.attr('data-l2key');
    if (raw === '') {
        $p.html('');
        if (key) {
            delete tf_lastValues[key];
            tf_refreshLive();
        }
        return;
    }
    var isNotif = $input.hasClass('n_cmd');
    var expect = $input.attr('data-expect') || (isNotif ? 'action' : 'info');
    tf_ajax({
        action: 'preview', raw: raw, expect: expect,
        min: $input.attr('data-min') || '', max: $input.attr('data-max') || '',
        numeric: $input.attr('data-numeric') || '', binary: $input.attr('data-binary') || '',
        subtypes: isNotif ? 'message' : ($input.attr('data-subtypes') || '')
    }, function (r) {
        if (r.valid === null) {
            $p.html('');
            return;
        }
        if (r.valid) {
            var v = (r.value === null || r.value === undefined || r.value === '') ? '<i>{{vide}}</i>' : tf_esc(r.value) + (r.unit ? ' ' + tf_esc(r.unit) : '');
            $p.html('<span class="tf-ok"><i class="fas fa-check-circle"></i> ' + v + '</span>');
        } else {
            $p.html('<span class="tf-ko"><i class="fas fa-times-circle"></i> ' + tf_esc(r.error || '{{Erreur}}') + (r.value !== undefined && r.value !== null ? ' (' + tf_esc(r.value) + ')' : '') + '</span>');
        }
        if (key) {
            tf_lastValues[key] = r.value;
            tf_refreshLive();
        }
    }, true);
}

$(document).on('change.tf blur.tf', '#div_tf .tf-cmd, #div_tf .n_cmd', function () {
    tf_preview($(this));
});

$(document).on('click.tf', '#div_tf .tf-pick', function (e) {
    e.preventDefault();
    var $bt = $(this);
    var $input = $bt.attr('data-self') ? $bt.closest('td').find('.n_cmd') : $bt.closest('.input-group').find('input');
    var filter = { type: $bt.attr('data-cmdtype') || 'info' };
    if ($bt.attr('data-cmdsubtype')) {
        filter.subType = $bt.attr('data-cmdsubtype');
    }
    jeedom.cmd.getSelectModal({ cmd: filter }, function (result) {
        if (result && result.human) {
            $input.val(result.human).trigger('change');
        }
    });
});

/* ------------------------------------------------------------------ */
/* Textes explicatifs calculés en direct                                */
/* ------------------------------------------------------------------ */
function tf_live(name, html) {
    $('.tf-live[data-live="' + name + '"]').html(html);
}

function tf_refreshLive() {
    var md = function (mk, dk) { return (parseInt(tf_val(mk), 10) || 1) * 100 + (parseInt(tf_val(dk), 10) || 1); };
    var ws = md('winter_start', 'winter_start_day'), we = md('winter_end', 'winter_end_day');
    var now = new Date(), m = (now.getMonth() + 1) * 100 + now.getDate();
    var winter = ws <= we ? (m >= ws && m <= we) : (m >= ws || m <= we);
    // Prochain changement de saison
    var next = '';
    for (var i = 1; i <= 366; i++) {
        var d = new Date(now.getFullYear(), now.getMonth(), now.getDate() + i), dm = (d.getMonth() + 1) * 100 + d.getDate();
        var w = ws <= we ? (dm >= ws && dm <= we) : (dm >= ws || dm <= we);
        if (w != winter) {
            next = ' — {{passage en}} ' + (w ? '❄️ {{hiver}}' : '☀️ {{été}}') + ' {{le}} ' + d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' });
            break;
        }
    }
    var force = tf_val('season_force');
    if (force == 'winter') { winter = true; }
    if (force == 'summer') { winter = false; }
    $('#tf_season_months').toggle(force == 'auto');
    tf_live('season', '{{Aujourd\'hui}} : <b>' + (winter ? '❄️ {{hiver}}' : '☀️ {{été}}') + '</b>' + (force == 'auto' ? next : ''));

    // Zones VMC
    var h = tf_num('vmc_hyst') || 0;
    // Règles en phrases simples, avec les valeurs saisies.
    var batNeed = Math.max(parseInt(tf_val('b1_min_speed'), 10) || 0, tf_val('b2_enabled') == '1' ? (parseInt(tf_val('b2_min_speed'), 10) || 0) : 0);
    var seasonTxt = function (p, icon, lab) {
        var keep = p == 'w' ? '{{la chaleur}}' : '{{la fraîcheur}}';
        var sp = function (k) { return parseInt(tf_val(p + k), 10) || '?'; };
        var V = function (x) { return '<b>{{vitesse}} ' + x + '</b>'; };
        // Une seule vitesse si les deux cas sont identiques.
        var pair = function (kc, kn, coolTxt) {
            var c = sp(kc), n = sp(kn);
            if (c === n) {
                return V(c);
            }
            return V(c) + ' {{si l\'air du dehors est plus frais}}' + (coolTxt || '') + ', {{sinon}} ' + V(n);
        };
        var t = '<div class="tf-rule">' + icon + ' <b>' + lab + '</b>';
        if (tf_val('vmc_mode_' + p) == 'bypass') {
            t += ' — {{la VMC suit son bypass}} :</div><ul style="margin:0 0 6px 0; padding-left:18px;">'
                + '<li>{{bypass fermé (l\'échangeur garde}} ' + keep + ') : ' + V(sp('_sp_comf_n')) + ' ;</li>'
                + '<li>{{bypass ouvert (la VMC rafraîchit) :}} ' + V(sp('_cool_day')) + (sp('_cool_night') !== sp('_cool_day') ? ', ' + V(sp('_cool_night')) + ' {{en plage de silence}}' : '') + ' ;</li>'
                + (tf_val('bypass_cmd') ? '' : '<li class="tf-ko">{{choisissez la commande « Statut du bypass » plus haut.}}</li>');
        } else {
            var f = tf_num(p + '_t_fresh'), c = tf_num(p + '_t_hot');
            if (f === null || c === null) {
                return '';
            }
            if (c < f) {
                return '<div class="tf-ko">' + icon + ' ' + lab + ' : {{le seuil « trop chaude » doit être au-dessus du seuil « fraîche ».}}</div>';
            }
            t += ' — {{selon la température de la maison}} :</div><ul style="margin:0 0 6px 0; padding-left:18px;">'
                + '<li>{{maison fraîche, sous}} <b>' + tf_fmt(f) + ' °C</b> : ' + pair('_sp_fresh_c', '_sp_fresh_n', ' {{(et pas froid dehors)}}') + ' ;</li>'
                + (c > f ? '<li>{{bonne température, de}} ' + tf_fmt(f + h) + ' {{à}} ' + tf_fmt(c) + ' °C : ' + pair('_sp_comf_c', '_sp_comf_n') + ' ;</li>' : '')
                + '<li>{{maison trop chaude, au-dessus de}} <b>' + tf_fmt(c + h) + ' °C</b>' + (p == 'w' ? ' {{(en hiver, c\'est souvent le soleil par les vitrages)}}' : '') + ' : '
                + (sp('_cool_day') === sp('_sp_hot_n') ? V(sp('_cool_day')) : V(sp('_cool_day')) + ' {{pour rafraîchir si l\'air du dehors est plus frais, sinon}} ' + V(sp('_sp_hot_n')) + ' {{pour garder}} ' + keep) + ' ;</li>';
        }
        if (p == 'w' && tf_val('mod_heat') == '1') {
            t += '<li>{{quand une batterie chauffe : au moins}} ' + V(batNeed || '?') + ', {{puis encore}} ' + (tf_num('heat_postvent') || 0) + ' min {{après son arrêt.}}</li>';
        }
        return t + '</ul>';
    };
    var dl = tf_num('vmc_delta');
    tf_live('zones', seasonTxt('w', '❄️', '{{Hiver}}') + seasonTxt('s', '☀️', '{{Été}}')
        + '<div class="text-muted" style="font-size:.9em;">{{« L\'air du dehors est plus frais » = au moins}} ' + (dl === null ? '?' : tf_fmt(dl)) + ' °C {{de moins que dans la maison. En hiver c\'est presque toujours le cas.}} {{Le CO2, les plages de renouvellement, le boost et le silence passent par-dessus ces règles.}}</div>');
    // Plages de renouvellement actives en hiver
    var wb = Math.max(parseInt(tf_val('w_sp_comf_n'), 10) || 1, parseInt(tf_val('w_sp_fresh_n'), 10) || 1);
    var wins = tf_arraysConfig().vmc_windows.filter(function (w) { return (w.season || 'all') != 'summer' && parseInt(w.speed, 10) > wb; });
    tf_live('windows_winter', wins.length ? '<span class="tf-warn"><i class="fas fa-exclamation-triangle"></i> ' + wins.map(function (w) { return '« ' + tf_esc(w.label || '{{plage}}') + ' » (' + tf_esc(w.start) + '–' + tf_esc(w.end) + ', V' + w.speed + ')'; }).join(', ') + ' {{s\'applique aussi en hiver : la VMC dépassera la vitesse}} ' + wb + ' {{sur ce créneau. Pour l\'éviter, choisissez « Été » dans la colonne « Saison » du tableau juste en dessous.}}</span>' : '');
    // Bypass : valeur actuelle
    if (tf_lastValues.bypass_cmd !== undefined) {
        var bv = String(tf_lastValues.bypass_cmd).trim();
        var bo = String(tf_val('bypass_open_value')).split(/[,;]/).some(function (x) {
            x = x.trim();
            return x !== '' && (x == bv || (!isNaN(parseFloat(x)) && parseFloat(x) == parseFloat(bv)));
        });
        var bTxt = { '0': '{{initialisation}}', '1': '{{en cours d\'ouverture}}', '2': '{{en cours de fermeture}}', '3': '{{ouvert}}', '4': '{{fermé}}', '255': '{{erreur}}' };
        var detail = (tf_val('bypass_open_value').indexOf('3') >= 0 && bTxt[bv]) ? ' — Flair : ' + bTxt[bv] : '';
        tf_live('bypass_now', '{{Actuellement}} : <b>' + (bo ? '{{ouvert}}' : '{{fermé}}') + '</b> (' + tf_esc(bv) + detail + ')');
    } else {
        tf_live('bypass_now', '');
    }
    // Météo : traduction du code.
    if (tf_lastValues.weather_cmd !== undefined) {
        var wv = String(tf_lastValues.weather_cmd).trim();
        if (wv === '' || isNaN(parseFloat(wv))) {
            tf_live('weather_now', '<span class="tf-ko"><i class="fas fa-times-circle"></i> {{« }}' + tf_esc(wv) + '{{ » n\'est pas un numéro : choisissez la commande « id / code condition » de votre plugin météo.}}</span>');
        } else {
            tf_weatherOfficial(parseInt(wv, 10), tf_val('weather_source'), tf_weatherLabel(parseInt(wv, 10), tf_val('weather_source')));
        }
    } else {
        tf_live('weather_now', '');
    }
    // Échangeur : efficacité calculée avec les valeurs du moment.
    var pf = function (k) { var v = parseFloat(String(tf_lastValues[k]).replace(',', '.')); return (tf_lastValues[k] === undefined || isNaN(v)) ? null : v; };
    var hs = pf('hx_supply_cmd'), he = pf('hx_exhaust_cmd'), to = pf('temp_out_cmd');
    var ti = tf_val('vmc_temp_ref') == 'up' && pf('temp_up_cmd') !== null ? pf('temp_up_cmd') : (tf_val('vmc_temp_ref') == 'avg' && pf('temp_up_cmd') !== null && pf('temp_in_cmd') !== null ? (pf('temp_in_cmd') + pf('temp_up_cmd')) / 2 : pf('temp_in_cmd'));
    if (hs === null && he === null) {
        tf_live('hx_now', '<span class="text-muted">{{choisissez la température d\'air soufflé}}</span>');
    } else if (to === null || ti === null) {
        tf_live('hx_now', '<span class="text-muted">{{il faut les thermomètres intérieur et extérieur (onglet Maison)}}</span>');
    } else if (ti - to < 5) {
        tf_live('hx_now', '<span class="text-muted">{{écart dedans / dehors trop faible pour calculer}} (' + tf_fmt(ti - to) + ' °C)</span>');
    } else {
        var parts = [];
        if (hs !== null) { parts.push('<b>' + Math.round(100 * (hs - to) / (ti - to)) + ' %</b> {{côté air soufflé}}'); }
        if (he !== null) { parts.push(Math.round(100 * (ti - he) / (ti - to)) + ' % {{côté air rejeté}}'); }
        tf_live('hx_now', parts.join(' · ') + ' <span class="text-muted">({{dehors}} ' + tf_fmt(to) + ' °C, {{dedans}} ' + tf_fmt(ti) + ' °C ; {{faussé si le bypass est ouvert ou le préchauffage en marche}})</span>');
    }
    if (tf_lastValues.preheat_cmd !== undefined) {
        var pv = String(tf_lastValues.preheat_cmd).trim();
        var pon = String(tf_val('preheat_on_value')).split(/[,;]/).some(function (x) { x = x.trim(); return x !== '' && parseFloat(x) == parseFloat(pv); });
        var pTxt = { '0': '{{initialisation}}', '1': '{{inactif}}', '2': '{{actif}}', '3': '{{mode test}}' };
        tf_live('preheat_now', '{{Actuellement}} : <b>' + (pon ? '{{en marche}}' : '{{arrêté}}') + '</b> (' + tf_esc(pv) + (pTxt[pv] ? ' — Flair : ' + pTxt[pv] : '') + ')');
    } else {
        tf_live('preheat_now', '');
    }
    var delta = tf_num('vmc_delta'), cold = tf_num('vmc_cold_out');
    tf_live('vmc_delta', delta === null ? '' : '{{Ex. dedans 22 °C : plus frais si dehors sous}} ' + tf_fmt(22 - delta) + ' °C');
    tf_live('vmc_cold_out', cold === null ? '' : '{{Froid sous}} ' + tf_fmt(cold) + ' °C, {{ne l\'est plus à}} ' + tf_fmt(cold + h) + ' °C');
    var hi = tf_num('co2_high'), lo = tf_num('co2_low');
    tf_live('co2_low', (hi !== null && lo !== null && lo >= hi) ? '<span class="tf-ko">{{Doit être sous le seuil haut}}</span>' : '');
    var dwell = tf_num('vmc_dwell');
    tf_live('vmc_dwell', dwell === null ? '' : (dwell >= 10 ? '<span class="tf-ok">' + Math.floor(60 / Math.max(dwell, 1)) + ' {{changements par heure au maximum}}</span>' : '<span class="tf-ko">{{Court : risque de faire varier souvent les ventilateurs}}</span>'));

    // Position actuelle -> vitesse
    if (tf_lastValues.vmc_pos_cmd !== undefined) {
        var raw = String(tf_lastValues.vmc_pos_cmd), sp = null;
        for (var i = 1; i <= 4; i++) {
            if (String(tf_val('vmc_p' + i)).trim() !== '' && parseFloat(tf_val('vmc_p' + i)) == parseFloat(raw)) {
                sp = i;
            }
        }
        tf_live('position', sp ? '<span class="tf-ok">{{Valeur lue}} ' + tf_esc(raw) + ' = {{vitesse}} ' + sp + '</span>' : '<span class="tf-ko">{{Valeur lue}} ' + tf_esc(raw) + ' = {{arrêt / inconnue}}</span>');
    } else {
        tf_live('position', '');
    }

    // Conflits silence / vitesse minimum
    var conf = tf_conflicts();
    var dayLbl = function (days) {
        var d = (days || []).map(Number).sort().join(',');
        return d == '1,2,3,4,5,6,7' ? '{{tous les jours}}' : (d == '1,2,3,4,5' ? '{{en semaine}}' : (d == '6,7' ? '{{le week-end}}' : (days || []).map(function (x) { return { 1: 'L', 2: 'Ma', 3: 'Me', 4: 'J', 5: 'V', 6: 'S', 7: 'D' }[x]; }).join(' ')));
    };
    var ch = '';
    conf.forEach(function (c) {
        ch += '<div class="tf-warn"><i class="fas fa-exclamation-triangle"></i> « ' + tf_esc(c.q.label || '{{silence}}') + ' » (' + tf_esc(c.q.start) + ' → ' + tf_esc(c.q.end) + ', ' + dayLbl(c.q.days) + ') : {{VMC limitée à}} ' + c.q.max + ', {{donc}} « ' + tf_esc(tf_batName(c.b)) + ' » ({{vitesse}} ' + c.need + ') <b>{{ne chauffe pas}}</b>.</div>';
    });
    tf_live('quiet_conflicts', ch ? ch + '<div class="text-muted" style="font-size:.9em;">{{Vous serez prévenu si une batterie en a besoin pendant ces plages.}}</div>' : '');
    ['b1', 'b2'].forEach(function (b) {
        var mine = conf.filter(function (c) { return c.b == b; });
        tf_live(b + '_min_speed', mine.length ? '<span class="tf-warn"><i class="fas fa-moon"></i> {{Bloquée pendant}} ' + mine.map(function (c) { return '« ' + tf_esc(c.q.label || c.q.start) + ' »'; }).join(', ') + '</span>' : '<span class="tf-ok">{{Compatible avec le calendrier de silence}}</span>');
    });

    // Thermostat
    var c = tf_num('heat_consigne'), hl = tf_num('heat_hyst_low'), hh = tf_num('heat_hyst_high');
    if (c !== null && hl !== null && hh !== null) {
        tf_live('thermostat', '{{Avec une consigne de}} <b>' + tf_fmt(c) + ' °C</b> : {{la batterie s\'allume sous}} <b>' + tf_fmt(c - hl, 2) + ' °C</b> {{et s\'éteint à}} <b>' + tf_fmt(c + hh, 2) + ' °C</b>'
            + ((hl + hh) < 0.2 ? ' <span class="tf-ko">({{battement très faible}})</span>' : '') + '. {{Entre les deux, elle garde son état.}}');
    }
    var cmin = tf_num('heat_c_min'), cmax = tf_num('heat_c_max');
    tf_live('heat_c_max', (cmin !== null && cmax !== null && cmin >= cmax) ? '<span class="tf-ko">{{Doit être au-dessus du minimum}}</span>' : '');
    var ce = tf_num('heat_eco');
    var ecoTxt = '';
    if (c !== null && ce !== null) {
        if (ce > c - 0.5) {
            ecoTxt = '<span class="tf-ko">{{Doit être au moins 0,5 °C sous la consigne Normal}} (' + tf_fmt(c - 0.5) + ' °C {{maximum}})</span>';
        } else {
            ecoTxt = '<span class="tf-ok">' + tf_fmt(c - ce) + ' °C {{sous Normal}}</span>' + (hl !== null && hh !== null ? ' — {{allume sous}} ' + tf_fmt(ce - hl, 2) + ' °C, {{éteint à}} ' + tf_fmt(ce + hh, 2) + ' °C' : '');
        }
        if (cmin !== null && cmax !== null && (ce < cmin || ce > cmax)) {
            ecoTxt += ' <span class="tf-ko">{{Hors de la plage réglable}}</span>';
        }
    }
    tf_live('heat_eco', ecoTxt);
    tf_live('heat_consigne', (c !== null && cmin !== null && cmax !== null && (c < cmin || c > cmax)) ? '<span class="tf-ko">{{Hors de la plage réglable}}</span>' : '');
    var lx = tf_num('lux_max');
    if (tf_lastValues.lux_cmd !== undefined && lx) {
        var lv = parseFloat(tf_lastValues.lux_cmd);
        tf_live('lux_max', '{{Actuellement}} ' + Math.round(lv) + ' lux' + (lv >= 0.95 * lx ? ' — <b>{{au maximum}}</b>' : ' (' + Math.round(100 * lv / lx) + ' % {{du maximum}})'));
    } else {
        tf_live('lux_max', '');
    }

    ['b1', 'b2'].forEach(function (b) {
        var on = tf_num(b + '_min_on'), off = tf_num(b + '_min_off');
        if (on !== null && off !== null) {
            var cyc = (on + off) > 0 ? Math.floor(60 / (on + off) * 10) / 10 : null;
            tf_live(b + '_min_off', cyc === null ? '<span class="tf-ko">{{Aucune protection anti court-cycle}}</span>' : '{{Au plus}} ' + tf_fmt(cyc) + ' {{cycle(s) par heure}}');
        }
        var p = tf_num(b + '_power');
        tf_live(b + '_power', p ? tf_fmt(p / 1000, 2) + ' {{kWh par heure de chauffe}}' : '');
        $('.tf-bat-panel[data-bat=' + b + ']').css('opacity', tf_val(b + '_enabled') == '1' ? 1 : 0.55);
    });
    tf_batteryNames();

    // Appoint
    if (tf_val('assist_enabled') == '1') {
        tf_live('assist', '{{Si}} « ' + tf_esc(tf_batName('b1')) + ' » {{chauffe depuis}} ' + (tf_val('assist_after') || '?') + ' min {{et qu\'il manque encore}} ' + tf_fmt(tf_num('assist_gap')) + ' °C {{ou plus,}} « ' + tf_esc(tf_batName('b2')) + ' » {{s\'allume en renfort jusqu\'à la consigne.}}');
    } else {
        tf_live('assist', '<i>{{Pas d\'appoint : la batterie 2 ne chauffe que sur ses plages (ou selon « Hors plage »).}}</i>');
    }

    // Délestage
    var lim = tf_num('shed_limit');
    var p1 = tf_num('b1_power') || 0, p2 = tf_num('b2_power') || 0;
    if (tf_lastValues.shed_power_cmd !== undefined && lim) {
        var pw = parseFloat(tf_lastValues.shed_power_cmd);
        tf_live('shed_limit', (pw >= lim ? '<span class="tf-ko">' : '<span class="tf-ok">') + '{{Actuellement}} ' + Math.round(pw) + ' / ' + lim + ' (' + Math.round(100 * pw / lim) + ' %)</span>');
    } else {
        tf_live('shed_limit', '');
    }
    tf_live('shed_margin', lim ? '{{Ex. la batterie 2 repart si la puissance est sous}} ' + Math.round(lim - p2 - (tf_num('shed_margin') || 0)) : '');

    // Filtre
    if (tf_lastValues.filter_cmd !== undefined) {
        var dirty = String(tf_lastValues.filter_cmd).trim() == String(tf_val('filter_value')).trim();
        tf_live('filter_now', dirty ? '<span class="tf-ko">{{Actuellement : à nettoyer}}</span>' : '<span class="tf-ok">{{Actuellement : propre}}</span>');
    } else {
        tf_live('filter_now', '');
    }

    // Modules désactivés
    $('.tf-module').each(function () {
        var on = tf_val($(this).attr('data-module')) == '1';
        $(this).find('.tf-module-disabled').toggle(!on);
        $(this).find('fieldset, .row').css('opacity', on ? 1 : 0.5);
    });
}

$(document).on('change.tf input.tf', '#div_tf .eqLogicAttr[data-l1key=configuration]', function () {
    tf_refreshLive();
    var k = $(this).attr('data-l2key') || '';
    if (/^(b[12]_(name|outside|min_speed|enabled)|heat_consigne|heat_eco)$/.test(k)) {
        tf_renderWeek();
    }
});

function tf_refreshAll() {
    $('#div_tf .tf-cmd').each(function () {
        tf_preview($(this));
    });
    tf_refreshLive();
    tf_renderWeek();
    tf_validateNumbers();
}

/* ------------------------------------------------------------------ */
/* Vérification de la configuration                                     */
/* ------------------------------------------------------------------ */
var TF_MODULES = { general: '{{Maison}}', vmc: 'VMC', heat: '{{Chauffage}}', shed: '{{Délestage}}', filter: '{{Filtre}}', stats: '{{Statistiques}}', notif: '{{Notifications}}' };

function tf_renderCheck(r, $target, withWarnings) {
    var nbErr = 0, nbWarn = 0, html = '';
    $.each(TF_MODULES, function (m) {
        nbErr += (r.errors[m] || []).length;
        nbWarn += withWarnings ? (r.warnings[m] || []).length : 0;
    });
    if (nbErr) {
        html += '<div class="alert alert-danger"><b><i class="fas fa-times-circle"></i> ' + nbErr + ' {{erreur(s) — le module concerné ne pilote rien tant que ce n\'est pas corrigé}}</b><ul style="margin:6px 0 0 0;">';
        $.each(TF_MODULES, function (m, lab) {
            (r.errors[m] || []).forEach(function (e) { html += '<li><b>' + lab + '</b> : ' + tf_esc(e) + '</li>'; });
        });
        html += '</ul></div>';
    }
    if (nbWarn) {
        html += '<div class="alert alert-warning"><b><i class="fas fa-exclamation-triangle"></i> ' + nbWarn + ' {{remarque(s)}}</b><ul style="margin:6px 0 0 0;">';
        $.each(TF_MODULES, function (m, lab) {
            (r.warnings[m] || []).forEach(function (e) { html += '<li><b>' + lab + '</b> : ' + tf_esc(e) + '</li>'; });
        });
        html += '</ul></div>';
    }
    if (!nbErr && !nbWarn) {
        html = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> {{Configuration valide, aucune remarque.}}</div>';
    }
    $target.html(html).toggle(nbErr + nbWarn > 0 || withWarnings);
    return nbErr;
}

function tf_renderSummary(r) {
    var sum = '<legend><i class="fas fa-clipboard-check"></i> {{État des modules}}</legend><table class="table table-condensed">';
    [['vmc', 'fa-fan', 'VMC'], ['heat', 'fa-fire', '{{Chauffage}}'], ['shed', 'fa-bolt', '{{Délestage}}'], ['filter', 'fa-filter', '{{Filtre}}'], ['stats', 'fa-chart-line', '{{Statistiques}}']].forEach(function (x) {
        var on = tf_val('mod_' + x[0]) == '1';
        var st = !on ? '<span class="text-muted">{{désactivé}}</span>' : (r.ok[x[0]] ? '<span class="tf-ok"><i class="fas fa-check-circle"></i> {{actif}}</span>' : '<span class="tf-ko"><i class="fas fa-times-circle"></i> {{arrêté : configuration à corriger}}</span>');
        var wl = r.warnings[x[0]] || [], w = wl.length;
        // Remarques : cliquables, la liste s'ouvre juste sous la ligne.
        sum += '<tr><td><i class="fas ' + x[1] + '"></i> ' + x[2] + '</td><td>' + st + (on && w ? ' <a class="tf-warn tf-sum-warn" data-mod="' + x[0] + '" style="cursor:pointer;text-decoration:underline dotted;">(' + w + ' {{remarque(s)}} <i class="fas fa-caret-down"></i>)</a>' : '') + '</td></tr>';
        if (on && w) {
            sum += '<tr class="tf-sum-list" data-mod="' + x[0] + '" style="display:none;"><td colspan="2"><div class="alert alert-warning" style="margin:0;"><b>{{Remarques}}</b> {{(conseils : le module fonctionne, rien n\'est bloqué)}}<ul style="margin:6px 0 0 0;">'
                + wl.map(function (e) { return '<li>' + tf_esc(e) + '</li>'; }).join('') + '</ul></div></td></tr>';
        }
    });
    $('#tf_summary').html(sum + '</table>');
}

$(document).on('click.tf', '.tf-sum-warn', function () {
    $('#tf_summary .tf-sum-list[data-mod="' + $(this).attr('data-mod') + '"]').toggle();
    $(this).find('i').toggleClass('fa-caret-down fa-caret-up');
});

function tf_checkConfig(fromForm) {
    var data = { action: 'check', id: tf_eqId() };
    if (fromForm) {
        data.config = JSON.stringify(tf_formConfig());
    }
    tf_ajax(data, function (r) {
        if (fromForm) {
            tf_renderCheck(r, $('#tf_check_result'), true);
        }
        tf_renderCheck(r, $('#tf_check_banner'), false); // bandeau : erreurs bloquantes uniquement
        tf_renderSummary(r);
    }, true);
}

$(document).on('click.tf', '#bt_tfCheck', function () {
    tf_checkConfig(true);
});

/* ------------------------------------------------------------------ */
/* Diagnostic                                                            */
/* ------------------------------------------------------------------ */
function tf_stateIcon(s) {
    if (s === true) {
        return '<span class="tf-ok"><i class="fas fa-check-circle"></i></span>';
    }
    if (s === false) {
        return '<span class="text-muted"><i class="far fa-circle"></i></span>';
    }
    return '<span class="text-muted">•</span>';
}

function tf_rowsTable(rows) {
    var h = '<table class="table table-condensed tf-diag-table">';
    (rows || []).forEach(function (r) {
        h += '<tr><td>' + tf_stateIcon(r.state) + ' ' + tf_esc(r.label) + '</td><td>' + tf_esc(r.detail) + '</td></tr>';
    });
    return h + '</table>';
}

function tf_cmdButton(lid, label, cls, value) {
    return '<a class="btn btn-xs ' + (cls || 'btn-default') + ' tf-diag-cmd" data-lid="' + lid + '"' + (value !== undefined ? ' data-value="' + value + '"' : '') + '>' + label + '</a> ';
}

function tf_renderDiag(res) {
    var d = res.diag || {}, rt = res.runtime || {};
    $('#tf_diag_time').html(tf_esc('{{Dernier cycle}} : ' + (d.time || '?') + ' — ' + (d.season == 'winter' ? '❄️ {{hiver}}' : '☀️ {{été}}')) + (d.solar ? ' — <span class="tf-warn">' + tf_esc(d.solar.charAt(0).toUpperCase() + d.solar.slice(1)) + '</span>' : ''));
    var disabled = '<i>{{Module désactivé.}}</i>', invalid = '<div class="tf-ko">{{Module arrêté : configuration invalide (voir le bandeau).}}</div>';

    // VMC
    var h = '';
    if (tf_val('mod_vmc') != '1') {
        h = disabled;
    } else if (!d.vmc) {
        h = invalid;
    } else {
        var v = d.vmc;
        h += '<div style="margin-bottom:6px;">';
        for (var i = 1; i <= 4; i++) {
            h += '<span class="tf-speed-pill' + (v.position == i ? ' on' : '') + (i == 4 ? ' v4' : '') + '">' + i + '</span>';
        }
        h += ' {{Position actuelle}} : <b>' + (v.position === null ? '?' : v.position) + '</b>';
        if (v.target !== null && v.target != v.position) {
            h += ' → {{demandée}} <b>' + v.target + '</b>';
        }
        h += '</div><div style="font-weight:bold;">' + tf_esc(v.reason || '—') + '</div>';
        h += '<div style="margin:6px 0;">{{Mode}} : <b>' + (rt.vmc_auto == 1 ? '{{automatique}}' : '{{manuel}}') + '</b> ';
        h += rt.vmc_auto == 1 ? tf_cmdButton('vmc_auto_off', '{{Passer en manuel}}') : tf_cmdButton('vmc_auto_on', '{{Passer en automatique}}', 'btn-success');
        // Boutons : les lignes du tableau des boosts cochées « Widget ».
        tf_arraysConfig().vmc_boosts.forEach(function (b) {
            if (b.widget != '1' || !b.name || !tf_val('vmc_s' + b.speed + '_cmd')) {
                return;
            }
            h += '<span title="' + tf_esc('{{Vitesse}} ' + b.speed + ' {{pendant}} ' + tf_fmtMin(parseInt(b.minutes, 10) || 0)) + '">'
                + tf_cmdButton(b.id, '<i class="fas fa-rocket"></i> ' + tf_esc(b.name), b.speed == '4' ? 'btn-warning' : 'btn-info') + '</span>';
        });
        if (rt.override) {
            h += tf_cmdButton('override_stop', '{{Annuler le forçage}}', 'btn-danger');
        }
        h += '</div>' + tf_rowsTable(v.rows);
    }
    $('#tf_diag_vmc .tf-body').html(h);

    // Chauffage
    h = '';
    if (tf_val('mod_heat') != '1') {
        h = disabled;
    } else if (!d.heat) {
        h = invalid;
    } else {
        h += '<div style="margin-bottom:6px;">{{Thermostat}} : <b>' + (rt.heat_on == 1 ? 'ON' : 'OFF') + '</b> ';
        h += rt.heat_on == 1 ? tf_cmdButton('heat_off_cmd', '{{Couper}}') : tf_cmdButton('heat_on_cmd', '{{Activer}}', 'btn-success');
        h += ' — {{mode}} <b>' + (rt.mode == 'eco' ? '🌙 ECO' : '☀️ Normal') + '</b> ';
        h += rt.mode == 'eco' ? tf_cmdButton('mode_normal', '{{Passer en Normal}}') : tf_cmdButton('mode_eco', '{{Passer en ECO}}');
        h += ' — {{Normal}} <b>' + tf_fmt(parseFloat(rt.consigne)) + ' °C</b> ';
        h += tf_cmdButton('consigne_set', '−0,5', 'btn-default', parseFloat(rt.consigne) - 0.5) + tf_cmdButton('consigne_set', '+0,5', 'btn-default', parseFloat(rt.consigne) + 0.5);
        h += ' — {{ECO}} <b>' + tf_fmt(parseFloat(rt.eco)) + ' °C</b> ';
        h += tf_cmdButton('consigne_eco_set', '−0,5', 'btn-default', parseFloat(rt.eco) - 0.5) + tf_cmdButton('consigne_eco_set', '+0,5', 'btn-default', parseFloat(rt.eco) + 0.5);
        h += '<br>🛡️ {{Verrou de nuit}} : <b>' + (rt.lock == 1 ? '{{actif}}' : '{{DÉSACTIVÉ}}') + '</b> ' + (rt.lock == 1 ? tf_cmdButton('night_lock_off', '{{Désactiver}}') : tf_cmdButton('night_lock_on', '{{Activer}}', 'btn-success'));
        h += '<br>🔥 {{Chauffage forcé}} : ' + (rt.force ? '<b>' + tf_esc(rt.force) + '</b> ' + tf_cmdButton('heat_force_stop', '{{Arrêter}}') : '{{aucun}} ' + tf_cmdButton('heat_force_b1', '{{Forcer le bas}} ' + (tf_num('heat_force_default') || 60) + ' min', 'btn-danger', tf_num('heat_force_default') || 60));
        if (d.heat.cap) {
            h += '<br><span class="tf-warn"><i class="fas fa-moon"></i> {{Plage de silence}} « ' + tf_esc(d.heat.cap.label) + ' » : VMC max ' + d.heat.cap.max + ' {{jusqu\'à}} ' + tf_esc(d.heat.cap.end) + '</span>';
        }
        if (d.heat.demand) {
            h += '<br>{{Demande à la VMC}} : {{vitesse}} ' + d.heat.demand + ' minimum';
        }
        h += '</div><table class="table table-condensed tf-diag-table">';
        $.each(d.heat.batteries || {}, function (b, x) {
            var since = x.since ? new Date(x.since * 1000) : null;
            h += '<tr><td>' + (x.actual === true ? '🔥' : (x.blocked ? '🌙' : '○')) + ' <b>' + tf_esc(x.name) + '</b>'
                + '<br><small>' + (x.actual === true ? '{{en chauffe}}' : (x.actual === false ? '{{arrêtée}}' : '{{état inconnu}}'))
                + (since ? ' {{depuis}} ' + since.toTimeString().substr(0, 5) : '') + (x.postvent ? ' — {{ventilation après arrêt}}' : '') + '</small></td>'
                + '<td>' + tf_esc(x.reason || '—')
                + '<br><small>{{Référence}} ' + tf_fmt(x.temp) + ' °C' + (x.consigne !== null ? ' — {{consigne}} ' + tf_fmt(x.consigne) + ' °C' : '')
                + ' — {{VMC mini}} ' + x.min_speed + ' — {{aujourd\'hui}} ' + Math.floor(x.today_min / 60) + 'h' + String(x.today_min % 60).padStart(2, '0') + ' (' + tf_fmt(x.kwh, 2) + ' kWh)</small></td></tr>';
        });
        h += '</table>';
        if (d.heat.assist) {
            h += '<div class="alert alert-warning" style="padding:6px;">{{Appoint en cours : la batterie 2 aide la batterie 1.}}</div>';
        }
    }
    $('#tf_diag_heat .tf-body').html(h);

    // Délestage
    h = '';
    if (tf_val('mod_shed') != '1') {
        h = disabled;
    } else if (!d.shed) {
        h = invalid;
    } else {
        var pct = (d.shed.power !== null && d.shed.limit) ? Math.round(100 * d.shed.power / d.shed.limit) : null;
        h += '<div class="tf-big">' + (d.shed.power === null ? '?' : Math.round(d.shed.power)) + ' / ' + Math.round(d.shed.limit || 0) + (pct !== null ? ' <small>(' + pct + ' %)</small>' : '') + '</div>';
        h += (d.shed.active && d.shed.active.length) ? '<div class="tf-ko">⚡ {{Batterie(s) coupée(s)}} : ' + d.shed.active.map(function (b) { return tf_esc(tf_batName(b)); }).join(', ') + '</div>' : '<div class="tf-ok">{{Aucun délestage en cours.}}</div>';
        if (d.shed.reason) {
            h += '<div class="text-muted">' + tf_esc(d.shed.reason) + '</div>';
        }
    }
    $('#tf_diag_shed .tf-body').html(h);

    // Filtre
    h = '';
    if (tf_val('mod_filter') != '1') {
        h = disabled;
    } else if (!d.filter) {
        h = invalid;
    } else {
        h += '<div class="tf-big">' + (d.filter.dirty ? '🔴 {{À nettoyer}}' : '✅ {{Propre}}') + '</div>';
        h += '<div>{{Valeur lue}} : ' + tf_esc(d.filter.value) + (d.filter.dirty && d.filter.dirty_since ? ' — {{depuis le}} ' + d.filter.dirty_since.split('-').reverse().join('/') : '') + '</div>';
    }
    $('#tf_diag_filter .tf-body').html(h);
    // Garde-fous
    if (d.guard && d.guard.rows) {
        $('#tf_diag_guard .tf-body').html((d.guard.ok ? '<div class="tf-ok" style="margin-bottom:6px;"><b>{{Toutes les règles de sécurité sont respectées.}}</b></div>' : '<div class="tf-ko" style="margin-bottom:6px;"><b>{{Une règle de sécurité n\'est pas respectée : voir ci-dessous.}}</b></div>') + tf_rowsTable(d.guard.rows));
    } else {
        $('#tf_diag_guard .tf-body').html('<i>{{Pas encore de cycle.}}</i>');
    }
    $('#tf_filter_cleaned').text(rt.filter_cleaned ? rt.filter_cleaned.split('-').reverse().join('/') : '{{pas encore constaté}}');
}

function tf_loadDiag(run) {
    if (!tf_eqId()) {
        return;
    }
    tf_ajax({ action: 'diagnostic', id: tf_eqId(), run: run ? '1' : '0' }, function (res) {
        tf_renderDiag(res);
    }, !run);
}

$(document).on('click.tf', '#bt_tfDiagTab, a[href="#filtertab"]', function () {
    setTimeout(function () { tf_loadDiag(false); }, 50);
});
$(document).on('click.tf', '#bt_tfRun', function () {
    tf_loadDiag(true);
});
$(document).on('click.tf', '.tf-diag-cmd', function () {
    var data = { action: 'command', id: tf_eqId(), lid: $(this).attr('data-lid') };
    if ($(this).attr('data-value') !== undefined) {
        data.value = $(this).attr('data-value');
    }
    tf_ajax(data, function () {
        tf_loadDiag(false);
    });
});

/* ------------------------------------------------------------------ */
/* Statistiques                                                          */
/* ------------------------------------------------------------------ */
var TF_COLORS = { tb: '#e76f51', th: '#9b5de5', to: '#2a9d8f', b1: '#f4a261', b2: '#c77dff', night: '#264653', day: '#e9c46a', model: '#6c757d', sun: '#f4c430' };

var tf_periodTxt = '';
function tf_chart(id, opts) {
    if (typeof Highcharts === 'undefined' || !document.getElementById(id)) {
        $('#' + id).html('<div class="text-muted" style="padding:20px;">{{Graphique indisponible}}</div>');
        return;
    }
    var base = {
        chart: { height: 460, spacingTop: 10, spacingBottom: 8, backgroundColor: 'transparent', style: { fontSize: '13px' } },
        title: { text: opts.title || null, style: { fontSize: '15px', fontWeight: 'bold' } },
        xAxis: { labels: { style: { fontSize: '12px' } } },
        yAxis: { labels: { style: { fontSize: '12px' } } },
        credits: { enabled: false },
        exporting: { enabled: false },
        legend: { itemStyle: { fontSize: '12px', fontWeight: 'normal' }, margin: 4, padding: 4 },
        tooltip: { shared: false, valueDecimals: 2 },
        plotOptions: { series: { animation: false, marker: { radius: 3 } } }
    };
    // Titre passé en texte : on le range dans l'objet title (sinon il
    // remplaçait l'objet entier et n'était pas affiché).
    // Graphique sans axe de dates : on rappelle la période mesurée sous le titre.
    if (opts.period && tf_periodTxt) {
        opts = $.extend(true, {}, opts);
        var sb = (opts.subtitle && opts.subtitle.text) ? opts.subtitle.text + '<br>' : '';
        opts.subtitle = $.extend(opts.subtitle || {}, { text: sb + '<span style="font-size:11px;opacity:.75;">📅 ' + tf_periodTxt + '</span>' });
        delete opts.period;
    }
    if (typeof opts.title === 'string') {
        base.title.text = opts.title;
        opts = $.extend({}, opts);
        delete opts.title;
    }
    tf_charts.push(Highcharts.chart(id, $.extend(true, base, opts)));
}

function tf_noData(id, msg) {
    $('#' + id).html('<div class="text-muted" style="padding:40px 12px; text-align:center;">' + msg + '</div>');
}

function tf_kpi(v, label, title) {
    return '<div class="tf-kpi"' + (title ? ' title="' + tf_esc(title) + '"' : '') + '><div class="v">' + v + '</div><div class="l">' + label + '</div></div>';
}

function tf_renderStats(a) {
    tf_charts.forEach(function (c) { try { c.destroy(); } catch (e) { } });
    tf_charts = [];
    var q = a.quality || {}, st = q.storage || {};
    $('#tf_stats_quality').html('{{Données}} : ' + (q.samples || 0) + ' {{points sur la période}} — ' + (st.days || 0) + ' {{jour(s) enregistré(s)}}' + (st.first ? ' ({{du}} ' + st.first.split('-').reverse().join('/') + ' {{au}} ' + st.last.split('-').reverse().join('/') + ', ' + st.size_kb + ' Ko)' : '')
        + (q.has_out ? '' : ' — <span class="tf-warn">{{sans sonde extérieure : analyse limitée}}</span>')
        + (q.has_sun ? ' — ☀️ {{maximum du capteur}} : ' + q.lux_max + ' lux' : ''));
    if (a.empty) {
        $('#tf_kpis').html('<div class="text-muted">{{Pas encore assez de données. Le plugin enregistre un point toutes les 5 minutes ; vous pouvez aussi importer l\'historique Jeedom ci-dessus.}}</div>');
        $('#tf_insights_card, #tf_pred_card, #tf_modes_card, #tf_advice_card').hide();
        ['tf_ch_week', 'tf_ch_daily', 'tf_ch_heat', 'tf_ch_cool', 'tf_ch_profile', 'tf_ch_speed', 'tf_ch_sun', 'tf_ch_noheat', 'tf_ch_signature', 'tf_ch_comfort', 'tf_ch_air', 'tf_ch_hx'].forEach(function (id) { tf_noData(id, '—'); });
        return;
    }
    var pr = a.period || {};
    tf_periodTxt = pr.data_from ? '{{Mesuré du}} ' + pr.data_from.split('-').reverse().slice(0, 2).join('/') + ' {{au}} ' + pr.data_to.split('-').reverse().slice(0, 2).join('/') + ' (' + pr.data_days + ' ' + (pr.data_days > 1 ? '{{jours de données}}' : '{{jour de données}}') + ')' : '';
    var k = a.kpi, n = a.names;
    var hk = '';
    hk += tf_kpi(tf_fmt(k.kwh_total, 1) + ' kWh', '{{chauffage sur la période}}');
    hk += tf_kpi(tf_fmt(k.kwh_per_day, 2) + ' kWh', '{{par jour en moyenne}}');
    hk += tf_kpi(tf_fmt(k.b1_hours, 1) + ' h', '« ' + tf_esc(n.b1) + ' » {{en marche}}');
    hk += tf_kpi(tf_fmt(k.b2_hours, 1) + ' h', '« ' + tf_esc(n.b2) + ' » {{en marche}}');
    if (k.kwh_per_dju !== null) { hk += tf_kpi(tf_fmt(k.kwh_per_dju, 2), '{{kWh par degré-jour}}', '{{Consommation ramenée à la rigueur du climat : comparable d\'une période à l\'autre.}}'); }
    if (k.tau !== null) { hk += tf_kpi(k.tau + ' h', '{{constante de temps}}', '{{Temps caractéristique de refroidissement de la maison.}}'); }
    if (k.k !== null) { hk += tf_kpi(tf_fmt(k.k * 10, 2) + ' °C/h', '{{perte pour 10 °C d\'écart}}'); }
    if (k.gain_b1 !== null) { hk += tf_kpi('+' + tf_fmt(k.gain_b1, 2) + ' °C/h', '{{apport de}} « ' + tf_esc(n.b1) + ' »'); }
    if (k.gain_b2 !== null) { hk += tf_kpi('+' + tf_fmt(k.gain_b2, 2) + ' °C/h', '{{apport de}} « ' + tf_esc(n.b2) + ' »'); }
    if (k.night_drop !== null) { hk += tf_kpi('−' + tf_fmt(k.night_drop, 2) + ' °C', '{{par nuit sans chauffe}} (' + k.nights + ')'); }
    if (k.solar_h) { hk += tf_kpi('☀️ ' + tf_fmt(k.solar_h, 1) + ' h', '{{de surchauffe due au soleil}} (' + k.solar_days + ' {{j}})', '{{Bas au moins 2 °C au-dessus de la consigne Normal et 10 °C au-dessus du dehors, en journée, sans chauffage depuis 1 h. Maximum :}} ' + tf_fmt(k.solar_max, 1) + ' °C {{pour}} ' + tf_fmt(k.solar_to, 1) + ' °C {{dehors.}}'); }
    if (k.sky_days) { hk += tf_kpi('<span style="font-size:.85em;">☀️' + k.sky_days.sun + ' ⛅' + k.sky_days.mixed + ' ☁️' + k.sky_days.grey + '</span>', '{{journées ensoleillées · mitigées · maussades}}', '{{Selon le temps passé au maximum du capteur entre 9h et 17h : 60 % et plus = ensoleillée, 15 à 60 % = mitigée, moins = maussade.}}'); }
    if (k.sun_coef !== null) { hk += tf_kpi((k.sun_coef >= 0 ? '+' : '') + tf_fmt(k.sun_coef, 2) + ' °C/h', '{{apport d\'une journée ensoleillée (10h-16h, bas)}}'); }
    if (k.sun_min_b1 !== null) { hk += tf_kpi(k.sun_min_b1 + ' min', '{{de}} « ' + tf_esc(n.b1) + ' » {{pour 1 h de soleil}}', '{{Durée de chauffe de la batterie qui apporterait autant qu\'une heure de plein soleil.}}'); }
    if (k.bypass_open_h !== null) { hk += tf_kpi(tf_fmt(k.bypass_open_h, 1) + ' h', '{{bypass ouvert}}'); }
    if (k.kb !== null && k.k) { hk += tf_kpi('+' + Math.round(100 * k.kb / k.k) + ' %', '{{de pertes bypass ouvert}}', '{{Pertes de chaleur en plus quand le bypass est ouvert (air neuf sans échangeur).}}'); }
    if (k.bypass_heat_min) { hk += tf_kpi('⚠️ ' + k.bypass_heat_min + ' min', '{{de chauffe bypass ouvert}}', '{{Chaleur perdue : le bypass devrait rester fermé quand on chauffe.}}'); }
    if (k.sun_kwh !== null) { hk += tf_kpi(tf_fmt(k.sun_kwh, 1) + ' kWh', '{{offerts par le soleil}}', '{{Équivalent en énergie de chauffage des apports solaires sur la période.}}'); }
    $('#tf_kpis').html(hk);
    tf_renderAdvice(a.advice || null, n);
    if (a.insights && a.insights.length) {
        $('#tf_insights').html(a.insights.map(function (t) { return '<li>' + tf_esc(t) + '</li>'; }).join(''));
        $('#tf_insights_card').show();
    } else {
        $('#tf_insights_card').hide();
    }

    // 1) Semaine
    var w = a.week || { curves: {}, bands: {} };
    var bands = [];
    ['b1', 'b2'].forEach(function (b) {
        (w.bands[b] || []).forEach(function (x) {
            bands.push({ from: x[0], to: x[1], color: b == 'b1' ? 'rgba(244,162,97,.25)' : 'rgba(199,125,255,.2)' });
        });
    });
    var ser = [{ name: '{{Bas}}', data: w.curves.tb || [], color: TF_COLORS.tb }];
    if ((w.curves.th || []).length) { ser.push({ name: '{{Haut}}', data: w.curves.th, color: TF_COLORS.th }); }
    if ((w.curves.to || []).length) { ser.push({ name: '{{Extérieur}}', data: w.curves.to, color: TF_COLORS.to, yAxis: 1, dashStyle: 'ShortDot' }); }
    if ((w.curves.sun || []).length) { ser.push({ type: 'area', name: '{{Lumière}} %', data: w.curves.sun, color: TF_COLORS.sun, yAxis: 2, fillOpacity: 0.15, lineWidth: 0, tooltip: { valueSuffix: ' %' } }); }
    tf_chart('tf_ch_week', {
        title: '{{Températures (14 jours)}}', chart: { zoomType: 'x' },
        xAxis: { type: 'datetime', plotBands: bands },
        yAxis: [{ title: { text: null }, labels: { format: '{value}°' } }, { title: { text: null }, labels: { format: '{value}°' }, opposite: true }, { visible: false, min: 0, max: 100 }],
        tooltip: { valueSuffix: ' °C', xDateFormat: '%a %d/%m %H:%M' },
        plotOptions: { series: { marker: { enabled: false }, lineWidth: 1.5 } },
        series: ser
    });

    // 2) Énergie par jour
    var days = (a.daily || []).map(function (d) { var p = d.date.split('-'); return Date.UTC(+p[0], p[1] - 1, +p[2]); });
    var dser = [
        { type: 'column', name: tf_esc(n.b1) + ' (kWh)', data: (a.daily || []).map(function (d, i) { return [days[i], d.b1_kwh]; }), color: TF_COLORS.b1, stack: 'e' },
        { type: 'column', name: tf_esc(n.b2) + ' (kWh)', data: (a.daily || []).map(function (d, i) { return [days[i], d.b2_kwh]; }), color: TF_COLORS.b2, stack: 'e' }
    ];
    if (q.has_out) {
        dser.push({ type: 'spline', name: '{{T ext moyenne}}', yAxis: 1, data: (a.daily || []).map(function (d, i) { return [days[i], d.to_avg]; }), color: TF_COLORS.to, marker: { enabled: false } });
    }
    if (q.has_sun) {
        dser.push({ type: 'spline', name: '{{h au max de lumière}}', yAxis: 2, data: (a.daily || []).map(function (d, i) { return [days[i], d.sun_hours]; }), color: TF_COLORS.sun, dashStyle: 'ShortDash', marker: { enabled: false }, tooltip: { valueSuffix: ' h' } });
    }
    tf_chart('tf_ch_daily', {
        title: '{{Énergie par jour}}',
        xAxis: { type: 'datetime' },
        yAxis: [{ title: { text: null }, labels: { format: '{value} kWh' } }, { title: { text: null }, labels: { format: '{value}°' }, opposite: true }, { visible: false, min: 0 }],
        plotOptions: { column: { stacking: 'normal', borderWidth: 0, pointPadding: 0.05, groupPadding: 0.05 } },
        tooltip: { xDateFormat: '%a %d/%m' },
        series: dser
    });

    // 3) Montée en température selon l'extérieur
    var he = a.heat_episodes || {};
    var hser = [];
    ['b1', 'b2'].forEach(function (b) {
        var pts = (he[b] || []).filter(function (e) { return e.rate !== null && (q.has_out ? e.to !== null : true); })
            .map(function (e) { return { x: q.has_out ? e.to : e.hour, y: e.rate, name: new Date(e.start * 1000).toLocaleString().substr(0, 16) + ' — ' + e.min + ' min' }; });
        if (pts.length) {
            hser.push({ type: 'scatter', name: tf_esc(n[b]), data: pts, color: TF_COLORS[b] });
        }
    });
    (a.predictions && q.has_out ? ['b1', 'b2'] : []).forEach(function (b) {
        var pr = a.predictions[b];
        if (pr && pr.length) {
            hser.push({ type: 'line', name: '{{modèle}} ' + tf_esc(n[b]), data: pr.map(function (p) { return [p.to, p.rate_on]; }), color: TF_COLORS[b], dashStyle: 'Dash', marker: { enabled: false }, enableMouseTracking: false });
        }
    });
    if (hser.length) {
        tf_chart('tf_ch_heat', { period: true,
            title: '{{Montée en température pendant la chauffe}}',
            xAxis: { title: { text: q.has_out ? '{{T extérieure (°C)}}' : '{{heure de début}}' } },
            yAxis: { title: { text: '°C/h' } },
            tooltip: { pointFormat: '{point.name}<br>{point.x} → <b>{point.y:.2f} °C/h</b>' },
            series: hser
        });
    } else {
        tf_noData('tf_ch_heat', '{{Pas encore de période où une batterie a chauffé seule au moins 20 minutes.}}');
    }

    // 4) Refroidissement sans chauffage
    // Hors tranches où le plugin évacuait une surchauffe et heures de soleil :
    // ce ne sont pas des pertes de la maison.
    var ceAll = (a.cool_episodes || []).filter(function (e) { return e.rate !== null; });
    var ce = ceAll.filter(function (e) { return !e.evac && !e.sunlit; });
    var ceOut = ceAll.length - ce.length;
    if (ce.length) {
        var mk = function (night, sunTest) {
            return ce.filter(function (e) { return e.night == night && (q.has_out ? e.gap !== null : true) && (!sunTest || sunTest(e)); })
                .map(function (e) { return { x: q.has_out ? e.gap : e.hour, y: -e.rate, name: new Date(e.start * 1000).toLocaleString().substr(0, 16) + ' — VMC ' + (e.sp === null ? '?' : e.sp) + (e.sun !== null && e.sun !== undefined ? ' — {{soleil}} ' + e.sun + ' %' : '') }; });
        };
        var cser = [{ type: 'scatter', name: '{{nuit}}', data: mk(true), color: TF_COLORS.night }];
        if (q.has_sun) {
            cser.push({ type: 'scatter', name: '{{jour ensoleillé (matin, soir)}}', data: mk(false, function (e) { return e.sky == 'sun'; }), color: TF_COLORS.sun });
            cser.push({ type: 'scatter', name: '{{jour mitigé ou maussade}}', data: mk(false, function (e) { return e.sky != 'sun'; }), color: TF_COLORS.model });
        } else {
            cser.push({ type: 'scatter', name: '{{jour}}', data: mk(false), color: TF_COLORS.day });
        }
        var mb = a.models && a.models.bas;
        if (q.has_out && mb && mb.ok && mb.k !== null) {
            var gaps = ce.map(function (e) { return e.gap; }).filter(function (g) { return g !== null; });
            var gmax = Math.max.apply(null, gaps.concat([5]));
            cser.push({ type: 'line', name: '{{modèle (nuit)}}', data: [[0, -mb.base], [gmax, mb.k * gmax - mb.base]], color: TF_COLORS.model, dashStyle: 'Dash', marker: { enabled: false }, enableMouseTracking: false });
        }
        tf_chart('tf_ch_cool', { period: true,
            title: '{{Refroidissement sans chauffage}}',
            subtitle: { text: ceOut ? ceOut + ' {{tranches écartées : soleil entre 9h et 17h (il réchauffe en même temps) ou plugin en train d\'évacuer une surchauffe (bypass ouvert, VMC accélérée). Ce ne sont pas des pertes de la maison.}}' : '' },
            xAxis: { title: { text: q.has_out ? '{{écart intérieur − extérieur (°C)}}' : '{{heure}}' } },
            yAxis: { title: { text: '{{°C perdus / h}}' } },
            tooltip: { pointFormat: '{point.name}<br>{point.x} → <b>{point.y:.3f} °C/h</b>' },
            series: cser
        });
    } else {
        tf_noData('tf_ch_cool', '{{Pas encore de période d\'au moins 2 h sans chauffage.}}');
    }

    // 5) Journée type
    var pr = a.profile || [];
    var hrs = pr.map(function (p) { return p.h + 'h'; });
    var pser = [
        { type: 'column', name: '% ' + tf_esc(n.b1), data: pr.map(function (p) { return p.b1; }), color: TF_COLORS.b1, yAxis: 1, opacity: 0.6 },
        { type: 'column', name: '% ' + tf_esc(n.b2), data: pr.map(function (p) { return p.b2; }), color: TF_COLORS.b2, yAxis: 1, opacity: 0.6 },
        { type: 'spline', name: '{{Bas}}', data: pr.map(function (p) { return p.tb; }), color: TF_COLORS.tb, marker: { enabled: false } }
    ];
    if (q.has_up) { pser.push({ type: 'spline', name: '{{Haut}}', data: pr.map(function (p) { return p.th; }), color: TF_COLORS.th, marker: { enabled: false } }); }
    if (q.has_sun) { pser.push({ type: 'areaspline', name: '% {{lumière}}', data: pr.map(function (p) { return p.sun; }), color: TF_COLORS.sun, yAxis: 1, fillOpacity: 0.15, lineWidth: 1, marker: { enabled: false } }); }
    tf_chart('tf_ch_profile', { period: true,
        title: '{{Journée type}}',
        xAxis: { categories: hrs, labels: { step: 3 } },
        yAxis: [{ title: { text: null }, labels: { format: '{value}°' } }, { title: { text: null }, labels: { format: '{value} %' }, opposite: true, max: 100 }],
        plotOptions: { column: { borderWidth: 0, grouping: true, pointPadding: 0, groupPadding: 0.1 } },
        tooltip: { shared: true, valueDecimals: 1 },
        series: pser
    });

    // 6) Effet de la vitesse de VMC
    var se = a.speed_effect || [];
    if (se.length) {
        tf_chart('tf_ch_speed', { period: true,
            title: '{{Pertes selon la vitesse de VMC}}',
            subtitle: { text: '{{Refroidissement mesuré les nuits sans chauffage, à vitesse constante (médiane), pour 10 °C d\'écart dedans / dehors.}}' },
            xAxis: { categories: se.map(function (s) { return '{{Vitesse}} ' + s.speed + (s.n ? '<br><span style="font-size:10px;opacity:.75;">' + s.n + ' {{tranches de nuit}}</span>' : ''); }), labels: { useHTML: true } },
            yAxis: { title: { text: '{{°C/h pour 10 °C d\'écart}}' }, min: 0 },
            legend: { enabled: false },
            tooltip: { valueDecimals: 3, valueSuffix: ' °C/h' },
            series: [{ type: 'column', name: '{{Perte}}', data: se.map(function (s) { return s.loss10; }), color: TF_COLORS.to, borderWidth: 0 }]
        });
    } else {
        tf_noData('tf_ch_speed', q.has_out ? '{{Pas encore assez de données.}}' : '{{Nécessite une sonde extérieure.}}');
    }

    // 7) Ce que le soleil apporte (selon le type de journée)
    var sfx = a.sun_effect || null;
    if (sfx && sfx.some(function (x) { return x.tb !== null; })) {
        var skyName = { sun: '☀️ {{Journées ensoleillées}}', mixed: '⛅ {{Journées mitigées}}', grey: '☁️ {{Journées maussades}}' };
        var cats = sfx.map(function (x) {
            return (skyName[x.type] || x.label) + '<br><span style="font-size:10px;opacity:.75;">' + (x.method == 'days' ? x.days + (x.days > 1 ? ' {{j mesurés}}' : ' {{j mesuré}}') + (x.days_all > x.days ? ' / ' + x.days_all : '') : x.days + ' {{j}} ({{tranches}})') + (x.to !== null && x.to !== undefined ? ' · {{dehors}} ' + tf_fmt(x.to, 0) + ' °C' : '') + (x.sp !== null && x.sp !== undefined ? ' · VMC ' + tf_fmt(x.sp, 1) : '') + '</span>'
                + (x.tb_sd !== null && x.tb_sd !== undefined ? '<br><span style="font-size:10px;opacity:.75;">{{d\'un jour à l\'autre}} ± ' + tf_fmt(x.tb_sd * 6, 1) + ' °C</span>' : '');
        });
        // Écart ensoleillé / mitigé plus petit que la variation d'un jour à l'autre ?
        var sOf = {}; sfx.forEach(function (x) { sOf[x.type] = x; });
        var sunNoise = sOf.sun && sOf.mixed && sOf.sun.tb !== null && sOf.mixed.tb !== null && sOf.sun.tb < sOf.mixed.tb && sOf.sun.tb_sd != null && sOf.mixed.tb_sd != null
            && (sOf.mixed.tb - sOf.sun.tb) < Math.max(sOf.sun.tb_sd, sOf.mixed.tb_sd);
        var colOf = { sun: '#f4c430', mixed: '#a8b5c2', grey: '#6c757d' };
        var pt = function (x, k) { return x[k] === null ? null : { y: Math.round(x[k] * 60) / 10, color: colOf[x.type], opacity: x.days < 3 ? 0.45 : 1 }; };
        var sser = [{ type: 'column', name: '{{Bas}}', data: sfx.map(function (x) { return pt(x, 'tb'); }), borderWidth: 0, dataLabels: { enabled: true, formatter: function () { return (this.y > 0 ? '+' : '') + Highcharts.numberFormat(this.y, 1) + ' °C'; } } }];
        if (q.has_up) { sser.push({ type: 'column', name: '{{Haut}}', data: sfx.map(function (x) { return x.th === null ? null : { y: Math.round(x.th * 60) / 10, color: TF_COLORS.th, opacity: x.days < 3 ? 0.45 : 0.8 }; }), borderWidth: 0, dataLabels: { enabled: true, formatter: function () { return (this.y > 0 ? '+' : '') + Highcharts.numberFormat(this.y, 1) + ' °C'; } } }); }
        tf_chart('tf_ch_sun', { period: true,
            title: '{{Ce que le soleil apporte : hausse de température entre 10h et 16h, chauffage éteint}}',
            subtitle: { text: (q.has_up ? '{{Barre de couleur : le bas ; barre violette : le haut.}} ' : '') + '{{Barres pâles : moins de 3 jours de ce type, à confirmer.}} {{Mesure arrêtée dès que la VMC accélère ou que le bypass s\'ouvre pour évacuer la chaleur.}}'
                + (sunNoise ? '<br><span style="color:#b5652d;">{{Écart ensoleillé / mitigé plus petit que la variation d\'un jour à l\'autre : pas encore significatif.}}</span>' : '') },
            xAxis: { categories: cats, labels: { useHTML: true } },
            yAxis: { title: { text: '{{°C gagnés en 6 h}}' }, plotLines: [{ value: 0, width: 1, color: '#888' }] },
            legend: { enabled: false },
            tooltip: { shared: true, valueDecimals: 1, valueSuffix: ' °C' },
            plotOptions: { column: { pointPadding: 0.05, groupPadding: 0.15 } },
            series: sser
        });
    } else {
        tf_noData('tf_ch_sun', q.has_sun ? '{{Pas encore assez de journées de chaque type.}}' : '{{Nécessite un capteur de luminosité extérieur ou les conditions météo.}}');
    }

    // 7 bis) Journées sans chauffage : gain du matin à l'après-midi
    var nh = a.noheat || null;
    if (nh && nh.days && nh.days.length) {
        var skyC = { sun: '#f4c430', mixed: '#a8b5c2', grey: '#6c757d' };
        var skyN = { sun: '☀️', mixed: '⛅', grey: '☁️' };
        var nd = nh.days.slice(-30);
        var subp = [];
        ['sun', 'mixed', 'grey'].forEach(function (t) {
            var b = nh.by_sky[t];
            if (b && b.days) { subp.push(skyN[t] + ' +' + tf_fmt(b.gain, 1) + ' °C (' + b.days + ' {{j}})'); }
        });
        // Place disponible par journée : en dessous de ~34 px, la date n'affiche le mois qu'au changement de mois.
        var nhW = ($('#tf_ch_noheat').width() || 800) - 90;
        var nhCompact = nhW / nd.length < 34;
        var nser = [{ type: 'column', name: '{{Bas}}', data: nd.map(function (d) { return { y: d.gain, color: skyC[d.sky] || '#c0c0c0' }; }), borderWidth: 0, dataLabels: { enabled: true, crop: false, style: { fontSize: '10px', textOutline: 'none' }, formatter: function () { return '+' + Highcharts.numberFormat(this.y, 1); } } }];
        if (nd.some(function (d) { return d.gain_up !== null; })) {
            nser.push({ type: 'column', name: '{{Haut}}', data: nd.map(function (d) { return d.gain_up; }), color: TF_COLORS.th, borderWidth: 0, opacity: 0.7 });
        }
        var nhCats = nd.map(function (d, i) {
            var p = d.date.split('-');
            var prevM = i > 0 ? nd[i - 1].date.split('-')[1] : null;
            // Écran étroit : jour seul, en gras au premier jour et à chaque changement de mois (période en titre d'axe).
            var dt = !nhCompact ? p[2] + '/' + p[1] : (prevM !== p[1] ? '<b>' + p[2] + '</b>' : p[2]);
            // 3 lignes empilées (emoji / date / T ext), sans rotation : plus de chevauchement.
            return '<div style="text-align:center;line-height:13px;">' + (skyN[d.sky] || '&nbsp;') + '<br><span style="font-size:10px;">' + dt + '</span>' + (d.to !== null ? '<br><span style="font-size:10px;opacity:.65;">' + tf_fmt(d.to, 0) + '°</span>' : '') + '</div>';
        });
        tf_chart('tf_ch_noheat', {
            title: '{{Journées sans chauffage : hausse de température dans la journée}}',
            subtitle: { text: '{{Moyennes (bas)}} : ' + (subp.join(' · ') || '—') + ' — {{couleur = temps du jour}}' + (nser.length > 1 ? ', {{barre violette = haut}}' : '') + ' — {{sous chaque barre : temps, date, T extérieure moyenne}}' },
            xAxis: { categories: nhCats, title: nhCompact ? { text: nd[0].date.split('-').reverse().slice(0, 2).join('/') + ' → ' + nd[nd.length - 1].date.split('-').reverse().slice(0, 2).join('/') + ' {{(en gras : début de mois)}}', style: { fontSize: '10px' } } : undefined, labels: { useHTML: true, rotation: 0, autoRotation: false, step: 1, style: { textOverflow: 'none', whiteSpace: 'nowrap' } } },
            plotOptions: { column: { groupPadding: 0.1, pointPadding: 0.02 } },
            yAxis: { title: { text: '{{°C gagnés (minimum du matin → maximum de l\'après-midi)}}' }, min: 0 },
            tooltip: { shared: true, useHTML: true, valueDecimals: 1, valueSuffix: ' °C' },
            legend: { enabled: false },
            series: nser
        });
    } else {
        tf_noData('tf_ch_noheat', '{{Aucune journée sans chauffage (4h-18h) sur la période pour l\'instant : elles apparaîtront en mi-saison ou quand le soleil suffit.}}');
    }

    // 8) Signature énergétique
    var sg = a.signature || null;
    if (sg && sg.points && sg.points.length >= 3) {
        var skyCol = { sun: '#f4c430', mixed: '#a8b5c2', grey: '#6c757d' };
        var sgs = [{ type: 'scatter', name: '{{Jours}}', data: sg.points.map(function (p) { return { x: p.to, y: p.kwh, name: p.date, color: skyCol[p.sky] || TF_COLORS.b1 }; }), marker: { radius: 5 } }];
        var sub = '';
        if (sg.ua !== null) {
            var tmin = Math.min.apply(null, sg.points.map(function (p) { return p.to; }));
            sgs.push({ type: 'line', name: '{{Tendance}}', data: [[Math.floor(tmin) - 1, Math.max(0, sg.slope * (Math.floor(tmin) - 1) + sg.intercept)], [sg.balance, 0]], color: TF_COLORS.model, dashStyle: 'Dash', marker: { enabled: false }, enableMouseTracking: false });
            sub = '{{Déperditions}} ≈ <b>' + sg.ua + ' W/K</b> · {{plus de chauffage au-dessus de}} <b>' + tf_fmt(sg.balance, 1) + ' °C</b> {{dehors}} · {{fiabilité}} ' + Math.round((sg.r2 || 0) * 100) + ' %';
        } else {
            sub = '{{Il faut au moins 5 jours chauffés et assez d\'écart de température extérieure pour tracer la tendance.}}';
        }
        tf_chart('tf_ch_signature', { period: true,
            title: '{{Signature énergétique}}', subtitle: { text: sub, useHTML: true },
            xAxis: { title: { text: '{{T extérieure moyenne du jour (°C)}}' } },
            yAxis: { title: { text: '{{kWh de chauffage par jour}}' }, min: 0 },
            legend: { enabled: false },
            tooltip: { pointFormat: '{point.name} : <b>{point.y:.1f} kWh</b> à {point.x:.1f} °C' },
            series: sgs
        });
    } else {
        tf_noData('tf_ch_signature', '{{Il faut quelques jours complets avec une sonde extérieure.}}');
    }

    // 9) Confort : répartition des températures
    var cf = a.comfort || {};
    if (cf.tb) {
        var cser = [{ type: 'column', name: '{{Bas}}', data: cf.tb.hist, color: TF_COLORS.tb }];
        if (cf.th) { cser.push({ type: 'column', name: '{{Haut}}', data: cf.th.hist, color: TF_COLORS.th }); }
        var csub = '{{Bas}} : ' + tf_fmt(cf.tb.ok, 0) + ' % {{entre 20 et 25 °C}}, ' + tf_fmt(cf.tb.lt20, 0) + ' % {{sous 20 °C}}, ' + tf_fmt(cf.tb.gt25, 0) + ' % {{au-dessus de 25 °C}}'
            + (cf.th ? ' · {{Haut}} : ' + tf_fmt(cf.th.ok, 0) + ' / ' + tf_fmt(cf.th.lt20, 0) + ' / ' + tf_fmt(cf.th.gt25, 0) + ' %' : '');
        tf_chart('tf_ch_comfort', { period: true,
            title: '{{Confort : temps passé à chaque température}}', subtitle: { text: csub },
            xAxis: { title: { text: '°C' }, plotBands: [{ from: 19.75, to: 25.25, color: 'rgba(42,157,143,.10)', label: { text: '{{confort}}', style: { color: '#2a9d8f' } } }], plotLines: [{ value: 25.25, color: '#e76f51', width: 2, dashStyle: 'Dash', label: { text: '25 °C' } }] },
            yAxis: { title: { text: '% {{du temps}}' }, min: 0 },
            tooltip: { shared: true, valueSuffix: ' %', valueDecimals: 1, headerFormat: '<b>{point.key} °C</b><br>' },
            plotOptions: { column: { pointPadding: 0.02, groupPadding: 0.08, borderWidth: 0, pointRange: 0.5 } },
            series: cser
        });
    } else {
        tf_noData('tf_ch_comfort', '{{Pas encore assez de données.}}');
    }

    // 10) Ventilation et qualité de l'air
    var air = a.air || {};
    if (air.speed || air.co2) {
        var aser = [];
        var acats = [];
        if (air.speed) {
            air.speed.forEach(function (x) { acats.push('{{Vitesse}} ' + x.speed); });
        }
        if (air.co2) {
            air.co2.forEach(function (x) { acats.push('CO2 ' + x.label); });
        }
        var sp = (air.speed || []).map(function (x) { return { y: x.pct, color: ['#8ecae6', '#219ebc', '#126782', '#d9534f'][x.speed - 1] }; });
        var co = (air.co2 || []).map(function (x, i) { return { y: x.pct, color: ['#2a9d8f', '#8ab17d', '#e9c46a', '#e76f51'][i] }; });
        aser.push({ type: 'column', name: '% {{du temps}}', data: sp.concat(co), borderWidth: 0, dataLabels: { enabled: true, format: '{y:.0f} %' } });
        tf_chart('tf_ch_air', { period: true,
            title: '{{Ventilation et qualité de l\'air}}', subtitle: { text: air.co2_avg ? '{{CO2 moyen}} : ' + air.co2_avg + ' ppm' : '' },
            xAxis: { categories: acats },
            yAxis: { title: { text: '% {{du temps}}' }, min: 0, max: 100 },
            legend: { enabled: false },
            tooltip: { valueSuffix: ' %', valueDecimals: 1 },
            series: aser
        });
    } else {
        tf_noData('tf_ch_air', '{{Pas encore assez de données.}}');
    }

    // 11) Échangeur et préchauffage
    var dly = a.daily || [];
    var efd = dly.filter(function (d) { return d.ef_avg !== null && d.ef_avg !== undefined; });
    var phd = dly.filter(function (d) { return d.ph_min !== null && d.ph_min !== undefined; });
    if (efd.length || phd.length) {
        var dts = function (d) { var p = d.date.split('-'); return Date.UTC(+p[0], p[1] - 1, +p[2]); };
        var hser = [];
        if (phd.length) { hser.push({ type: 'column', name: '{{Préchauffage (min / jour)}}', yAxis: 1, data: phd.map(function (d) { return [dts(d), d.ph_min]; }), color: '#457b9d', borderWidth: 0, opacity: 0.6 }); }
        if (efd.length) { hser.push({ type: 'line', name: '{{Efficacité de l\'échangeur (%)}}', data: efd.map(function (d) { return [dts(d), d.ef_avg]; }), color: '#2a9d8f', lineWidth: 3, marker: { enabled: true, radius: 4 } }); }
        tf_chart('tf_ch_hx', {
            title: '{{Échangeur et préchauffage antigel}}',
            xAxis: { type: 'datetime' },
            yAxis: [{ title: { text: '%' }, min: 50, max: 100 }, { title: { text: 'min' }, min: 0, opposite: true }],
            tooltip: { shared: true, xDateFormat: '%a %d/%m', valueDecimals: 0 },
            series: hser
        });
    } else {
        tf_noData('tf_ch_hx', '{{Nécessite la température d\'air soufflé ou l\'état du préchauffage (onglet VMC).}}');
    }

    // Comparaison des modes de la VMC
    var md = a.modes || [];
    if (md.length) {
        var mh = '<table class="table table-condensed table-bordered tf-pred" style="width:auto;"><thead><tr><th>{{Mode}}</th><th>{{Durée}}</th><th title="{{Vitesse moyenne de la VMC}}">{{Vitesse moy.}}</th><th>{{Bypass ouvert}}</th><th title="{{Consommation de chauffage par degré-jour : à comparer d\'un mode à l\'autre}}">{{kWh / degré-jour}}</th><th title="{{Baisse de température d\'une nuit sans chauffage, par degré d\'écart intérieur − extérieur}}">{{Perte nuit / °C d\'écart}}</th><th title="{{Part du temps d\'été où la maison dépasse le seuil « trop chaude »}}">{{Été trop chaud}}</th><th>{{T moy. bas}}</th></tr></thead><tbody>';
        md.forEach(function (m) {
            mh += '<tr><td><b>' + tf_esc(m.label) + '</b></td><td>' + m.days + ' {{j}} (' + tf_fmt(m.hours, 0) + ' h)</td><td>' + (m.sp_avg === null ? '—' : tf_fmt(m.sp_avg, 2)) + '</td><td>' + (m.by_open === null ? '—' : m.by_open + ' %') + '</td><td>' + (m.kwh_dju === null ? '—' : tf_fmt(m.kwh_dju, 2)) + '</td><td>' + (m.night_loss === null ? '—' : tf_fmt(m.night_loss, 3) + ' °C (' + m.nights + ' {{nuits}})') + '</td><td>' + (m.overheat_h === null ? '—' : Math.round(100 * m.overheat_h / Math.max(1, m.summer_h)) + ' %') + '</td><td>' + (m.tb_avg === null ? '—' : tf_fmt(m.tb_avg, 1) + ' °C') + '</td></tr>';
        });
        mh += '</tbody></table>';
        $('#tf_modes').html(mh + (md.length < 2 ? '<div class="help-block">{{Un seul mode utilisé sur la période. Essayez l\'autre mode quelques semaines pour pouvoir comparer.}}</div>' : ''));
        $('#tf_modes_card').show();
    } else {
        $('#tf_modes_card').hide();
    }

    // Prévisions
    var preds = a.predictions || {};
    var ph = '';
    ['b1', 'b2'].forEach(function (b) {
        if (!preds[b] || !preds[b].length) {
            return;
        }
        var withSun = preds[b].some(function (p) { return p.rate_sun !== null && p.rate_sun !== undefined; });
        ph += '<table class="table table-condensed table-bordered tf-pred" style="display:inline-table; width:auto; margin-right:16px; vertical-align:top;"><thead><tr><th colspan="' + (withSun ? 5 : 4) + '">« ' + tf_esc(n[b]) + ' »</th></tr><tr><th>{{Extérieur}}</th><th>{{Montée}}</th><th>{{Pour +1 °C}}</th><th>{{Nuit sans chauffe}}</th>' + (withSun ? '<th title="{{Variation par heure entre 10h et 16h, une journée ensoleillée, batteries éteintes}}">☀️ {{Midi ensoleillé}}</th>' : '') + '</tr></thead><tbody>';
        preds[b].forEach(function (p) {
            ph += '<tr><td>' + (p.to === null ? '—' : p.to + ' °C') + '</td><td>' + (p.rate_on > 0 ? '+' : '') + tf_fmt(p.rate_on, 2) + ' °C/h</td><td>' + (p.min_per_deg === null ? '<span class="tf-ko">{{n\'y arrive pas}}</span>' : (p.min_per_deg >= 60 ? Math.floor(p.min_per_deg / 60) + ' h ' + String(p.min_per_deg % 60).padStart(2, '0') : p.min_per_deg + ' min')) + '</td><td>' + (p.night_drop > 0 ? '+' : '') + tf_fmt(p.night_drop, 1) + ' °C</td>'
                + (withSun ? '<td>' + (p.rate_sun > 0 ? '+' : '') + tf_fmt(p.rate_sun, 2) + ' °C/h</td>' : '') + '</tr>';
        });
        ph += '</tbody></table>';
    });
    if (ph) {
        $('#tf_pred').html(ph);
        $('#tf_pred_card').show();
    } else {
        $('#tf_pred_card').hide();
    }
}

/* Préconisations */
var tf_adviceList = [];
function tf_advFmt(key, v) {
    if (v === null || v === undefined) {
        return '—';
    }
    if (key == 'assist_enabled') {
        return v == 1 ? '{{oui}}' : '{{non}}';
    }
    if (key == 'vmc_mode_w' || key == 'vmc_mode_s') {
        return v == 'bypass' ? '{{bypass}}' : '{{températures}}';
    }
    var unit = { heat_hyst_low: ' °C', heat_hyst_high: ' °C', heat_eco: ' °C', vmc_hyst: ' °C', assist_after: ' min', b1_min_on: ' min', b2_min_on: ' min', b1_min_off: ' min', b2_min_off: ' min', b1_min_speed: '', b2_min_speed: '', w_sp_comf_n: '' }[key];
    if (key.match(/_speed$|_sp_/)) {
        return '{{vitesse}} ' + v;
    }
    return (typeof v === 'number' ? tf_fmt(v, 2) : tf_esc(String(v))) + (unit || '');
}

function tf_renderAdvice(adv, n) {
    if (!adv || (!adv.advice.length && !Object.keys(adv.stability || {}).length)) {
        $('#tf_advice_card').hide();
        return;
    }
    var st = '';
    ['b1', 'b2'].forEach(function (b) {
        var s = adv.stability && adv.stability[b];
        if (!s) {
            return;
        }
        st += '<div title="{{Pendant les plages Normal et à consigne fixe}}">« ' + tf_esc(n[b]) + ' » : <b>' + s.in_band + ' %</b> {{du temps à ±0,5 °C de la consigne}} · {{trop froid}} ' + s.under + ' % · {{trop chaud}} ' + s.over + ' %' + (s.over_sun ? ' ({{dont soleil}} ' + s.over_sun + ' %)' : '') + ' · {{écart-type}} ' + tf_fmt(s.std, 2) + ' °C · ' + tf_fmt(s.cycles_day, 1) + ' {{démarrages/jour}}</div>';
    });
    $('#tf_stability').html(st ? '<div class="tf-stab">' + st + '</div>' : '');
    tf_adviceList = adv.advice || [];
    var icons = { action: '🔧', info: '💡', ok: '✅' };
    var h = '';
    tf_adviceList.forEach(function (x, i) {
        h += '<div class="tf-adv"><div class="tf-adv-ico">' + (icons[x.level] || '•') + '</div><div class="tf-adv-body"><div class="tf-adv-title">' + tf_esc(x.title) + '</div><div>' + tf_esc(x.text) + '</div>'
            + '<div class="tf-adv-meta">{{Confiance}} <span class="tf-conf ' + x.confidence + '">' + x.confidence + '</span> · ' + tf_esc(x.evidence) + '</div></div>';
        if (x.key) {
            h += '<div class="tf-adv-change">' + tf_advFmt(x.key, x.current) + ' → <b>' + tf_advFmt(x.key, x.suggested) + '</b><br><a class="btn btn-xs btn-primary bt_tfApplyAdv" data-i="' + i + '" style="margin-top:4px;"><i class="fas fa-check"></i> {{Appliquer}}</a></div>';
        }
        h += '</div>';
    });
    if (!h) {
        h = '<div class="text-muted">{{Pas encore assez de données pour conseiller (il faut quelques jours de chauffe).}}</div>';
    }
    if (adv.error) {
        h += '<div class="tf-ko">' + tf_esc(adv.error) + '</div>';
    }
    $('#tf_advice').html(h);
    // Réglages successifs
    var ps = adv.periods || [];
    if (ps.length) {
        var labels = adv.labels || {};
        var t = '<table class="table table-condensed table-bordered tf-pred" style="width:auto;"><thead><tr><th>{{Période}}</th><th>{{Jours}}</th><th title="{{Part du temps à ±0,5 °C de la consigne (bas)}}">{{Stable}}</th><th>{{Écart-type}}</th><th>{{Démarrages/j}}</th><th>kWh/j</th><th>{{T ext.}}</th><th>{{Ce qui a changé}}</th></tr></thead><tbody>';
        ps.forEach(function (p, i) {
            var chg = [];
            if (i > 0) {
                $.each(p.settings, function (k, v) {
                    if (ps[i - 1].settings[k] !== v) {
                        chg.push(tf_esc(labels[k] || k) + ' : ' + tf_esc(ps[i - 1].settings[k]) + ' → <b>' + tf_esc(v) + '</b>');
                    }
                });
            }
            var d = function (x) { return x.substr(8, 2) + '/' + x.substr(5, 2); };
            t += '<tr' + (p.current ? ' style="font-weight:bold;"' : '') + '><td>' + d(p.from) + ' → ' + d(p.to) + (p.current ? ' ({{actuels}})' : '') + '</td><td>' + tf_fmt(p.days, 1) + '</td><td>' + (p.stab ? p.stab.in_band + ' %' : '—') + '</td><td>' + (p.stab ? tf_fmt(p.stab.std, 2) + ' °C' : '—') + '</td><td>' + (p.stab ? tf_fmt(p.stab.cycles_day, 1) : '—') + '</td><td>' + tf_fmt(p.kwh_day, 2) + '</td><td>' + (p.to_avg === null ? '—' : tf_fmt(p.to_avg, 1) + ' °C') + '</td><td style="text-align:left;">' + (i == 0 ? '<i>{{départ}}</i>' : (chg.join('<br>') || '—')) + '</td></tr>';
        });
        t += '</tbody></table><div class="help-block">{{Chaque changement de réglage (sauvegarde) ouvre une nouvelle période. Comparez des périodes de météo proche.}}</div>';
        $('#tf_periods').html(t);
        $('#tf_periods_wrap').show();
    } else {
        $('#tf_periods_wrap').hide();
    }
    $('#tf_advice_card').show();
}

$(document).on('click.tf', '#bt_tfPeriods', function () {
    $('#tf_periods').toggle();
});
$(document).on('click.tf', '.bt_tfApplyAdv', function () {
    var x = tf_adviceList[parseInt($(this).attr('data-i'), 10)];
    if (!x || !x.key) {
        return;
    }
    if (x.key == 'heat_slots') {
        var $tr = $('#table_tf_slots tbody tr').eq(parseInt(x.slot, 10));
        if (!$tr.length) {
            tf_alert('{{Plage introuvable : la liste a changé depuis le calcul.}}', 'warning');
            return;
        }
        $tr.find('.s_start').val(x.suggested).trigger('change');
    } else {
        var $f = tf_field(x.key);
        if ($f.is(':checkbox')) {
            $f.prop('checked', x.suggested == 1).trigger('change');
        } else {
            $f.val(String(x.suggested)).trigger('change');
        }
    }
    $(this).replaceWith('<span class="tf-ok"><i class="fas fa-check"></i> {{appliqué}}</span>');
    tf_alert('{{Réglage modifié :}} ' + x.title + '. {{Pensez à sauvegarder l\'équipement.}}', 'success');
});

function tf_loadStats() {
    if (!tf_eqId()) {
        return;
    }
    $('#tf_stats_quality').html('<i class="fas fa-spinner fa-spin"></i> {{Calcul...}}');
    tf_ajax({ action: 'stats', id: tf_eqId(), days: $('#tf_stats_days').val() }, function (a) {
        tf_renderStats(a);
    });
}

$(document).on('click.tf', '#bt_tfStatsTab', function () {
    setTimeout(tf_loadStats, 80);
});
$(document).on('click.tf', '#bt_tfStatsRefresh', tf_loadStats);
$(document).on('change.tf', '#tf_stats_days', tf_loadStats);
$(document).on('click.tf', '#bt_tfImport', function () {
    var days = $('#tf_import_days').val();
    tf_alert('{{Import en cours...}}', 'info');
    tf_ajax({ action: 'importHistory', id: tf_eqId(), days: days }, function (r) {
        if (r.message) {
            tf_alert(r.message, 'warning');
        } else {
            tf_alert(r.imported_days + ' {{jour(s) importé(s)}}, ' + (r.completed_days || 0) + ' {{jour(s) déjà présents complétés (météo, préchauffage, échangeur…)}}, ' + r.skipped_days + ' {{jour(s) déjà complets}}.', 'success');
        }
        tf_loadStats();
    });
});

/* ------------------------------------------------------------------ */
/* Tableau des commandes                                                */
/* ------------------------------------------------------------------ */
function addCmdToTable(_cmd) {
    if (!isset(_cmd)) {
        var _cmd = { configuration: {} };
    }
    if (!isset(_cmd.configuration)) {
        _cmd.configuration = {};
    }
    var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
    tr += '<td class="hidden-xs"><span class="cmdAttr" data-l1key="id"></span></td>';
    tr += '<td><div class="input-group">';
    tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom de la commande}}">';
    tr += '<span class="input-group-btn"><a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a></span>';
    tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>';
    tr += '</div></td>';
    var help = (typeof ThermoFlair325CmdHelp !== 'undefined' && _cmd.logicalId && ThermoFlair325CmdHelp[_cmd.logicalId]) ? ThermoFlair325CmdHelp[_cmd.logicalId] : ((_cmd.configuration && _cmd.configuration.tf_help) ? _cmd.configuration.tf_help : '');
    tr += '<td style="font-size:.9em;">' + tf_esc(help) + '</td>';
    tr += '<td><span class="cmdAttr" data-l1key="type"></span> / <span class="cmdAttr" data-l1key="subType"></span></td>';
    tr += '<td><label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked/>{{Afficher}}</label> ';
    if (_cmd.type == 'info') {
        tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized"/>{{Historiser}}</label>';
    }
    tr += '</td>';
    tr += '<td><span class="cmdAttr" data-l1key="htmlstate"></span></td>';
    tr += '<td>';
    if (is_numeric(_cmd.id)) {
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> ';
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>';
    }
    tr += '</td></tr>';
    $('#table_cmd tbody').append(tr);
    $('#table_cmd tbody tr').last().setValues(_cmd, '.cmdAttr');
}

/* ------------------------------------------------------------------ */
/* Aide ModBus : analyse d'un équipement MyModbus                        */
/* ------------------------------------------------------------------ */
var tf_mbLast = null;
var TF_FIELD_LABELS = {
    vmc_s1_cmd: '{{VMC → Vitesse 1}}', vmc_s2_cmd: '{{VMC → Vitesse 2}}', vmc_s3_cmd: '{{VMC → Vitesse 3}}', vmc_s4_cmd: '{{VMC → Vitesse 4 (boost)}}',
    vmc_pos_cmd: '{{VMC → Position ventilation}}', vmc_pre_action_cmd: '{{VMC → Passer en mode ModBus}}', vmc_pre_cmd: '{{VMC → Lecture du mode ModBus}}',
    modbus_ok_cmd: '{{VMC → Communication ModBus}}', bypass_cmd: '{{VMC → Statut du bypass}}', co2_cmd: '{{VMC → CO2}}',
    filter_cmd: '{{Filtre → Statut du filtre}}', filter_reset_cmd: '{{Filtre → Remise à zéro}}', hx_supply_cmd: '{{VMC → Air soufflé (efficacité)}}', hx_exhaust_cmd: '{{VMC → Air rejeté (efficacité)}}', preheat_cmd: '{{VMC → Préchauffage (état)}}', preheat_power_cmd: '{{VMC → Préchauffage (puissance)}}'
};

function tf_mbLoadList() {
    tf_ajax({ action: 'mymodbusList' }, function (list) {
        var $s = $('#tf_mb_eq');
        var cur = $s.val();
        $s.empty();
        if (!list.length) {
            $s.append('<option value="">{{Aucun équipement MyModbus (plugin absent ?)}}</option>');
            return;
        }
        list.forEach(function (e) {
            $s.append('<option value="' + e.id + '">' + tf_esc(e.name) + (e.enable ? '' : ' ({{désactivé}})') + '</option>');
        });
        if (cur) {
            $s.val(cur);
        }
    }, true);
}

$(document).on('click.tf', '#bt_tfHelpTab', function () {
    if (!$('#tf_mb_eq option').length) {
        tf_mbLoadList();
    }
});

$(document).on('click.tf', '#bt_tfMbScan', function () {
    var mb = $('#tf_mb_eq').val();
    if (!mb) {
        tf_alert('{{Choisissez un équipement MyModbus.}}', 'warning');
        return;
    }
    $('#tf_mb_result').html('<i class="fas fa-spinner fa-spin"></i> {{Analyse…}}');
    tf_ajax({ action: 'mymodbusScan', id: tf_eqId(), mb: mb, config: JSON.stringify(tf_formConfig()) }, function (r) {
        tf_mbLast = r;
        tf_mbRender(r);
    });
});

function tf_mbNote(n) {
    var icon = { danger: 'fa-times-circle text-danger', warning: 'fa-exclamation-triangle text-warning', info: 'fa-info-circle text-info' }[n[0]] || 'fa-info-circle';
    return '<span class="tf-mb-note"><i class="fas ' + icon + '"></i> ' + tf_esc(n[1]) + '</span>';
}

function tf_mbRender(r) {
    var h = '';
    var c = r.count;
    h += '<p><b>' + tf_esc(r.eq.name) + '</b> — ' + r.rows.length + ' {{commandes}} : '
        + '<span class="tf-ok">' + c.ok + ' {{bonnes}}</span>'
        + (c.warning ? ', <span class="text-warning">' + c.warning + ' {{à revoir}}</span>' : '')
        + (c.danger ? ', <span class="text-danger"><b>' + c.danger + ' {{à corriger}}</b></span>' : '') + '.</p>';
    // Équipement
    h += '<table class="table table-condensed tf-help-table" style="width:auto;">';
    r.conf.forEach(function (x) {
        h += '<tr><th>' + tf_esc(x[0]) + '</th><td>' + tf_esc(x[1]) + '</td></tr>';
    });
    h += '</table>';
    r.eqNotes.forEach(function (n) {
        h += '<div class="alert alert-' + n[0] + '" style="padding:6px 10px; margin-bottom:6px;">' + tf_esc(n[1]) + '</div>';
    });
    // Commandes par catégorie
    var cats = $.extend({}, r.categories, { mymodbus: { icon: 'fa-cogs', title: '{{Commandes internes de MyModbus}}' }, other: { icon: 'fa-question', title: '{{Autres commandes}}' } });
    var byCat = {};
    r.rows.forEach(function (row) {
        (byCat[row.cat] = byCat[row.cat] || []).push(row);
    });
    Object.keys(cats).forEach(function (ck) {
        if (!byCat[ck]) {
            return;
        }
        h += '<h5 class="tf-cat-title"><i class="fas ' + cats[ck].icon + '"></i> ' + cats[ck].title + '</h5>';
        h += '<table class="table table-condensed table-bordered tf-help-table"><thead><tr><th>{{Commande}}</th><th>{{Registre}}</th><th>{{Fonction · format}}</th><th>{{Valeur}}</th><th>{{C\'est…}}</th><th>{{Dans ThermoFlair}}</th><th>{{Remarques}}</th></tr></thead><tbody>';
        byCat[ck].forEach(function (row) {
            var val = row.type == 'action' ? (row.write !== '' ? '{{écrit}} <b>' + tf_esc(row.write) + '</b>' : '') : (row.value === '' ? '<span class="text-muted">{{aucune}}</span>' : '<b>' + tf_esc(row.value) + '</b>' + (row.unit ? ' ' + tf_esc(row.unit) : '') + (row.decoded ? '<br><small>' + tf_esc(row.decoded) + '</small>' : ''));
            var what = row.suggest ? tf_esc(row.suggest) + (row.suggest != row.name ? '<br><small class="text-muted">{{nom conseillé}}</small>' : '') : '';
            var used = (row.used || []).map(function (f) {
                return '<span class="tf-ok"><i class="fas fa-link"></i> ' + tf_esc(TF_FIELD_LABELS[f] || f) + '</span>';
            }).join('<br>');
            if (!used && row.field) {
                used = '<span class="text-muted">{{peut servir à}} ' + tf_esc(TF_FIELD_LABELS[row.field] || row.field) + '</span>';
            }
            var notes = (row.notes || []).map(tf_mbNote).join('');
            h += '<tr class="' + (row.status == 'danger' ? 'tf-mb-row-danger' : (row.status == 'warning' ? 'tf-mb-row-warning' : '')) + '">'
                + '<td>' + tf_esc(row.name) + ' <small class="text-muted">#' + row.id + '</small></td>'
                + '<td>' + tf_esc(row.addr) + '</td>'
                + '<td><small>' + tf_esc(row.fct) + (row.fmt ? ' · ' + tf_esc(row.fmt) : '') + (row.option ? '<br>Option : ' + tf_esc(row.option) : '') + '</small></td>'
                + '<td style="white-space:nowrap;">' + val + '</td><td>' + what + '</td><td>' + used + '</td><td>' + notes + '</td></tr>';
        });
        h += '</tbody></table>';
    });
    // Manquants
    if (r.missing.length) {
        h += '<h5 class="tf-cat-title"><i class="fas fa-plus-circle"></i> {{Commandes conseillées à créer dans MyModbus}}</h5><table class="table table-condensed table-bordered tf-help-table"><tbody>';
        r.missing.forEach(function (m) {
            var lv = r.levels[m.level];
            h += '<tr><td style="width:110px;"><span class="label label-' + lv['class'] + '">' + lv.label + '</span></td><td style="width:200px;"><b>' + tf_esc(m.name) + '</b></td><td><code class="tf-howto">' + tf_esc(m.how) + '</code></td><td>' + tf_esc(m.desc) + '</td></tr>';
        });
        h += '</tbody></table>';
    } else {
        h += '<p class="tf-ok"><i class="fas fa-check-circle"></i> {{Toutes les commandes indispensables et conseillées existent.}}</p>';
    }
    // Remplissage
    var keys = Object.keys(r.fill);
    if (keys.length) {
        var empty = keys.filter(function (k) { return !r.fill[k].current; }).length;
        h += '<div class="tf-inline" style="margin-top:8px;">'
            + '<a class="btn btn-success btn-sm" id="bt_tfMbFill"><i class="fas fa-magic"></i> {{Remplir les champs ThermoFlair vides}} (' + empty + ')</a>'
            + '<a class="btn btn-warning btn-sm" id="bt_tfMbFillAll"><i class="fas fa-exchange-alt"></i> {{Remplacer tous les champs reconnus}} (' + keys.length + ')</a>'
            + '<span class="text-muted">{{Pensez ensuite à sauvegarder.}}</span></div>';
    }
    $('#tf_mb_result').html(h);
}

function tf_mbFill(all) {
    var r = tf_mbLast;
    if (!r) {
        return;
    }
    var n = 0;
    Object.keys(r.fill).forEach(function (k) {
        var $f = tf_field(k);
        if (!$f.length || (!all && $f.val() !== '')) {
            return;
        }
        $f.val(r.fill[k].value).trigger('change');
        n++;
    });
    Object.keys(r.extra).forEach(function (k) {
        var $f = tf_field(k);
        if ($f.length && (all || n > 0)) {
            $f.val(r.extra[k]).trigger('change');
        }
    });
    tf_alert(n ? n + ' {{champ(s) rempli(s). Vérifiez les onglets VMC et Filtre, puis sauvegardez.}}' : '{{Aucun champ vide à remplir.}}', n ? 'success' : 'info');
}

$(document).on('click.tf', '#bt_tfMbFill', function () {
    tf_mbFill(false);
});
$(document).on('click.tf', '#bt_tfMbFillAll', function () {
    tf_mbFill(true);
});

// Code météo -> texte (même logique que ThermoFlair325::mapWeather).
function tf_weatherLabel(c, src) {
    if (!src || src == 'auto') { src = c >= 1000 ? 'weatherapi' : (c >= 200 ? 'owm' : 'wmo'); }
    var cat = null;
    if (src == 'owm') {
        cat = c < 300 && c >= 200 ? 'storm' : c < 400 && c >= 300 ? 'drizzle' : c < 600 && c >= 500 ? 'rain' : c < 700 && c >= 600 ? 'snow' : c < 800 && c >= 700 ? 'fog' : ({ 800: 'clear', 801: 'few', 802: 'partly', 803: 'cloudy', 804: 'overcast' })[c] || null;
    } else if (src == 'weatherapi') {
        cat = ({ 1000: 'clear', 1003: 'partly', 1006: 'cloudy', 1009: 'overcast', 1030: 'fog', 1135: 'fog', 1147: 'fog', 1087: 'storm', 1273: 'storm', 1276: 'storm', 1279: 'storm', 1282: 'storm' })[c] || null;
        if (!cat) {
            if ([1063, 1150, 1153, 1168, 1171, 1180, 1183].indexOf(c) >= 0) { cat = 'drizzle'; }
            else if ([1186, 1189, 1192, 1195, 1198, 1201, 1240, 1243, 1246].indexOf(c) >= 0) { cat = 'rain'; }
            else if (c >= 1066 && c <= 1264) { cat = 'snow'; }
        }
    } else {
        cat = c == 0 ? 'clear' : c == 1 ? 'few' : c == 2 ? 'partly' : c == 3 ? 'overcast' : (c == 45 || c == 48) ? 'fog' : (c >= 51 && c <= 57) ? 'drizzle' : ((c >= 61 && c <= 67) || (c >= 80 && c <= 82)) ? 'rain' : ((c >= 71 && c <= 77) || c == 85 || c == 86) ? 'snow' : (c >= 95 && c <= 99) ? 'storm' : null;
    }
    var lab = { clear: '☀️ {{ciel dégagé}}', few: '🌤️ {{quelques nuages}}', partly: '⛅ {{partiellement nuageux}}', cloudy: '🌥️ {{nuageux}}', overcast: '☁️ {{couvert}}', fog: '🌫️ {{brouillard}}', drizzle: '🌦️ {{bruine}}', rain: '🌧️ {{pluie}}', snow: '🌨️ {{neige}}', storm: '⛈️ {{orage}}' };
    var srcName = { owm: 'OpenWeatherMap', weatherapi: 'WeatherAPI', wmo: 'Open-Meteo / WMO' };
    return cat ? '{{Actuellement}} : <b>' + lab[cat] + '</b> ({{code}} ' + c + ', ' + srcName[src] + ')' : '<span class="tf-warn"><i class="fas fa-exclamation-triangle"></i> {{Code}} ' + c + ' {{non reconnu pour}} ' + srcName[src] + '.</span>';
}

// Libellé officiel du code (OpenWeatherMap / WeatherAPI / Open-Meteo), demandé
// au serveur une seule fois par code (WeatherAPI : liste officielle avec copie
// locale d'une semaine côté Jeedom).
var tf_wxCache = {};
function tf_weatherOfficial(code, src, base) {
    var key = code + '|' + (src || 'auto');
    var render = function () {
        var lab = tf_wxCache[key];
        tf_live('weather_now', base + (lab ? ' <span class="text-muted">— {{libellé officiel}} : « ' + tf_esc(lab) + ' »</span>' : ''));
    };
    render();
    if (tf_wxCache[key] === undefined) {
        tf_wxCache[key] = '';
        tf_ajax({ action: 'weatherLabel', code: code, source: src || 'auto' }, function (r) {
            tf_wxCache[key] = r.label || '';
            render();
        }, true);
    }
}
