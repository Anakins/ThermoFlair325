<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('ThermoFlair325');
sendVarToJS('eqType', $plugin->getId());
sendVarToJS('ThermoFlair325Apikey', ThermoFlair325::getApiKey());
sendVarToJS('ThermoFlair325Defaults', ThermoFlair325::DEFAULTS);
sendVarToJS('ThermoFlair325CmdHelp', ThermoFlair325::CMD_HELP);
$eqLogics = eqLogic::byType($plugin->getId());

/* ---------- Générateurs de champs (homogénéité de la page) ---------- */
if (!function_exists('tf_cmd')) {

    // Icône « ? » avec info-bulle (classe « tooltips » de Jeedom).
    function tf_tip($text) {
        if ($text === '') {
            return '';
        }
        return ' <sup><i class="fas fa-question-circle tooltips tf-tip" title="' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '"></i></sup>';
    }

    // Champ "commande liée" : saisie + bouton de sélection + aperçu ✅/❌.
    function tf_cmd($key, $label, $expect, $help = '', $opts = array()) {
        $attrs = '';
        foreach (array('min', 'max', 'numeric', 'binary', 'subtypes') as $o) {
            if (isset($opts[$o])) {
                $attrs .= ' data-' . $o . '="' . (is_array($opts[$o]) ? implode(',', $opts[$o]) : $opts[$o]) . '"';
            }
        }
        $sub = !empty($opts['subtype']) ? ' data-cmdsubtype="' . $opts['subtype'] . '"' : '';
        $req = !empty($opts['required']) ? ' <sup class="tf-req" title="{{Obligatoire}}">*</sup>' : '';
        echo '<div class="form-group">';
        echo '<label class="col-sm-3 control-label">' . $label . $req . tf_tip($help) . '</label>';
        echo '<div class="col-sm-5"><div class="input-group">';
        echo '<input type="text" class="eqLogicAttr form-control tf-cmd" data-l1key="configuration" data-l2key="' . $key . '" data-expect="' . $expect . '"' . $attrs . ' placeholder="#[Objet][Équipement][Commande]#">';
        echo '<span class="input-group-btn"><a class="btn btn-' . ($expect == 'action' ? 'warning' : 'success') . ' tf-pick" data-cmdtype="' . $expect . '"' . $sub . ' title="' . ($expect == 'action' ? '{{Choisir une commande action}}' : '{{Choisir une commande info}}') . '"><i class="fas fa-list-alt"></i></a></span>';
        echo '</div>';
        echo '</div>';
        echo '<div class="col-sm-4 tf-preview-col"><span class="tf-preview" data-for="' . $key . '"></span></div>';
        echo '</div>';
    }

    function tf_num($key, $label, $unit, $min, $max, $step = 1, $help = '') {
        echo '<div class="form-group">';
        echo '<label class="col-sm-3 control-label">' . $label . tf_tip($help) . '</label>';
        echo '<div class="col-sm-5"><div class="tf-inline">';
        echo '<input type="number" class="eqLogicAttr form-control tf-num" data-l1key="configuration" data-l2key="' . $key . '" min="' . $min . '" max="' . $max . '" step="' . $step . '">';
        echo '<span class="tf-unit">' . $unit . '</span></div>';
        echo '</div>';
        echo '<div class="col-sm-4 tf-preview-col"><span class="tf-live" data-live="' . $key . '"></span></div>';
        echo '</div>';
    }

    function tf_time($key, $label, $help = '') {
        echo '<div class="form-group">';
        echo '<label class="col-sm-3 control-label">' . $label . tf_tip($help) . '</label>';
        echo '<div class="col-sm-5"><input type="time" class="eqLogicAttr form-control tf-time" data-l1key="configuration" data-l2key="' . $key . '" style="width:130px;">';
        echo '</div></div>';
    }

    function tf_check($key, $label, $help = '') {
        echo '<div class="form-group">';
        echo '<div class="col-sm-9 col-sm-offset-3" style="clear:both;">';
        echo '<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="' . $key . '"> ' . $label . '</label>' . tf_tip($help);
        echo '</div></div>';
    }

    function tf_select($key, $label, $options, $help = '', $width = 260) {
        echo '<div class="form-group">';
        echo '<label class="col-sm-3 control-label">' . $label . tf_tip($help) . '</label>';
        echo '<div class="col-sm-5"><select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="' . $key . '" style="width:' . $width . 'px;">';
        foreach ($options as $v => $t) {
            echo '<option value="' . $v . '">' . $t . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '<div class="col-sm-4 tf-preview-col"><span class="tf-live" data-live="' . $key . '"></span></div>';
        echo '</div>';
    }

    function tf_speeds($withSpeed4 = false) {
        $o = array();
        foreach (array(1, 2, 3) as $s) {
            $o[$s] = '{{Vitesse}} ' . $s . ' (' . ThermoFlair325::SPEED_LABELS[$s] . ')';
        }
        return $o;
    }
} // function_exists

$months = ThermoFlair325::MONTHS;
?>

<style>
    #div_tf .tf-req { color: #d9534f; }
    #div_tf .tf-inline { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    #div_tf .tf-inline input { width: 110px; }
    #div_tf .tf-unit { opacity: .8; white-space: nowrap; }
    #div_tf .tf-preview-col { padding-top: 7px; }
    #div_tf .tf-ok { color: #3c763d; }
    #div_tf .tf-ko { color: #a94442; }
    #div_tf .tf-warn { color: #8a6d3b; }
    #div_tf .tf-live { font-size: .9em; opacity: .95; }
    #div_tf .help-block { margin-bottom: 0; }
    #div_tf .tf-explain { margin: 0 15px 12px 15px; }
    #div_tf .tf-season-table td, #div_tf .tf-season-table th { vertical-align: middle; text-align: center; }
    #div_tf .tf-season-table input, #div_tf .tf-season-table select { max-width: 120px; margin: auto; }
    #div_tf .tf-season-table select[data-l2key^=vmc_mode] { max-width: 180px; }
    #div_tf .tf-rows td { vertical-align: middle; }
    #div_tf .tf-rows .form-control { padding: 2px 6px; }
    #div_tf .tf-days label, #div_tf .tf-bats label { display: inline-flex; align-items: center; gap: 3px; margin: 1px 6px 1px 0; padding: 1px 6px 1px 4px; border: 1px solid rgba(128,128,128,.35); border-radius: 10px; font-weight: normal; white-space: nowrap; cursor: pointer; }
    #div_tf .tf-days label input, #div_tf .tf-bats label input { margin: 0; }
    #div_tf .tf-tip { opacity: .65; cursor: help; margin-left: 2px; }
    #div_tf .tf-tip:hover { opacity: 1; }
    #div_tf .tf-explain ul { margin: 4px 0 0 0; padding-left: 18px; }
    #div_tf .tf-rule { margin: 2px 0; }
    #div_tf .tf-season-table .tf-subhead td { text-align: left; font-weight: bold; padding-top: 10px; opacity: .85; }
    #div_tf .tf-bat-panel { border: 1px solid rgba(128,128,128,.35); border-radius: 6px; padding: 10px 10px 0 10px; margin-bottom: 12px; }
    #div_tf .tf-bat-panel legend { font-size: 1.05em; }
    #div_tf .tf-week { margin: 6px 15px 16px 15px; }
    #div_tf .tf-week-row { display: flex; align-items: center; padding: 5px 0; border-top: 1px solid rgba(128,128,128,.25); }
    #div_tf .tf-week-row:first-of-type { border-top: none; }
    #div_tf .tf-week-day { width: 32px; font-weight: bold; }
    #div_tf .tf-week-bars { flex: 1; }
    #div_tf .tf-week-bar { position: relative; height: 10px; background: rgba(128,128,128,.15); border-radius: 3px; margin: 1px 0; }
    #div_tf .tf-week-seg { position: absolute; top: 0; bottom: 0; border-radius: 3px; }
    #div_tf .tf-seg-comfort, #div_tf .tf-seg-normal { background: #e76f51; }
    #div_tf .tf-seg-eco { background: #6a9fb5; }
    #div_tf .tf-seg-consigne { background: #f4a261; }
    #div_tf .tf-seg-forced { background: #9d0208; }
    #div_tf .tf-seg-off { background: #8ecae6; }
    #div_tf .tf-seg-outside { background: repeating-linear-gradient(45deg, rgba(244,162,97,.35), rgba(244,162,97,.35) 4px, transparent 4px, transparent 8px); }
    #div_tf .tf-seg-vmc { background: #2a9d8f; }
    #div_tf .tf-seg-quiet { background: #264653; }
    #div_tf .tf-seg-conflict { box-shadow: inset 0 0 0 2px #d9534f; }
    #div_tf .tf-week-scale { display: flex; justify-content: space-between; margin-left: 32px; font-size: .8em; opacity: .7; }
    #div_tf .tf-legend span { display: inline-block; margin-right: 12px; font-size: .9em; }
    #div_tf .tf-legend i { display: inline-block; width: 12px; height: 10px; border-radius: 2px; margin-right: 4px; vertical-align: middle; }
    #div_tf .tf-diag-table td:first-child { width: 45%; }
    #div_tf .tf-big { font-size: 1.25em; font-weight: bold; }
    #div_tf .tf-card { border: 1px solid rgba(128,128,128,.35); border-radius: 6px; padding: 10px; margin-bottom: 12px; }
    #div_tf .tf-card h4 { margin-top: 0; }
    #div_tf .tf-speed-pill { display: inline-block; width: 26px; height: 26px; line-height: 26px; text-align: center; border-radius: 50%; margin-right: 3px; background: rgba(128,128,128,.2); font-weight: bold; }
    #div_tf .tf-speed-pill.on { background: #2a9d8f; color: #fff; }
    #div_tf .tf-speed-pill.v4 { border: 1px dashed #d9534f; }
    /* Statistiques */
    #div_tf .tf-kpis { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 8px; margin-bottom: 10px; }
    #div_tf .tf-kpi { border: 1px solid rgba(128,128,128,.3); border-radius: 6px; padding: 6px 8px; }
    #div_tf .tf-kpi .v { font-size: 1.35em; font-weight: bold; line-height: 1.2; }
    #div_tf .tf-kpi .l { font-size: .8em; opacity: .75; }
    #div_tf .tf-charts { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 720px), 1fr)); gap: 14px; }
    #div_tf .tf-chart { border: 1px solid rgba(128,128,128,.3); border-radius: 6px; padding: 4px; }
    #div_tf .tf-chart .tf-ch { height: 460px; }
    #div_tf .tf-chart .tf-cap { font-size: .95em; opacity: .8; padding: 2px 8px 6px 8px; }
    #div_tf .tf-insights li { margin-bottom: 3px; }
    #div_tf .tf-adv { display: flex; gap: 10px; align-items: flex-start; padding: 7px 4px; border-top: 1px solid rgba(128,128,128,.2); }
    #div_tf .tf-adv:first-child { border-top: none; }
    #div_tf .tf-adv-ico { font-size: 1.2em; width: 22px; text-align: center; padding-top: 1px; }
    #div_tf .tf-adv-body { flex: 1; }
    #div_tf .tf-adv-title { font-weight: bold; }
    #div_tf .tf-adv-meta { font-size: .85em; opacity: .75; margin-top: 2px; }
    #div_tf .tf-adv-change { white-space: nowrap; text-align: right; }
    #div_tf .tf-conf { display: inline-block; padding: 0 6px; border-radius: 8px; font-size: .8em; background: rgba(128,128,128,.2); }
    #div_tf .tf-conf.bonne { background: rgba(42,157,143,.25); }
    #div_tf .tf-conf.faible { background: rgba(217,83,79,.2); }
    #div_tf .tf-stab { display: flex; flex-wrap: wrap; gap: 14px; margin: 2px 0 8px 0; }
    #div_tf .tf-stab b { font-size: 1.1em; }
    #div_tf .tf-pred td, #div_tf .tf-pred th { text-align: center; padding: 3px 6px !important; }
    #div_tf .tf-help-table { font-size: 12px; }
    #div_tf .tf-help-table th { white-space: nowrap; }
    #div_tf .tf-howto { white-space: normal; font-size: 11px; background: transparent; color: inherit; padding: 0; }
    #div_tf .tf-cat-title { margin: 14px 0 6px; font-weight: bold; }
    #div_tf .tf-cat-title small { font-weight: normal; margin-left: 6px; }
    #div_tf .tf-field-tag { font-size: 10px; color: #31708f; }
    #div_tf .tf-steps li { margin-bottom: 4px; }
    #div_tf .tf-mb-row-danger { background: rgba(217, 83, 79, .12); }
    #div_tf .tf-mb-row-warning { background: rgba(240, 173, 78, .14); }
    #div_tf .tf-mb-note { display: block; font-size: 11px; }
</style>

<div class="row row-overflow" id="div_tf">

    <!-- ============================ LISTE ============================ -->
    <div class="col-xs-12 eqLogicThumbnailDisplay">
        <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor eqLogicAction logoPrimary" data-action="add">
                <i class="fas fa-plus-circle"></i><br><span>{{Ajouter}}</span>
            </div>
            <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
                <i class="fas fa-wrench"></i><br><span>{{Configuration}}</span>
            </div>
        </div>
        <legend><i class="fas fa-home"></i> {{Mes maisons}}</legend>
        <?php
        if (count($eqLogics) == 0) {
            echo '<br><div class="text-center" style="font-size:1.2em;font-weight:bold;">{{Aucun équipement : cliquez sur Ajouter (un seul équipement suffit pour toute la maison).}}</div>';
        } else {
            echo '<div class="eqLogicThumbnailContainer">';
            foreach ($eqLogics as $eqLogic) {
                $opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
                echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
                echo '<img src="' . $plugin->getPathImgIcon() . '">';
                echo '<br><span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
                echo '</div>';
            }
            echo '</div>';
        }
        ?>
    </div>

    <!-- ============================ ÉQUIPEMENT ============================ -->
    <div class="col-xs-12 eqLogic" style="display: none;">
        <div class="input-group pull-right" style="display:inline-flex;">
            <span class="input-group-btn">
                <a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span>
                </a><a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}
                </a><a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
            </span>
        </div>
        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
            <li role="presentation" class="active"><a href="#diagtab" role="tab" data-toggle="tab" id="bt_tfDiagTab"><i class="fas fa-clipboard-check"></i> {{Récapitulatif}}</a></li>
            <li role="presentation"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-home"></i> {{Maison}}</a></li>
            <li role="presentation"><a href="#vmctab" role="tab" data-toggle="tab"><i class="fas fa-fan"></i> {{VMC}}</a></li>
            <li role="presentation"><a href="#heattab" role="tab" data-toggle="tab"><i class="fas fa-fire"></i> {{Chauffage}}</a></li>
            <li role="presentation"><a href="#shedtab" role="tab" data-toggle="tab"><i class="fas fa-bolt"></i> {{Délestage}}</a></li>
            <li role="presentation"><a href="#filtertab" role="tab" data-toggle="tab"><i class="fas fa-filter"></i> {{Filtre}}</a></li>
            <li role="presentation"><a href="#notiftab" role="tab" data-toggle="tab"><i class="fas fa-bell"></i> {{Notifications}}</a></li>
            <li role="presentation"><a href="#statstab" role="tab" data-toggle="tab" id="bt_tfStatsTab"><i class="fas fa-chart-line"></i> {{Statistiques}}</a></li>
            <li role="presentation"><a href="#commandtab" role="tab" data-toggle="tab"><i class="fas fa-list"></i> {{Commandes}}</a></li>
            <li role="presentation"><a href="#helptab" role="tab" data-toggle="tab" id="bt_tfHelpTab"><i class="fas fa-question-circle"></i> {{Aide ModBus}}</a></li>
        </ul>

        <div id="tf_check_banner" style="display:none; margin:10px 0 0 0;"></div>

        <div class="tab-content">

            <!-- ============================ MAISON ============================ -->
            <div role="tabpanel" class="tab-pane" id="eqlogictab">
                <form class="form-horizontal">
                    <fieldset>
                        <div class="col-lg-7">
                            <legend><i class="fas fa-wrench"></i> {{Paramètres généraux}}</legend>
                            <div class="form-group">
                                <label class="col-sm-3 control-label">{{Nom de l'équipement}}</label>
                                <div class="col-sm-6">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Maison}}">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-3 control-label">{{Objet parent}}</label>
                                <div class="col-sm-6">
                                    <select class="eqLogicAttr form-control" data-l1key="object_id">
                                        <option value="">{{Aucun}}</option>
                                        <?php
                                        foreach ((jeeObject::buildTree(null, false)) as $object) {
                                            echo '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-3 control-label">{{Options}}</label>
                                <div class="col-sm-9">
                                    <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>{{Activer}}</label>
                                    <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>{{Visible}}</label>
                                    <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="custom_widget">{{Widget ThermoFlair 325}}</label>
                                </div>
                            </div>

                            <legend><i class="fas fa-puzzle-piece"></i> {{Modules}}</legend>
                            <div class="form-group">
                                <label class="col-sm-3 control-label">{{Le plugin gère}}</label>
                                <div class="col-sm-9">
                                    <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="mod_vmc"> <i class="fas fa-fan"></i> {{la VMC}}</label>
                                    <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="mod_heat"> <i class="fas fa-fire"></i> {{les batteries}}</label>
                                    <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="mod_shed"> <i class="fas fa-bolt"></i> {{le délestage Linky}}</label>
                                    <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="mod_filter"> <i class="fas fa-filter"></i> {{le filtre}}</label>
                                    <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="mod_stats"> <i class="fas fa-chart-line"></i> {{les statistiques}}</label>
                                    <?php echo tf_tip('{{Un module décoché ne pilote rien. Ses réglages sont gardés.}}'); ?>
                                </div>
                            </div>

                            <legend><i class="fas fa-thermometer-half"></i> {{Thermomètres}}</legend>
                            <?php
                            tf_cmd('temp_in_cmd', '{{Bas (rez-de-chaussée)}}', 'info', '{{Thermomètre du rez-de-chaussée. Il règle la batterie 1 et sert de référence à la VMC.}}', array('min' => -10, 'max' => 45, 'numeric' => 1, 'subtype' => 'numeric', 'required' => 1));
                            tf_cmd('temp_up_cmd', '{{Haut (étage)}}', 'info', '{{Thermomètre de l\'étage. Il règle la batterie 2. Sans lui, la batterie 2 suit le bas.}}', array('min' => -10, 'max' => 45, 'numeric' => 1, 'subtype' => 'numeric'));
                            tf_cmd('temp_out_cmd', '{{Extérieur}}', 'info', '{{Obligatoire pour la VMC. Sans lui, les statistiques sont limitées.}}', array('min' => -40, 'max' => 55, 'numeric' => 1, 'subtype' => 'numeric'));
                            ?>

                            <legend><i class="fas fa-sun"></i> {{Soleil et météo}}</legend>
                            <?php
                            tf_cmd('lux_cmd', '{{Luminosité extérieure}}', 'info', '{{Optionnel. Capteur en lux placé dehors, côté soleil (au sud de préférence). Sert aux statistiques : chaque journée est classée ensoleillée, mitigée ou maussade selon le temps passé au maximum du capteur.}}', array('min' => 0, 'max' => 200000, 'numeric' => 1, 'subtype' => 'numeric'));
                            tf_cmd('weather_cmd', '{{Conditions météo (code)}}', 'info', '{{Facultatif. Le NUMÉRO de la condition météo actuelle donné par votre plugin météo (pas le texte) : OpenWeatherMap (ex. 800 = ciel dégagé, 804 = couvert), WeatherAPI (ex. 1000, 1003, 1183) ou Open-Meteo (codes WMO 0 à 99). Les statistiques classent alors les journées plus finement (seule, ou avec le capteur de luminosité), comptent les jours de pluie et comparent la consommation. Elle s\'affiche aussi sur le widget et dans le Récapitulatif.}}', array('subtypes' => 'numeric,string'));
                            tf_cmd('weather_label_cmd', '{{Libellé de la condition (texte)}}', 'info', '{{Facultatif, informatif : le texte de la condition donné par votre plugin météo (ex. « Partiellement nuageux »). Affiché dans le Récapitulatif et sur le widget. Sans lui, le plugin utilise sa propre traduction du code.}}', array('subtypes' => 'string,numeric'));
                            tf_select('weather_source', '{{Source des codes météo}}', array('auto' => '{{Automatique (d\'après la valeur)}}', 'owm' => 'OpenWeatherMap (200 à 804)', 'weatherapi' => 'WeatherAPI (1000 à 1282)', 'wmo' => 'Open-Meteo / WMO (0 à 99)'), '{{Automatique suffit : les trois familles de codes ne se chevauchent pas.}}', 300);
                            tf_select('sky_source', '{{Classer les journées avec}}', array('weather' => '{{La météo d\'abord (conseillé)}}', 'both' => '{{La moyenne météo + capteur}}', 'lux' => '{{Le capteur de luminosité seul}}'), '{{Pour dire si une journée était ensoleillée, mitigée ou maussade. La météo (nébulosité) distingue bien un ciel voilé d\'un plein soleil ; un capteur de luminosité bon marché plafonne vite (ex. 2000 lx alors que le plein soleil dépasse 50 000 lx) et dépend de son exposition. Sans code météo, le capteur est utilisé.}}', 300);
                            ?>
                            <div class="form-group">
                                <label class="col-sm-3 control-label">{{Météo actuelle}}</label>
                                <div class="col-sm-9 tf-preview-col"><span class="tf-live" data-live="weather_now"></span></div>
                            </div>
                            <?php
                            tf_num('lux_max', '{{Maximum du capteur}}', 'lux', 10, 200000, 10, '{{Valeur la plus haute que VOTRE capteur affiche en plein soleil (2000 par défaut, à adapter : regardez son historique un jour de beau temps). Beaucoup de capteurs l\'atteignent dès qu\'il fait jour clair : entre 9h et 17h, 60 % du temps ou plus au maximum = journée ensoleillée, 15 à 60 % = mitigée, moins = maussade.}}');
                            ?>

                            <legend><i class="fas fa-calendar-alt"></i> {{Saisons}}</legend>
                            <div class="form-group">
                                <label class="col-sm-3 control-label">{{Saison}}<?php echo tf_tip('{{L\'hiver et l\'été n\'ont pas les mêmes règles de VMC. Le chauffage peut être limité à l\'hiver.}}'); ?></label>
                                <div class="col-sm-9">
                                    <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="season_force" style="width:280px; display:inline-block;">
                                        <option value="auto">{{Automatique selon les dates}}</option>
                                        <option value="winter">{{Forcer l'hiver}}</option>
                                        <option value="summer">{{Forcer l'été}}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group" id="tf_season_months">
                                <label class="col-sm-3 control-label">{{L'hiver va du}}<?php echo tf_tip('{{Dates incluses. Le reste de l\'année est l\'été. Le changement se fait à minuit.}}'); ?></label>
                                <div class="col-sm-9 tf-inline">
                                    <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="winter_start_day" style="width:70px;"><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option><option value="5">5</option><option value="6">6</option><option value="7">7</option><option value="8">8</option><option value="9">9</option><option value="10">10</option><option value="11">11</option><option value="12">12</option><option value="13">13</option><option value="14">14</option><option value="15">15</option><option value="16">16</option><option value="17">17</option><option value="18">18</option><option value="19">19</option><option value="20">20</option><option value="21">21</option><option value="22">22</option><option value="23">23</option><option value="24">24</option><option value="25">25</option><option value="26">26</option><option value="27">27</option><option value="28">28</option><option value="29">29</option><option value="30">30</option><option value="31">31</option></select>
                                    <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="winter_start" style="width:130px;">
                                        <?php foreach ($months as $i => $m) { echo '<option value="' . $i . '">' . $m . '</option>'; } ?>
                                    </select>
                                    <span>{{au}}</span>
                                    <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="winter_end_day" style="width:70px;"><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option><option value="5">5</option><option value="6">6</option><option value="7">7</option><option value="8">8</option><option value="9">9</option><option value="10">10</option><option value="11">11</option><option value="12">12</option><option value="13">13</option><option value="14">14</option><option value="15">15</option><option value="16">16</option><option value="17">17</option><option value="18">18</option><option value="19">19</option><option value="20">20</option><option value="21">21</option><option value="22">22</option><option value="23">23</option><option value="24">24</option><option value="25">25</option><option value="26">26</option><option value="27">27</option><option value="28">28</option><option value="29">29</option><option value="30">30</option><option value="31">31</option></select>
                                    <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="winter_end" style="width:130px;">
                                        <?php foreach ($months as $i => $m) { echo '<option value="' . $i . '">' . $m . '</option>'; } ?>
                                    </select>
                                    <span class="tf-live" data-live="season"></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <legend><i class="fas fa-info"></i> {{Comment ça marche}}</legend>
                            <div class="alert alert-info">
                                {{Le plugin pilote vos commandes Jeedom (VMC, batteries, Linky, capteurs).}}
                                <ul style="margin:8px 0 0 0; padding-left:18px;">
                                    <li>{{Chaque champ affiche sa valeur (✅) ou le problème (❌).}}</li>
                                    <li>{{Un module mal réglé ne pilote rien. Un bandeau rouge le signale.}}</li>
                                    <li>{{Survolez les}} <i class="fas fa-question-circle"></i> {{pour une explication.}}</li>
                                    <li>{{Récapitulatif (premier onglet) : ce que fait le plugin et pourquoi. Statistiques : comment réagit la maison.}}</li>
                                </ul>
                            </div>
                            <div id="tf_summary"></div>
                        </div>
                    </fieldset>
                </form>
            </div>

            <!-- ============================ VMC ============================ -->
            <div role="tabpanel" class="tab-pane" id="vmctab">
                <form class="form-horizontal tf-module" data-module="mod_vmc">
                    <div class="alert alert-warning tf-module-disabled" style="display:none; margin:10px;">{{Le module VMC est désactivé (onglet Maison) : réglages conservés mais non appliqués. La commande Position ventilation reste nécessaire au chauffage.}}</div>
                    <fieldset>
                        <legend><i class="fas fa-plug"></i> {{Commandes de la VMC}}</legend>
                        <div class="alert alert-info tf-explain">{{Ces commandes viennent de votre équipement MyModbus. L'onglet « Aide ModBus » explique comment les créer, vérifie votre équipement et peut remplir ces champs pour vous.}}</div>
                        <?php
                        tf_cmd('vmc_s1_cmd', '{{Vitesse 1}}', 'action', '{{MyModbus : action, 0x06, registre 8001, valeur 0 (absence).}}', array('required' => 1, 'subtypes' => 'other'));
                        tf_cmd('vmc_s2_cmd', '{{Vitesse 2}}', 'action', '{{MyModbus : action, 0x06, registre 8001, valeur 1.}}', array('required' => 1, 'subtypes' => 'other'));
                        tf_cmd('vmc_s3_cmd', '{{Vitesse 3}}', 'action', '{{MyModbus : action, 0x06, registre 8001, valeur 2.}}', array('required' => 1, 'subtypes' => 'other'));
                        tf_cmd('vmc_s4_cmd', '{{Vitesse 4 (boost)}}', 'action', '{{MyModbus : action, 0x06, registre 8001, valeur 3. Utilisée seulement par les boosts (déclenchés de l\'extérieur : bouton, détecteur, scénario). Jamais par l\'automatisme.}}', array('subtypes' => 'other'));
                        tf_cmd('vmc_pos_cmd', '{{Position ventilation}}', 'info', '{{MyModbus : info, 0x03 Read holding registers, registre 8001. Vitesse en cours de la VMC : le plugin vérifie avec elle que la VMC a obéi. Une batterie ne chauffe que si cette vitesse est suffisante.}}', array('required' => 1));
                        ?>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Valeur lue pour chaque vitesse}}<?php echo tf_tip('{{Ce que renvoie « Position ventilation » pour chaque vitesse. Flair 325 (registre 8001) : 0, 1, 2, 3.}}'); ?></label>
                            <div class="col-sm-5 tf-inline">
                                <?php for ($i = 1; $i <= 4; $i++) { ?>
                                    <span>V<?php echo $i; ?></span><input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="vmc_p<?php echo $i; ?>" style="width:55px;">
                                <?php } ?>
                            </div>
                            <div class="col-sm-4 tf-preview-col"><span class="tf-live" data-live="position"></span></div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Débit de chaque vitesse}}<?php echo tf_tip('{{Facultatif, pour l\'affichage seulement (widget, messages). Ces débits sont réglés par votre installateur sur la VMC (menu 1.1 à 1.4 ; d\'usine 50, 100, 150 et 250 m³/h).}}'); ?></label>
                            <div class="col-sm-5 tf-inline">
                                <?php for ($i = 1; $i <= 4; $i++) { ?>
                                    <span>V<?php echo $i; ?></span><input type="number" min="0" max="400" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="vmc_flow_<?php echo $i; ?>" style="width:70px;">
                                <?php } ?>
                                <span class="tf-unit">m³/h</span>
                            </div>
                        </div>
                        <?php
                        tf_cmd('vmc_pre_action_cmd', '{{Passer en mode ModBus}}', 'action', '{{MyModbus : action, 0x06, registre 8000, valeur 1. Sans ce mode, la VMC ignore les vitesses. Une coupure de courant le remet à 0 : le plugin le renvoie régulièrement.}}', array('subtypes' => 'other'));
                        tf_num('vmc_pre_every', '{{Renvoyer le mode ModBus toutes les}}', 'min', 0, 240, 1, '{{Une VMC coupée (courant, disjoncteur, entretien) redémarre hors mode ModBus et ne le reprend pas d\'elle-même : elle ignore alors les vitesses. Le plugin renvoie donc l\'ordre régulièrement. 0 = jamais.}}');
                        tf_check('vmc_pre_always', '{{Garder la VMC en mode ModBus en permanence, même mode automatique coupé}}', '{{Coché : le renvoi continue quand le mode auto du plugin est coupé. Décoché : seulement quand le plugin pilote (mode auto, boost, forçage). Attention : en mode ModBus, la vitesse ne se change plus sur l\'écran de la VMC, seulement depuis Jeedom.}}');
                        tf_cmd('vmc_pre_cmd', '{{Lecture du mode ModBus}}', 'info', '{{Facultatif. MyModbus : info, 0x03 Read holding registers, registre 8000. Brink : c\'est « la dernière valeur acceptée ». Elle repasse à 0 après une coupure de courant : le plugin renvoie alors l\'ordre tout de suite, sans attendre.}}');
                        ?>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Valeur attendue}}<?php echo tf_tip('{{Valeur lue quand la VMC est bien en mode ModBus : 1.}}'); ?></label>
                            <div class="col-sm-5"><input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="vmc_pre_value" style="width:110px;"></div>
                        </div>
                        <?php
                        tf_cmd('modbus_ok_cmd', '{{Communication ModBus (Cycle OK)}}', 'info', '{{Facultatif, conseillé. Commande « Cycle OK » créée par MyModbus : 0 = une lecture a échoué. Le plugin prévient si elle reste à 0.}}');
                        tf_num('modbus_fail_min', '{{Prévenir après}}', 'min', 1, 60, 1, '{{Durée sans réponse de la VMC avant l\'alerte (catégorie Alertes).}}');
                        tf_cmd('co2_cmd', '{{CO2}}', 'info', '{{Optionnel. Au-dessus du seuil, la VMC accélère.}}', array('min' => 300, 'max' => 5000, 'numeric' => 1, 'subtype' => 'numeric'));
                        tf_cmd('bypass_cmd', '{{Statut du bypass}}', 'info', '{{Optionnel. MyModbus : info, 0x04 Read input registers, registre 4050 (3 = ouvert, 4 = fermé). Sert au mode « bypass » et aux statistiques.}}', array('subtypes' => 'binary,numeric,string'));
                        ?>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Valeur « bypass ouvert »}}<?php echo tf_tip('{{Valeur(s) lue(s) quand le bypass est ouvert, séparées par des virgules. Flair 325 (registre 4050) : 1,3 (en cours d\'ouverture, ouvert).}}'); ?></label>
                            <div class="col-sm-5"><input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="bypass_open_value" style="width:80px;"></div>
                            <div class="col-sm-4 tf-preview-col"><span class="tf-live" data-live="bypass_now"></span></div>
                        </div>
                        <?php
                        ?>
                    </fieldset>

                    <fieldset>
                        <legend><i class="fas fa-exchange-alt"></i> {{Échangeur et préchauffage (facultatif)}}<?php echo tf_tip('{{Pour suivre l\'état de la VMC. Ces mesures ne changent pas les décisions du plugin.}}'); ?></legend>
                        <?php
                        tf_cmd('hx_supply_cmd', '{{Température air soufflé}}', 'info', '{{MyModbus : info, 0x04, registre 4036, int16, Option #value# / 10. Air neuf après l\'échangeur, envoyé dans la maison. Sert à calculer l\'efficacité de l\'échangeur : (air soufflé − dehors) ÷ (dedans − dehors). Une Flair en bon état est vers 85 à 95 %.}}', array('min' => -30, 'max' => 60, 'numeric' => 1, 'subtype' => 'numeric'));
                        tf_cmd('hx_exhaust_cmd', '{{Température air rejeté}}', 'info', '{{Facultatif. MyModbus : info, 0x04, registre 4046, int16, Option #value# / 10. Air de la maison après l\'échangeur, rejeté dehors. Donne une seconde mesure de contrôle de l\'efficacité.}}', array('min' => -30, 'max' => 60, 'numeric' => 1, 'subtype' => 'numeric'));
                        ?>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Efficacité actuelle}}</label>
                            <div class="col-sm-9 tf-preview-col"><span class="tf-live" data-live="hx_now"></span></div>
                        </div>
                        <?php
                        tf_cmd('preheat_cmd', '{{État du préchauffage}}', 'info', '{{MyModbus : info, 0x04 Read input registers, registre 4060 (0 initialisation, 1 inactif, 2 actif, 3 test). Le préchauffage chauffe l\'air neuf par grand froid pour que l\'échangeur ne gèle pas. Notification « Préchauffage » quand il s\'allume et s\'éteint, hors plage de repos.}}', array('subtypes' => 'binary,numeric,string'));
                        ?>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Valeur « actif »}}<?php echo tf_tip('{{Valeur(s) lue(s) quand le préchauffage chauffe, séparées par des virgules. Flair 325 (registre 4060) : 2.}}'); ?></label>
                            <div class="col-sm-5"><input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="preheat_on_value" style="width:80px;"></div>
                            <div class="col-sm-4 tf-preview-col"><span class="tf-live" data-live="preheat_now"></span></div>
                        </div>
                        <?php
                        tf_cmd('preheat_power_cmd', '{{Puissance du préchauffage}}', 'info', '{{Facultatif. MyModbus : info, 0x04, registre 4061, en % de la puissance maximale.}}', array('min' => 0, 'max' => 100, 'numeric' => 1, 'subtype' => 'numeric'));
                        ?>
                    </fieldset>

                    <fieldset>
                        <legend><i class="fas fa-moon"></i> {{Calendrier de silence (vitesse maximum)}}</legend>
                        <div class="alert alert-info tf-explain">{{Pendant ces plages, la VMC ne dépasse pas la vitesse choisie (pour dormir au calme).}}
                            <ul><li>{{Le boost et un réglage manuel passent quand même.}}</li>
                            <li>{{Une batterie qui a besoin d'une vitesse plus haute ne chauffe pas pendant la plage. C'est signalé ci-dessous.}}</li></ul></div>
                        <table class="table table-condensed tf-rows" id="table_tf_quiet">
                            <thead><tr><th>{{Nom}}</th><th>{{Jours}}</th><th>{{De}}</th><th>{{À}}</th><th>{{Vitesse max}}</th><th></th></tr></thead>
                            <tbody></tbody>
                        </table>
                        <a class="btn btn-default btn-sm" id="bt_tfAddQuiet" style="margin-left:15px;"><i class="fas fa-plus"></i> {{Ajouter une plage de silence}}</a>
                        <div class="tf-live" data-live="quiet_conflicts" style="margin:8px 15px;"></div>
                    </fieldset>

                    <fieldset>
                        <legend><i class="fas fa-magic"></i> {{Mode automatique}}</legend>
                        <?php
                        tf_check('vmc_auto_default', '{{Mode automatique actif au démarrage du plugin}}', '{{Ensuite, le mode se change avec « VMC auto ON / OFF » (widget ou scénario).}}');
                        tf_select('vmc_temp_ref', '{{Température de la maison}}', array('down' => '{{Thermomètre du bas}}', 'up' => '{{Thermomètre du haut}}', 'avg' => '{{Moyenne bas / haut}}'), '{{Température comparée aux seuils des règles ci-dessous.}}');
                        tf_select('vmc_off_speed', '{{Vitesse quand on coupe le mode auto}}', tf_speeds(), '{{Envoyée une fois quand on coupe le mode auto. Ensuite, le plugin ne touche plus à la VMC.}}');
                        tf_select('vmc_fallback_speed', '{{Vitesse de repli}}', tf_speeds(), '{{Utilisée si une température ne peut pas être lue.}}');
                        tf_num('vmc_dwell', '{{Temps minimum entre deux changements}}', 'min', 0, 120, 1, '{{Évite que la VMC change sans arrêt. Ne retarde jamais le chauffage, le boost, la fin du boost ni les plages.}}');
                        tf_num('vmc_manual_hold', '{{Respecter un réglage manuel pendant}}', 'min', 0, 240, 5, '{{Si quelqu\'un change la vitesse en dehors du plugin (appli, bouton), le plugin attend ce temps avant de reprendre. Une vitesse 4 manuelle est coupée au bout de la durée maximum du boost.}}');
                        ?>
                    </fieldset>

                    <fieldset>
                        <legend><i class="fas fa-thermometer-three-quarters"></i> {{Règles de vitesse}}</legend>
                        <div class="alert alert-info tf-explain">
                            {{Deux façons de décider, à choisir pour chaque saison :}}
                            <ul><li><b>{{Températures}}</b> : {{le plugin compare la maison à vos seuils et l'air du dehors à l'air de la maison.}}</li>
                            <li><b>{{Bypass de la VMC}}</b> : {{le plugin suit le bypass de la VMC. Ouvert = la VMC rafraîchit, on accélère. Fermé = on garde la vitesse « bonne température ».}}</li></ul>
                            <div class="tf-rule" style="margin-top:4px;">{{Dans les deux cas : le chauffage, le CO2, les plages de renouvellement et le silence passent par-dessus. Les statistiques comparent les deux modes.}}</div>
                        </div>
                        <?php
                        tf_num('vmc_hyst', '{{Battement}}', '°C', 0.1, 2, 0.1, '{{Le « plus ou moins un demi-degré ». On change de zone au seuil + battement dans un sens, au seuil dans l\'autre.}}');
                        tf_num('vmc_delta', '{{Dehors « plus frais » si}}', '{{°C de moins que dedans}}', 0, 10, 0.5, '{{Mode températures : écart minimum pour que l\'air du dehors serve à rafraîchir.}}');
                        tf_num('vmc_cold_out', '{{Dehors « froid » sous}}', '°C', -10, 30, 0.5, '{{Maison fraîche : sous cette température dehors, on n\'accélère pas même si l\'air est plus frais.}}');
                        ?>
                        <div class="form-group">
                            <div class="col-sm-9 col-sm-offset-3">
                                <table class="table table-condensed tf-season-table" style="max-width:680px;">
                                    <thead><tr><th></th><th>❄️ {{Hiver}}</th><th>☀️ {{Été}}</th></tr></thead>
                                    <tbody>
                                    <tr><td>{{Décider avec}}<?php echo tf_tip('{{Températures : seuils ci-dessous. Bypass : suit le bypass de la VMC (commande à choisir plus haut).}}'); ?></td><td><select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="vmc_mode_w"><option value="temp">{{Températures}}</option><option value="bypass">{{Bypass de la VMC}}</option></select></td><td><select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="vmc_mode_s"><option value="temp">{{Températures}}</option><option value="bypass">{{Bypass de la VMC}}</option></select></td></tr>
                                    <tr><td>{{Maison fraîche sous}}<?php echo tf_tip('{{Sous ce seuil, la maison est « fraîche ».}}'); ?></td><td><input type="number" step="0.5" min="15" max="35" class="eqLogicAttr form-control tf-num" data-l1key="configuration" data-l2key="w_t_fresh"></td><td><input type="number" step="0.5" min="15" max="35" class="eqLogicAttr form-control tf-num" data-l1key="configuration" data-l2key="s_t_fresh"></td></tr>
                                    <tr><td>{{Maison trop chaude à partir de}}<?php echo tf_tip('{{Au-dessus de ce seuil (+ battement), la maison est « trop chaude ». Entre les deux : « bonne température ».}}'); ?></td><td><input type="number" step="0.5" min="15" max="35" class="eqLogicAttr form-control tf-num" data-l1key="configuration" data-l2key="w_t_hot"></td><td><input type="number" step="0.5" min="15" max="35" class="eqLogicAttr form-control tf-num" data-l1key="configuration" data-l2key="s_t_hot"></td></tr>
                                    <tr class="tf-subhead"><td colspan="3">{{Vitesse quand l'air du dehors est plus frais que la maison}}<?php echo tf_tip('{{Au moins « Dehors plus frais si » °C de moins que dedans. En hiver, c\'est presque toujours le cas : ce sont ces vitesses qui s\'appliquent.}}'); ?></td></tr>
                                    <tr><td>{{Maison fraîche}}<?php echo tf_tip('{{Dehors plus frais mais pas froid.}}'); ?></td><td><select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="w_sp_fresh_c"><option value="1">V1</option><option value="2">V2</option><option value="3">V3</option></select></td><td><select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="s_sp_fresh_c"><option value="1">V1</option><option value="2">V2</option><option value="3">V3</option></select></td></tr>
                                    <tr><td>{{Bonne température}}<?php echo tf_tip(''); ?></td><td><select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="w_sp_comf_c"><option value="1">V1</option><option value="2">V2</option><option value="3">V3</option></select></td><td><select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="s_sp_comf_c"><option value="1">V1</option><option value="2">V2</option><option value="3">V3</option></select></td></tr>
                                    <tr><td>{{Trop chaude (on rafraîchit)}}<?php echo tf_tip('{{Aussi la vitesse quand le bypass est ouvert (mode bypass).}}'); ?></td><td><select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="w_cool_day"><option value="1">V1</option><option value="2">V2</option><option value="3">V3</option></select></td><td><select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="s_cool_day"><option value="1">V1</option><option value="2">V2</option><option value="3">V3</option></select></td></tr>
                                    <tr><td>{{Trop chaude, en plage de silence}}<?php echo tf_tip('{{Toujours plafonnée par la vitesse maximum du silence.}}'); ?></td><td><select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="w_cool_night"><option value="1">V1</option><option value="2">V2</option><option value="3">V3</option></select></td><td><select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="s_cool_night"><option value="1">V1</option><option value="2">V2</option><option value="3">V3</option></select></td></tr>
                                    <tr class="tf-subhead"><td colspan="3">{{Vitesse quand l'air du dehors n'est pas plus frais}}<?php echo tf_tip('{{Dehors aussi chaud ou plus chaud que dedans (surtout l\'été) : on évite de faire entrer cet air.}}'); ?></td></tr>
                                    <tr><td>{{Maison fraîche}}<?php echo tf_tip(''); ?></td><td><select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="w_sp_fresh_n"><option value="1">V1</option><option value="2">V2</option><option value="3">V3</option></select></td><td><select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="s_sp_fresh_n"><option value="1">V1</option><option value="2">V2</option><option value="3">V3</option></select></td></tr>
                                    <tr><td>{{Bonne température}}<?php echo tf_tip('{{Aussi la vitesse quand le bypass est fermé (mode bypass).}}'); ?></td><td><select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="w_sp_comf_n"><option value="1">V1</option><option value="2">V2</option><option value="3">V3</option></select></td><td><select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="s_sp_comf_n"><option value="1">V1</option><option value="2">V2</option><option value="3">V3</option></select></td></tr>
                                    <tr><td>{{Trop chaude}}<?php echo tf_tip('{{Dehors plus chaud : on garde la fraîcheur.}}'); ?></td><td><select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="w_sp_hot_n"><option value="1">V1</option><option value="2">V2</option><option value="3">V3</option></select></td><td><select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="s_sp_hot_n"><option value="1">V1</option><option value="2">V2</option><option value="3">V3</option></select></td></tr>
                                    </tbody>
                                </table>
                                <div class="tf-live" data-live="zones"></div>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend><i class="fas fa-smog"></i> {{CO2}}</legend>
                        <?php
                        tf_num('co2_high', '{{Renouveler l\'air au-dessus de}}', 'ppm', 500, 5000, 50, '{{Au-dessus, la VMC passe au moins à la vitesse choisie ci-dessous.}}');
                        tf_num('co2_low', '{{Arrêter sous}}', 'ppm', 400, 5000, 50, '{{La VMC revient à la normale sous cette valeur.}}');
                        tf_select('co2_speed', '{{Vitesse minimum si CO2 élevé}}', tf_speeds());
                        ?>
                    </fieldset>

                    <fieldset>
                        <legend><i class="fas fa-clock"></i> {{Plages de renouvellement}}</legend>
                        <div class="alert alert-info tf-explain">{{Vitesse imposée sur un créneau (ex. 12h-16h en vitesse 2).}}
                            <ul><li>{{Saison : choisissez « Été » pour ne pas ventiler plus fort en hiver.}}</li>
                            <li>{{« Sauf jour chaud » : plage ignorée les jours où l'aération matinale a eu lieu.}}</li>
                            <li>{{Le calendrier de silence reste prioritaire.}}</li></ul></div>
                        <div class="tf-live" data-live="windows_winter" style="margin:0 15px 8px 15px;"></div>
                        <table class="table table-condensed tf-rows" id="table_tf_windows">
                            <thead><tr><th>{{Nom}}</th><th>{{Saison}}</th><th>{{Jours}}</th><th>{{De}}</th><th>{{À}}</th><th>{{Vitesse}}</th><th>{{Sauf jour chaud}}</th><th></th></tr></thead>
                            <tbody></tbody>
                        </table>
                        <a class="btn btn-default btn-sm" id="bt_tfAddWindow" style="margin-left:15px;"><i class="fas fa-plus"></i> {{Ajouter une plage}}</a>
                    </fieldset>

                    <fieldset>
                        <legend><i class="fas fa-sun"></i> {{Aération matinale des jours chauds (été)}}</legend>
                        <?php
                        tf_check('flush_enabled', '{{Activer}}', '{{S\'il fait déjà chaud dehors au réveil, la journée sera chaude : on aère fort le matin, puis on garde la fraîcheur.}}');
                        tf_time('flush_detect_start', '{{Vérifier de}}', '{{Créneau où l\'on regarde la température du dehors.}}');
                        tf_time('flush_detect_end', '{{à}}');
                        tf_num('flush_out', '{{Jour chaud si dehors au-dessus de}}', '°C', 5, 35, 0.5);
                        tf_time('flush_end', '{{Aérer jusqu\'à}}');
                        tf_select('flush_speed', '{{Vitesse d\'aération}}', tf_speeds());
                        ?>
                    </fieldset>

                    <fieldset>
                        <legend><i class="fas fa-rocket"></i> {{Boosts et vitesses temporaires — déclenchés de l'extérieur}}<?php echo tf_tip('{{Chaque ligne crée une commande action du même nom : bouton (salle de bain, cuisine…), interrupteur, détecteur d\'humidité, scénario ou widget. Elle impose la vitesse choisie pendant la durée choisie, puis le plugin reprend la main. Ajoutez autant de lignes que vous voulez.}}'); ?></legend>
                        <table class="table table-condensed tf-rows" id="table_tf_boosts" style="max-width:950px;">
                            <thead><tr><th style="width:280px;">{{Nom}}<?php echo tf_tip('{{Nom de la commande action créée à la sauvegarde (onglet Commandes), à utiliser dans vos scénarios ou boutons. Renommer la ligne renomme la commande ; supprimer la ligne supprime la commande.}}'); ?></th><th style="width:110px;">{{Vitesse}}</th><th style="width:130px;">{{Durée}}</th><th style="width:80px;">{{Widget}}<?php echo tf_tip('{{Coché : bouton sur le widget de l\'équipement.}}'); ?></th><th style="width:260px;"></th><th style="width:40px;"></th></tr></thead>
                            <tbody></tbody>
                        </table>
                        <a class="btn btn-default btn-sm" id="bt_tfAddBoost" style="margin-left:15px;"><i class="fas fa-plus"></i> {{Ajouter un boost}}</a>
                        <?php
                        tf_num('boost_max', '{{Vitesse 4 mise à la main : respectée au plus}}', 'min', 1, 60, 1, '{{Si quelqu\'un met la VMC en vitesse 4 sans passer par le plugin, le plugin la laisse faire au plus ce temps, puis reprend la main.}}');
                        ?>
                        <div class="alert alert-info tf-explain">
                            <b>{{Exemple avec un bouton à appuis multiples}}</b> : {{1 appui → un boost court, 2 appuis → un boost plus long, 3 appuis → une vitesse 3 pendant 1 h. Un détecteur d'humidité ou un scénario peut lancer les mêmes commandes.}}
                            <br>{{Le scénario déclencheur ne doit rien faire d'autre sur la VMC (pas de vitesse 4 directe, pas de « VMC AUTO OFF », pas de bloc DANS) : le plugin gère la fin du boost.}}
                        </div>
                    </fieldset>
                </form>
            </div>

            <!-- ============================ CHAUFFAGE ============================ -->
            <div role="tabpanel" class="tab-pane" id="heattab">
                <form class="form-horizontal tf-module" data-module="mod_heat">
                    <div class="alert alert-warning tf-module-disabled" style="display:none; margin:10px;">{{Le module chauffage est désactivé (onglet Maison).}}</div>
                    <fieldset>
                        <legend><i class="fas fa-sliders-h"></i> {{Thermostat}}</legend>
                        <?php
                        tf_check('heat_on_default', '{{Thermostat actif au démarrage du plugin}}', '{{Ensuite, se change avec « Thermostat ON / OFF » (widget ou scénario).}}');
                        tf_select('heat_mode_default', '{{Mode au démarrage du plugin}}', array('normal' => '{{☀️ Normal}}', 'eco' => '{{🌙 ECO}}'), '{{Normal : chaque plage utilise sa consigne. ECO (absence, vacances) : on ne chauffe jamais au-dessus de la consigne ECO. Se change ensuite sur le widget ou par scénario.}}', 200);
                        tf_num('heat_consigne', '{{Consigne Normal de départ}}', '°C', 5, 30, 0.5, '{{Température voulue dans les plages « Normal ». Se règle ensuite sur le widget.}}');
                        tf_num('heat_eco', '{{Consigne ECO de départ}}', '°C', 5, 30, 0.5, '{{Température plus basse, pour les plages « ECO », hors plage si choisi, et partout en mode ECO. Au moins 0,5 °C sous Normal.}}');
                        tf_num('heat_c_min', '{{Consigne réglable de}}', '°C', 5, 30, 0.5, '{{Limites des boutons − / + du widget et des curseurs « Régler la consigne Normal » / « Régler la consigne ECO » (Jeedom Connect, scénarios), par pas de 0,5 °C.}}');
                        tf_num('heat_c_max', '{{à}}', '°C', 10, 30, 0.5);
                        tf_num('heat_hyst_low', '{{Allumer quand il manque}}', '{{°C sous la consigne}}', 0, 2, 0.1, '{{Écart sous la consigne qui allume la batterie.}}');
                        tf_num('heat_hyst_high', '{{Éteindre quand on dépasse de}}', '{{°C au-dessus}}', 0, 2, 0.1, '{{Écart au-dessus de la consigne qui éteint la batterie. Entre les deux, elle ne change pas.}}');
                        ?>
                        <div class="form-group"><div class="col-sm-9 col-sm-offset-3"><span class="tf-live" data-live="thermostat"></span></div></div>
                    </fieldset>

                    <fieldset>
                        <legend><i class="fas fa-shield-alt"></i> {{Sécurités}}</legend>
                        <?php
                        tf_num('heat_postvent', '{{Garder la VMC après l\'arrêt d\'une batterie}}', 'min', 0, 15, 1, '{{Après l\'arrêt d\'une batterie, la VMC garde sa vitesse ce temps-là pour évacuer la chaleur restante.}}');
                        tf_check('heat_only_winter', '{{Chauffer uniquement en hiver}}', '{{Les mois d\'hiver se règlent dans l\'onglet Maison.}}');
                        tf_check('heat_off_when_disabled', '{{Thermostat OFF = éteindre les batteries}}', '{{Décoché : en thermostat OFF, le plugin ne touche plus aux batteries.}}');
                        tf_num('heat_out_max', '{{Pas de chauffe si dehors au-dessus de}}', '°C', -20, 30, 0.5, '{{Optionnel. Vide = pas de limite.}}');
                        ?>
                    </fieldset>

                    <div class="row" style="margin:0;">
                        <?php foreach (array('b1' => array('{{Batterie 1}}', '{{principale, en général pour le bas}}'), 'b2' => array('{{Batterie 2}}', '{{facultative : autre zone ou appoint}}')) as $b => $txt) { ?>
                            <div class="col-lg-6">
                                <div class="tf-bat-panel" data-bat="<?php echo $b; ?>">
                                    <legend><i class="fas fa-fire-alt"></i> <?php echo $txt[0]; ?> <small style="opacity:.7;">(<?php echo $txt[1]; ?>)</small></legend>
                                    <?php tf_check($b . '_enabled', '{{Utiliser cette batterie}}'); ?>
                                    <div class="form-group">
                                        <label class="col-sm-3 control-label">{{Nom}}<?php echo tf_tip('{{Nom affiché partout : widget, messages, statistiques. Ex. « Bas », « Séjour », « Étage », « Chambres ».}}'); ?></label>
                                        <div class="col-sm-5"><input type="text" class="eqLogicAttr form-control tf-bat-name" data-l1key="configuration" data-l2key="<?php echo $b; ?>_name"></div>
                                    </div>
                                    <?php
                                    tf_select($b . '_sensor', '{{Régulée sur}}', array('down' => '{{le thermomètre du bas}}', 'up' => '{{le thermomètre du haut}}', 'avg' => '{{la moyenne bas / haut}}'), '{{Thermomètre comparé à la consigne pour allumer ou éteindre cette batterie. Par défaut : le bas pour la batterie 1, le haut pour la batterie 2.}}', 230);
                                    tf_cmd($b . '_on_cmd', '{{Allumer}}', 'action', '', array('required' => 1, 'subtypes' => 'other'));
                                    tf_cmd($b . '_off_cmd', '{{Éteindre}}', 'action', '', array('required' => 1, 'subtypes' => 'other'));
                                    tf_cmd($b . '_state_cmd', '{{État}}', 'info', '{{0 ou 1. Conseillé : le plugin vérifie que la batterie a obéi. Utile au délestage et aux statistiques.}}', array('binary' => 1));
                                    tf_num($b . '_power', '{{Puissance}}', 'W', 0, 5000, 50, '{{Puissance de VOTRE batterie, en watts (plaque signalétique). Sert à calculer la consommation et au délestage.}}');
                                    tf_select($b . '_min_speed', '{{Vitesse VMC minimum}}', array('2' => '{{Vitesse 2}}', '3' => '{{Vitesse 3}}'), '{{La batterie ne chauffe bien qu\'à partir de cette vitesse. Le plugin monte la VMC avant de l\'allumer et l\'éteint si la VMC redescend.}}', 160);
                                    tf_select($b . '_outside', '{{Hors plage}}', array('off' => '{{Éteinte}}', 'eco' => '{{Consigne ECO}}', 'normal' => '{{Consigne Normal}}'), '{{Ce que fait la batterie quand aucune de ses plages n\'est en cours.}}', 220);
                                    tf_num($b . '_min_on', '{{Marche minimum}}', 'min', 0, 240, 1, '{{Une fois allumée, elle chauffe au moins ce temps. Seuls la sécurité, le délestage et le silence l\'arrêtent avant.}}');
                                    tf_num($b . '_min_off', '{{Arrêt minimum}}', 'min', 0, 240, 1, '{{Une fois éteinte, elle attend au moins ce temps avant de repartir.}}');
                                    tf_num($b . '_max_on', '{{Marche continue maximum}}', 'min', 0, 1440, 5, '{{Au-delà, une pause est imposée. 0 = pas de limite.}}');
                                    tf_num($b . '_rest', '{{Durée de la pause}}', 'min', 0, 240, 1, '{{Pause après une marche continue maximum.}}');
                                    tf_num($b . '_safety', '{{Couper au-dessus de}}', '°C', 15, 35, 0.5, '{{Sécurité : la batterie s\'éteint au-dessus, même en marche forcée.}}');
                                    ?>
                                </div>
                            </div>
                        <?php } ?>
                    </div>

                    <fieldset>
                        <legend><i class="fas fa-user-shield"></i> {{Verrou de nuit (sécurité)}}</legend>
                        <div class="alert alert-info tf-explain">{{Sur ce créneau, aucune batterie ne chauffe, quoi que disent les plages ou un chauffage forcé. C'est un filet de sécurité en cas d'erreur de réglage. S'il coupe ou bloque une batterie, vous recevez une notification « Sécurité ». Commandes « Verrou de nuit ON / OFF » pour le couper ponctuellement.}}</div>
                        <?php
                        tf_check('night_lock_enabled', '{{Activer le verrou de nuit}}', '{{Fortement conseillé.}}');
                        tf_time('night_lock_start', '{{De}}');
                        tf_time('night_lock_end', '{{À}}');
                        tf_num('night_lock_frost', '{{Sauf hors-gel sous}}', '°C', 5, 20, 0.5, '{{Si la zone descend sous cette température pendant le verrou, la batterie chauffe juste assez pour la remonter à cette valeur. Vide = jamais.}}');
                        ?>
                    </fieldset>

                    <fieldset>
                        <legend><i class="fas fa-fire"></i> {{Chauffage forcé (à distance)}}</legend>
                        <div class="alert alert-info tf-explain">{{Pour chauffer avant de rentrer, même hors plage ou en mode ECO : la batterie chauffe jusqu'à la consigne Normal pendant la durée choisie, puis retour aux plages. Toutes les sécurités restent actives (verrou de nuit, température maximum, délestage).}}
                            <ul><li><code>Forcer la batterie 1 (minutes)</code> / <code>Forcer les deux batteries (minutes)</code> : {{curseur = durée en minutes (bouton JeedomConnect, scénario).}}</li>
                            <li><code>Forcer le chauffage</code> : {{titre « bas » ou « tout », message = durée (60, 1h, 1h30).}}</li>
                            <li><code>Arrêter le chauffage forcé</code>.</li></ul></div>
                        <?php
                        tf_num('heat_force_default', '{{Durée par défaut}}', 'min', 5, 720, 5, '{{Utilisée par les boutons du widget, et si aucune durée n\'est donnée.}}');
                        ?>
                    </fieldset>

                    <fieldset>
                        <legend><i class="fas fa-hands-helping"></i> {{Appoint automatique}}</legend>
                        <?php
                        tf_check('assist_enabled', '{{La batterie 2 aide la batterie 1 si elle n\'y arrive pas}}', '{{Jamais pendant une plage de la batterie 2, pour ne pas surchauffer sa propre zone.}}');
                        tf_num('assist_after', '{{Si la batterie 1 chauffe depuis}}', 'min', 5, 600, 5);
                        tf_num('assist_gap', '{{et qu\'il manque encore au moins}}', '°C', 0.1, 5, 0.1);
                        ?>
                        <div class="form-group"><div class="col-sm-9 col-sm-offset-3"><span class="tf-live" data-live="assist"></span></div></div>
                    </fieldset>

                    <fieldset>
                        <legend><i class="fas fa-calendar-week"></i> {{Plages de chauffage}}</legend>
                        <div class="alert alert-info tf-explain">
                            {{Autant de plages que vous voulez. Chacune vaut pour le bas, le haut, ou les deux. Modes :}}
                            <ul><li><b>{{Normal}}</b> / <b>{{ECO}}</b> : {{consigne Normal ou ECO du widget ;}}</li>
                            <li><b>{{Consigne fixe}}</b> : {{une température à part (ex. chambres à 19,5 °C) ;}}</li>
                            <li><b>{{Marche forcée}}</b> : {{chauffe toute la plage ;}} <b>{{Arrêt}}</b> : {{ne chauffe pas.}}</li></ul>
                            <div class="tf-rule" style="margin-top:4px;">{{Mode ECO activé (widget) : on ne dépasse jamais la consigne ECO. Arrêt reste Arrêt.}}<?php echo tf_tip('{{Si deux plages se chevauchent : Arrêt, puis Marche forcée, puis Normal, puis la consigne fixe la plus haute, puis ECO. Une plage peut passer minuit (ex. 22:00 → 06:00).}}'); ?></div>
                        </div>
                        <table class="table table-condensed tf-rows" id="table_tf_slots">
                            <thead><tr><th>{{Nom}}</th><th>{{Batteries}}</th><th>{{Jours}}</th><th>{{De}}</th><th>{{À}}</th><th>{{Mode}}</th><th>{{Consigne}}</th><th></th></tr></thead>
                            <tbody></tbody>
                        </table>
                        <div style="margin-left:15px;">
                            <a class="btn btn-default btn-sm" id="bt_tfAddSlot"><i class="fas fa-plus"></i> {{Ajouter une plage}}</a>
                        </div>
                        <div class="tf-week" id="tf_week"></div>
                    </fieldset>
                </form>
            </div>

            <!-- ============================ DÉLESTAGE ============================ -->
            <div role="tabpanel" class="tab-pane" id="shedtab">
                <form class="form-horizontal tf-module" data-module="mod_shed">
                    <div class="alert alert-warning tf-module-disabled" style="display:none; margin:10px;">{{Le délestage est désactivé (onglet Maison).}}</div>
                    <fieldset>
                        <legend><i class="fas fa-bolt"></i> {{Délestage sur la puissance Linky}}</legend>
                        <div class="alert alert-info tf-explain">
                            {{Le plugin réagit à chaque nouvelle mesure du Linky.}}
                            <ul><li>{{Seuil atteint : il coupe la première batterie.}}</li>
                            <li>{{Toujours au seuil après le délai (15 s) : il coupe la seconde.}}</li>
                            <li>{{Il les rallume une par une quand il y a de nouveau la place.}}</li>
                            <li>{{Chaque coupure est notifiée, même la nuit.}}</li></ul>
                        </div>
                        <?php
                        tf_cmd('shed_power_cmd', '{{Puissance instantanée}}', 'info', '{{Ex. « PAPP » (VA) ou puissance soutirée (W) du Linky.}}', array('min' => 0, 'max' => 36000, 'numeric' => 1, 'subtype' => 'numeric', 'required' => 1));
                        tf_num('shed_limit', '{{Seuil}}', '{{(même unité que la mesure)}}', 500, 36000, 100, '{{Gardez une marge sous l\'abonnement (ex. 8 500 pour 9 kVA).}}');
                        tf_select('shed_first', '{{Couper d\'abord}}', array('b2' => '{{la batterie 2 (haut), puis la 1}}', 'b1' => '{{la batterie 1 (bas), puis la 2}}'));
                        tf_num('shed_delay', '{{Délai avant la 2e coupure}}', 's', 5, 300, 1, '{{Si la puissance est encore au seuil après ce délai, la seconde batterie est coupée.}}');
                        tf_num('shed_margin', '{{Marge de réenclenchement}}', '{{(même unité)}}', 0, 5000, 50, '{{Une batterie ne se rallume que si puissance + sa puissance + cette marge reste sous le seuil.}}');
                        tf_num('shed_restore', '{{Attendre au moins avant de réenclencher}}', 'min', 1, 120, 1, '{{Temps minimum entre une coupure et le rallumage.}}');
                        ?>
                    </fieldset>
                </form>
            </div>

            <!-- ============================ FILTRE ============================ -->
            <div role="tabpanel" class="tab-pane" id="filtertab">
                <form class="form-horizontal tf-module" data-module="mod_filter">
                    <div class="alert alert-warning tf-module-disabled" style="display:none; margin:10px;">{{Le module filtre est désactivé (onglet Maison).}}</div>
                    <fieldset>
                        <legend><i class="fas fa-filter"></i> {{Filtre de la VMC}}</legend>
                        <?php tf_cmd('filter_cmd', '{{Statut du filtre}}', 'info', '{{MyModbus : info, 0x04 Read input registers, registre 4100. Passe à 1 quand il faut nettoyer le filtre, revient à 0 une fois fait. La date du nettoyage est alors notée.}}', array('binary' => 1, 'required' => 1)); ?>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Valeur « à nettoyer »}}<?php echo tf_tip('{{Valeur lue quand le filtre doit être nettoyé (1 en général).}}'); ?></label>
                            <div class="col-sm-5"><input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="filter_value" style="width:80px;"></div>
                            <div class="col-sm-4 tf-preview-col"><span class="tf-live" data-live="filter_now"></span></div>
                        </div>
                        <?php
                        tf_time('filter_notify_time', '{{Prévenir à}}', '{{Heure du rappel (catégorie Filtre des notifications).}}');
                        tf_select('filter_repeat', '{{Répéter}}', array('daily' => '{{Tous les jours tant que ce n\'est pas fait}}', 'once' => '{{Une seule fois}}'));
                        tf_cmd('filter_reset_cmd', '{{Remise à zéro du filtre}}', 'action', '{{Facultatif. MyModbus : action, 0x06, registre 8010, valeur 1. Permet la commande « Filtre nettoyé (remise à zéro VMC) » du plugin, à lancer après avoir nettoyé ou changé les filtres. Sinon, remise à zéro sur l\'écran de la VMC (assistant filtre ou étape 4.3).}}', array('subtypes' => 'other'));
                        ?>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Dernier nettoyage}}</label>
                            <div class="col-sm-9"><span id="tf_filter_cleaned" class="tf-big">—</span></div>
                        </div>
                    </fieldset>
                </form>
            </div>

            <!-- ============================ NOTIFICATIONS ============================ -->
            <div role="tabpanel" class="tab-pane" id="notiftab">
                <form class="form-horizontal">
                    <fieldset>
                        <legend><i class="fas fa-bell"></i> {{Destinataires}}</legend>
                        <details style="margin:0 0 10px 15px;" open>
                            <summary style="cursor:pointer;font-weight:bold;">{{Ce que reçoit chaque case}}</summary>
                            <table class="table table-condensed" style="max-width:1100px;margin-top:6px;font-size:0.95em;">
                                <thead><tr><th style="width:150px;">{{Case}}</th><th>{{Messages}}</th><th style="width:150px;">{{Pendant le repos}}</th></tr></thead>
                                <tbody>
                                    <tr><td><b>{{Toutes les actions}}</b></td><td>{{Le canal journal (Discord, Telegram, Slack…) : tout ce qui suit, plus :}}<ul style="margin:2px 0 0 0;padding-left:16px;"><li>💨 {{chaque changement de vitesse de la VMC avec sa raison : température, CO2, plage de renouvellement, plage de silence, demande du chauffage, boost et sa fin, réglage manuel détecté (voir « Changements de vitesse de la VMC » sous le tableau)}}</li><li>💨 {{mode automatique de la VMC activé / coupé, forçage annulé}}</li><li>🔥 {{thermostat ON / OFF, mode Normal / ECO, consignes réglées, chauffage forcé (début, fin, arrêt)}}</li><li>☀️ {{maison chauffée par le soleil (la chaleur ne vient pas des batteries)}}</li><li>🧹 {{avertissement filtre remis à zéro}}</li></ul></td><td>{{selon la case Repos}}</td></tr>
                                    <tr><td><b>VMC</b></td><td>{{VMC sortie du mode ModBus (coupure de courant…), ordre renvoyé. Les changements de vitesse seulement si le réglage sous le tableau le demande.}}</td><td>{{selon la case Repos}}</td></tr>
                                    <tr><td><b>{{Chauffage}}</b></td><td>{{Chaque batterie allumée / éteinte avec la température, la consigne et la raison ; batterie bloquée par une plage de silence de la VMC.}}</td><td>{{selon la case Repos}}</td></tr>
                                    <tr><td><b>{{Délestage}}</b></td><td>{{Batteries coupées puis réenclenchées par le Linky.}}</td><td>{{toujours envoyé}}</td></tr>
                                    <tr><td><b>{{Filtre}}</b></td><td>{{Filtre à nettoyer (à l'heure choisie), filtre nettoyé.}}</td><td>{{selon la case Repos}}</td></tr>
                                    <tr><td><b>{{Préchauffage}}</b></td><td>{{Préchauffage antigel de la VMC allumé (température extérieure, puissance) puis éteint (durée).}}</td><td>{{selon la case Repos}}</td></tr>
                                    <tr><td><b>{{Alertes}}</b></td><td>{{Pannes : VMC ou batterie qui n'obéit pas, VMC qui ne répond plus en ModBus, capteur illisible, configuration invalide, bypass ouvert pendant la chauffe.}}</td><td>{{toujours envoyé}}</td></tr>
                                    <tr><td><b>{{Sécurité}}</b></td><td>{{Verrou de nuit déclenché ou activé / désactivé, garde-fou (batterie qui chauffe sans raison, vitesse 4 sans boost).}}</td><td>{{toujours envoyé}}</td></tr>
                                </tbody>
                            </table>
                        </details>
                        <table class="table table-condensed tf-rows" id="table_tf_notifs">
                            <thead><tr><th style="width:28%;">{{Commande (message)}}</th><th>{{Titre}}<?php echo tf_tip('{{Vide = titre automatique selon le sujet (VMC, CHAUFFAGE, DÉLESTAGE, FILTRE VMC, ALERTE, THERMOFLAIR 325).}}'); ?></th><th>{{HTML}}<?php echo tf_tip('{{Coché : retours à la ligne en <br/> (pour les commandes qui affichent du HTML).}}'); ?></th><th>{{Toutes les actions}}</th><th>VMC</th><th>{{Chauffage}}</th><th>{{Délestage}}</th><th>{{Filtre}}</th><th>{{Préchauffage}}</th><th>{{Alertes}}</th><th>{{Sécurité}}</th><th>{{Repos}}<?php echo tf_tip('{{Pendant la plage de repos, ce destinataire ne reçoit que le délestage, les alertes et la sécurité.}}'); ?></th><th><?php echo tf_tip('{{✈️ envoie un message de test par sujet coché, avec les vraies valeurs du moment.}}'); ?></th></tr></thead>
                            <tbody></tbody>
                        </table>
                        <a class="btn btn-default btn-sm" id="bt_tfAddNotif" style="margin-left:15px;"><i class="fas fa-plus"></i> {{Ajouter un destinataire}}</a>
                    </fieldset>
                    <fieldset>
                        <legend><i class="fas fa-fan"></i> {{Changements de vitesse de la VMC}}</legend>
                        <?php
                        tf_select('vmc_speed_notif', '{{Envoyer à}}', array('log' => '{{« Toutes les actions » seulement (conseillé)}}', 'vmc' => '{{« Toutes les actions » et « VMC »}}'), '{{Chaque changement de vitesse (température, CO2, plage de renouvellement, silence, chauffage, boost et sa fin, réglage manuel) est un message de routine : par défaut il ne part que sur le canal journal « Toutes les actions ». La case « VMC » garde alors seulement ce qui demande votre attention : VMC sortie du mode ModBus, préchauffage (si sa case est vide). Les pannes restent dans « Alertes ».}}', 360);
                        ?>
                    </fieldset>
                    <fieldset>
                        <legend><i class="fas fa-bed"></i> {{Repos}}</legend>
                        <?php
                        tf_time('rest_start', '{{De}}');
                        tf_time('rest_end', '{{À}}', '{{Vaut pour les destinataires dont la case « Repos » est cochée.}}');
                        ?>
                    </fieldset>
                </form>
            </div>

            <!-- ============================ STATISTIQUES ============================ -->
            <div role="tabpanel" class="tab-pane" id="statstab">
                <div class="tf-inline" style="margin:10px 0;">
                    <span>{{Période}}</span>
                    <select class="form-control" id="tf_stats_days" style="width:130px;">
                        <option value="7">7 {{jours}}</option>
                        <option value="30" selected>30 {{jours}}</option>
                        <option value="90">90 {{jours}}</option>
                        <option value="365">1 {{an}}</option>
                    </select>
                    <a class="btn btn-default" id="bt_tfStatsRefresh"><i class="fas fa-sync"></i> {{Actualiser}}</a>
                    <span style="margin-left:20px;">{{Importer l'historique Jeedom des}}</span>
                    <input type="number" class="form-control" id="tf_import_days" value="90" min="1" max="730" style="width:80px;">
                    <span>{{derniers jours}}</span>
                    <a class="btn btn-default" id="bt_tfImport"><i class="fas fa-file-import"></i> {{Importer}}</a><?php echo tf_tip('{{Reprend l\'historique Jeedom des commandes choisies (cochez « Historiser » sur chacune) : températures, batteries, vitesse de VMC, Linky, CO2, luminosité, bypass, et aussi code météo, préchauffage et températures de l\'échangeur (efficacité recalculée). Les jours absents sont créés ; les jours déjà enregistrés sont seulement complétés là où il manque une valeur, sans rien écraser.}}'); ?>
                    <span style="margin-left:20px;">{{Conserver}}</span>
                    <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="stats_keep" min="30" max="1500" style="width:80px;" title="{{Sauvegardez l'équipement pour appliquer}}">
                    <span>{{jours}}</span>
                </div>
                <div class="help-block" style="margin-bottom:10px;">{{Un point est enregistré toutes les 5 minutes. L'import reprend l'historique Jeedom des commandes historisées. Les calculs sont fiables après une à deux semaines de chauffe.}}</div>
                <div id="tf_stats_quality" class="text-muted" style="margin-bottom:8px;"></div>
                <div class="tf-kpis" id="tf_kpis"></div>
                <div class="tf-card" id="tf_advice_card" style="display:none;">
                    <h4><i class="fas fa-magic"></i> {{Préconisations pour une température plus constante}}<?php echo tf_tip('{{Calculées sur l\'historique : inertie des batteries, retard au début des plages, cycles, soleil, VMC, et comparaison de vos réglages successifs. Rien n\'est changé tant que vous ne cliquez pas sur « Appliquer » puis « Sauvegarder ».}}'); ?></h4>
                    <div id="tf_stability"></div>
                    <div id="tf_advice"></div>
                    <div id="tf_periods_wrap" style="display:none; margin-top:8px;">
                        <a class="cursor" id="bt_tfPeriods"><i class="fas fa-history"></i> {{Vos réglages successifs et leurs résultats}}</a>
                        <div id="tf_periods" style="display:none; margin-top:6px;"></div>
                    </div>
                </div>
                <div class="tf-card" id="tf_insights_card" style="display:none;"><h4><i class="fas fa-lightbulb"></i> {{Ce qu'on apprend de la maison}}</h4><ul class="tf-insights" id="tf_insights"></ul></div>
                <div class="tf-charts">
                    <div class="tf-chart"><div class="tf-ch" id="tf_ch_week"></div><div class="tf-cap">{{14 derniers jours : températures et périodes de chauffe.}}</div></div>
                    <div class="tf-chart"><div class="tf-ch" id="tf_ch_daily"></div><div class="tf-cap">{{Énergie de chauffage par jour et température extérieure moyenne.}}</div></div>
                    <div class="tf-chart"><div class="tf-ch" id="tf_ch_heat"></div><div class="tf-cap">{{Vitesse de montée en température pendant la chauffe, selon la température extérieure (chaque point = une période de chauffe), et le modèle.}}</div></div>
                    <div class="tf-chart"><div class="tf-ch" id="tf_ch_cool"></div><div class="tf-cap">{{Refroidissement sans chauffage selon l'écart intérieur − extérieur (nuit / jour). Plus la pente est faible, mieux la maison garde la chaleur.}}</div></div>
                    <div class="tf-chart"><div class="tf-ch" id="tf_ch_profile"></div><div class="tf-cap">{{Journée type : températures moyennes heure par heure et part du temps où chaque batterie chauffe.}}</div></div>
                    <div class="tf-chart"><div class="tf-ch" id="tf_ch_speed"></div><div class="tf-cap">{{Déperdition selon la vitesse de VMC (°C perdus par heure pour 10 °C d'écart).}}</div></div>
                    <div class="tf-chart"><div class="tf-ch" id="tf_ch_sun"></div><div class="tf-cap">{{Hausse de température entre 10h et 16h selon le type de journée, mesurée sur les jours sans aucun chauffage de 8h à 16h (« j mesurés / jours de ce type »). Sans ces jours, estimation sur des tranches sans chauffage depuis 2 h (« tranches »), moins fiable. Un résultat inversé (gris > soleil) vient presque toujours d'un capteur de luminosité dont le maximum est réglé trop haut : voir les préconisations.}}</div></div>
                    <div class="tf-chart"><div class="tf-ch" id="tf_ch_noheat"></div><div class="tf-cap">{{Journées sans aucun chauffage de 4h à 18h : de combien la maison est montée entre le matin (minimum 6h-10h) et l'après-midi (maximum 10h-18h), selon le temps. C'est la mesure la plus directe de l'apport du soleil.}}</div></div>
                    <div class="tf-chart"><div class="tf-ch" id="tf_ch_signature"></div><div class="tf-cap">{{Signature énergétique : chaque point = un jour (énergie de chauffage selon la température extérieure moyenne). La pente donne les déperditions de la maison en W par degré d'écart ; là où la droite touche 0, on n'a plus besoin de chauffer. Indicateur clé d'une maison passive.}}</div></div>
                    <div class="tf-chart"><div class="tf-ch" id="tf_ch_comfort"></div><div class="tf-cap">{{Confort : part du temps passée à chaque température. Zone verte : 20 à 25 °C. Critère maison passive : pas plus de 10 % du temps au-dessus de 25 °C.}}</div></div>
                    <div class="tf-chart"><div class="tf-ch" id="tf_ch_air"></div><div class="tf-cap">{{Ventilation et qualité de l'air : part du temps à chaque vitesse de VMC et, avec un capteur, à chaque niveau de CO2 (moins de 1000 ppm = bon).}}</div></div>
                    <div class="tf-chart"><div class="tf-ch" id="tf_ch_hx"></div><div class="tf-cap">{{Échangeur : efficacité moyenne par jour (bypass fermé) et minutes de préchauffage antigel.}}</div></div>
                </div>
                <div class="tf-card" id="tf_modes_card" style="margin-top:10px; display:none;">
                    <h4><i class="fas fa-balance-scale"></i> {{Températures ou bypass : quel mode garde le mieux la chaleur ?}}</h4>
                    <div id="tf_modes"></div>
                    <div class="help-block">{{Chaque jour est compté dans le mode le plus utilisé ce jour-là. Plus les kWh par degré-jour et la perte de nuit sont bas, mieux le mode garde la chaleur.}}</div>
                </div>
                <div class="tf-card" id="tf_pred_card" style="margin-top:10px; display:none;">
                    <h4><i class="fas fa-stopwatch"></i> {{Combien de temps chauffer ?}}</h4>
                    <div id="tf_pred"></div>
                    <div class="help-block">{{Estimations du modèle pour une maison à 20 °C, la batterie seule, la VMC à sa vitesse minimum. « Nuit sans chauffe » : variation sur 8 h sans chauffage. « Midi ensoleillé » : variation par heure entre 10h et 16h une journée ensoleillée, batteries éteintes.}}</div>
                </div>
                <div class="tf-card" id="tf_report_card" style="margin-top:10px;">
                    <h4><i class="fas fa-envelope-open-text"></i> {{Rapport automatique}}<?php echo tf_tip('{{Aux jours et heures choisis, le plugin envoie un rapport : évolution par rapport à la période précédente, préconisations, ce qu\'on apprend de la maison, et les graphiques en images (pièces jointes). Idéal par mail.}}'); ?></h4>
                    <form class="form-horizontal">
                        <?php
                        tf_check('report_enabled', '{{Envoyer le rapport automatiquement}}');
                        tf_num('report_days', '{{Période analysée}}', '{{jours}}', 3, 90, 1, '{{Le rapport compare cette période à la précédente de même durée (ex. 7 jours : cette semaine contre la semaine d\'avant).}}');
                        tf_num('report_chart_days', '{{Historique des graphiques}}', '{{jours}}', 7, 60, 1, '{{Durée affichée par les graphiques du rapport (14 jours par défaut). Avec le plugin Mail, les graphiques sont dans le corps du mail ; avec les autres commandes (Discord, Telegram…), en pièces jointes.}}');
                        tf_check('report_charts', '{{Joindre les graphiques (images PNG)}}', '{{Températures de la semaine, énergie par jour, stabilité jour par jour, effet du temps qu\'il fait.}}');
                        ?>
                    </form>
                    <div class="row">
                        <div class="col-lg-5">
                            <b>{{Quand}}</b><?php echo tf_tip('{{Une ligne par heure d\'envoi, avec ses jours. Ex. mercredi et samedi à 8:00, et une autre ligne pour 19:00.}}'); ?>
                            <table class="table table-condensed tf-rows" id="table_tf_rsched"><thead><tr><th>{{Jours}}</th><th>{{Heure}}</th><th></th></tr></thead><tbody></tbody></table>
                            <a class="btn btn-default btn-xs" id="bt_tfAddRSched"><i class="fas fa-plus"></i> {{Ajouter une heure d'envoi}}</a>
                        </div>
                        <div class="col-lg-7">
                            <b>{{À qui}}</b><?php echo tf_tip('{{Commande action de type message : plugin Mail (conseillé, avec HTML et pièces jointes), Discord, Telegram... Les graphiques partent en pièces jointes (option « files » de Jeedom).}}'); ?>
                            <table class="table table-condensed tf-rows" id="table_tf_rrec"><thead><tr><th style="width:50%;">{{Commande (message)}}</th><th>{{Titre}}</th><th>{{HTML}}</th><th>{{Graphiques}}</th><th></th></tr></thead><tbody></tbody></table>
                            <a class="btn btn-default btn-xs" id="bt_tfAddRRec"><i class="fas fa-plus"></i> {{Ajouter un destinataire}}</a>
                        </div>
                    </div>
                    <div style="margin-top:8px;">
                        <a class="btn btn-default btn-sm" id="bt_tfReportPreview"><i class="fas fa-eye"></i> {{Aperçu}}</a>
                        <a class="btn btn-primary btn-sm" id="bt_tfReportSend"><i class="fas fa-paper-plane"></i> {{Envoyer maintenant}}</a>
                        <span class="text-muted" id="tf_report_next" style="margin-left:10px;"></span>
                    </div>
                    <div id="tf_report_preview" style="display:none; margin-top:10px; border-top:1px solid rgba(128,128,128,.3); padding-top:8px;"></div>
                </div>
            </div>

            <!-- ============================ DIAGNOSTIC ============================ -->
            <div role="tabpanel" class="tab-pane active" id="diagtab">
                <div style="margin:10px 0;">
                    <a class="btn btn-default" id="bt_tfCheck"><i class="fas fa-flask"></i> {{Vérifier la configuration}}</a>
                    <a class="btn btn-primary" id="bt_tfRun"><i class="fas fa-play"></i> {{Exécuter un cycle maintenant}}</a>
                    <span class="text-muted" id="tf_diag_time" style="margin-left:10px;"></span>
                    <span class="help-block">{{Le plugin décide chaque minute. « Exécuter un cycle » le fait tout de suite. Sauvegardez avant.}}</span>
                </div>
                <div id="tf_check_result"></div>
                <div class="row">
                    <div class="col-lg-6"><div class="tf-card" id="tf_diag_vmc"><h4><i class="fas fa-fan"></i> VMC</h4><div class="tf-body">…</div></div></div>
                    <div class="col-lg-6">
                        <div class="tf-card" id="tf_diag_heat"><h4><i class="fas fa-fire"></i> {{Chauffage}}</h4><div class="tf-body">…</div></div>
                        <div class="tf-card" id="tf_diag_shed"><h4><i class="fas fa-bolt"></i> {{Délestage}}</h4><div class="tf-body">…</div></div>
                        <div class="tf-card" id="tf_diag_filter"><h4><i class="fas fa-filter"></i> {{Filtre}}</h4><div class="tf-body">…</div></div>
                        <div class="tf-card" id="tf_diag_guard"><h4><i class="fas fa-user-shield"></i> {{Garde-fous}}<?php echo tf_tip('{{Contrôlés à chaque cycle sur l\'état réel des équipements. Une règle non respectée pendant 3 minutes déclenche une notification « Sécurité » et, pour une batterie, un nouvel ordre d\'arrêt.}}'); ?></h4><div class="tf-body">…</div></div>
                    </div>
                </div>
            </div>

            <!-- ============================ COMMANDES ============================ -->
            <div role="tabpanel" class="tab-pane" id="commandtab">
                <div class="alert alert-info" style="margin:10px 0;">{{Commandes utilisables dans vos scénarios. Survolez le}} <i class="fas fa-question-circle"></i> {{de chaque commande pour son rôle.}}
                    <ul style="margin:6px 0 0 0; padding-left:18px;">
                        <li><b>{{Mode chauffage}}</b> : {{le mode choisi, « Normal » ou « ECO ». Ce n'est pas une température.}}</li>
                        <li><b>{{Consigne Normal}}</b> : {{la température voulue dans les plages Normal. Elle ne bouge pas quand on passe en ECO.}}</li>
                        <li><b>{{Consigne ECO}}</b> : {{la température plus basse utilisée en ECO. Toujours au moins 0,5 °C sous Normal.}}</li>
                        <li><b>{{Batterie 1 / 2 consigne}}</b> : {{la température réellement visée par chaque batterie en ce moment (selon la plage et le mode).}}</li>
                    </ul></div>
                <table id="table_cmd" class="table table-bordered table-condensed">
                    <thead>
                        <tr>
                            <th class="hidden-xs" style="min-width:50px;width:70px;">ID</th>
                            <th style="min-width:200px;width:300px;">{{Nom}}</th>
                            <th>{{Rôle}}</th>
                            <th>{{Type}}</th>
                            <th>{{Options}}</th>
                            <th>{{État}}</th>
                            <th style="min-width:80px;width:160px;">{{Actions}}</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <!-- ============================ AIDE MODBUS ============================ -->
            <div role="tabpanel" class="tab-pane" id="helptab">
                <div class="tf-help">
                    <div class="alert alert-info" style="margin:10px 0;">
                        <i class="fas fa-info-circle"></i> {{La Brink Flair 325 se pilote en ModBus RTU (liaison RS485) grâce au plugin}} <b>MyModbus</b>.
                        {{ThermoFlair 325 ne parle pas lui-même au bus : il utilise les commandes de votre équipement MyModbus. Cet onglet explique le câblage, les réglages et chaque registre utile.}}
                        {{Quand tout va bien, la valeur de chaque commande info apparaît à côté de son nom dans MyModbus, et « Cycle OK » vaut 1.}}
                    </div>

                    <!-- ---------- Analyse ---------- -->
                    <div class="tf-card">
                        <h4><i class="fas fa-stethoscope"></i> {{Vérifier mon équipement MyModbus}}<?php echo tf_tip('{{Lit la configuration de l\'équipement MyModbus choisi : chaque commande est reconnue d\'après son registre, vérifiée (fonction, format, valeur écrite) et sa valeur actuelle est traduite. Rien n\'est écrit sur la VMC.}}'); ?></h4>
                        <div class="tf-body">
                            <div class="tf-inline">
                                <select id="tf_mb_eq" class="form-control" style="width:320px;"></select>
                                <a class="btn btn-primary btn-sm" id="bt_tfMbScan"><i class="fas fa-search"></i> {{Analyser}}</a>
                            </div>
                            <div id="tf_mb_result" style="margin-top:10px;"></div>
                        </div>
                    </div>

                    <!-- ---------- Câblage ---------- -->
                    <div class="tf-card">
                        <h4><i class="fas fa-plug"></i> {{Câblage et réglages de la VMC}}</h4>
                        <div class="tf-body">
                            <table class="table table-condensed tf-help-table">
                                <tr><th style="width:220px;">{{Connecteur}}</th><td>{{Connecteur rouge à 3 pôles}} <b>X15</b> {{(circuit de base UWA2-B), ou}} <b>X06</b> {{si la VMC a le circuit « Plus » (UWA2-E).}}</td></tr>
                                <tr><th>{{Les trois fils}}</th><td><b>1 = GND</b> (masse) · <b>2 = A</b> (RS485-A) · <b>3 = B</b> (RS485-B). {{Utilisez une paire torsadée pour A et B. Si rien ne remonte, inversez A et B : les marquages des dongles USB varient (A = D+ = T+, B = D− = T−).}}</td></tr>
                                <tr><th>{{Cavaliers de la carte}}</th><td>{{En ModBus, retirez les cavaliers}} <b>X121</b> {{et}} <b>X122</b>. <b>X12</b> {{= résistance de fin de ligne 120 Ω : laissez-la si la VMC est au bout du câble, retirez-la s'il y en a déjà une à cet endroit.}}</td></tr>
                                <tr><th>{{Menu de la VMC (écran ⚙)}}</th><td><b>14.1</b> {{type de connexion = ModBus}} · <b>14.2</b> {{adresse esclave (20 d'usine)}} · <b>14.3</b> {{vitesse (19 200 bauds d'usine)}} · <b>14.4</b> {{parité (paire / Even d'usine)}}.<br>{{MyModbus doit avoir exactement les mêmes réglages : si vous avez mis 9 600 bauds sans parité sur la VMC, mettez 9 600 et « Aucune » dans MyModbus. 8 bits, 1 bit de stop.}}</td></tr>
                                <tr><th>{{Côté Jeedom}}</th><td>{{Un dongle USB-RS485 suffit. Donnez-lui un nom fixe (règle udev, ex. /dev/ttyUSB_VMC) pour qu'il ne change pas au redémarrage. Un seul équipement MyModbus par port série.}}</td></tr>
                                <tr><th>{{Quand le ModBus pilote}}</th><td>{{La vitesse ne peut plus être changée sur l'écran ni avec un interrupteur à positions, et le capteur d'humidité Brink éventuel ne fonctionne plus (doc Brink §5.5.8). Les boosts passent donc par Jeedom (commandes Boost du plugin).}}</td></tr>
                                <tr><th>{{Coupure de courant}}</th><td>{{Les registres 8000 à 8011 repartent à 0 : la VMC n'est plus en mode ModBus et ignore les vitesses. Le plugin renvoie « Mode ModBus ON » régulièrement (onglet VMC), et tout de suite si la lecture du registre 8000 n'est pas 1.}}</td></tr>
                            </table>
                        </div>
                    </div>

                    <!-- ---------- MyModbus pas à pas ---------- -->
                    <div class="tf-card">
                        <h4><i class="fas fa-list-ol"></i> {{Configurer MyModbus pas à pas}}</h4>
                        <div class="tf-body">
                            <ol class="tf-steps">
                                <li>{{Installez MyModbus (Market), installez ses dépendances, puis créez un équipement (ex. « VMC MyModbus »).}}</li>
                                <li>{{Onglet Équipement : réglez-le comme dans le tableau ci-dessous et sauvegardez une première fois sans l'activer, puis activez-le.}}</li>
                                <li>{{Onglet Commandes : créez les commandes de la section « Registres » plus bas, au moins les « Indispensables ». Colonne Paramètres = valeur écrite par une action ; colonne Option = calcul sur la valeur lue (ex. #value# / 10).}}</li>
                                <li>{{Sauvegardez : les valeurs lues apparaissent à côté du nom des commandes info après un cycle de lecture, et « Cycle OK » passe à 1.}}</li>
                                <li>{{Revenez ici, choisissez l'équipement en haut de l'onglet et cliquez sur « Analyser », puis sur « Remplir les champs ThermoFlair ». Sauvegardez ThermoFlair.}}</li>
                            </ol>
                            <table class="table table-condensed table-bordered tf-help-table">
                                <thead><tr><th style="width:280px;">{{Champ de l'équipement MyModbus}}</th><th>{{Réglage conseillé}}</th></tr></thead>
                                <tr><td>{{Protocole de connexion}}</td><td>serial ({{dongle USB-RS485}})</td></tr>
                                <tr><td>{{Mode de rafraîchissement}}</td><td>Polling</td></tr>
                                <tr><td>{{Polling (s)}}</td><td>{{5 à 30. ThermoFlair lit les valeurs toutes les minutes.}}</td></tr>
                                <tr><td>{{Timeout (s) / tentatives}}</td><td>5 / 3</td></tr>
                                <tr><td>{{Temps entre 2 requêtes de lecture (s)}}</td><td>{{0,01 à 0,05 (augmentez si des lectures échouent)}}</td></tr>
                                <tr><td>{{Interface}}</td><td>{{votre dongle (ex. /dev/ttyUSB0, ou un nom fixe comme /dev/ttyUSB_VMC)}}</td></tr>
                                <tr><td>{{Méthode de transport}}</td><td>RTU</td></tr>
                                <tr><td>{{Vitesse / bits / parité / stop}}</td><td>{{identiques au menu 14 de la VMC (d'usine 19 200 / 8 / paire / 1)}}</td></tr>
                                <tr><td>{{ID du serveur identique à toutes les commandes}}</td><td>{{coché, 20 (adresse esclave de la VMC) : plus besoin de la saisir dans chaque commande}}</td></tr>
                                <tr><td>{{Équipement destiné à tester l'existence des registres}}</td><td class="text-danger"><b>{{décoché}}</b> — {{coché, une sauvegarde supprime toutes les commandes sans confirmation}}</td></tr>
                            </table>
                        </div>
                    </div>

                    <!-- ---------- Registres ---------- -->
                    <div class="tf-card">
                        <h4><i class="fas fa-th-list"></i> {{Registres de la Flair 325, par catégorie}}<?php echo tf_tip('{{D\'après la notice Brink Flair 325 (615676-B, chapitre 14). « Réglage MyModbus » : type / sous-type · fonction · format · adresse · Paramètres (valeur écrite) · Option (calcul).}}'); ?></h4>
                        <div class="tf-body">
                            <p>
                                <?php foreach (ThermoFlair325Brink::LEVELS as $lv) { echo '<span class="label label-' . $lv['class'] . '" style="margin-right:4px;">' . $lv['label'] . '</span>'; } ?>
                                <span class="text-muted">— {{« Lecture seule » : lire si vous voulez, ne pas écrire. « Ne pas créer » : commande inutile et risquée.}}</span>
                            </p>
                            <?php
                            $tfCat = ThermoFlair325Brink::catalog();
                            foreach (ThermoFlair325Brink::CATEGORIES as $ck => $cv) {
                                echo '<h5 class="tf-cat-title"><i class="fas ' . $cv['icon'] . '"></i> ' . $cv['title'] . ' <small>' . $cv['help'] . '</small></h5>';
                                echo '<table class="table table-condensed table-bordered tf-help-table"><thead><tr><th style="width:110px;">{{Niveau}}</th><th style="width:210px;">{{Nom conseillé}}</th><th>{{Réglage MyModbus}}</th><th style="width:200px;">{{Valeurs}}</th><th>{{Rôle}}</th></tr></thead><tbody>';
                                foreach ($tfCat as $e) {
                                    if ($e['cat'] != $ck) {
                                        continue;
                                    }
                                    $lv = ThermoFlair325Brink::LEVELS[$e['level']];
                                    $vals = '';
                                    if (!empty($e['decode'])) {
                                        $p = array();
                                        foreach ($e['decode'] as $k => $t) {
                                            $p[] = '<b>' . $k . '</b> ' . $t;
                                        }
                                        $vals = implode('<br>', $p);
                                    } elseif (!empty($e['unit'])) {
                                        $vals = $e['unit'];
                                    }
                                    $field = !empty($e['field']) ? '<br><span class="tf-field-tag"><i class="fas fa-link"></i> ThermoFlair</span>' : '';
                                    echo '<tr><td><span class="label label-' . $lv['class'] . '">' . $lv['label'] . '</span></td><td><b>' . htmlspecialchars($e['name']) . '</b>' . $field . '</td><td><code class="tf-howto">' . htmlspecialchars(ThermoFlair325Brink::howTo($e)) . '</code></td><td>' . $vals . '</td><td>' . htmlspecialchars($e['desc']) . '</td></tr>';
                                }
                                echo '</tbody></table>';
                            }
                            ?>
                        </div>
                    </div>

                    <!-- ---------- Pannes ---------- -->
                    <div class="tf-card">
                        <h4><i class="fas fa-wrench"></i> {{Codes de panne affichés par la VMC}}<?php echo tf_tip('{{Doc Brink chapitre 8. * = panne bloquante : les deux ventilateurs s\'arrêtent jusqu\'à ce que le problème soit réglé.}}'); ?></h4>
                        <div class="tf-body">
                            <div class="alert alert-warning">{{La doc Brink ne donne aucun registre ModBus pour lire le code de panne : il s'affiche sur l'écran (symbole clé). Deux moyens de le savoir dans Jeedom :}}
                                <ul style="margin:6px 0 0; padding-left:18px;">
                                    <li>{{« Cycle OK » de MyModbus à 0 et le plugin qui prévient : la VMC ne répond plus (câble, alimentation, E2300 / E2400).}}</li>
                                    <li>{{La sortie « signal output » X19 (bornier bleu à 2 pôles) : réglée à l'étape 16.1 sur « Filtre et condition d'erreur », elle ferme un contact sec en cas de filtre ou de panne. Raccordée à une entrée de module (contact sec), elle donne une info « Panne VMC » dans Jeedom.}}</li>
                                </ul></div>
                            <table class="table table-condensed table-bordered tf-help-table">
                                <thead><tr><th style="width:80px;">{{Code}}</th><th>{{Cause}}</th><th>{{Que faire}}</th></tr></thead>
                                <?php foreach (ThermoFlair325Brink::ERRORS as $er) {
                                    echo '<tr><td><b>' . $er[0] . '</b>' . ($er[1] ? ' <span class="text-danger" title="{{Panne bloquante}}">*</span>' : '') . '</td><td>' . htmlspecialchars($er[2]) . '</td><td>' . htmlspecialchars($er[3]) . '</td></tr>';
                                } ?>
                            </table>
                        </div>
                    </div>

                    <!-- ---------- Bon à savoir ---------- -->
                    <div class="tf-card">
                        <h4><i class="fas fa-lightbulb"></i> {{Bon à savoir sur la Flair 325}}</h4>
                        <div class="tf-body">
                            <ul class="tf-steps">
                                <li><b>{{Vitesses}}</b> : {{4 positions (0 à 3). Le débit de chacune est réglé par l'installateur (étapes 1.1 à 1.4 ; d'usine 50, 100, 150 et 250 m³/h, maximum 325). Ne les modifiez pas sans lui : ils dépendent de vos conduits. La position la plus haute demandée l'emporte.}}</li>
                                <li><b>{{Filtres}}</b> : {{avertissement au bout de 90 jours d'usage (étape 4.1). Nettoyez-les à l'avertissement, remplacez-les chaque année ou après 3 nettoyages. Jamais de VMC sans filtres. Remise à zéro : assistant filtre de l'écran, étape 4.3, ou registre 8010.}}</li>
                                <li><b>{{Bypass}}</b> : {{il s'ouvre quand il fait plus de 10 °C dehors, moins chaud dehors que dedans et plus de 22 °C dedans (étapes 2.2 et 2.3). Il se ferme sous 10 °C dehors, ou quand la maison descend à 20 °C (22 − 2 d'hystérésis).}}</li>
                                <li><b>{{Antigel}}</b> : {{le préchauffage intégré protège l'échangeur. Par grand froid, la VMC peut aussi réduire le soufflage (balourd) : c'est normal.}}</li>
                                <li><b>{{Entretien installateur}}</b> : {{échangeur et ventilateurs à nettoyer environ tous les 3 ans. Débranchez toujours la prise avant d'ouvrir.}}</li>
                                <li><b>{{Réglages d'usine}}</b> : {{l'étape 15.9 remet tous les réglages d'usine (y compris vitesse et parité ModBus : MyModbus ne répondra plus tant que vous ne les aurez pas refaits).}}</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modèles de lignes (cachés) -->
<table style="display:none;">
    <tr class="tfQuietTemplate">
        <td><input type="text" class="form-control input-sm q_label" placeholder="{{Nuit}}"></td>
        <td class="tf-days"></td>
        <td><input type="time" class="form-control input-sm q_start"></td>
        <td><input type="time" class="form-control input-sm q_end"></td>
        <td><select class="form-control input-sm q_max"><option value="1">V1</option><option value="2">V2</option><option value="3">V3</option></select></td>
        <td><a class="btn btn-danger btn-xs bt_tfRemoveRow"><i class="fas fa-trash"></i></a></td>
    </tr>
    <tr class="tfBoostTemplate">
        <td><input type="hidden" class="b_id"><input type="text" class="form-control input-sm b_name" placeholder="{{ex. Boost douche}}"></td>
        <td><select class="form-control input-sm b_speed"><option value="1">V1</option><option value="2">V2</option><option value="3">V3</option><option value="4">V4</option></select></td>
        <td><div class="input-group input-group-sm"><input type="number" class="form-control b_min" min="1" max="1440" step="1"><span class="input-group-addon">min</span></div></td>
        <td style="text-align:center;"><input type="checkbox" class="b_widget"></td>
        <td class="b_warn" style="font-size:.9em;"></td>
        <td><a class="btn btn-danger btn-xs bt_tfRemoveRow"><i class="fas fa-trash"></i></a></td>
    </tr>
    <tr class="tfWindowTemplate">
        <td><input type="text" class="form-control input-sm w_label" placeholder="{{Renouvellement}}"></td>
        <td><select class="form-control input-sm w_season"><option value="all">{{Toute l'année}}</option><option value="winter">{{Hiver}}</option><option value="summer">{{Été}}</option></select></td>
        <td class="tf-days"></td>
        <td><input type="time" class="form-control input-sm w_start"></td>
        <td><input type="time" class="form-control input-sm w_end"></td>
        <td><select class="form-control input-sm w_speed"><option value="1">V1</option><option value="2">V2</option><option value="3">V3</option></select></td>
        <td style="text-align:center;"><input type="checkbox" class="w_skip_hot"></td>
        <td><a class="btn btn-danger btn-xs bt_tfRemoveRow"><i class="fas fa-trash"></i></a></td>
    </tr>
    <tr class="tfSlotTemplate">
        <td><input type="text" class="form-control input-sm s_label" placeholder="{{ex. Présence}}"></td>
        <td class="tf-bats">
            <label><input type="checkbox" class="s_bat" value="b1"> <span class="s_bat_name" data-bat="b1">Bas</span></label>
            <label><input type="checkbox" class="s_bat" value="b2"> <span class="s_bat_name" data-bat="b2">Haut</span></label>
        </td>
        <td class="tf-days"></td>
        <td><input type="time" class="form-control input-sm s_start"></td>
        <td><input type="time" class="form-control input-sm s_end"></td>
        <td><select class="form-control input-sm s_mode"><option value="normal">{{Normal}}</option><option value="eco">{{ECO}}</option><option value="consigne">{{Consigne fixe}}</option><option value="forced">{{Marche forcée}}</option><option value="off">{{Arrêt}}</option></select></td>
        <td><div class="tf-inline"><input type="number" step="0.5" min="5" max="30" class="form-control input-sm s_consigne" style="width:70px;"><span>°C</span></div></td>
        <td><a class="btn btn-danger btn-xs bt_tfRemoveRow"><i class="fas fa-trash"></i></a></td>
    </tr>
    <tr class="tfRSchedTemplate">
        <td class="tf-days"></td>
        <td><input type="time" class="form-control input-sm r_time" style="width:120px;"></td>
        <td><a class="btn btn-danger btn-xs bt_tfRemoveRow"><i class="fas fa-trash"></i></a></td>
    </tr>
    <tr class="tfRRecTemplate">
        <td>
            <div class="input-group">
                <input type="text" class="form-control input-sm n_cmd r_cmd" placeholder="#[Objet][Mail][Envoi]#">
                <span class="input-group-btn"><a class="btn btn-warning btn-sm tf-pick" data-cmdtype="action" data-cmdsubtype="message" data-self="1"><i class="fas fa-list-alt"></i></a></span>
            </div>
            <span class="tf-preview tf-preview-row"></span>
        </td>
        <td><input type="text" class="form-control input-sm r_title" placeholder="{{(automatique)}}"></td>
        <td style="text-align:center;"><input type="checkbox" class="r_html"></td>
        <td style="text-align:center;"><input type="checkbox" class="r_files"></td>
        <td><a class="btn btn-danger btn-xs bt_tfRemoveRow"><i class="fas fa-trash"></i></a></td>
    </tr>
    <tr class="tfNotifTemplate">
        <td>
            <div class="input-group">
                <input type="text" class="form-control input-sm n_cmd" placeholder="#[Objet][Équipement][Commande]#">
                <span class="input-group-btn"><a class="btn btn-warning btn-sm tf-pick" data-cmdtype="action" data-cmdsubtype="message" data-self="1"><i class="fas fa-list-alt"></i></a></span>
            </div>
            <span class="tf-preview tf-preview-row"></span>
        </td>
        <td><input type="text" class="form-control input-sm n_title" placeholder="{{(automatique)}}"></td>
        <td style="text-align:center;"><input type="checkbox" class="n_html"></td>
        <td style="text-align:center;"><input type="checkbox" class="n_all"></td>
        <td style="text-align:center;"><input type="checkbox" class="n_cat n_vmc"></td>
        <td style="text-align:center;"><input type="checkbox" class="n_cat n_heat"></td>
        <td style="text-align:center;"><input type="checkbox" class="n_cat n_shed"></td>
        <td style="text-align:center;"><input type="checkbox" class="n_cat n_filter"></td>
        <td style="text-align:center;"><input type="checkbox" class="n_cat n_preheat"></td>
        <td style="text-align:center;"><input type="checkbox" class="n_cat n_alert"></td>
        <td style="text-align:center;"><input type="checkbox" class="n_cat n_safety"></td>
        <td style="text-align:center;"><input type="checkbox" class="n_rest"></td>
        <td style="white-space:nowrap;">
            <a class="btn btn-default btn-xs bt_tfTestNotif" title="{{Envoyer un message de test par sujet coché, avec les valeurs du moment}}"><i class="fas fa-paper-plane"></i></a>
            <a class="btn btn-danger btn-xs bt_tfRemoveRow"><i class="fas fa-trash"></i></a>
        </td>
    </tr>
</table>

<?php include_file('desktop', 'ThermoFlair325', 'js', 'ThermoFlair325'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
