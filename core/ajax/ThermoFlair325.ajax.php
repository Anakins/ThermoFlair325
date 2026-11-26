<?php
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

try {
    // Session admin, ou clé API du plugin en secours (même mécanisme que
    // RoboProg/LandroidRTK : isConnect('admin') s'est montré peu fiable
    // pour les appels ajax de plugin dans certains environnements).
    $apikey = init('apikey');
    $authorized = ($apikey != '' && hash_equals(ThermoFlair325::getApiKey(), $apikey));
    if (!$authorized && !isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }
    $action = init('action');

    $getEq = function () {
        $eq = eqLogic::byId(init('id'));
        if (!is_object($eq) || $eq->getEqType_name() != 'ThermoFlair325') {
            throw new Exception("Équipement introuvable : sauvegardez-le au moins une fois.");
        }
        return $eq;
    };
    $formConfig = function () {
        $raw = init('config');
        if ($raw === '' || $raw === null) {
            return null;
        }
        $c = is_array($raw) ? $raw : json_decode($raw, true);
        if (!is_array($c)) {
            throw new Exception('Configuration illisible (JSON invalide).');
        }
        return $c;
    };

    // Aperçu en direct d'une commande liée : existe ? bon type ? valeur ?
    // Une commande ACTION n'est jamais exécutée ici.
    if ($action == 'preview') {
        $raw = trim((string) init('raw'));
        $expect = init('expect', 'info');
        if ($raw === '') {
            ajax::success(array('valid' => null));
        }
        $cmd = ThermoFlair325::resolveCmd($raw);
        if (!is_object($cmd)) {
            ajax::success(array('valid' => false, 'error' => 'Commande introuvable'));
        }
        if ($cmd->getType() != $expect) {
            ajax::success(array('valid' => false, 'error' => 'Commande ' . $cmd->getType() . ' : il faut une commande ' . $expect));
        }
        $subtypes = array_filter(explode(',', (string) init('subtypes')));
        if (!empty($subtypes) && !in_array($cmd->getSubType(), $subtypes)) {
            ajax::success(array('valid' => false, 'error' => 'Sous-type « ' . $cmd->getSubType() . ' » : il faut ' . implode(' ou ', $subtypes)));
        }
        if ($expect == 'action') {
            ajax::success(array('valid' => true, 'value' => 'commande action (' . $cmd->getSubType() . ')', 'name' => $cmd->getHumanName()));
        }
        $val = $cmd->execCmd();
        if (init('binary') == '1' && !in_array(trim((string) $val), array('0', '1'), true)) {
            ajax::success(array('valid' => false, 'error' => '0 ou 1 attendu', 'value' => $val));
        }
        $min = init('min');
        $max = init('max');
        $res = array('valid' => true, 'value' => $val, 'unit' => $cmd->getUnite());
        if (init('numeric') == '1' && !is_numeric($val)) {
            $res['valid'] = false;
            $res['error'] = 'Pas un nombre';
        } elseif ($min !== '' && $max !== '' && $min !== null && $max !== null && (!is_numeric($val) || $val < $min || $val > $max)) {
            $res['valid'] = false;
            $res['error'] = "Hors limites ($min à $max)";
        }
        ajax::success($res);
    }

    // Vérification de la configuration (celle du formulaire, même non
    // sauvegardée, ou celle enregistrée).
    if ($action == 'check') {
        $eq = eqLogic::byId(init('id'));
        $cfg = ThermoFlair325::getCfg(is_object($eq) ? $eq : null, $formConfig());
        // Le formulaire envoie les tags lisibles : on les convertit comme le
        // ferait la sauvegarde de Jeedom.
        $cfg = jeedom::fromHumanReadable($cfg);
        ajax::success(ThermoFlair325::checkConfig($cfg));
    }

    // Diagnostic : dernier cycle (cache), ou nouveau cycle si run=1.
    if ($action == 'diagnostic') {
        $eq = $getEq();
        $diag = null;
        if (init('run') == '1') {
            $diag = $eq->evaluate('diagnostic');
        } else {
            $diag = cache::byKey('ThermoFlair325::diag::' . $eq->getId())->getValue(null);
            if (!is_array($diag)) {
                $diag = $eq->evaluate('diagnostic');
            }
        }
        $cfg = ThermoFlair325::getCfg($eq);
        $rt = $eq->getRuntime();
        ThermoFlair325::initRuntime($cfg, $rt);
        ajax::success(array(
            'diag' => $diag,
            'runtime' => array(
                'vmc_auto' => $rt['vmc']['auto'],
                'override' => $rt['vmc']['ovr'],
                'heat_on' => $rt['heat']['on'],
                'consigne' => $rt['heat']['consigne'],
                'eco' => isset($rt['heat']['eco']) ? $rt['heat']['eco'] : null,
                'mode' => isset($rt['heat']['mode']) ? $rt['heat']['mode'] : 'normal',
                'lock' => !empty($rt['heat']['lock']) ? 1 : 0,
                'force' => ThermoFlair325::forceLabel(ThermoFlair325::getCfg($eq), $rt['heat'], ThermoFlair325::now()),
                'force_add_b2' => (ThermoFlair325::forceEnd($rt['heat'], ThermoFlair325::now()) > 0 && $cfg['b2_enabled'] == '1' && !(isset($rt['heat']['force']['b2']) && ThermoFlair325::now() < intval($rt['heat']['force']['b2']))) ? 1 : 0,
                'b2_name' => $cfg['b2_name'],
                'filter_cleaned' => $rt['filter']['cleaned'],
                'filter_dirty' => $rt['filter']['dirty'],
                'shed' => $rt['shed'],
                'last_alert' => isset($rt['last_alert']) ? $rt['last_alert'] : '',
            ),
            'now' => date('H:i:s', ThermoFlair325::now()),
        ));
    }

    // Actions du plugin depuis la page (mêmes effets que les commandes).
    if ($action == 'command') {
        $eq = $getEq();
        $lid = init('lid');
        $isBoost = ThermoFlair325::boostById(ThermoFlair325::getCfg($eq), $lid) !== null;
        if (!$isBoost && (!array_key_exists($lid, ThermoFlair325::CMDS) || ThermoFlair325::CMDS[$lid]['type'] != 'action')) {
            throw new Exception('Commande inconnue : ' . $lid);
        }
        $opt = array();
        if (init('value') !== '') {
            $opt['slider'] = init('value');
        }
        $eq->handleAction($lid, $opt);
        ajax::success(true);
    }

    // Statistiques thermiques de la période demandée.
    if ($action == 'stats') {
        $eq = $getEq();
        ajax::success(ThermoFlair325Stats::analyze($eq, intval(init('days', 30))));
    }

    // Import de l'historique Jeedom (commandes historisées).
    if ($action == 'importHistory') {
        $eq = $getEq();
        $days = max(1, min(730, intval(init('days', 90))));
        ajax::success(ThermoFlair325Stats::importHistory($eq, $days));
    }

    if ($action == 'testNotification') {
        $cmd = ThermoFlair325::resolveCmd(init('cmd_id'));
        if (!is_object($cmd) || $cmd->getType() != 'action') {
            throw new Exception('Choisissez une commande action valide avant de tester.');
        }
        $eq = $getEq();
        $asked = array_filter(explode(',', init('cats')));
        if (empty($asked)) {
            throw new Exception('Aucune case cochée pour ce destinataire : il ne recevra rien.');
        }
        // « Toutes les actions » = un message par sujet, plus le journal.
        $cats = in_array('all', $asked) ? array('vmc', 'heat', 'shed', 'filter', 'preheat', 'alert', 'safety', 'log') : array_values(array_intersect(array('vmc', 'heat', 'shed', 'filter', 'preheat', 'alert', 'safety'), $asked));
        $row = array('cmd_id' => init('cmd_id'), 'title' => init('title'), 'html' => init('html'));
        $sent = 0;
        $problems = array();
        $names = array();
        foreach ($eq->testMessages($cats) as $m) {
            $row['title'] = (init('title') !== '') ? init('title') : $m['title'];
            $res = ThermoFlair325::sendNotifications(array($row), $m['title'], $m['text'], 'test');
            $sent += $res['sent'];
            if ($res['detail'] !== '') {
                $problems[] = $res['detail'];
            }
            $names[] = ThermoFlair325::NOTIF_TITLES[$m['cat']];
        }
        if ($sent == 0) {
            throw new Exception('Envoi impossible : ' . implode(' ; ', array_unique($problems)));
        }
        ajax::success(array('sent' => $sent, 'cats' => $names, 'detail' => implode(' ; ', array_unique($problems))));
    }

    // Rapport : aperçu (texte + graphiques en images) ou envoi immédiat.
    if ($action == 'reportPreview') {
        $eq = $getEq();
        $r = ThermoFlair325Report::build($eq);
        // Aperçu identique au mail : images dans le corps.
        $html = $r['html_inline'];
        foreach ($r['charts'] as $c) {
            $html = str_replace('cid:' . $c['cid'], 'data:image/png;base64,' . base64_encode(file_get_contents($c['path'])), $html);
        }
        $imgs = array();
        ajax::success(array('title' => $r['title'], 'html' => $html, 'count' => count($r['charts']), 'images' => $imgs, 'gd' => $r['gd'], 'next' => ($n = ThermoFlair325Report::nextRun(ThermoFlair325::getCfg($eq), ThermoFlair325::now())) ? date('d/m/Y H:i', $n) : null));
    }
    if ($action == 'reportSend') {
        $eq = $getEq();
        $rec = json_decode(init('recipients', '[]'), true);
        $res = ThermoFlair325Report::send($eq, is_array($rec) && $rec ? $rec : null);
        if ($res['sent'] == 0) {
            throw new Exception('Rapport non envoyé : ' . ($res['detail'] ?: 'aucun destinataire'));
        }
        ajax::success($res);
    }

    // Libellé officiel d'un code météo (aperçu de la configuration).
    if ($action == 'weatherLabel') {
        $code = init('code');
        $m = ThermoFlair325::mapWeather($code, init('source', 'auto'));
        ajax::success(array('label' => ThermoFlair325::getConditionCodeLabel($code, init('source', 'auto')), 'fr' => $m ? $m['label'] : null, 'icon' => $m ? $m['icon'] : null, 'cc' => $m ? $m['cc'] : null, 'wet' => $m ? $m['wet'] : null));
    }

    // Aide ModBus : équipements MyModbus et analyse de l'un d'eux.
    if ($action == 'mymodbusList') {
        ajax::success(ThermoFlair325Brink::mymodbusList());
    }

    if ($action == 'mymodbusScan') {
        $eq = eqLogic::byId(init('id'));
        $cfg = jeedom::fromHumanReadable(ThermoFlair325::getCfg(is_object($eq) ? $eq : null, $formConfig()));
        ajax::success(ThermoFlair325Brink::scan(intval(init('mb')), $cfg));
    }

    throw new Exception(__('Aucune méthode correspondante à', __FILE__) . ' : ' . $action);
} catch (\Throwable $e) {
    ajax::error(displayException($e), $e->getCode());
}
