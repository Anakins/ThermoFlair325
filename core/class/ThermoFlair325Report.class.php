<?php

/*
 * ThermoFlair325Report — rapport périodique des statistiques (mail / notification).
 *
 * Aux jours et heures choisis (onglet Statistiques), le plugin envoie :
 *  - un résumé (chiffres clés de la période et évolution par rapport à la
 *    période précédente de même durée) ;
 *  - les préconisations et ce qu'on apprend de la maison ;
 *  - les mêmes graphiques que l'onglet Statistiques, en images PNG, sur les
 *    N derniers jours (14 par défaut). Pour une commande du plugin Mail,
 *    les images sont DANS le corps du mail (images intégrées « cid ») ;
 *    pour les autres (Discord, Telegram...), en pièces jointes.
 *
 * Les graphiques sont dessinés avec GD (extension PHP fournie avec Jeedom).
 * Sans GD, le rapport part en texte seul.
 *
 * Pas de propriété statique (voir la règle dans ThermoFlair325.class.php).
 */
class ThermoFlair325Report {

    const W = 900;
    const H = 480;

    /* ---------------------------------------------------------------- */
    /* Planification                                                     */
    /* ---------------------------------------------------------------- */

    // Un envoi est-il prévu à cette minute ? (jours 1..7 + heure HH:MM)
    public static function due($cfg, $now) {
        if ($cfg['report_enabled'] != '1' || empty($cfg['report_schedule']) || !is_array($cfg['report_schedule'])) {
            return false;
        }
        $dow = (string) date('N', $now);
        $hm = date('H:i', $now);
        foreach ($cfg['report_schedule'] as $r) {
            $days = isset($r['days']) && is_array($r['days']) ? array_map('strval', $r['days']) : array();
            $t = ThermoFlair325::hm2min(isset($r['time']) ? $r['time'] : '');
            if ($t !== null && in_array($dow, $days) && ThermoFlair325::min2hm($t) == $hm) {
                return true;
            }
        }
        return false;
    }

    // Prochain envoi (texte), pour l'interface.
    public static function nextRun($cfg, $now) {
        if ($cfg['report_enabled'] != '1') {
            return null;
        }
        for ($m = 1; $m <= 8 * 1440; $m++) {
            $t = $now + $m * 60;
            if (self::due($cfg, $t)) {
                return $t;
            }
        }
        return null;
    }

    /* ---------------------------------------------------------------- */
    /* Construction                                                      */
    /* ---------------------------------------------------------------- */

    public static function build($eq) {
        $cfg = ThermoFlair325::getCfg($eq);
        $days = max(3, min(90, intval($cfg['report_days'])));
        $a = ThermoFlair325Stats::analyze($eq, $days);
        $prev = self::previous($eq, $days);
        $now = ThermoFlair325::now();
        $f = function ($v, $d = 1) {
            return ThermoFlair325::fmt($v, $d);
        };
        $title = 'Rapport ' . $eq->getName() . ' — ' . $days . ' jours au ' . date('d/m/Y', $now);
        $lines = array();
        $html = array();
        if (!empty($a['empty'])) {
            $lines[] = 'Pas encore assez de données pour un rapport (le plugin enregistre un point toutes les 5 minutes).';
            return array('title' => $title, 'text' => implode("\n", $lines), 'html' => '<p>' . htmlspecialchars($lines[0]) . '</p>', 'html_inline' => '<p>' . htmlspecialchars($lines[0]) . '</p>', 'files' => array(), 'charts' => array(), 'gd' => function_exists('imagecreatetruecolor'));
        }
        $k = $a['kpi'];
        $pk = isset($prev['kpi']) ? $prev['kpi'] : null;
        $stab = isset($a['advice']['stability']['b1']) ? $a['advice']['stability']['b1'] : null;
        $pstab = isset($prev['advice']['stability']['b1']) ? $prev['advice']['stability']['b1'] : null;

        // Évolution : valeur, valeur précédente, sens favorable.
        $evo = function ($label, $cur, $old, $unit, $dec, $lowerIsBetter, $extra = '') use ($f) {
            if ($cur === null) {
                return null;
            }
            $s = $label . ' : ' . $f($cur, $dec) . $unit;
            if ($old !== null && $old != 0) {
                $diff = $cur - $old;
                $better = $lowerIsBetter ? ($diff < 0) : ($diff > 0);
                $arrow = (abs($diff) < 1e-9) ? '=' : (($diff > 0) ? '↗' : '↘');
                $du = ($unit == ' %') ? ' points' : $unit;
                $s .= ' (' . $arrow . ' ' . ($diff > 0 ? '+' : '') . $f($diff, $dec) . $du . ' ; avant ' . $f($old, $dec) . $unit . (abs($diff) < 1e-9 ? '' : ($better ? ', mieux' : ', moins bien')) . ')';
            }
            // Détail après l'évolution, pour que « avant … mieux » reste à côté du chiffre.
            return $s . $extra;
        };
        $sum = array_filter(array(
            $evo('Chauffage', $k['kwh_per_day'], $pk ? $pk['kwh_per_day'] : null, ' kWh/jour', 2, true),
            $evo('Consommation par degré-jour', $k['kwh_per_dju'], $pk ? $pk['kwh_per_dju'] : null, ' kWh', 2, true),
            // Part du temps où le bas est à ±0,5 °C de sa consigne, quand il doit
            // la tenir (plages Normal) ; le reste : au-dessus (souvent le soleil) ou en dessous.
            $stab ? $evo('Bas à sa consigne (à ±0,5 °C près, pendant les plages Normal)', $stab['in_band'], $pstab ? $pstab['in_band'] : null, ' %', 0, false,
                '. Le reste du temps : au-dessus ' . $stab['over'] . ' %' . (!empty($stab['over_sun']) ? ' (dont ' . $stab['over_sun'] . ' % à cause du soleil)' : '') . ', en dessous ' . $stab['under'] . ' %') : null,
            $stab ? $evo('Écart-type de la température', $stab['std'], $pstab ? $pstab['std'] : null, ' °C', 2, true) : null,
            $evo('Perte par nuit sans chauffage', $k['night_drop'], $pk ? $pk['night_drop'] : null, ' °C', 2, true),
            !empty($k['solar_h']) ? $evo('Surchauffe due au soleil (bas à ' . $f(floatval($cfg['heat_consigne']) + 2, 1) . ' °C ou plus, soit consigne Normal + 2 °C, sans chauffage)', $k['solar_h'], $pk ? $pk['solar_h'] : null, ' h', 1, true,
                '. Sur ' . $k['solar_days'] . ' jour(s), jusqu\'à ' . $f($k['solar_max'], 1) . ' °C dedans pour ' . $f($k['solar_to'], 1) . ' °C dehors') : null,
            !empty($k['sky_days']) ? 'Journées : ' . $k['sky_days']['sun'] . ' ensoleillée(s), ' . $k['sky_days']['mixed'] . ' mitigée(s), ' . $k['sky_days']['grey'] . ' maussade(s)' : null,
            'Batteries : « ' . $cfg['b1_name'] . ' » ' . $f($k['b1_hours'], 1) . ' h, « ' . $cfg['b2_name'] . ' » ' . $f($k['b2_hours'], 1) . ' h, total ' . $f($k['kwh_total'], 1) . ' kWh',
        ));
        $lines[] = '📊 ' . $title;
        $lines[] = '';
        $lines[] = 'ÉVOLUTION (par rapport aux ' . $days . ' jours précédents)';
        foreach ($sum as $s) {
            $lines[] = '• ' . $s;
        }
        $adv = isset($a['advice']['advice']) ? $a['advice']['advice'] : array();
        if ($adv) {
            $lines[] = '';
            $lines[] = 'PRÉCONISATIONS';
            foreach ($adv as $x) {
                $lines[] = '• ' . $x['title'] . ($x['key'] ? ' (' . self::val($x['current']) . ' → ' . self::val($x['suggested']) . ')' : '') . ' — ' . $x['text'] . ' [confiance ' . $x['confidence'] . ']';
            }
        }
        if (!empty($a['insights'])) {
            $lines[] = '';
            $lines[] = 'CE QU\'ON APPREND DE LA MAISON';
            foreach ($a['insights'] as $i) {
                $lines[] = '• ' . $i;
            }
        }

        // HTML (pour le mail).
        $e = function ($t) {
            return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
        };
        $html[] = '<h2 style="font-family:sans-serif;color:#b5652d;">📊 ' . $e($title) . '</h2>';
        $html[] = '<h3 style="font-family:sans-serif;">Évolution (par rapport aux ' . $days . ' jours précédents)</h3><ul style="font-family:sans-serif;">';
        foreach ($sum as $s) {
            $html[] = '<li>' . $e($s) . '</li>';
        }
        $html[] = '</ul>';
        if ($adv) {
            $html[] = '<h3 style="font-family:sans-serif;">Préconisations</h3><ul style="font-family:sans-serif;">';
            foreach ($adv as $x) {
                $html[] = '<li><b>' . $e($x['title']) . '</b>' . ($x['key'] ? ' : ' . $e(self::val($x['current'])) . ' → <b>' . $e(self::val($x['suggested'])) . '</b>' : '') . '<br>' . $e($x['text']) . ' <i>(confiance ' . $e($x['confidence']) . ')</i></li>';
            }
            $html[] = '</ul>';
        }
        if (!empty($a['insights'])) {
            $html[] = '<h3 style="font-family:sans-serif;">Ce qu\'on apprend de la maison</h3><ul style="font-family:sans-serif;">';
            foreach ($a['insights'] as $i) {
                $html[] = '<li>' . $e($i) . '</li>';
            }
            $html[] = '</ul>';
        }
        $charts = array();
        $gd = function_exists('imagecreatetruecolor') && function_exists('imagettftext');
        $cDays = max(7, min(60, intval($cfg['report_chart_days'])));
        if ($gd && $cfg['report_charts'] == '1') {
            try {
                // Les graphiques couvrent leur propre durée (14 jours par défaut).
                $ac = ($days >= $cDays) ? $a : ThermoFlair325Stats::analyze($eq, $cDays);
                $charts = self::charts($eq, $ac, $cfg, $cDays);
            } catch (\Throwable $ex) {
                log::add(ThermoFlair325::PLUGIN, 'warning', 'Rapport : graphiques impossibles : ' . $ex->getMessage());
            }
        } elseif (!$gd) {
            $lines[] = '';
            $lines[] = '(Graphiques indisponibles : extension PHP GD absente.)';
        }
        $files = array_column($charts, 'path');
        $htmlAttach = $html;
        $htmlInline = $html;
        if ($charts) {
            $htmlInline[] = '<h3 style="font-family:sans-serif;">Graphiques (' . $cDays . ' derniers jours)</h3>';
            foreach ($charts as $c) {
                $htmlInline[] = '<p style="margin:0 0 18px 0;"><img src="cid:' . $c['cid'] . '" alt="' . $e($c['title']) . '" width="900" style="max-width:100%;height:auto;border:1px solid #ddd;border-radius:6px;display:block;">'
                    . ($c['period'] !== '' ? '<span style="display:block;font-family:sans-serif;font-size:12px;color:#777;margin-top:3px;">' . $e($c['period']) . '</span>' : '') . '</p>';
            }
            $htmlAttach[] = '<p style="font-family:sans-serif;color:#666;">Graphiques (' . $cDays . ' derniers jours) en pièces jointes : ' . $e(implode(', ', array_column($charts, 'title'))) . '.</p>';
            $lines[] = '';
            $lines[] = 'Graphiques (' . $cDays . ' derniers jours) en pièces jointes : ' . implode(', ', array_column($charts, 'title')) . '.';
        }
        return array('title' => $title, 'text' => implode("\n", $lines), 'html' => implode("\n", $htmlAttach), 'html_inline' => implode("\n", $htmlInline),
            'files' => $files, 'charts' => $charts, 'gd' => $gd);
    }

