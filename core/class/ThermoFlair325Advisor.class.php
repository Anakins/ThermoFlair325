<?php

/*
 * ThermoFlair325Advisor — préconisations de réglage à partir de l'historique.
 *
 * But : une température la plus constante possible.
 *
 * 1. Historique des réglages : chaque changement des réglages qui comptent
 *    pour la stabilité est noté (data/stats/<id>/_settings.txt). L'historique
 *    est découpé en périodes de réglages identiques, et chaque période est
 *    notée (part du temps à ±0,5 °C de la consigne, écart-type, cycles...).
 *    C'est le « banc d'essai » : on compare vos réglages réels entre eux.
 * 2. Mesures physiques : inertie après l'arrêt d'une batterie (la
 *    température continue de monter), creux après l'allumage (le temps que
 *    l'air chaud arrive), retard au début des plages, cycles courts, apport
 *    du soleil, effet de la vitesse de VMC, bypass, surchauffe d'été.
 * 3. Préconisations : pour chaque point, valeur actuelle → valeur conseillée,
 *    explication en une phrase et niveau de confiance (selon le nombre de
 *    mesures). Rien n'est appliqué automatiquement.
 *
 * Pas de propriété statique (voir la règle dans ThermoFlair325.class.php).
 */
class ThermoFlair325Advisor {

    /* Réglages suivis (ceux qui changent la stabilité de la température). */
    const TRACKED = array(
        'heat_hyst_low' => 'Allumer sous la consigne de',
        'heat_hyst_high' => 'Éteindre au-dessus de',
        'b1_min_on' => 'Bas : marche minimum',
        'b1_min_off' => 'Bas : arrêt minimum',
        'b2_min_on' => 'Haut : marche minimum',
        'b2_min_off' => 'Haut : arrêt minimum',
        'b1_min_speed' => 'Bas : vitesse VMC minimum',
        'b2_min_speed' => 'Haut : vitesse VMC minimum',
        'assist_enabled' => 'Appoint du haut',
        'assist_after' => 'Appoint après',
        'heat_postvent' => 'VMC après arrêt',
        'vmc_mode_w' => 'VMC hiver : décision',
        'vmc_mode_s' => 'VMC été : décision',
        'w_sp_comf_n' => 'VMC hiver : vitesse bonne température',
        'vmc_hyst' => 'VMC : battement',
        'vmc_dwell' => 'VMC : temps entre deux changements',
    );
    /* Écart toléré autour de la consigne pour « température stable ». */
    const BAND = 0.5;

    /* ---------------------------------------------------------------- */
    /* Historique des réglages                                           */
    /* ---------------------------------------------------------------- */

    public static function file($eqId) {
        return ThermoFlair325Stats::dir($eqId) . '/_settings.txt';
    }

    public static function snapshot($cfg) {
        $s = array();
        foreach (self::TRACKED as $k => $lab) {
            $s[$k] = isset($cfg[$k]) ? (string) $cfg[$k] : '';
        }
        return $s;
    }

    // Note les réglages s'ils ont changé depuis la dernière fois.
    public static function record($eqId, $cfg, $now) {
        $snap = self::snapshot($cfg);
        $all = self::load($eqId);
        if (!empty($all) && end($all)['s'] == $snap) {
            return false;
        }
        @file_put_contents(self::file($eqId), json_encode(array('t' => intval($now), 's' => $snap)) . "\n", FILE_APPEND | LOCK_EX);
        return true;
    }