    // Jour abrégé en français (lun. 12), sans dépendre de la locale du système.
    private static function dayFr($ts) {
        $j = array(1 => 'lun.', 2 => 'mar.', 3 => 'mer.', 4 => 'jeu.', 5 => 'ven.', 6 => 'sam.', 7 => 'dim.');
        return $j[intval(date('N', $ts))] . ' ' . date('j', $ts);
    }

    private static function val($v) {
        if ($v === null) {
            return '—';
        }
        if (is_numeric($v)) {
            return ThermoFlair325::fmt($v, 2);
        }
        return (string) $v;
    }

    // Analyse de la période précédente de même durée (pour l'évolution).
    private static function previous($eq, $days) {
        $saved = isset($GLOBALS['THERMOFLAIR325_FAKE_NOW']) ? $GLOBALS['THERMOFLAIR325_FAKE_NOW'] : null;
        $GLOBALS['THERMOFLAIR325_FAKE_NOW'] = ThermoFlair325::now() - $days * 86400;
        try {
            $p = ThermoFlair325Stats::analyze($eq, $days);
        } catch (\Throwable $e) {
            $p = array();
        }
        if ($saved === null) {
            unset($GLOBALS['THERMOFLAIR325_FAKE_NOW']);
        } else {
            $GLOBALS['THERMOFLAIR325_FAKE_NOW'] = $saved;
        }
        return !empty($p['empty']) ? array() : $p;
    }

    /* ---------------------------------------------------------------- */
    /* Envoi                                                             */
    /* ---------------------------------------------------------------- */

    public static function send($eq, $recipients = null) {
        $cfg = ThermoFlair325::getCfg($eq);
        $rec = ($recipients !== null) ? $recipients : $cfg['report_recipients'];
        if (empty($rec) || !is_array($rec)) {
            return array('sent' => 0, 'detail' => 'aucun destinataire de rapport');
        }
        $r = self::build($eq);
        $sent = 0;
        $problems = array();
        foreach ($rec as $n) {
            if (empty($n['cmd_id'])) {
                continue;
            }
            $cmd = ThermoFlair325::resolveCmd($n['cmd_id']);
            if (!is_object($cmd) || $cmd->getType() != 'action') {
                $problems[] = 'commande introuvable ' . $n['cmd_id'];
                continue;
            }
            $title = !empty($n['title']) ? $n['title'] : $r['title'];
            // Plugin Mail : images dans le corps du mail.
            if (!empty($r['charts']) && $cmd->getEqType() == 'mail' && (!isset($n['files']) || $n['files'] == '1')) {
                try {
                    if (self::sendMailInline($cmd, $title, $r['html_inline'], $r['text'], $r['charts'])) {
                        $sent++;
                        continue;
                    }
                } catch (\Throwable $e) {
                    log::add(ThermoFlair325::PLUGIN, 'warning', 'Rapport : envoi avec images intégrées impossible (' . $e->getMessage() . '), envoi avec pièces jointes.');
                }
            }
            $opt = array(
                'title' => $title,
                'message' => (!empty($n['html']) && $n['html'] == '1') ? $r['html'] : $r['text'],
            );
            if (!empty($r['files']) && (!isset($n['files']) || $n['files'] == '1')) {
                $opt['files'] = $r['files'];
            }
            try {
                $cmd->execCmd($opt);
                $sent++;
            } catch (\Throwable $e) {
                $problems[] = $n['cmd_id'] . ' : ' . $e->getMessage();
                log::add(ThermoFlair325::PLUGIN, 'error', 'Rapport ' . $n['cmd_id'] . ' : ' . $e->getMessage());
            }
        }
        return array('sent' => $sent, 'detail' => implode(' ; ', $problems), 'files' => count($r['files']), 'gd' => $r['gd']);
    }