    public static function load($eqId) {
        $f = self::file($eqId);
        if (!file_exists($f)) {
            return array();
        }
        $out = array();
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $r = json_decode($line, true);
            if (is_array($r) && isset($r['t'], $r['s'])) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /* ---------------------------------------------------------------- */
    /* Mesures                                                           */
    /* ---------------------------------------------------------------- */

    private static function avg($a) {
        $a = array_values(array_filter($a, function ($v) {
            return $v !== null;
        }));
        return $a ? array_sum($a) / count($a) : null;
    }

    private static function std($a) {
        $m = self::avg($a);
        if ($m === null || count($a) < 2) {
            return null;
        }
        $s = 0;
        foreach ($a as $v) {
            $s += ($v - $m) * ($v - $m);
        }
        return sqrt($s / (count($a) - 1));
    }

    private static function conf($n, $good, $mid) {
        return $n >= $good ? 'bonne' : ($n >= $mid ? 'moyenne' : 'faible');
    }

    private static function fr($v) {
        return str_replace('.', ',', (string) $v);
    }

    private static function r1($v) {
        return round($v * 10) / 10;
    }

    /*
     * Stabilité d'une batterie / zone sur des échantillons : écart à la
     * consigne quand la batterie a une consigne (= elle doit tenir une
     * température), en saison de chauffe.
     */
    /*
     * Répartition du temps par écart à la consigne (mêmes moments que
     * stability()) : trop froid (< −0,5 °C), à la consigne (±0,5 °C),
     * plus chaud (+0,5 à +5 °C), bien plus chaud (> +5 °C). En % ; null si
     * moins d'une heure de mesures.
     */
    const WARM_HI = 5;

    public static function stabilityBands($rows, $b, $z) {
        $c = ($b == 'b1') ? 'c1' : 'c2';
        $kf = ($b == 'b1') ? 'k1' : 'k2';
        $useK = false;
        foreach ($rows as $r) {
            if (isset($r[$kf]) && $r[$kf] !== null) {
                $useK = true;
                break;
            }
        }
        $cnt = array('cold' => 0, 'ok' => 0, 'warm' => 0, 'hot' => 0);
        $n = 0;
        foreach ($rows as $r) {
            if ($r[$c] === null || $r[$z] === null || ($useK && ($r[$kf] === null || $r[$kf] < 0.5))) {
                continue;
            }
            $d = $r[$z] - $r[$c];
            $n++;
            if ($d < -self::BAND) {
                $cnt['cold']++;
            } elseif ($d <= self::BAND) {
                $cnt['ok']++;
            } elseif ($d <= self::WARM_HI) {
                $cnt['warm']++;
            } else {
                $cnt['hot']++;
            }
        }
        if ($n < 12) {
            return null;
        }
        foreach ($cnt as $k => $v) {
            $cnt[$k] = 100 * $v / $n;
        }
        return $cnt;
    }

    public static function stability($rows, $b, $z) {
        $c = ($b == 'b1') ? 'c1' : 'c2';
        $kf = ($b == 'b1') ? 'k1' : 'k2';
        // Seulement les moments où la batterie doit tenir une consigne de
        // confort (Normal / consigne fixe) quand cette information existe.
        $useK = false;
        foreach ($rows as $r) {
            if (isset($r[$kf]) && $r[$kf] !== null) {
                $useK = true;
                break;
            }
        }
        $dev = array();
        $on = 0;
        $prev = null;
        $first = null;
        $last = null;
        $overHeat = 0;
        $overSun = 0;
        $lastOn = -1;
        foreach ($rows as $i => $r) {
            if ($r[$b] !== null && $r[$b] >= 0.5) {
                $lastOn = $r['t'];
            }
            if ($r[$c] === null || $r[$z] === null || ($useK && ($r[$kf] === null || $r[$kf] < 0.5))) {
                $prev = null;
                continue;
            }
            $dev[] = $r[$z] - $r[$c];
            if ($r[$z] - $r[$c] > self::BAND && $lastOn >= 0 && $r['t'] - $lastOn <= 3600) {
                $overHeat++;
            }
            // Dépassement attribué au soleil : journée ensoleillée ou mitigée
            // (ou surchauffe solaire détectée), 10h-19h, sans chauffe depuis 1 h.
            $hr = intval(date('G', $r['t']));
            if ($r[$z] - $r[$c] > self::BAND && ($lastOn < 0 || $r['t'] - $lastOn > 3600)
                && (!empty($r['sol']) || ($hr >= 10 && $hr < 19 && isset($r['sky']) && in_array($r['sky'], array('sun', 'mixed'))))) {
                $overSun++;
            }
            $first = ($first === null) ? $r['t'] : $first;
            $last = $r['t'];
            $isOn = ($r[$b] !== null && $r[$b] >= 0.5);
            if ($isOn && $prev === false) {
                $on++;
            }
            $prev = $isOn;
        }
        $n = count($dev);
        if ($n < 36) {
            return null;
        }
        $in = 0;
        $under = 0;
        $over = 0;
        foreach ($dev as $d) {
            if (abs($d) <= self::BAND) {
                $in++;
            } elseif ($d < 0) {
                $under++;
            } else {
                $over++;
            }
        }
        $days = max(1 / 24, ($n * ThermoFlair325Stats::STEP) / 86400);
        return array(
            'n' => $n,
            'hours' => round($n * 5 / 60, 1),
            'in_band' => round(100 * $in / $n),
            'under' => round(100 * $under / $n),
            'over' => round(100 * $over / $n),
            'over_heat' => round(100 * $overHeat / $n),
            'over_sun' => round(100 * $overSun / $n),
            'std' => round(self::std($dev), 2),
            'mean' => round(self::avg($dev), 2),
            'cycles_day' => round($on / $days, 1),
        );
    }

    /*
     * Événements de chauffe d'une batterie : à l'arrêt, combien la
     * température monte encore (inertie) ; à l'allumage, combien elle
     * descend encore (retard) ; durée de chaque marche.
     */
    public static function events($rows, $b, $z) {
        $c = ($b == 'b1') ? 'c1' : 'c2';
        $n = count($rows);
        $over = array();
        $dip = array();
        $runs = array();
        $offs = array();
        $startI = null;
        for ($i = 1; $i < $n; $i++) {
            if ($rows[$i]['t'] - $rows[$i - 1]['t'] != ThermoFlair325Stats::STEP) {
                $startI = null;
                continue;
            }
            $was = ($rows[$i - 1][$b] !== null && $rows[$i - 1][$b] >= 0.5);
            $is = ($rows[$i][$b] !== null && $rows[$i][$b] >= 0.5);
            if (!$was && $is) {
                $startI = $i;
                $t0 = $rows[$i - 1][$z];
                if ($t0 !== null) {
                    $min = $t0;
                    for ($j = $i; $j < min($n, $i + 12); $j++) {
                        if ($rows[$j][$z] !== null) {
                            $min = min($min, $rows[$j][$z]);
                        }
                    }
                    $dip[] = $t0 - $min;
                }
            }
            if ($was && !$is) {
                if ($startI !== null) {
                    $runs[] = ($i - $startI) * 5;
                }
                $t0 = $rows[$i - 1][$z];
                $cons = $rows[$i - 1][$c];
                if ($t0 !== null) {
                    $max = $t0;
                    $ok = true;
                    // On regarde 90 min, en s'arrêtant si une batterie repart.
                    for ($j = $i; $j < min($n, $i + 18); $j++) {
                        if (($rows[$j]['b1'] ?: 0) >= 0.5 || ($rows[$j]['b2'] ?: 0) >= 0.5) {
                            break;
                        }
                        if ($rows[$j][$z] !== null) {
                            $max = max($max, $rows[$j][$z]);
                        }
                    }
                    $over[] = $max - $t0;
                    $offs[] = array('run' => ($startI !== null) ? ($i - $startI) * 5 : null, 'gap' => ($cons !== null) ? $t0 - $cons : null);
                }
                $startI = null;
            }
        }
        return array(
            'overrun' => $over ? round(self::avg($over), 2) : null,
            'dip' => $dip ? round(self::avg($dip), 2) : null,
            'n_off' => count($over),
            'n_on' => count($dip),
            'runs' => $runs,
            'offs' => $offs,
        );
    }

    /*
     * Début des plages : quand une plage démarre, la température est-elle
     * déjà là ? Sinon, combien de minutes faut-il pour l'atteindre ?
     */
    public static function slotStarts($rows, $cfg) {
        $byT = array();
        foreach ($rows as $r) {
            $byT[$r['t']] = $r;
        }
        $out = array();
        if (empty($rows)) {
            return $out;
        }
        $from = strtotime(date('Y-m-d', $rows[0]['t']));
        $to = end($rows)['t'];
        foreach ($cfg['heat_slots'] as $idx => $s) {
            $mode = isset($s['mode']) ? $s['mode'] : '';
            if (!in_array($mode, array('normal', 'comfort', 'consigne', 'eco'))) {
                continue;
            }
            $st = ThermoFlair325::hm2min(isset($s['start']) ? $s['start'] : '');
            if ($st === null) {
                continue;
            }
            foreach ((isset($s['batteries']) ? $s['batteries'] : array()) as $b) {
                $z = ThermoFlair325Stats::zoneKey($cfg, $b, $cfg['temp_up_cmd'] !== '');
                $c = ($b == 'b2') ? 'c2' : 'c1';
                $delays = array();
                $deficits = array();
                for ($d = $from; $d <= $to; $d += 86400) {
                    if (!ThermoFlair325::slotAppliesOn($cfg, $s, $b, $d)) {
                        continue;
                    }
                    $t0 = intval(floor(($d + $st * 60) / ThermoFlair325Stats::STEP) * ThermoFlair325Stats::STEP);
                    if (!isset($byT[$t0]) || !isset($byT[$t0 + ThermoFlair325Stats::STEP])) {
                        continue;
                    }
                    $target = $byT[$t0 + ThermoFlair325Stats::STEP][$c];
                    $T = $byT[$t0][$z];
                    if ($target === null || $T === null) {
                        continue;
                    }
                    $def = $target - $T;
                    $deficits[] = $def;
                    $reach = 0;
                    if ($def > 0.2) {
                        $reach = null;
                        for ($k = 1; $k <= 48; $k++) {
                            $rr = isset($byT[$t0 + $k * ThermoFlair325Stats::STEP]) ? $byT[$t0 + $k * ThermoFlair325Stats::STEP] : null;
                            if ($rr !== null && $rr[$z] !== null && $rr[$z] >= $target - 0.2) {
                                $reach = $k * 5;
                                break;
                            }
                        }
                        if ($reach === null) {
                            $reach = 240;
                        }
                    }
                    $delays[] = $reach;
                }
                if (count($delays) >= 2) {
                    $out[] = array('idx' => $idx, 'slot' => $s, 'b' => $b, 'n' => count($delays),
                        'delay' => round(self::avg($delays)), 'deficit' => round(self::avg($deficits), 2),
                        'late_pct' => round(100 * count(array_filter($delays, function ($x) {
                            return $x >= 15;
                        })) / count($delays)));
                }
            }
        }
        return $out;
    }

    /* Découpe l'historique en périodes de réglages identiques et les note. */
    public static function periods($eqId, $rows, $cfg) {
        $hist = self::load($eqId);
        if (empty($rows)) {
            return array();
        }
        if (empty($hist)) {
            $hist = array(array('t' => 0, 's' => self::snapshot($cfg)));
        }
        $out = array();
        $count = count($hist);
        for ($i = 0; $i < $count; $i++) {
            $a = ($i == 0) ? 0 : $hist[$i]['t'];
            $b = ($i + 1 < $count) ? $hist[$i + 1]['t'] : PHP_INT_MAX;
            $part = array_values(array_filter($rows, function ($r) use ($a, $b) {
                return $r['t'] >= $a && $r['t'] < $b;
            }));
            if (count($part) < 36) {
                continue;
            }
            $st = self::stability($part, 'b1', 'tb');
            $kwh = 0;
            foreach ($part as $r) {
                $kwh += (($r['b1'] ?: 0) * floatval($cfg['b1_power']) + ($r['b2'] ?: 0) * floatval($cfg['b2_power'])) * 5 / 60 / 1000;
            }
            $tos = array_column($part, 'to');
            $out[] = array(
                'from' => date('Y-m-d H:i', max($a, $part[0]['t'])),
                'to' => date('Y-m-d H:i', end($part)['t']),
                'days' => round(count($part) * 5 / 1440, 1),
                'settings' => $hist[$i]['s'],
                'stab' => $st,
                'kwh_day' => round($kwh / max(1, count($part) * 5 / 1440), 2),
                'to_avg' => ($v = self::avg($tos)) !== null ? round($v, 1) : null,
                'current' => ($i == $count - 1),
            );
        }
        return $out;
    }

    /* ---------------------------------------------------------------- */
    /* Préconisations                                                    */
    /* ---------------------------------------------------------------- */

    /*
     * $res = résultat de ThermoFlair325Stats::analyze (modèles, épisodes...).
     * Retourne array('stability' => ..., 'periods' => ..., 'advice' => [...]).
     * Chaque conseil : id, level (action|info|ok), title, text, key (réglage
     * à changer, ou null), current, suggested, confidence, evidence.
     */
    public static function advise($eqId, $rows, $cfg, $res) {
        $adv = array();
        $names = array('b1' => $cfg['b1_name'], 'b2' => $cfg['b2_name']);
        $f = function ($v, $d = 1) {
            return ThermoFlair325::fmt($v, $d);
        };
        $hasUp = !empty($res['quality']['has_up']);
        $zoneOf = array('b1' => ThermoFlair325Stats::zoneKey($cfg, 'b1', $hasUp), 'b2' => ThermoFlair325Stats::zoneKey($cfg, 'b2', $hasUp));
        $add = function ($a) use (&$adv) {
            $adv[] = $a + array('key' => null, 'current' => null, 'suggested' => null, 'confidence' => 'moyenne', 'evidence' => '', 'level' => 'action', 'slot' => null);
        };

        // Capteur de luminosité mal calibré : fausse le classement des journées.
        $lc = isset($res['lux_check']) ? $res['lux_check'] : null;
        if ($lc && $lc['suggest'] !== null) {
            $add(array('id' => 'lux_max', 'title' => 'Régler le maximum du capteur de luminosité',
                'text' => 'Votre capteur ne dépasse presque jamais ' . $lc['p90'] . ' lx (atteint ' . $lc['reach'] . ' jour(s) sur ' . $lc['days'] . ' à 95 % du maximum réglé). Les journées ensoleillées sont classées « maussades », ce qui inverse les statistiques du soleil. Une fois réglé, réimportez l\'historique n\'est pas nécessaire : le classement est recalculé à chaque analyse.',
                'key' => 'lux_max', 'current' => $lc['max'], 'suggested' => $lc['suggest'], 'confidence' => ($lc['days'] >= 14) ? 'bonne' : 'moyenne',
                'evidence' => 'plus haut de chaque jour sur ' . $lc['days'] . ' jours (90 % des jours sous ' . $lc['p90'] . ' lx)', 'level' => 'action'));
        }

        // Mesures sur la période des réglages actuels si elle est assez longue
        // (sinon tout l'historique).
        $hist = self::load($eqId);
        $since = $hist ? end($hist)['t'] : 0;
        $cur = array_values(array_filter($rows, function ($r) use ($since) {
            return $r['t'] >= $since;
        }));
        $all = $rows;
        if (count($cur) >= 3 * 288) {
            $rows = $cur;
        }
        // ---- Stabilité actuelle ----
        $stab = array();
        foreach (array('b1', 'b2') as $b) {
            if ($cfg[$b . '_enabled'] == '1') {
                $stab[$b] = self::stability($rows, $b, $zoneOf[$b]);
            }
        }
        $ev = array();
        foreach (array('b1', 'b2') as $b) {
            if ($cfg[$b . '_enabled'] == '1') {
                $ev[$b] = self::events($rows, $b, $zoneOf[$b]);
            }
        }

        // ---- 1. Battement du thermostat : inertie et retard ----
        $hl = floatval($cfg['heat_hyst_low']);
        $hh = floatval($cfg['heat_hyst_high']);
        $e = isset($ev['b1']) ? $ev['b1'] : null;
        if ($e && $e['n_off'] >= 3 && $e['overrun'] !== null) {
            if ($e['overrun'] >= 0.15) {
                $sug = max(0, self::r1($hh - $e['overrun'] + 0.1));
                if ($sug < $hh) {
                    $add(array('id' => 'hyst_high', 'title' => 'Couper la batterie plus tôt',
                        'text' => 'Après l\'arrêt de « ' . $names['b1'] . ' », la température monte encore de ' . $f($e['overrun'], 2) . ' °C en moyenne (chaleur stockée dans la batterie et les gaines). En coupant plus tôt, le pic retombe sur la consigne.',
                        'key' => 'heat_hyst_high', 'current' => $hh, 'suggested' => $sug,
                        'confidence' => self::conf($e['n_off'], 15, 5), 'evidence' => $e['n_off'] . ' arrêts mesurés'));
                }
            } elseif ($e['overrun'] < 0.05 && $hh < 0.2) {
                $add(array('id' => 'hyst_high_up', 'title' => 'Couper un peu plus tard',
                    'text' => 'La température ne monte presque plus après l\'arrêt (' . $f($e['overrun'], 2) . ' °C). Un battement haut un peu plus grand espace les cycles sans perdre en confort.',
                    'key' => 'heat_hyst_high', 'current' => $hh, 'suggested' => 0.2,
                    'confidence' => self::conf($e['n_off'], 15, 5), 'evidence' => $e['n_off'] . ' arrêts mesurés'));
            }
        }
        if ($e && $e['n_on'] >= 3 && $e['dip'] !== null && $e['dip'] >= 0.15) {
            $sug = max(0.1, self::r1($hl - $e['dip'] + 0.1));
            if ($sug < $hl) {
                $add(array('id' => 'hyst_low', 'title' => 'Allumer la batterie plus tôt',
                    'text' => 'Après l\'allumage, la température baisse encore de ' . $f($e['dip'], 2) . ' °C avant que l\'air chaud n\'arrive. En allumant plus tôt, le creux reste près de la consigne.',
                    'key' => 'heat_hyst_low', 'current' => $hl, 'suggested' => $sug,
                    'confidence' => self::conf($e['n_on'], 15, 5), 'evidence' => $e['n_on'] . ' allumages mesurés'));
            }
        }

        // ---- 2. Marche minimum qui fait dépasser / cycles trop courts ----
        foreach (array('b1', 'b2') as $b) {
            if (empty($ev[$b]) || empty($stab[$b])) {
                continue;
            }
            $minOn = intval($cfg[$b . '_min_on']);
            $forced = array_filter($ev[$b]['offs'], function ($o) use ($minOn, $hh) {
                return $o['run'] !== null && $o['run'] <= $minOn + 5 && $o['gap'] !== null && $o['gap'] > $hh + 0.2;
            });
            if (count($ev[$b]['offs']) >= 4 && count($forced) / count($ev[$b]['offs']) >= 0.3 && $minOn > 5) {
                $sug = max(5, intval(round($minOn * 0.6 / 5) * 5));
                $add(array('id' => $b . '_min_on', 'title' => '« ' . $names[$b] . ' » : marche minimum trop longue',
                    'text' => round(100 * count($forced) / count($ev[$b]['offs'])) . ' % des arrêts arrivent après la marche minimum alors que la consigne est déjà dépassée : la marche minimum fait surchauffer.',
                    'key' => $b . '_min_on', 'current' => $minOn, 'suggested' => $sug,
                    'confidence' => self::conf(count($ev[$b]['offs']), 15, 5), 'evidence' => count($ev[$b]['offs']) . ' marches'));
            } elseif ($stab[$b]['cycles_day'] > 20) {
                $sug = min(60, intval($cfg[$b . '_min_off']) + 10);
                $add(array('id' => $b . '_min_off', 'title' => '« ' . $names[$b] . ' » : trop de démarrages',
                    'text' => $f($stab[$b]['cycles_day']) . ' démarrages par jour de consigne. Un arrêt minimum plus long évite l\'usure sans changer le confort.',
                    'key' => $b . '_min_off', 'current' => intval($cfg[$b . '_min_off']), 'suggested' => $sug,
                    'confidence' => self::conf($stab[$b]['hours'], 72, 24), 'evidence' => $f($stab[$b]['hours'], 0) . ' h mesurées'));
            }
        }

        // ---- 2 bis. Zone au-dessus de sa consigne sans chauffer ----
        foreach (array('b1', 'b2') as $b) {
            if (!empty($stab[$b]) && $stab[$b]['over'] >= 40 && $stab[$b]['over_heat'] <= 5) {
                $add(array('id' => $b . '_passive', 'level' => 'info',
                    'title' => ($b == 'b2' && $hasUp ? 'Le haut' : 'Le bas') . ' dépasse sa consigne sans chauffer',
                    'text' => 'Pendant ses plages, la zone de « ' . $names[$b] . ' » est au-dessus de la consigne de plus de ' . self::fr(self::BAND) . ' °C ' . $stab[$b]['over'] . ' % du temps, sans que la batterie ait chauffé : la chaleur vient ' . (($stab[$b]['over_sun'] * 2 >= $stab[$b]['over']) ? 'surtout du soleil par les vitrages (' . $stab[$b]['over_sun'] . ' % du temps au-dessus de la consigne à cause du soleil)' : ($b == 'b2' ? 'de la zone de « ' . $names['b1'] . ' » ou du soleil' : 'du soleil ou de la vie dans la maison')) . '. Rien à régler sur cette batterie' . ($b == 'b2' ? ' ; pour garder cette zone plus fraîche, chauffez un peu moins « ' . $names['b1'] . ' ».' : '.'),
                    'confidence' => self::conf($stab[$b]['hours'], 72, 24), 'evidence' => self::fr(round($stab[$b]['hours'])) . ' h mesurées'));
            }
        }

        // ---- 3. Début des plages : anticipation ----
        foreach (self::slotStarts($rows, $cfg) as $s) {
            if ($s['delay'] < 15 || $s['late_pct'] < 50) {
                continue;
            }
            $b = $s['b'];
            $lead = intval(ceil($s['delay'] / 5) * 5);
            $st = ThermoFlair325::hm2min($s['slot']['start']);
            $new = ThermoFlair325::min2hm(($st - $lead + 1440) % 1440);
            $title = 'Plage ' . ThermoFlair325::rowName($s['slot']);
            $base = 'Au début de la plage, il manque en moyenne ' . $f($s['deficit'], 1) . ' °C (« ' . $names[$b] . ' ») et il faut ' . $s['delay'] . ' min pour atteindre la consigne.';
            // La batterie peut-elle chauffer avant le début (silence) ?
            $days = isset($s['slot']['days']) ? $s['slot']['days'] : array('1');
            $dow = intval(reset($days));
            $ref = strtotime('next monday', ThermoFlair325::now()) + ($dow - 1) * 86400;
            $need = intval($cfg[$b . '_min_speed']);
            $blockedAt = null;
            for ($m = $st - $lead; $m < $st; $m += 5) {
                $cap = ThermoFlair325::quietCap($cfg, $ref + $m * 60);
                if ($cap !== null && $cap['max'] < $need) {
                    $blockedAt = $cap;
                    break;
                }
            }
            if ($blockedAt !== null) {
                $add(array('id' => 'slot_' . $s['idx'] . '_' . $b, 'title' => $title . ' : le silence empêche de chauffer avant',
                    'text' => $base . ' Mais pendant « ' . $blockedAt['label'] . ' » la VMC est limitée à ' . $blockedAt['max'] . ' et « ' . $names[$b] . ' » a besoin de la vitesse ' . $need . ' : elle ne peut pas chauffer avant la fin du silence (' . $blockedAt['end'] . '). Pistes : finir le silence plus tôt, passer la vitesse minimum de la batterie à 2 si elle chauffe assez en vitesse 2, ou une consigne ECO plus haute pour la nuit.',
                    'level' => 'info', 'confidence' => self::conf($s['n'], 10, 4), 'evidence' => $s['n'] . ' débuts de plage'));
            } elseif ($lead > 120 && $cfg['heat_slots'][$s['idx']]['mode'] != 'eco') {
                $eco = floatval($cfg['heat_eco']);
                $sug = min(floatval($cfg['heat_consigne']) - ThermoFlair325::ECO_GAP, $eco + 0.5);
                $add(array('id' => 'slot_' . $s['idx'] . '_' . $b, 'title' => $title . ' : écart trop grand à rattraper',
                    'text' => $base . ' Plutôt que de commencer plus de 2 h plus tôt, une consigne ECO un peu plus haute réduit l\'écart à rattraper et garde une température plus régulière.',
                    'key' => 'heat_eco', 'current' => $eco, 'suggested' => $sug,
                    'confidence' => self::conf($s['n'], 10, 4), 'evidence' => $s['n'] . ' débuts de plage'));
            } else {
                $add(array('id' => 'slot_' . $s['idx'] . '_' . $b, 'title' => $title . ' : commencer plus tôt',
                    'text' => $base . ' Commencer la plage ' . $lead . ' min plus tôt pour qu\'il fasse bon dès ' . $s['slot']['start'] . '.',
                    'key' => 'heat_slots', 'current' => $s['slot']['start'], 'suggested' => $new, 'slot' => $s['idx'],
                    'confidence' => self::conf($s['n'], 10, 4), 'evidence' => $s['n'] . ' débuts de plage'));
            }
        }

        // ---- 4. Le bas n'arrive pas à suivre : appoint ----
        if (!empty($stab['b1']) && $stab['b1']['under'] >= 15) {
            $pred = isset($res['predictions']['b1']) ? $res['predictions']['b1'] : array();
            $slow = array_filter($pred, function ($p) {
                return $p['to'] !== null && $p['to'] <= 0 && ($p['min_per_deg'] === null || $p['min_per_deg'] > 180);
            });
            if ($cfg['assist_enabled'] != '1' && $cfg['b2_enabled'] == '1') {
                $add(array('id' => 'assist_on', 'title' => 'Activer l\'appoint du haut',
                    'text' => 'Le bas est sous la consigne de plus de ' . self::fr(self::BAND) . ' °C ' . $stab['b1']['under'] . ' % du temps' . ($slow ? ' : par grand froid, « ' . $names['b1'] . ' » met plus de 3 h pour gagner 1 °C' : '') . '. L\'appoint de « ' . $names['b2'] . ' » l\'aide quand elle n\'y arrive pas.',
                    'key' => 'assist_enabled', 'current' => 0, 'suggested' => 1,
                    'confidence' => self::conf($stab['b1']['hours'], 72, 24), 'evidence' => $f($stab['b1']['hours'], 0) . ' h mesurées'));
            } elseif ($cfg['assist_enabled'] == '1' && intval($cfg['assist_after']) > 30) {
                $add(array('id' => 'assist_after', 'title' => 'Déclencher l\'appoint plus tôt',
                    'text' => 'Le bas reste trop froid ' . $stab['b1']['under'] . ' % du temps malgré l\'appoint. Le déclencher plus tôt raccourcit ces périodes.',
                    'key' => 'assist_after', 'current' => intval($cfg['assist_after']), 'suggested' => max(30, intval($cfg['assist_after']) - 20),
                    'confidence' => self::conf($stab['b1']['hours'], 72, 24), 'evidence' => $f($stab['b1']['hours'], 0) . ' h mesurées'));
            }
        }

        // ---- 5. Vitesse de VMC pendant la chauffe ----
        foreach (array('b1', 'b2') as $b) {
            $eps = isset($res['heat_episodes'][$b]) ? $res['heat_episodes'][$b] : array();
            $by = array(2 => array(), 3 => array());
            foreach ($eps as $ep) {
                if ($ep['sp'] !== null && $ep['rate'] !== null) {
                    $k = intval(round($ep['sp']));
                    if (isset($by[$k])) {
                        $by[$k][] = $ep['rate'];
                    }
                }
            }
            if (count($by[2]) >= 3 && count($by[3]) >= 3 && intval($cfg[$b . '_min_speed']) == 3) {
                $r2 = self::avg($by[2]);
                $r3 = self::avg($by[3]);
                if ($r3 > 0 && $r2 >= 0.85 * $r3) {
                    $add(array('id' => $b . '_speed', 'title' => '« ' . $names[$b] . ' » chauffe aussi bien en vitesse 2',
                        'text' => 'Montée mesurée : ' . $f($r2, 2) . ' °C/h en vitesse 2 contre ' . $f($r3, 2) . ' °C/h en vitesse 3. En vitesse 2, moins de pertes par la VMC, moins de bruit, et la batterie peut chauffer pendant les plages de silence.',
                        'key' => $b . '_min_speed', 'current' => 3, 'suggested' => 2,
                        'confidence' => self::conf(min(count($by[2]), count($by[3])), 10, 3), 'evidence' => count($by[2]) . ' + ' . count($by[3]) . ' chauffes'));
                }
            }
        }
        $mb = isset($res['models']['bas']) ? $res['models']['bas'] : null;
        if ($mb && !empty($mb['ok']) && !empty($mb['k_speed']) && intval($cfg['w_sp_comf_n']) > 1 && $mb['k_speed'][1] > 0) {
            $pct = round(100 * ($mb['k_speed'][intval($cfg['w_sp_comf_n'])] / $mb['k_speed'][1] - 1));
            if ($pct >= 10) {
                $add(array('id' => 'w_speed', 'title' => 'Hiver : VMC en vitesse 1 hors chauffe',
                    'text' => 'En vitesse ' . intval($cfg['w_sp_comf_n']) . ', la maison perd ' . $pct . ' % de chaleur de plus qu\'en vitesse 1. La vitesse 1 garde la température plus stable entre deux chauffes.',
                    'key' => 'w_sp_comf_n', 'current' => intval($cfg['w_sp_comf_n']), 'suggested' => 1,
                    'confidence' => self::conf($mb['n'], 2000, 600), 'evidence' => 'modèle sur ' . $mb['n'] . ' points'));
            }
        }

        // ---- 6. VMC qui change trop souvent ----
        $chg = 0;
        $prev = null;
        $n = 0;
        foreach ($rows as $r) {
            if ($r['sp'] === null) {
                continue;
            }
            $sp = intval(round($r['sp']));
            if ($prev !== null && $sp != $prev) {
                $chg++;
            }
            $prev = $sp;
            $n++;
        }
        $perDay = $n ? $chg / ($n * 5 / 1440) : 0;
        if ($n >= 288 && $perDay > 16) {
            $add(array('id' => 'vmc_hyst', 'title' => 'La VMC change trop souvent de vitesse',
                'text' => round($perDay) . ' changements par jour en moyenne. Un battement plus grand stabilise la vitesse (et le bruit).',
                'key' => 'vmc_hyst', 'current' => floatval($cfg['vmc_hyst']), 'suggested' => self::r1(floatval($cfg['vmc_hyst']) + 0.2),
                'confidence' => self::conf($n / 288, 14, 3), 'evidence' => round($n / 288, 1) . ' jours'));
        }

        // ---- 7. Soleil : surchauffe des après-midi ensoleillés ----
        if (!empty($res['quality']['has_sun']) && !empty($stab['b1'])) {
            $sunny = array();
            $cloudy = array();
            foreach ($rows as $r) {
                $h = intval(date('G', $r['t']));
                if ($h < 12 || $h >= 17 || $r['c1'] === null || $r['tb'] === null || empty($r['sky'])) {
                    continue;
                }
                if ($r['sky'] == 'sun') {
                    $sunny[] = $r['tb'] - $r['c1'];
                } elseif ($r['sky'] == 'grey') {
                    $cloudy[] = $r['tb'] - $r['c1'];
                }
            }
            if (count($sunny) >= 24 && count($cloudy) >= 24) {
                $ds = self::avg($sunny);
                $dc = self::avg($cloudy);
                if ($ds - $dc >= 0.4 && $ds > self::BAND) {
                    $add(array('id' => 'sun', 'title' => 'Les après-midi ensoleillés font dépasser la consigne',
                        'level' => 'info',
                        'text' => 'Les jours ensoleillés, le bas dépasse la consigne de ' . $f($ds, 1) . ' °C l\'après-midi (contre ' . $f($dc, 1) . ' °C les jours maussades). Pistes : une plage ECO de fin de matinée (la batterie chauffe moins avant le soleil), ou des protections solaires.',
                        'confidence' => self::conf(min(count($sunny), count($cloudy)), 144, 36), 'evidence' => round(count($sunny) * 5 / 60) . ' h de journées ensoleillées'));
                }
            }
        }

        // ---- 8. Bypass ouvert pendant la chauffe ----
        if (!empty($res['kpi']['bypass_heat_min']) && $res['kpi']['bypass_heat_min'] >= 30) {
            $add(array('id' => 'bypass_heat', 'title' => 'Bypass ouvert pendant la chauffe', 'level' => 'info',
                'text' => 'Le bypass était ouvert pendant ' . $res['kpi']['bypass_heat_min'] . ' min de chauffe : une partie de la chaleur repart dehors. Réglez le bypass de la VMC pour qu\'il reste fermé sous la consigne.',
                'confidence' => 'bonne', 'evidence' => $res['kpi']['bypass_heat_min'] . ' min'));
        }

        // ---- 9. Meilleur mode de décision (températures / bypass) ----
        $md = isset($res['modes']) ? $res['modes'] : array();
        if (count($md) == 2 && $md[0]['kwh_dju'] !== null && $md[1]['kwh_dju'] !== null && min($md[0]['kwh_dju'], $md[1]['kwh_dju']) > 0) {
            $best = ($md[0]['kwh_dju'] <= $md[1]['kwh_dju']) ? $md[0] : $md[1];
            $other = ($best === $md[0]) ? $md[1] : $md[0];
            $pct = round(100 * (1 - $best['kwh_dju'] / $other['kwh_dju']));
            $cur = ($cfg['vmc_mode_w'] == 'bypass') ? 1 : 0;
            if ($pct >= 10 && $best['mode'] != $cur && $best['days'] >= 5 && $other['days'] >= 5) {
                $add(array('id' => 'mode_w', 'title' => 'Hiver : décider avec « ' . $best['label'] . ' »',
                    'text' => 'Avec le mode « ' . $best['label'] . ' », le chauffage consomme ' . $pct . ' % de moins par degré-jour.',
                    'key' => 'vmc_mode_w', 'current' => $cur ? 'bypass' : 'temp', 'suggested' => $best['mode'] ? 'bypass' : 'temp',
                    'confidence' => self::conf(min($best['days'], $other['days']), 21, 7), 'evidence' => $best['days'] . ' + ' . $other['days'] . ' jours'));
            }
        }
        if (count($md) == 2 && $md[0]['overheat_h'] !== null && $md[1]['overheat_h'] !== null && $md[0]['summer_h'] > 0 && $md[1]['summer_h'] > 0) {
            $p0 = $md[0]['overheat_h'] / $md[0]['summer_h'];
            $p1 = $md[1]['overheat_h'] / $md[1]['summer_h'];
            $best = ($p0 <= $p1) ? $md[0] : $md[1];
            $gain = abs($p0 - $p1) * 100;
            $cur = ($cfg['vmc_mode_s'] == 'bypass') ? 1 : 0;
            if ($gain >= 10 && $best['mode'] != $cur) {
                $add(array('id' => 'mode_s', 'title' => 'Été : décider avec « ' . $best['label'] . ' »',
                    'text' => 'Avec le mode « ' . $best['label'] . ' », la maison est trop chaude ' . round($gain) . ' % du temps en moins.',
                    'key' => 'vmc_mode_s', 'current' => $cur ? 'bypass' : 'temp', 'suggested' => $best['mode'] ? 'bypass' : 'temp',
                    'confidence' => self::conf(min($md[0]['summer_h'], $md[1]['summer_h']) / 24, 21, 7), 'evidence' => round($md[0]['summer_h'] / 24) . ' + ' . round($md[1]['summer_h'] / 24) . ' jours d\'été'));
            }
        }

        // ---- 10. Retour à des réglages qui marchaient mieux ----
        $periods = self::periods($eqId, $all, $cfg);
        $curP = null;
        foreach ($periods as $p) {
            if ($p['current']) {
                $curP = $p;
            }
        }
        if ($curP !== null && $curP['stab'] !== null && $curP['days'] >= 3) {
            $best = null;
            foreach ($periods as $p) {
                if ($p['current'] || $p['stab'] === null || $p['days'] < 3) {
                    continue;
                }
                if ($best === null || $p['stab']['in_band'] > $best['stab']['in_band']) {
                    $best = $p;
                }
            }
            if ($best !== null && $best['stab']['in_band'] >= $curP['stab']['in_band'] + 8) {
                $diff = array();
                foreach ($best['settings'] as $k => $v) {
                    if (isset($curP['settings'][$k]) && $curP['settings'][$k] !== $v) {
                        $diff[] = self::TRACKED[$k] . ' : ' . $curP['settings'][$k] . ' → ' . $v;
                    }
                }
                if ($diff) {
                    $add(array('id' => 'history', 'title' => 'Vos réglages du ' . date('d/m', strtotime($best['from'])) . ' au ' . date('d/m', strtotime($best['to'])) . ' étaient plus stables', 'level' => 'info',
                        'text' => 'Température à ±' . self::fr(self::BAND) . ' °C de la consigne ' . $best['stab']['in_band'] . ' % du temps, contre ' . $curP['stab']['in_band'] . ' % aujourd\'hui. Différences : ' . implode(' ; ', $diff) . '. À comparer avec la météo de chaque période (' . $f($best['to_avg']) . ' °C dehors contre ' . $f($curP['to_avg']) . ' °C).',
                        'confidence' => self::conf(min($best['days'], $curP['days']), 14, 5), 'evidence' => $best['days'] . ' + ' . $curP['days'] . ' jours'));
                }
            }
        }

        // ---- Tout va bien ? ----
        $actions = array_filter($adv, function ($a) {
            return $a['level'] == 'action';
        });
        if (empty($actions) && !empty($stab['b1']) && $stab['b1']['in_band'] >= 85) {
            $add(array('id' => 'ok', 'level' => 'ok', 'title' => 'Réglages bien adaptés',
                'text' => 'Le bas reste à ±' . self::fr(self::BAND) . ' °C de la consigne ' . $stab['b1']['in_band'] . ' % du temps. Rien à changer pour l\'instant.',
                'confidence' => self::conf($stab['b1']['hours'], 72, 24), 'evidence' => $f($stab['b1']['hours'], 0) . ' h mesurées'));
        }
        $order = array('bonne' => 0, 'moyenne' => 1, 'faible' => 2);
        usort($adv, function ($a, $b) use ($order) {
            $la = ($a['level'] == 'action') ? 0 : (($a['level'] == 'info') ? 1 : 2);
            $lb = ($b['level'] == 'action') ? 0 : (($b['level'] == 'info') ? 1 : 2);
            return ($la <=> $lb) ?: ($order[$a['confidence']] <=> $order[$b['confidence']]);
        });
        return array('stability' => $stab, 'events' => array_map(function ($e) {
            unset($e['offs'], $e['runs']);
            return $e;
        }, $ev), 'periods' => $periods, 'advice' => $adv, 'labels' => self::TRACKED);
    }
}