    /*
     * Envoi direct par le plugin Mail de Jeedom, avec les graphiques intégrés
     * au corps du mail (images « cid », affichées par tous les logiciels de
     * mail, Gmail compris). Reprend la configuration de l'équipement Mail
     * (SMTP, sendmail...). Retourne false si ce n'est pas possible (mode
     * Jeedom Cloud, PHPMailer absent) : l'appelant envoie alors normalement.
     */
    private static function sendMailInline($cmd, $title, $html, $text, $charts) {
        $mailEq = $cmd->getEqLogic();
        if (!is_object($mailEq)) {
            return false;
        }
        $mode = $mailEq->getConfiguration('sendMode', 'mail');
        if (!in_array($mode, array('smtp', 'mail', 'sendmail', 'qmail'))) {
            return false; // Jeedom Cloud : pas d'images intégrées possibles
        }
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            $auto = dirname(__FILE__) . '/../../../mail/vendor/autoload.php';
            if (!file_exists($auto)) {
                return false;
            }
            require_once $auto;
            if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                return false;
            }
        }
        $to = trim((string) $cmd->getConfiguration('recipient', ''));
        if ($to === '') {
            return false;
        }
        $m = new \PHPMailer\PHPMailer\PHPMailer(true);
        $m->CharSet = 'utf-8';
        switch ($mode) {
            case 'smtp':
                $m->isSMTP();
                $m->Host = $mailEq->getConfiguration('smtp::server');
                $m->Port = (int) $mailEq->getConfiguration('smtp::port');
                $sec = $mailEq->getConfiguration('smtp::security', '');
                if ($sec != '' && $sec != 'none') {
                    $m->SMTPSecure = $sec;
                }
                if ($mailEq->getConfiguration('smtp::username') != '') {
                    $m->SMTPAuth = true;
                    $m->Username = $mailEq->getConfiguration('smtp::username');
                    $m->Password = $mailEq->getConfiguration('smtp::password');
                }
                if ($mailEq->getConfiguration('smtp::dontcheckssl', 0) == 1) {
                    $m->SMTPOptions = array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true));
                }
                break;
            case 'sendmail':
                $m->isSendmail();
                break;
            case 'qmail':
                $m->isQmail();
                break;
            default:
                $m->isMail();
        }
        $from = $mailEq->getConfiguration('fromMail');
        $fromName = $mailEq->getConfiguration('fromName', '');
        $m->From = $from;
        $m->FromName = ($fromName != '') ? $fromName : $from;
        $m->addReplyTo($from, $fromName);
        foreach (explode(',', $to) as $addr) {
            if (trim($addr) !== '') {
                $m->addAddress(trim($addr));
            }
        }
        $m->isHTML(true);
        $m->Subject = $title;
        $m->Body = '<div style="font-family:sans-serif;">' . $html . '</div>';
        $m->AltBody = $text;
        foreach ($charts as $c) {
            $m->addEmbeddedImage($c['path'], $c['cid'], basename($c['path']), 'base64', 'image/png');
        }
        try {
            return $m->send();
        } catch (\Throwable $e) {
            sleep(5);
            return $m->send();
        }
    }

    /* ---------------------------------------------------------------- */
    /* Graphiques PNG (GD)                                               */
    /* ---------------------------------------------------------------- */

    private static function font($bold = false) {
        $own = dirname(__FILE__) . '/../../resources/fonts/DejaVuSans' . ($bold ? '-Bold' : '') . '.ttf';
        if (file_exists($own)) {
            return $own;
        }
        $sys = '/usr/share/fonts/truetype/dejavu/DejaVuSans' . ($bold ? '-Bold' : '') . '.ttf';
        if (file_exists($sys)) {
            return $sys;
        }
        return dirname(__FILE__) . '/../../resources/fonts/DejaVuSans.ttf';
    }

    private static function hex($im, $hex, $alpha = 0) {
        $hex = ltrim($hex, '#');
        return imagecolorallocatealpha($im, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), $alpha);
    }

    private static function text($im, $size, $x, $y, $col, $txt, $bold = false, $align = 'left') {
        $font = self::font($bold);
        if ($align != 'left') {
            $bb = imagettfbbox($size, 0, $font, $txt);
            $w = $bb[2] - $bb[0];
            $x = ($align == 'center') ? $x - $w / 2 : $x - $w;
        }
        imagettftext($im, $size, 0, intval($x), intval($y), $col, $font, $txt);
    }

    /*
     * Cadre commun : fond, titre, axes. Retourne [im, zone de tracé, échelle].
     * $series : liste de ['label', couleur, axe 'l'|'r'].
     */
    private static function frame($title, $yMin, $yMax, $unit, $legend, $y2 = null) {
        $im = imagecreatetruecolor(self::W, self::H);
        imageantialias($im, true);
        $white = self::hex($im, 'ffffff');
        imagefill($im, 0, 0, $white);
        $grey = self::hex($im, 'dddddd');
        $dark = self::hex($im, '333333');
        $soft = self::hex($im, '777777');
        self::text($im, 13, 20, 26, $dark, $title, true);
        $L = 60;
        $R = self::W - ($y2 ? 60 : 20);
        $T = 46;
        $B = self::H - 58;
        if ($yMax - $yMin < 1e-6) {
            $yMax = $yMin + 1;
        }
        // Grille et graduations
        for ($i = 0; $i <= 4; $i++) {
            $y = $B - ($B - $T) * $i / 4;
            imageline($im, $L, intval($y), $R, intval($y), $grey);
            $v = $yMin + ($yMax - $yMin) * $i / 4;
            $st = abs($yMax - $yMin) / 4;
            $dec = 0;
            while ($dec < 3 && abs($st * pow(10, $dec) - round($st * pow(10, $dec))) > 1e-6) {
                $dec++;
            }
            self::text($im, 9, $L - 6, $y + 4, $soft, ThermoFlair325::fmt($v, $dec) . $unit, false, 'right');
            if ($y2) {
                $v2 = $y2[0] + ($y2[1] - $y2[0]) * $i / 4;
                self::text($im, 9, $R + 6, $y + 4, $soft, ThermoFlair325::fmt($v2, 0) . $y2[2], false);
            }
        }
        // Légende
        $x = $L;
        foreach ($legend as $lg) {
            $c = self::hex($im, $lg[1]);
            imagefilledrectangle($im, $x, self::H - 22, $x + 14, self::H - 12, $c);
            self::text($im, 10, $x + 19, self::H - 12, $dark, $lg[0]);
            $bb = imagettfbbox(10, 0, self::font(), $lg[0]);
            $x += 19 + ($bb[2] - $bb[0]) + 22;
        }
        return array($im, array($L, $T, $R, $B), array($yMin, $yMax));
    }

    private static function save($im, $name) {
        $dir = jeedom::getTmpFolder(ThermoFlair325::PLUGIN) . '/report';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        // Ne garder que les images des derniers rapports.
        $old = glob($dir . '/*.png') ?: array();
        if (count($old) > 40) {
            usort($old, function ($a, $b) {
                return filemtime($a) <=> filemtime($b);
            });
            foreach (array_slice($old, 0, count($old) - 40) as $f) {
                @unlink($f);
            }
        }
        $path = $dir . '/' . $name;
        imagepng($im, $path);
        imagedestroy($im);
        return $path;
    }

    /*
     * Tous les graphiques de l'onglet Statistiques, plus la stabilité.
     * Retourne une liste de ['path', 'title', 'cid'].
     */
    public static function charts($eq, $a, $cfg, $days = 14) {
        $out = array();
        $stamp = date('Ymd-Hi', ThermoFlair325::now());
        $list = array(
            array('temperatures', 'Températures', function ($n) use ($eq, $cfg, $days) { return self::chartTemps($eq, $cfg, $days, $n); }),
            array('energie', 'Énergie par jour', function ($n) use ($a, $cfg, $days) { return self::chartDaily($a, $cfg, $n, $days); }),
            array('stabilite', 'Écart à la consigne, jour par jour', function ($n) use ($eq, $a, $cfg, $days) { return self::chartStability($eq, $a, $cfg, $n, $days); }),
            array('montee', 'Montée en température pendant la chauffe', function ($n) use ($a, $cfg) { return self::chartHeat($a, $cfg, $n); }, true),
            array('refroidissement', 'Refroidissement sans chauffage', function ($n) use ($a) { return self::chartCool($a, $n); }, true),
            array('journee_type', 'Journée type', function ($n) use ($a, $cfg) { return self::chartProfile($a, $cfg, $n); }, true),
            array('vitesse_vmc', 'Pertes selon la vitesse de VMC', function ($n) use ($a) { return self::chartSpeed($a, $n); }, true),
            array('temps_qu_il_fait', 'Ce que le soleil apporte', function ($n) use ($a) { return !empty($a['sun_effect']) ? self::chartSky($a, $n) : null; }, true),
            array('sans_chauffage', 'Journées sans chauffage', function ($n) use ($a, $days) { return self::chartNoHeat($a, $n, $days); }),
            array('signature', 'Signature énergétique', function ($n) use ($a) { return self::chartSignature($a, $n); }, true),
            array('confort', 'Confort', function ($n) use ($a) { return self::chartComfort($a, $n); }, true),
            array('ventilation', 'Ventilation et qualité de l\'air', function ($n) use ($a) { return self::chartAir($a, $n); }, true),
            array('echangeur', 'Échangeur et préchauffage', function ($n) use ($a, $days) { return self::chartHx($a, $n, $days); }),
        );
        $i = 0;
        foreach ($list as $c) {
            try {
                $path = $c[2]($c[0] . '_' . $stamp . '.png');
            } catch (\Throwable $e) {
                log::add(ThermoFlair325::PLUGIN, 'warning', 'Rapport : graphique « ' . $c[1] . ' » impossible : ' . $e->getMessage());
                $path = null;
            }
            if ($path) {
                $out[] = array('path' => $path, 'title' => $c[1], 'cid' => 'tfchart' . (++$i) . '_' . $stamp,
                    'period' => !empty($c[3]) ? self::periodText($a) : '');
            }
        }
        return $out;
    }

    // « Mesuré du 12/10 au 25/10 (14 jours de données) » : pour les graphiques sans axe de dates.
    public static function periodText($a) {
        $p = isset($a['period']) ? $a['period'] : array();
        if (empty($p['data_from'])) {
            return '';
        }
        $f = function ($d) {
            return substr($d, 8, 2) . '/' . substr($d, 5, 2);
        };
        $n = intval($p['data_days']);
        return 'Mesuré du ' . $f($p['data_from']) . ' au ' . $f($p['data_to']) . ' (' . $n . ' jour' . ($n > 1 ? 's' : '') . ' de données)';
    }

    // Axe des x en jours (lignes et étiquettes) sur une durée.
    private static function dayAxis($im, $X, $t0, $t1, $T, $B) {
        $soft = self::hex($im, '999999');
        $line = self::hex($im, 'eeeeee');
        $n = ($t1 - $t0) / 86400;
        $step = ($n > 20) ? 3 : (($n > 10) ? 2 : 1);
        $k = 0;
        for ($d = strtotime(date('Y-m-d', $t0) . ' +1 day'); $d < $t1; $d = strtotime('+1 day', $d)) {
            imageline($im, intval($X($d)), $T, intval($X($d)), $B, $line);
            if ($k++ % $step == 0) {
                self::text($im, 8, $X($d) + 3, $B + 15, $soft, self::dayFr($d));
            }
        }
    }

    // Températures des N derniers jours, avec les périodes de chauffe.
    private static function chartTemps($eq, $cfg, $days, $name) {
        $now = ThermoFlair325::now();
        $t0 = $now - $days * 86400;
        $rows = ThermoFlair325Stats::load($eq->getId(), $t0, $now);
        if (count($rows) < 12) {
            return null;
        }
        $all = array();
        $hasUp = false;
        foreach ($rows as $r) {
            foreach (array('tb', 'th', 'to') as $k) {
                if (isset($r[$k]) && $r[$k] !== null) {
                    $all[] = $r[$k];
                    if ($k == 'th') {
                        $hasUp = true;
                    }
                }
            }
        }
        if (!$all) {
            return null;
        }
        $t0 = $rows[0]['t'];
        $t1 = $now;
        $legend = array(array('Bas', 'e76f51'));
        if ($hasUp) {
            $legend[] = array('Haut', '9b5de5');
        }
        $legend[] = array('Extérieur', '2a9d8f');
        $legend[] = array('« ' . $cfg['b1_name'] . ' » chauffe', 'f4a261');
        if ($cfg['b2_enabled'] == '1') {
            $legend[] = array('« ' . $cfg['b2_name'] . ' » chauffe', 'c77dff');
        }
        list($im, $box, $sc) = self::frame('Températures des ' . $days . ' derniers jours', floor(min($all)), ceil(max($all)), '°', $legend);
        list($L, $T, $R, $B) = $box;
        $X = function ($t) use ($t0, $t1, $L, $R) {
            return $L + ($R - $L) * (($t - $t0) / max(1, $t1 - $t0));
        };
        $Y = function ($v) use ($sc, $T, $B) {
            return $B - ($B - $T) * (($v - $sc[0]) / ($sc[1] - $sc[0]));
        };
        // Bandes de chauffe
        foreach (array('b1' => array('f4a261', 60), 'b2' => array('c77dff', 70)) as $b => $col) {
            $cc = self::hex($im, $col[0], $col[1]);
            $open = null;
            $last = null;
            foreach ($rows as $r) {
                $on = isset($r[$b]) && $r[$b] !== null && $r[$b] >= 0.5;
                if ($on && $open === null) {
                    $open = $r['t'];
                } elseif (!$on && $open !== null) {
                    imagefilledrectangle($im, intval($X($open)), $T, max(intval($X($open)) + 1, intval($X($r['t']))), $B, $cc);
                    $open = null;
                }
                $last = $r['t'];
            }
            if ($open !== null) {
                imagefilledrectangle($im, intval($X($open)), $T, intval($X($last)), $B, $cc);
            }
        }
        self::dayAxis($im, $X, $t0, $t1, $T, $B);
        imagesetthickness($im, 2);
        foreach (array('tb' => 'e76f51', 'th' => '9b5de5', 'to' => '2a9d8f') as $k => $col) {
            $cc = self::hex($im, $col);
            $prev = null;
            foreach ($rows as $r) {
                if (!isset($r[$k]) || $r[$k] === null) {
                    $prev = null;
                    continue;
                }
                $pt = array($X($r['t']), $Y($r[$k]), $r['t']);
                if ($prev !== null && $r['t'] - $prev[2] <= 900) {
                    imageline($im, intval($prev[0]), intval($prev[1]), intval($pt[0]), intval($pt[1]), $cc);
                }
                $prev = $pt;
            }
        }
        return self::save($im, $name);
    }

    // Nuage de points générique : $series = [[label, couleur, [[x, y], ...]], ...],
    // $lines = [[label, couleur, [[x, y], ...]], ...] (tirets).
    private static function scatter($title, $xLabel, $yUnit, $series, $lines, $name) {
        $xs = array();
        $ys = array();
        foreach (array_merge($series, $lines) as $s) {
            foreach ($s[2] as $p) {
                $xs[] = $p[0];
                $ys[] = $p[1];
            }
        }
        if (count($xs) < 2) {
            return null;
        }
        $xMin = floor(min($xs));
        $xMax = ceil(max($xs));
        if ($xMax - $xMin < 1) {
            $xMax = $xMin + 1;
        }
        list($yMin, $yMax) = self::nice(min(0, min($ys)), max(0.1, max($ys)));
        $legend = array();
        foreach (array_merge($series, $lines) as $s) {
            $legend[] = array($s[0], $s[1]);
        }
        list($im, $box, $sc) = self::frame($title, $yMin, $yMax, $yUnit, $legend);
        list($L, $T, $R, $B) = $box;
        $X = function ($v) use ($xMin, $xMax, $L, $R) {
            return $L + ($R - $L) * (($v - $xMin) / ($xMax - $xMin));
        };
        $Y = function ($v) use ($sc, $T, $B) {
            return $B - ($B - $T) * (($v - $sc[0]) / ($sc[1] - $sc[0]));
        };
        $soft = self::hex($im, '777777');
        $stepX = max(1, ceil(($xMax - $xMin) / 10));
        for ($v = $xMin; $v <= $xMax; $v += $stepX) {
            self::text($im, 8, $X($v), $B + 14, $soft, ThermoFlair325::fmt($v, 0), false, 'center');
        }
        self::text($im, 9, ($L + $R) / 2, $B + 30, $soft, $xLabel, false, 'center');
        if ($yMin < 0) {
            imageline($im, $L, intval($Y(0)), $R, intval($Y(0)), self::hex($im, '999999'));
        }
        foreach ($series as $s) {
            $cc = self::hex($im, $s[1], 30);
            foreach ($s[2] as $p) {
                imagefilledellipse($im, intval($X($p[0])), intval($Y($p[1])), 9, 9, $cc);
            }
        }
        imagesetthickness($im, 3);
        foreach ($lines as $s) {
            $cc = self::hex($im, $s[1]);
            $pts = $s[2];
            usort($pts, function ($a, $b) {
                return $a[0] <=> $b[0];
            });
            for ($k = 1; $k < count($pts); $k++) {
                self::dashed($im, $X($pts[$k - 1][0]), $Y($pts[$k - 1][1]), $X($pts[$k][0]), $Y($pts[$k][1]), $cc);
            }
        }
        imagesetthickness($im, 1);
        return self::save($im, $name);
    }

    // Échelle « ronde » en 4 intervalles couvrant [lo, hi].
    private static function nice($lo, $hi) {
        $span = max(1e-6, $hi - $lo);
        foreach (array(0.01, 0.02, 0.025, 0.05, 0.1, 0.2, 0.25, 0.5, 1, 2, 2.5, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000) as $st) {
            $mn = floor($lo / $st) * $st;
            if ($mn + 4 * $st >= $hi - 1e-9 && $st >= $span / 4) {
                return array($mn, $mn + 4 * $st);
            }
        }
        return array($lo, $hi);
    }

    // Ligne en tirets (GD ne sait pas combiner tirets et épaisseur).
    private static function dashed($im, $x0, $y0, $x1, $y1, $col) {
        $len = sqrt(($x1 - $x0) * ($x1 - $x0) + ($y1 - $y0) * ($y1 - $y0));
        if ($len < 1) {
            return;
        }
        $dash = 9;
        $gap = 6;
        for ($d = 0; $d < $len; $d += $dash + $gap) {
            $e = min($len, $d + $dash);
            imageline($im, intval($x0 + ($x1 - $x0) * $d / $len), intval($y0 + ($y1 - $y0) * $d / $len), intval($x0 + ($x1 - $x0) * $e / $len), intval($y0 + ($y1 - $y0) * $e / $len), $col);
        }
    }

    private static function chartHeat($a, $cfg, $name) {
        $he = isset($a['heat_episodes']) ? $a['heat_episodes'] : array();
        $hasOut = !empty($a['quality']['has_out']);
        $series = array();
        $lines = array();
        $cols = array('b1' => 'f4a261', 'b2' => 'c77dff');
        foreach (array('b1', 'b2') as $b) {
            $pts = array();
            foreach (isset($he[$b]) ? $he[$b] : array() as $e) {
                if ($e['rate'] !== null && (!$hasOut || $e['to'] !== null)) {
                    $pts[] = array($hasOut ? $e['to'] : $e['hour'], $e['rate']);
                }
            }
            if ($pts) {
                $series[] = array('« ' . $cfg[$b . '_name'] . ' »', $cols[$b], $pts);
            }
            if ($hasOut && !empty($a['predictions'][$b])) {
                $lp = array();
                foreach ($a['predictions'][$b] as $p) {
                    if ($p['to'] !== null) {
                        $lp[] = array($p['to'], $p['rate_on']);
                    }
                }
                if (count($lp) >= 2) {
                    $lines[] = array('modèle ' . $cfg[$b . '_name'], $cols[$b], $lp);
                }
            }
        }
        if (!$series) {
            return null;
        }
        return self::scatter('Montée en température pendant la chauffe (°C/h)', $hasOut ? 'température extérieure (°C)' : 'heure de début', '', $series, $lines, $name);
    }

    private static function chartCool($a, $name) {
        $ce = isset($a['cool_episodes']) ? $a['cool_episodes'] : array();
        $hasOut = !empty($a['quality']['has_out']);
        $hasSun = !empty($a['quality']['has_sun']);
        $grp = array('night' => array(), 'sun' => array(), 'day' => array());
        foreach ($ce as $e) {
            if ($e['rate'] === null || ($hasOut && $e['gap'] === null) || !empty($e['evac']) || !empty($e['sunlit'])) {
                continue;
            }
            $k = $e['night'] ? 'night' : (($hasSun && $e['sky'] == 'sun') ? 'sun' : 'day');
            $grp[$k][] = array($hasOut ? $e['gap'] : $e['hour'], -$e['rate']);
        }
        $series = array();
        if ($grp['night']) {
            $series[] = array('nuit', '264653', $grp['night']);
        }
        if ($grp['sun']) {
            $series[] = array('jour ensoleillé (matin, soir)', 'f4c430', $grp['sun']);
        }
        if ($grp['day']) {
            $series[] = array($hasSun ? 'jour mitigé ou maussade' : 'jour', '8ab17d', $grp['day']);
        }
        if (!$series) {
            return null;
        }
        $lines = array();
        $mb = isset($a['models']['bas']) ? $a['models']['bas'] : null;
        if ($hasOut && $mb && !empty($mb['ok']) && $mb['k'] !== null) {
            $gmax = 5;
            foreach ($grp['night'] as $p) {
                $gmax = max($gmax, $p[0]);
            }
            $lines[] = array('modèle (nuit)', 'b5652d', array(array(0, -$mb['base']), array($gmax, $mb['k'] * $gmax - $mb['base'])));
        }
        return self::scatter('Refroidissement sans chauffage (°C perdus par heure)', $hasOut ? 'écart intérieur − extérieur (°C)' : 'heure', '', $series, $lines, $name);
    }

    // Journée type : % de chauffe par heure (barres) et températures (lignes).
    private static function chartProfile($a, $cfg, $name) {
        $pr = isset($a['profile']) ? $a['profile'] : array();
        if (count($pr) < 12) {
            return null;
        }
        $ts = array();
        foreach ($pr as $p) {
            foreach (array('tb', 'th') as $k) {
                if ($p[$k] !== null) {
                    $ts[] = $p[$k];
                }
            }
        }
        if (!$ts) {
            return null;
        }
        $hasUp = !empty($a['quality']['has_up']);
        $legend = array(array('% « ' . $cfg['b1_name'] . ' »', 'f4a261'), array('% « ' . $cfg['b2_name'] . ' »', 'c77dff'), array('Bas', 'e76f51'));
        if ($hasUp) {
            $legend[] = array('Haut', '9b5de5');
        }
        list($im, $box, $sc) = self::frame('Journée type : part du temps en chauffe et température moyenne par heure', floor(min($ts) * 2) / 2, ceil(max($ts) * 2) / 2, '°', $legend, array(0, 100, ' %'));
        list($L, $T, $R, $B) = $box;
        $n = count($pr);
        $w = ($R - $L) / $n;
        $soft = self::hex($im, '777777');
        foreach ($pr as $i => $p) {
            $x0 = $L + $i * $w;
            $c1 = self::hex($im, 'f4a261', 40);
            $c2 = self::hex($im, 'c77dff', 40);
            if ($p['b1']) {
                imagefilledrectangle($im, intval($x0 + $w * 0.1), intval($B - ($B - $T) * $p['b1'] / 100), intval($x0 + $w * 0.5), $B, $c1);
            }
            if ($p['b2']) {
                imagefilledrectangle($im, intval($x0 + $w * 0.5), intval($B - ($B - $T) * $p['b2'] / 100), intval($x0 + $w * 0.9), $B, $c2);
            }
            if ($i % 3 == 0) {
                self::text($im, 8, $x0 + $w / 2, $B + 14, $soft, $p['h'] . 'h', false, 'center');
            }
        }
        imagesetthickness($im, 2);
        foreach (array('tb' => 'e76f51', 'th' => '9b5de5') as $k => $col) {
            $cc = self::hex($im, $col);
            $prev = null;
            foreach ($pr as $i => $p) {
                if ($p[$k] === null) {
                    $prev = null;
                    continue;
                }
                $pt = array($L + ($i + 0.5) * $w, $B - ($B - $T) * (($p[$k] - $sc[0]) / ($sc[1] - $sc[0])));
                if ($prev) {
                    imageline($im, intval($prev[0]), intval($prev[1]), intval($pt[0]), intval($pt[1]), $cc);
                }
                $prev = $pt;
            }
        }
        return self::save($im, $name);
    }

    // Barres simples par catégorie.
    private static function bars($title, $cats, $vals, $color, $unit, $name) {
        $ok = array_filter($vals, function ($v) {
            return $v !== null;
        });
        if (!$ok) {
            return null;
        }
        list($zz, $top) = self::nice(0, max(0.01, max($ok) * 1.15));
        list($im, $box, $sc) = self::frame($title, 0, $top, $unit, array());
        list($L, $T, $R, $B) = $box;
        $n = count($cats);
        $w = ($R - $L) / $n;
        $cc = self::hex($im, $color);
        $dark = self::hex($im, '333333');
        foreach ($cats as $i => $c) {
            $x0 = intval($L + $i * $w + $w * 0.25);
            $x1 = intval($L + ($i + 1) * $w - $w * 0.25);
            if ($vals[$i] !== null) {
                $y = intval($B - ($B - $T) * $vals[$i] / $sc[1]);
                imagefilledrectangle($im, $x0, $y, $x1, $B, $cc);
                self::text($im, 9, ($x0 + $x1) / 2, $y - 5, $dark, ThermoFlair325::fmt($vals[$i], 3), false, 'center');
            }
            self::text($im, 10, ($x0 + $x1) / 2, $B + 16, $dark, $c, false, 'center');
        }
        return self::save($im, $name);
    }

    private static function chartSpeed($a, $name) {
        $se = isset($a['speed_effect']) ? $a['speed_effect'] : array();
        if (!$se) {
            return null;
        }
        $cats = array();
        $vals = array();
        foreach ($se as $x) {
            $cats[] = 'Vitesse ' . $x['speed'] . (isset($x['n']) ? ' (' . $x['n'] . ' tranches de nuit)' : '');
            $vals[] = $x['loss10'];
        }
        return self::bars('Pertes selon la vitesse de VMC (refroidissement de nuit, °C/h pour 10 °C d\'écart)', $cats, $vals, '2a9d8f', '', $name);
    }

    /*
     * Pictogramme du temps dessiné (les polices des images ne contiennent pas d'emoji) :
     * soleil, soleil voilé d'un nuage, nuage. Centré en ($cx, $cy), ~26 px de large.
     */
    private static function skyIcon($im, $sky, $cx, $cy) {
        if (!in_array($sky, array('sun', 'mixed', 'grey'))) {
            return;
        }
        // Dessin 4x plus grand puis réduit : contours lissés.
        $k = 4;
        $w = 32 * $k;
        $h = 28 * $k;
        $ic = imagecreatetruecolor($w, $h);
        imagealphablending($ic, false);
        imagefill($ic, 0, 0, imagecolorallocatealpha($ic, 255, 255, 255, 127));
        imagealphablending($ic, true);
        $sun = imagecolorallocate($ic, 0xf4, 0xb4, 0x00);
        $cl = $sky == 'grey' ? array(0x7d, 0x87, 0x91) : array(0xb0, 0xbb, 0xc6);
        $cloudCol = imagecolorallocate($ic, $cl[0], $cl[1], $cl[2]);
        $edge = imagecolorallocate($ic, 0xff, 0xff, 0xff);
        $cloud = function ($ox, $oy, $col, $grow) use ($ic, $k) {
            $g = $grow * $k;
            imagefilledellipse($ic, intval(($ox - 7) * $k), intval(($oy + 2) * $k), 14 * $k + $g, 11 * $k + $g, $col);
            imagefilledellipse($ic, intval(($ox + 1) * $k), intval(($oy - 2) * $k), 15 * $k + $g, 15 * $k + $g, $col);
            imagefilledellipse($ic, intval(($ox + 8) * $k), intval(($oy + 2) * $k), 12 * $k + $g, 10 * $k + $g, $col);
            imagefilledrectangle($ic, intval(($ox - 7) * $k), intval(($oy + 2) * $k), intval(($ox + 8) * $k), intval(($oy + 7) * $k + $g / 2), $col);
        };
        if ($sky != 'grey') {
            $sx = $sky == 'sun' ? 16 : 12;
            $sy = $sky == 'sun' ? 14 : 10;
            imagesetthickness($ic, 2 * $k);
            for ($n = 0; $n < 8; $n++) {
                $a = $n * M_PI / 4;
                imageline($ic, intval(($sx + 8 * cos($a)) * $k), intval(($sy + 8 * sin($a)) * $k), intval(($sx + 12 * cos($a)) * $k), intval(($sy + 12 * sin($a)) * $k), $sun);
            }
            imagesetthickness($ic, 1);
            imagefilledellipse($ic, $sx * $k, $sy * $k, 12 * $k, 12 * $k, $sun);
        }
        if ($sky != 'sun') {
            $ox = $sky == 'grey' ? 16 : 19;
            $oy = $sky == 'grey' ? 13 : 17;
            if ($sky == 'mixed') {
                $cloud($ox, $oy, $edge, 3);
            }
            $cloud($ox, $oy, $cloudCol, 0);
        }
        imagecopyresampled($im, $ic, intval($cx - 16), intval($cy - 14), 0, 0, 32, 28, $w, $h);
        imagedestroy($ic);
    }

    // Journées sans chauffage : gain du matin à l'après-midi, couleur = temps.
    private static function chartNoHeat($a, $name, $days = 14) {
        $nh = isset($a['noheat']) ? $a['noheat'] : null;
        if (!$nh || !$nh['days']) {
            return null;
        }
        // Mail : uniquement les journées des $days derniers jours (lisible sur téléphone).
        $from = date('Y-m-d', ThermoFlair325::now() - ($days - 1) * 86400);
        $d = array_values(array_filter($nh['days'], function ($x) use ($from) { return $x['date'] >= $from; }));
        if (!$d) {
            return null;
        }
        $cols = array('sun' => 'f4c430', 'mixed' => 'a8b5c2', 'grey' => '6c757d');
        $nm = array('sun' => 'ensoleillé', 'mixed' => 'mitigé', 'grey' => 'maussade');
        $legend = array();
        foreach ($nh['by_sky'] as $t => $b) {
            if ($b['days']) {
                $legend[] = array($nm[$t] . ' : +' . ThermoFlair325::fmt($b['gain'], 1) . ' °C en moyenne (' . $b['days'] . ' j)', $cols[$t]);
            }
        }
        // Marge en haut pour le pictogramme du temps au-dessus de chaque barre.
        list($zz, $top) = self::nice(0, max(1, max(array_column($d, 'gain')) * 1.35));
        list($im, $box, $sc) = self::frame('Journées sans chauffage : hausse de température du matin à l\'après-midi (bas)', 0, $top, ' °C', $legend);
        list($L, $T, $R, $B) = $box;
        $n = count($d);
        $w = ($R - $L) / $n;
        $dark = self::hex($im, '333333');
        $soft = self::hex($im, '777777');
        foreach ($d as $i => $x) {
            $x0 = intval($L + $i * $w + $w * 0.15);
            $x1 = intval($L + ($i + 1) * $w - $w * 0.15);
            $y = intval($B - ($B - $T) * max(0, $x['gain']) / $sc[1]);
            imagefilledrectangle($im, $x0, $y, $x1, $B, self::hex($im, isset($cols[$x['sky']]) ? $cols[$x['sky']] : 'c0c0c0'));
            self::text($im, 10, ($x0 + $x1) / 2, $y - 6, $dark, '+' . ThermoFlair325::fmt($x['gain'], 1), true, 'center');
            self::skyIcon($im, $x['sky'], ($x0 + $x1) / 2, $y - 34);
            self::text($im, 8, ($x0 + $x1) / 2, $B + 14, $soft, substr($x['date'], 8, 2) . '/' . substr($x['date'], 5, 2), false, 'center');
            if ($x['to'] !== null) {
                self::text($im, 8, ($x0 + $x1) / 2, $B + 27, $soft, ThermoFlair325::fmt($x['to'], 0) . ' °C', false, 'center');
            }
        }
        return self::save($im, $name);
    }

    // Signature énergétique : kWh / jour selon la T extérieure moyenne.
    private static function chartSignature($a, $name) {
        $sg = isset($a['signature']) ? $a['signature'] : null;
        if (!$sg || count($sg['points']) < 3) {
            return null;
        }
        $cols = array('sun' => 'f4c430', 'mixed' => 'a8b5c2', 'grey' => '6c757d');
        $grp = array();
        foreach ($sg['points'] as $p) {
            $k = isset($cols[$p['sky']]) ? $p['sky'] : 'other';
            $grp[$k][] = array($p['to'], $p['kwh']);
        }
        $names = array('sun' => 'jour ensoleillé', 'mixed' => 'jour mitigé', 'grey' => 'jour maussade', 'other' => 'jour');
        $series = array();
        foreach ($grp as $k => $pts) {
            $series[] = array($names[$k], isset($cols[$k]) ? $cols[$k] : 'f4a261', $pts);
        }
        $lines = array();
        $title = 'Signature énergétique (kWh de chauffage par jour)';
        if ($sg['ua'] !== null) {
            $tmin = min(array_column($sg['points'], 'to'));
            $x0 = floor($tmin) - 1;
            $lines[] = array('tendance', 'b5652d', array(array($x0, max(0, $sg['slope'] * $x0 + $sg['intercept'])), array($sg['balance'], 0)));
            $title = 'Signature énergétique : ≈ ' . $sg['ua'] . ' W/K, plus de chauffage au-dessus de ' . ThermoFlair325::fmt($sg['balance'], 1) . ' °C dehors';
        }
        return self::scatter($title, 'température extérieure moyenne du jour (°C)', '', $series, $lines, $name);
    }

    // Confort : part du temps par tranche de 0,5 °C, bas et haut.
    private static function chartComfort($a, $name) {
        $cf = isset($a['comfort']) ? $a['comfort'] : array();
        if (empty($cf['tb'])) {
            return null;
        }
        $zones = array('tb' => array('Bas', 'e76f51'));
        if (!empty($cf['th'])) {
            $zones['th'] = array('Haut', '9b5de5');
        }
        $xs = array();
        $max = 1;
        foreach ($zones as $z => $info) {
            foreach ($cf[$z]['hist'] as $h) {
                $xs[] = $h[0];
                $max = max($max, $h[1]);
            }
        }
        $x0 = min(19, min($xs));
        $x1 = max(26, max($xs) + 0.5);
        $legend = array();
        foreach ($zones as $z => $info) {
            $legend[] = array($info[0] . ' : ' . ThermoFlair325::fmt($cf[$z]['ok'], 0) . ' % entre 20 et 25 °C, ' . ThermoFlair325::fmt($cf[$z]['gt25'], 0) . ' % au-dessus', $info[1]);
        }
        $legend[] = array('confort 20–25 °C', '2a9d8f');
        list($zz, $top) = self::nice(0, $max);
        list($im, $box, $sc) = self::frame('Confort : part du temps passée à chaque température', 0, $top, ' %', $legend);
        list($L, $T, $R, $B) = $box;
        $X = function ($v) use ($x0, $x1, $L, $R) {
            return $L + ($R - $L) * (($v - $x0) / ($x1 - $x0));
        };
        imagefilledrectangle($im, intval($X(20)), $T, intval($X(25.5)), $B, self::hex($im, '2a9d8f', 110));
        $soft = self::hex($im, '777777');
        for ($v = ceil($x0); $v <= $x1; $v++) {
            self::text($im, 9, $X($v), $B + 15, $soft, $v . '°', false, 'center');
        }
        $nz = count($zones);
        $bw = ($X(0.5) - $X(0)) / $nz;
        $i = 0;
        foreach ($zones as $z => $info) {
            $cc = self::hex($im, $info[1]);
            foreach ($cf[$z]['hist'] as $h) {
                $xa = $X($h[0]) + $i * $bw + 1;
                imagefilledrectangle($im, intval($xa), intval($B - ($B - $T) * $h[1] / $sc[1]), intval($xa + $bw - 2), $B, $cc);
            }
            $i++;
        }
        imagesetthickness($im, 2);
        self::dashed($im, $X(25.5), $T, $X(25.5), $B, self::hex($im, 'e76f51'));
        imagesetthickness($im, 1);
        return self::save($im, $name);
    }

    // Ventilation (temps par vitesse) et CO2 (temps par tranche).
    private static function chartAir($a, $name) {
        $air = isset($a['air']) ? $a['air'] : array();
        if (empty($air['speed']) && empty($air['co2'])) {
            return null;
        }
        $cats = array();
        $vals = array();
        $cols = array();
        foreach (isset($air['speed']) && $air['speed'] ? $air['speed'] : array() as $x) {
            $cats[] = 'Vitesse ' . $x['speed'];
            $vals[] = $x['pct'];
            $cols[] = array('8ecae6', '219ebc', '126782', 'd9534f')[$x['speed'] - 1];
        }
        foreach (isset($air['co2']) && $air['co2'] ? $air['co2'] : array() as $i => $x) {
            $cats[] = 'CO2 ' . str_replace(array('moins de ', 'plus de ', ' à ', ' ppm'), array('< ', '> ', '–', ''), $x['label']);
            $vals[] = $x['pct'];
            $cols[] = array('2a9d8f', '8ab17d', 'e9c46a', 'e76f51')[$i];
        }
        list($im, $box, $sc) = self::frame('Ventilation et qualité de l\'air : part du temps' . (!empty($air['co2_avg']) ? ' (CO2 moyen ' . $air['co2_avg'] . ' ppm)' : ''), 0, 100, ' %', array());
        list($L, $T, $R, $B) = $box;
        $n = count($cats);
        $w = ($R - $L) / $n;
        $dark = self::hex($im, '333333');
        foreach ($cats as $i => $c) {
            $x0 = intval($L + $i * $w + $w * 0.15);
            $x1 = intval($L + ($i + 1) * $w - $w * 0.15);
            $y = intval($B - ($B - $T) * $vals[$i] / 100);
            imagefilledrectangle($im, $x0, $y, $x1, $B, self::hex($im, $cols[$i]));
            self::text($im, 10, ($x0 + $x1) / 2, $y - 6, $dark, ThermoFlair325::fmt($vals[$i], 0) . ' %', true, 'center');
            self::text($im, 8, ($x0 + $x1) / 2, $B + 15, $dark, $c, false, 'center');
        }
        return self::save($im, $name);
    }

    // Efficacité de l'échangeur (moyenne par jour) et minutes de préchauffage.
    private static function chartHx($a, $name, $days) {
        $d = array_slice(isset($a['daily']) ? $a['daily'] : array(), -$days);
        $hasEf = false;
        $hasPh = false;
        $maxPh = 0;
        foreach ($d as $x) {
            if (isset($x['ef_avg']) && $x['ef_avg'] !== null) {
                $hasEf = true;
            }
            if (isset($x['ph_min']) && $x['ph_min'] !== null) {
                $hasPh = true;
                $maxPh = max($maxPh, $x['ph_min']);
            }
        }
        if (!$hasEf && !$hasPh) {
            return null;
        }
        $legend = array();
        if ($hasEf) {
            $legend[] = array('efficacité de l\'échangeur (%)', '2a9d8f');
        }
        if ($hasPh) {
            $legend[] = array('préchauffage (min / jour)', '457b9d');
        }
        $y2 = $hasPh ? array(0, max(60, ceil($maxPh / 60) * 60), ' min') : null;
        list($im, $box, $sc) = self::frame('Échangeur et préchauffage antigel', 60, 100, ' %', $legend, $y2);
        list($L, $T, $R, $B) = $box;
        $n = count($d);
        $w = ($R - $L) / max(1, $n);
        $soft = self::hex($im, '777777');
        $cp = self::hex($im, '457b9d', 50);
        foreach ($d as $i => $x) {
            $x0 = intval($L + $i * $w + $w * 0.2);
            $x1 = intval($L + ($i + 1) * $w - $w * 0.2);
            if ($hasPh && !empty($x['ph_min'])) {
                imagefilledrectangle($im, $x0, intval($B - ($B - $T) * $x['ph_min'] / $y2[1]), $x1, $B, $cp);
            }
            if ($n <= 16 || $i % 2 == 0) {
                self::text($im, 8, ($x0 + $x1) / 2, $B + 14, $soft, substr($x['date'], 8, 2) . '/' . substr($x['date'], 5, 2), false, 'center');
            }
        }
        if ($hasEf) {
            imagesetthickness($im, 3);
            $ce = self::hex($im, '2a9d8f');
            $prev = null;
            foreach ($d as $i => $x) {
                if (!isset($x['ef_avg']) || $x['ef_avg'] === null) {
                    $prev = null;
                    continue;
                }
                $pt = array($L + ($i + 0.5) * $w, $B - ($B - $T) * ((max(60, min(100, $x['ef_avg'])) - 60) / 40));
                imagefilledellipse($im, intval($pt[0]), intval($pt[1]), 7, 7, $ce);
                if ($prev) {
                    imageline($im, intval($prev[0]), intval($prev[1]), intval($pt[0]), intval($pt[1]), $ce);
                }
                $prev = $pt;
            }
        }
        return self::save($im, $name);
    }

    // Énergie de chauffage par jour (barres empilées) et T extérieure.
    private static function chartDaily($a, $cfg, $name, $days = 14) {
        $d = array_slice($a['daily'], -$days);
        if (!$d) {
            return null;
        }
        $max = 0;
        $tos = array();
        foreach ($d as $x) {
            $max = max($max, $x['b1_kwh'] + $x['b2_kwh']);
            if ($x['to_avg'] !== null) {
                $tos[] = $x['to_avg'];
            }
        }
        $y2 = $tos ? array(floor(min($tos)) - 1, ceil(max($tos)) + 1, '°') : null;
        list($im, $box, $sc) = self::frame('Énergie de chauffage par jour (' . count($d) . ' derniers jours)', 0, max(1, ceil($max)), ' kWh', array(array('« ' . $cfg['b1_name'] . ' »', 'f4a261'), array('« ' . $cfg['b2_name'] . ' »', 'c77dff'), array('T ext. moyenne', '2a9d8f')), $y2);
        list($L, $T, $R, $B) = $box;
        $n = count($d);
        $w = ($R - $L) / $n;
        $c1 = self::hex($im, 'f4a261');
        $c2 = self::hex($im, 'c77dff');
        $soft = self::hex($im, '777777');
        $Y = function ($v) use ($sc, $T, $B) {
            return $B - ($B - $T) * ($v / ($sc[1] - $sc[0]));
        };
        foreach ($d as $i => $x) {
            $x0 = intval($L + $i * $w + $w * 0.15);
            $x1 = intval($L + ($i + 1) * $w - $w * 0.15);
            imagefilledrectangle($im, $x0, intval($Y($x['b1_kwh'])), $x1, $B, $c1);
            if ($x['b2_kwh'] > 0) {
                imagefilledrectangle($im, $x0, intval($Y($x['b1_kwh'] + $x['b2_kwh'])), $x1, intval($Y($x['b1_kwh'])), $c2);
            }
            if ($n <= 14 || $i % 3 == 0) {
                self::text($im, 8, ($x0 + $x1) / 2, $B + 14, $soft, substr($x['date'], 8, 2) . '/' . substr($x['date'], 5, 2), false, 'center');
            }
        }
        if ($y2) {
            imagesetthickness($im, 2);
            $ct = self::hex($im, '2a9d8f');
            $prev = null;
            foreach ($d as $i => $x) {
                if ($x['to_avg'] === null) {
                    $prev = null;
                    continue;
                }
                $px = $L + ($i + 0.5) * $w;
                $py = $B - ($B - $T) * (($x['to_avg'] - $y2[0]) / ($y2[1] - $y2[0]));
                if ($prev) {
                    imageline($im, intval($prev[0]), intval($prev[1]), intval($px), intval($py), $ct);
                }
                $prev = array($px, $py);
            }
        }
        return self::save($im, $name);
    }

    // Jour par jour : répartition du temps du bas par rapport à sa consigne.
    private static function chartStability($eq, $a, $cfg, $name, $days = 14) {
        $from = strtotime(date('Y-m-d', ThermoFlair325::now() - ($days - 1) * 86400));
        $rows = ThermoFlair325Stats::load($eq->getId(), $from, ThermoFlair325::now());
        $byDay = array();
        foreach ($rows as $r) {
            $r['ta'] = $r['tb'];
            $r['sky'] = null;
            $r['sol'] = 0;
            $byDay[date('Y-m-d', $r['t'])][] = $r;
        }
        $vals = array();
        foreach ($byDay as $day => $list) {
            $st = ThermoFlair325Advisor::stabilityBands($list, 'b1', 'tb');
            if ($st !== null) {
                $vals[$day] = $st;
            }
        }
        $vals = array_slice($vals, -$days, null, true);
        if (count($vals) < 2) {
            return null;
        }
        // Barres empilées : chaque jour = 100 % du temps passé en plage Normal.
        $bands = array(
            'cold' => array('trop froid (< −0,5 °C)', '4a90d9'),
            'ok' => array('consigne ±0,5 °C', '2a9d8f'),
            'warm' => array('+0,5 à +' . ThermoFlair325Advisor::WARM_HI . ' °C', 'f4a261'),
            'hot' => array('plus de +' . ThermoFlair325Advisor::WARM_HI . ' °C', 'd62828'),
        );
        $legend = array();
        foreach ($bands as $bd) {
            $legend[] = array($bd[0], $bd[1]);
        }
        list($im, $box, $sc) = self::frame('Température du bas par rapport à sa consigne (plages Normal, % du temps)', 0, 100, ' %', $legend);
        list($L, $T, $R, $B) = $box;
        $n = count($vals);
        $w = ($R - $L) / $n;
        $soft = self::hex($im, '777777');
        $white = self::hex($im, 'ffffff');
        $i = 0;
        foreach ($vals as $day => $v) {
            $x0 = intval($L + $i * $w + $w * 0.15);
            $x1 = intval($L + ($i + 1) * $w - $w * 0.15);
            $acc = 0;
            foreach ($bands as $key => $bd) {
                if ($v[$key] <= 0) {
                    continue;
                }
                $yb = $B - ($B - $T) * $acc / 100;
                $acc += $v[$key];
                $yt = $B - ($B - $T) * $acc / 100;
                imagefilledrectangle($im, $x0, intval(round($yt)), $x1, intval(round($yb)) - 1, self::hex($im, $bd[1]));
                // Pourcentage dans le segment quand il y a la place.
                if ($yb - $yt >= 16 && $x1 - $x0 >= 26) {
                    self::text($im, 8, ($x0 + $x1) / 2, ($yt + $yb) / 2 + 4, $white, round($v[$key]) . '%', true, 'center');
                }
            }
            if ($n <= 14 || $i % 3 == 0) {
                self::text($im, 8, ($x0 + $x1) / 2, $B + 14, $soft, substr($day, 8, 2) . '/' . substr($day, 5, 2), false, 'center');
            }
            $i++;
        }
        return self::save($im, $name);
    }

    /*
     * Ce que le soleil apporte : de combien la température du bas monte
     * entre 10h et 16h, chauffage éteint, selon le type de journée.
     * Valeur affichée en °C sur les 6 heures (plus parlant que des °C/h),
     * avec sous chaque barre le nombre de jours, la température extérieure
     * moyenne et, s'il y en a, la consommation par degré-jour.
     */
    private static function chartSky($a, $name) {
        $s = $a['sun_effect'];
        $vals = array();
        foreach ($s as $x) {
            if ($x['tb'] !== null) {
                $vals[] = $x['tb'] * 6;
                if (isset($x['tb_sd']) && $x['tb_sd'] !== null) {
                    $vals[] = ($x['tb'] + $x['tb_sd']) * 6;
                    $vals[] = ($x['tb'] - $x['tb_sd']) * 6;
                }
            }
        }
        if (!$vals) {
            return null;
        }
        // Échelle « ronde » : 4 intervalles de 0,5, 1 ou 2 °C.
        $lo = min(0, min($vals));
        $hi = max(0.2, max($vals)) * 1.15;
        $step = 0.5;
        foreach (array(0.5, 1, 2, 5) as $st) {
            $step = $st;
            if ((ceil($hi / $st) - floor($lo / $st)) <= 4) {
                break;
            }
        }
        $mn = floor($lo / $step) * $step;
        $mx = $mn + 4 * $step;
        list($im, $box, $sc) = self::frame('Ce que le soleil apporte (bas, 10h-16h, sans chauffage)', $mn, $mx, ' °C', array());
        list($L, $T, $R, $B) = $box;
        $Y = function ($v) use ($sc, $T, $B) {
            return $B - ($B - $T) * (($v - $sc[0]) / ($sc[1] - $sc[0]));
        };
        $cols = array('sun' => 'f4c430', 'mixed' => 'a8b5c2', 'grey' => '6c757d');
        $names = array('sun' => 'Journées ensoleillées', 'mixed' => 'Journées mitigées', 'grey' => 'Journées maussades');
        $n = count($s);
        $w = ($R - $L) / $n;
        $dark = self::hex($im, '333333');
        $soft = self::hex($im, '777777');
        $few = false;
        $byType = array();
        $sdByType = array();
        foreach ($s as $i => $x) {
            $cx = $L + ($i + 0.5) * $w;
            $sub = (isset($x['method']) && $x['method'] == 'days' ? $x['days'] . ' j mesuré' . ($x['days'] > 1 ? 's' : '') . ((isset($x['days_all']) && $x['days_all'] > $x['days']) ? ' / ' . $x['days_all'] : '') : $x['days'] . ' jour' . ($x['days'] > 1 ? 's' : '') . ' (tranches)') . ($x['to'] !== null ? ' · dehors ' . ThermoFlair325::fmt($x['to'], 0) . ' °C' : '');
            $sub2 = trim((isset($x['sp']) && $x['sp'] !== null ? 'VMC vitesse ' . ThermoFlair325::fmt($x['sp'], 1) : '')
                . (($x['kwh_dju'] !== null && $x['kwh_dju'] > 0) ? ' · ' . ThermoFlair325::fmt($x['kwh_dju'], 2) . ' kWh/DJU' : ''), ' ·');
            self::text($im, 10, $cx, $B + 17, $dark, isset($names[$x['type']]) ? $names[$x['type']] : $x['label'], true, 'center');
            self::text($im, 9, $cx, $B + 31, $soft, $sub, false, 'center');
            self::text($im, 9, $cx, $B + 45, $soft, $sub2, false, 'center');
            if ($x['tb'] === null) {
                self::text($im, 10, $cx, $B - 10, $soft, 'pas assez de mesures', false, 'center');
                continue;
            }
            $v = $x['tb'] * 6;
            $byType[$x['type']] = $v;
            $thin = $x['days'] < 3;
            $few = $few || $thin;
            $x0 = intval($L + $i * $w + $w * 0.25);
            $x1 = intval($L + ($i + 1) * $w - $w * 0.25);
            imagefilledrectangle($im, $x0, intval(min($Y($v), $Y(0))), $x1, intval(max($Y($v), $Y(0))), self::hex($im, $cols[$x['type']], $thin ? 70 : 0));
            // Moustache : dispersion d'un jour à l'autre (± écart type).
            if (isset($x['tb_sd']) && $x['tb_sd'] !== null) {
                $sd = $x['tb_sd'] * 6;
                $sdByType[$x['type']] = $sd;
                $wc = self::hex($im, '444444');
                $ya = intval($Y(max($sc[0], min($sc[1], $v - $sd))));
                $yb = intval($Y(max($sc[0], min($sc[1], $v + $sd))));
                imageline($im, intval($cx), $ya, intval($cx), $yb, $wc);
                imageline($im, intval($cx - 8), $ya, intval($cx + 8), $ya, $wc);
                imageline($im, intval($cx - 8), $yb, intval($cx + 8), $yb, $wc);
            }
            $lab = ($v > 0 ? '+' : '') . ThermoFlair325::fmt($v, 1) . ' °C';
            if ($v >= 0) {
                self::text($im, 12, $cx, $Y($v) - 7, $dark, $lab, true, 'center');
            } else {
                self::text($im, 12, $x1 + 8, ($Y(0) + $Y($v)) / 2 + 6, $dark, $lab, true);
            }
        }
        imageline($im, $L, intval($Y(0)), $R, intval($Y(0)), self::hex($im, '999999'));
        // Remarque en bas : peu de jours, ou résultat contraire à l'attendu.
        $note = '';
        $noise = function ($a, $b) use ($byType, $sdByType) {
            // Écart plus petit que la variation d'un jour à l'autre.
            return isset($sdByType[$a], $sdByType[$b]) && abs($byType[$a] - $byType[$b]) < max($sdByType[$a], $sdByType[$b]);
        };
        if (isset($byType['sun'], $byType['mixed']) && $byType['sun'] < $byType['mixed'] && $noise('sun', 'mixed')) {
            $note = 'Écart plus petit que la variation d\'un jour à l\'autre (traits noirs) : pas encore significatif.';
        } elseif (isset($byType['sun'], $byType['grey']) && $byType['sun'] < $byType['grey']) {
            $note = isset($sdByType['sun']) && abs($byType['sun'] - $byType['grey']) < $sdByType['sun']
                ? 'Écart plus petit que la variation d\'un jour à l\'autre (traits noirs) : pas encore significatif.'
                : 'Jours maussades au-dessus des ensoleillés : trop peu de jours ou dehors plus doux. À confirmer.';
        } elseif ($few) {
            $note = 'Barres pâles : moins de 3 jours de ce type, résultat à confirmer.';
        }
        if ($note !== '') {
            self::text($im, 9, $L, $T - 4, self::hex($im, 'b5652d'), $note);
        }
        return self::save($im, $name);
    }
}
