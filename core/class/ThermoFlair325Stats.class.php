<?php

/*
 * ThermoFlair325Stats — enregistrement et analyse thermique de la maison.
 *
 * Enregistrement : un échantillon toutes les 5 minutes (moyennes sur les
 * 5 minutes), une ligne JSON par échantillon, un fichier par jour :
 *   plugins/ThermoFlair325/data/stats/<id équipement>/AAAA-MM-JJ.jsonl
 * (le dossier data/ est conservé lors des mises à jour du plugin).
 *
 * Champs : t (début du créneau), tb / th / to (températures bas, haut,
 * extérieur), b1 / b2 (part du créneau où chaque batterie chauffait, 0..1),
 * sp (vitesse VMC moyenne), pw (puissance Linky), co2, c1 / c2 (consignes),
 * lx (luminosité extérieure en lux ; l'ensoleillement 0..1 est calculé à
 * l'analyse avec le « plein soleil » configuré, qu'on peut donc changer),
 * by (part du créneau où le bypass de la VMC était ouvert), md (mode de
 * décision de la VMC : 0 = températures, 1 = bypass), k1 / k2 (1 = la
 * batterie tenait une consigne de confort : Normal ou consigne fixe).
 *
 * Analyse (sur la période demandée) :
 *  - bilan par jour : temps de chauffe, kWh, températures, degrés-jours ;
 *  - épisodes de chauffe : vitesse de montée (°C/h) selon l'extérieur ;
 *  - épisodes sans chauffage : vitesse de refroidissement, déperdition
 *    (°C/h par °C d'écart intérieur-extérieur), constante de temps ;
 *  - modèle thermique par zone (régression) :
 *      dT/dt = gain_bas × bas + gain_haut × haut + k × (Text − Tint)
 *              + soleil × ensoleillement + jour + base
 *    d'où les prévisions « combien de temps chauffer pour gagner X °C ».
 * Sans sonde extérieure, l'analyse se limite à ce qui ne dépend pas d'elle.
 *
 * Pas de propriété statique (voir la règle dans ThermoFlair325.class.php).
 */
class ThermoFlair325Stats {

    const STEP = 300;
    const FIELDS = array('tb', 'th', 'to', 'b1', 'b2', 'sp', 'pw', 'co2', 'c1', 'c2', 'lx', 'by', 'md', 'k1', 'k2', 'eg', 'ph', 'pp', 'cc', 'rn');
    /*
     * Type de journée d'après la luminosité. Beaucoup de capteurs saturent
     * (ex. 2000 lux) dès qu'il fait jour clair, même sans soleil direct :
     * on ne mesure donc pas l'intensité du soleil mais la part de la journée
     * (9h-17h) passée au maximum. ≥ 60 % : ensoleillée ; 15 à 60 % : mitigée
     * (soleil et nuages) ; < 15 % : maussade (le capteur reste sous son max).
     */
    const SKY_SAT = 0.95;
    const SKY_SUN = 0.6;
    const SKY_MIXED = 0.15;
    const SKY_LABELS = array('sun' => 'ensoleillée', 'mixed' => 'mitigée', 'grey' => 'maussade');
    const BASE_DJU = 18;

    /* ================================================================ */
    /* Stockage                                                          */
    /* ================================================================ */

    public static function dataDir() {
        return dirname(__FILE__) . '/../../data';
    }

    public static function dir($eqId) {
        $base = self::dataDir();
        $d = $base . '/stats/' . intval($eqId);
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        if (!file_exists($base . '/.htaccess')) {
            @file_put_contents($base . '/.htaccess', "Deny from all\n");
        }
        return $d;
    }

    // Cumule les mesures de la minute ; écrit un échantillon à chaque
    // changement de créneau de 5 minutes.
    public static function accumulate($eqId, $cfg, &$rt, $ctx, $now) {
        $bucket = intval(floor($now / self::STEP) * self::STEP);
        $a = &$rt['acc'];
        if (!empty($a['bucket']) && $a['bucket'] != $bucket && !empty($a['n'])) {
            self::append($eqId, self::finalize($a));
            $a = array();
        }
        if (empty($a['bucket']) || $a['bucket'] != $bucket) {
            $a = array('bucket' => $bucket, 'n' => 0, 's' => array(), 'c' => array());
        }
        $vals = array(
            'tb' => $ctx['temp_in'],
            'th' => $ctx['temp_up'],
            'to' => $ctx['temp_out'],
            'b1' => ($cfg['mod_heat'] == '1' && $cfg['b1_enabled'] == '1') ? (($rt['heat']['b1']['actual'] === true) ? 1 : 0) : null,
            'b2' => ($cfg['mod_heat'] == '1' && $cfg['b2_enabled'] == '1') ? (($rt['heat']['b2']['actual'] === true) ? 1 : 0) : null,
            'sp' => $ctx['pos'],
            'pw' => $ctx['power'],
            'co2' => $ctx['co2'],
            'lx' => isset($ctx['lux']) ? $ctx['lux'] : null,
            'by' => (isset($ctx['bypass']) && $ctx['bypass'] !== null) ? ($ctx['bypass'] ? 1 : 0) : null,
            'md' => ($cfg['mod_vmc'] == '1') ? (($cfg['vmc_mode_' . ($ctx['season'] == 'summer' ? 's' : 'w')] == 'bypass') ? 1 : 0) : null,
            'c1' => isset($rt['heat']['b1']['target']) ? $rt['heat']['b1']['target'] : null,
            'c2' => isset($rt['heat']['b2']['target']) ? $rt['heat']['b2']['target'] : null,
            'k1' => isset($rt['heat']['b1']['comfort']) ? $rt['heat']['b1']['comfort'] : null,
            'k2' => isset($rt['heat']['b2']['comfort']) ? $rt['heat']['b2']['comfort'] : null,
            // Efficacité de l'échangeur (moyenne air soufflé / air rejeté, 0.9.4 ;
            // l'ancien champ « ef », côté air soufflé seul et biaisé, est ignoré).
            'eg' => (function () use ($cfg, $ctx) {
                $why = '';
                $e = ThermoFlair325::hxEfficiency($cfg, $ctx + array('hx_sup' => null, 'hx_exh' => null, 'preheat' => null, 'bypass' => null), $why);
                return $e === null ? null : $e['val'];
            })(),
            'ph' => (isset($ctx['preheat']) && $ctx['preheat'] !== null) ? ($ctx['preheat'] ? 1 : 0) : null,
            'pp' => isset($ctx['preheat_pw']) ? $ctx['preheat_pw'] : null,
            // Météo : nébulosité (0 clair à 1 couvert) et précipitations (0/1).
            'cc' => !empty($ctx['wx']) ? $ctx['wx']['cc'] : null,
            'rn' => !empty($ctx['wx']) ? $ctx['wx']['wet'] : null,
        );
        $a['n']++;
        foreach ($vals as $k => $v) {
            if ($v === null || !is_numeric($v)) {
                continue;
            }
            $a['s'][$k] = (isset($a['s'][$k]) ? $a['s'][$k] : 0) + floatval($v);
            $a['c'][$k] = (isset($a['c'][$k]) ? $a['c'][$k] : 0) + 1;
        }
    }

    public static function finalize($a) {
        $row = array('t' => intval($a['bucket']));
        foreach (self::FIELDS as $k) {
            $row[$k] = (!empty($a['c'][$k])) ? round($a['s'][$k] / $a['c'][$k], ($k == 'pw' || $k == 'co2' || $k == 'lx') ? 0 : 2) : null;
        }
        return $row;
    }

    public static function append($eqId, $row) {
        $file = self::dir($eqId) . '/' . date('Y-m-d', $row['t']) . '.jsonl';
        @file_put_contents($file, json_encode($row) . "\n", FILE_APPEND | LOCK_EX);
    }

    public static function load($eqId, $from, $to) {
        $rows = array();
        $d = self::dir($eqId);
        for ($day = strtotime(date('Y-m-d', $from)); $day <= $to; $day = strtotime('+1 day', $day)) {
            $file = $d . '/' . date('Y-m-d', $day) . '.jsonl';
            if (!file_exists($file)) {
                continue;
            }
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $r = json_decode($line, true);
                if (is_array($r) && isset($r['t']) && $r['t'] >= $from && $r['t'] <= $to) {
                    // Champs ajoutés dans une version plus récente : null.
                    foreach (self::FIELDS as $k) {
                        if (!array_key_exists($k, $r)) {
                            $r[$k] = null;
                        }
                    }
                    $rows[intval($r['t'])] = $r;
                }
            }
        }
        ksort($rows);
        return array_values($rows);
    }

    public static function purge($eqId, $keepDays) {
        $limit = date('Y-m-d', ThermoFlair325::now() - max(30, $keepDays) * 86400);
        foreach (glob(self::dir($eqId) . '/*.jsonl') as $f) {
            if (basename($f, '.jsonl') < $limit) {
                @unlink($f);
            }
        }
    }

    public static function info($eqId) {
        $files = glob(self::dir($eqId) . '/*.jsonl');
        sort($files);
        $size = 0;
        foreach ($files as $f) {
            $size += filesize($f);
        }
        return array(
            'days' => count($files),
            'first' => $files ? basename($files[0], '.jsonl') : null,
            'last' => $files ? basename(end($files), '.jsonl') : null,
            'size_kb' => round($size / 1024),
        );
    }

    /* ================================================================ */
    /* Import de l'historique Jeedom                                     */
    /* ================================================================ */

    /*
     * Reconstitue des échantillons de 5 minutes à partir de l'historique
     * Jeedom des commandes liées (si elles sont historisées). Les jours
     * déjà enregistrés par le plugin ne sont jamais écrasés.
     */
    public static function importHistory($eq, $days) {
        $cfg = ThermoFlair325::getCfg($eq);
        $now = ThermoFlair325::now();
        $to = intval(floor($now / self::STEP) * self::STEP);
        $from = strtotime(date('Y-m-d', $now - max(1, intval($days)) * 86400));
        $map = array('tb' => 'temp_in_cmd', 'th' => 'temp_up_cmd', 'to' => 'temp_out_cmd', 'b1' => 'b1_state_cmd', 'b2' => 'b2_state_cmd', 'sp' => 'vmc_pos_cmd', 'pw' => 'shed_power_cmd', 'co2' => 'co2_cmd', 'lx' => 'lux_cmd', 'by' => 'bypass_cmd',
            // Ajouts : météo (code -> nébulosité / pluie), préchauffage,
            // températures de l'échangeur (-> efficacité, calculée plus bas).
            'wx' => 'weather_cmd', 'ph' => 'preheat_cmd', 'pp' => 'preheat_power_cmd', 'hs' => 'hx_supply_cmd', 'he' => 'hx_exhaust_cmd');
        // Durée pendant laquelle une valeur reste valable entre deux points.
        $hold = array('b1' => 86400, 'b2' => 86400, 'sp' => 86400, 'by' => 86400, 'ph' => 86400, 'lx' => 3 * 3600, 'wx' => 3 * 3600, 'pp' => 3 * 3600);
        $series = array();
        $report = array();
        foreach ($map as $field => $key) {
            if ($cfg[$key] === '') {
                continue;
            }
            $cmd = ThermoFlair325::resolveCmd($cfg[$key]);
            if (!is_object($cmd)) {
                continue;
            }
            $points = array();
            try {
                $hist = history::all($cmd->getId(), date('Y-m-d H:i:s', $from - 12 * 3600), date('Y-m-d H:i:s', $to));
            } catch (\Throwable $e) {
                $hist = array();
            }
            foreach ($hist as $hh) {
                $val = $hh->getValue();
                if ($field == 'sp') {
                    $val = self::speedFromRaw($cfg, $val);
                }
                if ($val === null || !is_numeric($val)) {
                    continue;
                }
                if ($field == 'b1' || $field == 'b2') {
                    $val = (floatval($val) > 0) ? 1 : 0;
                } elseif ($field == 'by') {
                    $val = ThermoFlair325::inList($val, $cfg['bypass_open_value']) ? 1 : 0;
                } elseif ($field == 'ph') {
                    $val = ThermoFlair325::inList($val, $cfg['preheat_on_value']) ? 1 : 0;
                }
                $points[] = array(strtotime($hh->getDatetime()), floatval($val));
            }
            usort($points, function ($a, $b) {
                return $a[0] <=> $b[0];
            });
            $report[$field] = count($points);
            if (!empty($points)) {
                $series[$field] = $points;
            }
        }
        // Météo : un code ne se moyenne pas. On le convertit d'abord en
        // nébulosité (cc) et pluie (rn). En mode automatique, les 0
        // « parasites » (commande vide) sont écartés si la famille de codes
        // dominante n'est pas Open-Meteo.
        if (!empty($series['wx'])) {
            $fam = array('wmo' => 0, 'owm' => 0, 'weatherapi' => 0);
            foreach ($series['wx'] as $pt) {
                $m = ThermoFlair325::mapWeather($pt[1], $cfg['weather_source']);
                if ($m) {
                    $fam[$m['src']]++;
                }
            }
            arsort($fam);
            $main = key($fam);
            $cc = array();
            $rn = array();
            foreach ($series['wx'] as $pt) {
                if ($cfg['weather_source'] == 'auto' && intval($pt[1]) == 0 && $main != 'wmo') {
                    continue;
                }
                $m = ThermoFlair325::mapWeather($pt[1], ($cfg['weather_source'] == 'auto') ? $main : $cfg['weather_source']);
                if ($m) {
                    $cc[] = array($pt[0], $m['cc']);
                    $rn[] = array($pt[0], $m['wet']);
                }
            }
            unset($series['wx']);
            if ($cc) {
                $series['cc'] = $cc;
                $series['rn'] = $rn;
                $hold['cc'] = $hold['wx'];
                $hold['rn'] = $hold['wx'];
            }
        }
        if (empty($series['tb'])) {
            return array('imported_days' => 0, 'skipped_days' => 0, 'points' => $report, 'message' => "Aucun historique pour la température du bas : activez l'historique de cette commande dans Jeedom pour pouvoir importer.");
        }
        $existing = array();
        foreach (glob(self::dir($eq->getId()) . '/*.jsonl') as $f) {
            $existing[basename($f, '.jsonl')] = true;
        }
        $byDay = array();
        foreach ($series as $field => $points) {
            $vals = self::stepAverage($points, $from, $to, isset($hold[$field]) ? $hold[$field] : 12 * 3600);
            foreach ($vals as $t => $v) {
                $byDay[date('Y-m-d', $t)][$t][$field] = $v;
            }
        }
        // Efficacité de l'échangeur, recalculée point par point avec la même
        // règle qu'en direct (bypass fermé, préchauffage arrêté, 5 °C d'écart).
        foreach ($byDay as $day => &$buckets) {
            foreach ($buckets as $t => &$v) {
                if (isset($v['hs']) || isset($v['he'])) {
                    $why = '';
                    $e = ThermoFlair325::hxEfficiency($cfg, array(
                        'temp_in' => isset($v['tb']) ? $v['tb'] : null, 'temp_up' => isset($v['th']) ? $v['th'] : null, 'temp_out' => isset($v['to']) ? $v['to'] : null,
                        'hx_sup' => isset($v['hs']) ? $v['hs'] : null, 'hx_exh' => isset($v['he']) ? $v['he'] : null,
                        'bypass' => isset($v['by']) ? ($v['by'] >= 0.5) : null, 'preheat' => isset($v['ph']) ? ($v['ph'] >= 0.5) : null), $why);
                    if ($e !== null) {
                        $v['eg'] = $e['val'];
                    }
                }
            }
            unset($v);
        }
        unset($buckets);
        $imported = 0;
        $skipped = 0;
        $completed = 0;
        foreach ($byDay as $day => $buckets) {
            if (isset($existing[$day])) {
                // Jour déjà enregistré : on complète seulement les valeurs
                // manquantes (ex. météo ou préchauffage ajoutés après coup),
                // sans jamais écraser ce que le plugin a mesuré.
                if (self::completeDay($eq->getId(), $day, $buckets)) {
                    $completed++;
                } else {
                    $skipped++;
                }
                continue;
            }
            ksort($buckets);
            $lines = '';
            foreach ($buckets as $t => $vals) {
                if (!isset($vals['tb'])) {
                    continue;
                }
                $row = array('t' => $t);
                foreach (self::FIELDS as $k) {
                    $row[$k] = isset($vals[$k]) ? round($vals[$k], ($k == 'pw' || $k == 'co2' || $k == 'lx') ? 0 : 2) : null;
                }
                $row['src'] = 'h';
                $lines .= json_encode($row) . "\n";
            }
            if ($lines !== '') {
                file_put_contents(self::dir($eq->getId()) . '/' . $day . '.jsonl', $lines);
                $imported++;
            }
        }
        return array('imported_days' => $imported, 'completed_days' => $completed, 'skipped_days' => $skipped, 'points' => $report, 'message' => '');
    }

    // Complète les champs vides d'un jour déjà enregistré. Retourne true si
    // au moins une valeur a été ajoutée.
    private static function completeDay($eqId, $day, $buckets) {
        $file = self::dir($eqId) . '/' . $day . '.jsonl';
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return false;
        }
        $changed = false;
        $out = array();
        foreach ($lines as $line) {
            $r = json_decode($line, true);
            if (!is_array($r) || !isset($r['t'])) {
                $out[] = $line;
                continue;
            }
            $b = isset($buckets[$r['t']]) ? $buckets[$r['t']] : null;
            if ($b !== null) {
                foreach (self::FIELDS as $k) {
                    if ((!isset($r[$k]) || $r[$k] === null) && isset($b[$k]) && $b[$k] !== null) {
                        $r[$k] = round($b[$k], ($k == 'pw' || $k == 'co2' || $k == 'lx') ? 0 : 2);
                        $changed = true;
                    }
                }
            }
            $out[] = json_encode($r);
        }
        if ($changed) {
            file_put_contents($file, implode("\n", $out) . "\n", LOCK_EX);
        }
        return $changed;
    }

    public static function speedFromRaw($cfg, $raw) {
        for ($i = 1; $i <= 4; $i++) {
            if (trim((string) $cfg['vmc_p' . $i]) !== '' && ThermoFlair325::compare($raw, '==', $cfg['vmc_p' . $i])) {
                return $i;
            }
        }
        return is_numeric($raw) ? 0 : null;
    }

    /*
     * Moyenne pondérée dans le temps d'une fonction en escalier (valeur
     * conservée jusqu'au changement suivant) sur chaque créneau de 5 min.
     * $maxHold : au-delà, une ancienne valeur n'est plus prolongée.
     */
    public static function stepAverage($points, $from, $to, $maxHold) {
        $out = array();
        $n = count($points);
        $i = 0;
        $cur = null;
        $curT = null;
        for ($t = $from; $t < $to; $t += self::STEP) {
            $end = $t + self::STEP;
            while ($i < $n && $points[$i][0] <= $t) {
                $cur = $points[$i][1];
                $curT = $points[$i][0];
                $i++;
            }
            $sum = 0;
            $dur = 0;
            $pos = $t;
            $val = $cur;
            $valT = $curT;
            $j = $i;
            while ($j < $n && $points[$j][0] < $end) {
                if ($val !== null && ($pos - $valT) <= $maxHold) {
                    $sum += $val * ($points[$j][0] - $pos);
                    $dur += ($points[$j][0] - $pos);
                }
                $pos = $points[$j][0];
                $val = $points[$j][1];
                $valT = $points[$j][0];
                $j++;
            }
            if ($val !== null && ($pos - $valT) <= $maxHold) {
                $sum += $val * ($end - $pos);
                $dur += ($end - $pos);
            }
            if ($dur >= self::STEP / 2) {
                $out[$t] = $sum / $dur;
            }
        }
        return $out;
    }

    /* ================================================================ */
    /* Outils mathématiques                                              */
    /* ================================================================ */

    // Pente (unité/heure) d'une série [[t, v], ...] par moindres carrés.
    // Colonne de température qui régule une batterie (tb, th ou ta = moyenne).
    public static function zoneKey($cfg, $b, $hasUp) {
        $ref = isset($cfg[$b . '_sensor']) ? $cfg[$b . '_sensor'] : ($b == 'b2' ? 'up' : 'down');
        if (!$hasUp || $ref == 'down') {
            return 'tb';
        }
        return ($ref == 'up') ? 'th' : 'ta';
    }

    public static function slope($pts) {
        $n = count($pts);
        if ($n < 3) {
            return null;
        }
        $sx = $sy = $sxx = $sxy = 0;
        $t0 = $pts[0][0];
        foreach ($pts as $p) {
            $x = ($p[0] - $t0) / 3600;
            $sx += $x;
            $sy += $p[1];
            $sxx += $x * $x;
            $sxy += $x * $p[1];
        }
        $den = $n * $sxx - $sx * $sx;
        return ($den == 0) ? null : ($n * $sxy - $sx * $sy) / $den;
    }

    // Moindres carrés multiples (équations normales + pivot de Gauss).
    public static function regress($X, $y) {
        $n = count($X);
        if ($n == 0) {
            return null;
        }
        $p = count($X[0]);
        $A = array_fill(0, $p, array_fill(0, $p + 1, 0.0));
        for ($r = 0; $r < $n; $r++) {
            for ($i = 0; $i < $p; $i++) {
                for ($j = 0; $j < $p; $j++) {
                    $A[$i][$j] += $X[$r][$i] * $X[$r][$j];
                }
                $A[$i][$p] += $X[$r][$i] * $y[$r];
            }
        }
        for ($i = 0; $i < $p; $i++) {
            $A[$i][$i] += 1e-6; // stabilité si une variable ne varie jamais
        }
        for ($c = 0; $c < $p; $c++) {
            $piv = $c;
            for ($r = $c + 1; $r < $p; $r++) {
                if (abs($A[$r][$c]) > abs($A[$piv][$c])) {
                    $piv = $r;
                }
            }
            if (abs($A[$piv][$c]) < 1e-12) {
                return null;
            }
            $tmp = $A[$c];
            $A[$c] = $A[$piv];
            $A[$piv] = $tmp;
            for ($r = 0; $r < $p; $r++) {
                if ($r == $c) {
                    continue;
                }
                $f = $A[$r][$c] / $A[$c][$c];
                for ($k = $c; $k <= $p; $k++) {
                    $A[$r][$k] -= $f * $A[$c][$k];
                }
            }
        }
        $beta = array();
        for ($i = 0; $i < $p; $i++) {
            $beta[$i] = $A[$i][$p] / $A[$i][$i];
        }
        $mean = array_sum($y) / $n;
        $ssTot = 0;
        $ssRes = 0;
        for ($r = 0; $r < $n; $r++) {
            $pred = 0;
            for ($i = 0; $i < $p; $i++) {
                $pred += $beta[$i] * $X[$r][$i];
            }
            $ssRes += ($y[$r] - $pred) * ($y[$r] - $pred);
            $ssTot += ($y[$r] - $mean) * ($y[$r] - $mean);
        }
        return array('beta' => $beta, 'r2' => ($ssTot > 0) ? 1 - $ssRes / $ssTot : 0, 'n' => $n);
    }

    // Écart type (échantillon).
    private static function sd($arr) {
        $arr = array_values(array_filter($arr, function ($v) {
            return $v !== null;
        }));
        $n = count($arr);
        if ($n < 2) {
            return null;
        }
        $m = array_sum($arr) / $n;
        $q = 0;
        foreach ($arr as $v) {
            $q += ($v - $m) * ($v - $m);
        }
        return sqrt($q / ($n - 1));
    }

    private static function avg($arr) {
        $arr = array_values(array_filter($arr, function ($v) {
            return $v !== null;
        }));
        return empty($arr) ? null : array_sum($arr) / count($arr);
    }

    private static function r($v, $d = 2) {
        return ($v === null) ? null : round($v, $d);
    }

    /* ================================================================ */
    /* Analyse                                                           */
    /* ================================================================ */

    public static function analyze($eq, $days) {
        $cfg = ThermoFlair325::getCfg($eq);
        $now = ThermoFlair325::now();
        $days = max(1, min(800, intval($days)));
        $from = strtotime(date('Y-m-d', $now - ($days - 1) * 86400));
        $rows = self::load($eq->getId(), $from, $now);
        $hasOut = false;
        $hasUp = false;
        $hasSun = false;
        $hasBy = false;
        $luxMax = max(1, floatval($cfg['lux_max']));
        // Type de chaque journée : part de 9h-17h passée au maximum du capteur.
        $skyAcc = array();
        $wxAcc = array();
        foreach ($rows as $r) {
            $hh = intval(date('G', $r['t']));
            // Météo : part de ciel dégagé entre 9h et 17h (1 − nébulosité).
            if (isset($r['cc']) && $r['cc'] !== null && $hh >= 9 && $hh < 17) {
                $dk = date('Y-m-d', $r['t']);
                if (!isset($wxAcc[$dk])) {
                    $wxAcc[$dk] = array(0, 0);
                }
                $wxAcc[$dk][0]++;
                $wxAcc[$dk][1] += 1 - $r['cc'];
            }
            if ($r['lx'] !== null && $hh >= 9 && $hh < 17) {
                $dk = date('Y-m-d', $r['t']);
                if (!isset($skyAcc[$dk])) {
                    $skyAcc[$dk] = array(0, 0);
                }
                $skyAcc[$dk][0]++;
                if ($r['lx'] >= self::SKY_SAT * $luxMax) {
                    $skyAcc[$dk][1]++;
                }
            }
        }
        $sky = array();
        $skySrc = array('lux' => 0, 'meteo' => 0, 'both' => 0);
        foreach ($skyAcc as $dk => $a) {
            if ($a[0] >= 48) {
                $score = $a[1] / $a[0];
                // Capteur ET météo : selon le réglage « Classer les journées
                // avec » : météo d'abord (défaut : un capteur bon marché sature
                // vite et ne distingue pas un ciel voilé d'un plein soleil),
                // moyenne des deux, ou capteur seul.
                $mode = isset($cfg['sky_source']) ? $cfg['sky_source'] : 'weather';
                if ($mode != 'lux' && isset($wxAcc[$dk]) && $wxAcc[$dk][0] >= 48) {
                    $wsc = $wxAcc[$dk][1] / $wxAcc[$dk][0];
                    $score = ($mode == 'both') ? ($score + $wsc) / 2 : $wsc;
                    $skySrc[$mode == 'both' ? 'both' : 'meteo']++;
                } else {
                    $skySrc['lux']++;
                }
                $sky[$dk] = array('score' => $score, 'type' => ($score >= self::SKY_SUN) ? 'sun' : (($score >= self::SKY_MIXED) ? 'mixed' : 'grey'));
            }
        }
        // Sans capteur de luminosité ce jour-là : la météo seule.
        foreach ($wxAcc as $dk => $a) {
            if (!isset($sky[$dk]) && $a[0] >= 48 && (!isset($cfg['sky_source']) || $cfg['sky_source'] != 'lux' || empty($skyAcc[$dk]))) {
                $score = $a[1] / $a[0];
                $sky[$dk] = array('score' => $score, 'type' => ($score >= self::SKY_SUN) ? 'sun' : (($score >= self::SKY_MIXED) ? 'mixed' : 'grey'));
                $skySrc['meteo']++;
            }
        }
        foreach ($rows as &$rr) {
            $dk = date('Y-m-d', $rr['t']);
            $hh = intval(date('G', $rr['t']));
            // lxp : luminosité relative (courbes). sun : « soleil de midi »
            // = score de la journée entre 10h et 16h (apports par les vitres
            // au sud), 0 le reste du temps ; null sans mesure.
            $rr['ta'] = ($rr['tb'] !== null && $rr['th'] !== null) ? ($rr['tb'] + $rr['th']) / 2 : $rr['tb'];
            $rr['lxp'] = ($rr['lx'] !== null) ? min(1, max(0, $rr['lx'] / $luxMax)) : null;
            $rr['sky'] = isset($sky[$dk]) ? $sky[$dk]['type'] : null;
            $rr['sun'] = isset($sky[$dk]) ? (($hh >= 10 && $hh < 16) ? $sky[$dk]['score'] : 0) : null;
            if ($rr['sun'] !== null) {
                $hasSun = true;
            }
            if ($rr['by'] !== null && $rr['by'] > 0) {
                $hasBy = true;
            }
        }
        unset($rr);
        // Surchauffe solaire : bas ≥ consigne Normal + 2 °C, 10 °C de plus que
        // dehors, en journée, sans chauffage depuis au moins 1 h.
        $solarThr = floatval($cfg['heat_consigne']) + 2;
        $lastHeatT = -1;
        foreach ($rows as &$rr) {
            if (($rr['b1'] ?: 0) > 0.05 || ($rr['b2'] ?: 0) > 0.05) {
                $lastHeatT = $rr['t'];
            }
            $hh = intval(date('G', $rr['t']));
            $rr['sol'] = ($rr['tb'] !== null && $rr['to'] !== null && $rr['tb'] >= $solarThr && $rr['tb'] - $rr['to'] >= 10
                && $hh >= 9 && $hh < 19 && ($lastHeatT < 0 || $rr['t'] - $lastHeatT >= 3600)) ? 1 : 0;
        }
        unset($rr);
        foreach ($rows as $r) {
            if ($r['to'] !== null) {
                $hasOut = true;
            }
            if ($r['th'] !== null) {
                $hasUp = true;
            }
        }
        $names = array('b1' => $cfg['b1_name'], 'b2' => $cfg['b2_name']);
        $power = array('b1' => floatval($cfg['b1_power']), 'b2' => floatval($cfg['b2_power']));
        $res = array(
            'period' => array('days' => $days, 'from' => date('Y-m-d', $from), 'to' => date('Y-m-d', $now)),
            'quality' => array('samples' => count($rows), 'has_out' => $hasOut, 'has_up' => $hasUp, 'has_sun' => $hasSun, 'sky_source' => $skySrc, 'has_weather' => !empty($wxAcc), 'has_bypass' => $hasBy, 'lux_max' => $luxMax, 'storage' => self::info($eq->getId())),
            'names' => $names,
        );
        if (count($rows) < 12) {
            $res['empty'] = true;
            return $res;
        }

        // ---------- Courbes des 7 derniers jours ----------
        $since7 = $now - 14 * 86400; // courbes : 14 derniers jours
        $curves = array('tb' => array(), 'th' => array(), 'to' => array(), 'sp' => array(), 'sun' => array());
        $bands = array('b1' => array(), 'b2' => array());
        $open = array('b1' => null, 'b2' => null);
        foreach ($rows as $r) {
            if ($r['t'] < $since7) {
                continue;
            }
            $ms = $r['t'] * 1000;
            foreach (array('tb', 'th', 'to', 'sp') as $k) {
                if ($r[$k] !== null) {
                    $curves[$k][] = array($ms, $r[$k]);
                }
            }
            if ($r['lxp'] !== null) {
                $curves['sun'][] = array($ms, round($r['lxp'] * 100));
            }
            foreach (array('b1', 'b2') as $b) {
                $on = ($r[$b] !== null && $r[$b] >= 0.5);
                if ($on && $open[$b] === null) {
                    $open[$b] = $ms;
                } elseif (!$on && $open[$b] !== null) {
                    $bands[$b][] = array($open[$b], $ms);
                    $open[$b] = null;
                }
            }
        }
        foreach (array('b1', 'b2') as $b) {
            if ($open[$b] !== null) {
                $bands[$b][] = array($open[$b], $now * 1000);
            }
        }
        $res['week'] = array('curves' => $curves, 'bands' => $bands);

        // ---------- Bilan par jour ----------
        $byDay = array();
        foreach ($rows as $r) {
            $byDay[date('Y-m-d', $r['t'])][] = $r;
        }
        $daily = array();
        foreach ($byDay as $day => $list) {
            $d = array('date' => $day, 'n' => count($list));
            foreach (array('b1', 'b2') as $b) {
                $min = 0;
                foreach ($list as $r) {
                    $min += ($r[$b] !== null) ? $r[$b] * 5 : 0;
                }
                $d[$b . '_min'] = round($min);
                $d[$b . '_kwh'] = round($min / 60 * $power[$b] / 1000, 2);
            }
            foreach (array('tb', 'th', 'to') as $k) {
                $vals = array_values(array_filter(array_column($list, $k), function ($v) {
                    return $v !== null;
                }));
                $d[$k . '_avg'] = $vals ? round(array_sum($vals) / count($vals), 1) : null;
                $d[$k . '_min'] = $vals ? round(min($vals), 1) : null;
                $d[$k . '_max'] = $vals ? round(max($vals), 1) : null;
            }
            $d['kwh'] = round($d['b1_kwh'] + $d['b2_kwh'], 2);
            $d['dju'] = ($d['to_avg'] !== null && count($list) >= 200) ? round(max(0, self::BASE_DJU - $d['to_avg']), 1) : null;
            $d['kwh_dju'] = ($d['dju'] !== null && $d['dju'] >= 1) ? round($d['kwh'] / $d['dju'], 2) : null;
            $d['sp_avg'] = self::r(self::avg(array_column($list, 'sp')), 1);
            $d['ef_avg'] = self::r(self::avg(array_column($list, 'eg')), 0);
            $phv = array_filter(array_column($list, 'ph'), function ($v) {
                return $v !== null;
            });
            $d['ph_min'] = $phv ? round(array_sum($phv) * 5) : null;
            $rnv = array_filter(array_column($list, 'rn'), function ($v) {
                return $v !== null;
            });
            $d['rain_h'] = $rnv ? round(array_sum($rnv) * 5 / 60, 1) : null;
            $d['cc_avg'] = self::r(self::avg(array_column($list, 'cc')), 2);
            // Lumière : heures au maximum du capteur, type de journée.
            $full = 0;
            foreach ($list as $r) {
                if ($r['lx'] !== null && $r['lx'] >= self::SKY_SAT * $luxMax) {
                    $full += 5;
                }
            }
            $d['sun_hours'] = $hasSun ? round($full / 60, 1) : null;
            $d['sky'] = isset($sky[$day]) ? $sky[$day]['type'] : null;
            $d['solar_h'] = round(array_sum(array_column($list, 'sol')) * 5 / 60, 1);
            $d['sun_avg'] = isset($sky[$day]) ? round(100 * $sky[$day]['score']) : null;
            $d['lx_max'] = $hasSun ? self::r(max(array_map(function ($r) {
                return $r['lx'] !== null ? $r['lx'] : 0;
            }, $list)), 0) : null;
            $daily[] = $d;
        }
        $res['daily'] = $daily;
        // Période réellement couverte par des mesures (pour l'afficher sous les graphiques sans axe de temps).
        if ($daily) {
            $res['period']['data_from'] = $daily[0]['date'];
            $res['period']['data_to'] = $daily[count($daily) - 1]['date'];
            $res['period']['data_days'] = count($daily);
        }

        // ---------- Épisodes ----------
        $zoneOf = array('b1' => self::zoneKey($cfg, 'b1', $hasUp), 'b2' => self::zoneKey($cfg, 'b2', $hasUp));
        $heatEp = array('b1' => array(), 'b2' => array());
        $coolEp = array();
        $n = count($rows);
        $contiguous = function ($i) use ($rows) {
            return $i > 0 && ($rows[$i]['t'] - $rows[$i - 1]['t']) == self::STEP;
        };
        // Chauffe "seule" (l'autre batterie éteinte) d'au moins 20 min.
        foreach (array('b1', 'b2') as $b) {
            $o = ($b == 'b1') ? 'b2' : 'b1';
            $z = $zoneOf[$b];
            $cur = array();
            for ($i = 0; $i <= $n; $i++) {
                $ok = $i < $n && $rows[$i][$b] !== null && $rows[$i][$b] >= 0.5 && ($rows[$i][$o] === null || $rows[$i][$o] < 0.5) && $rows[$i][$z] !== null && (empty($cur) || $contiguous($i));
                if ($ok) {
                    $cur[] = $rows[$i];
                    continue;
                }
                if (count($cur) >= 4) {
                    $pts = array();
                    foreach ($cur as $r) {
                        $pts[] = array($r['t'], $r[$z]);
                    }
                    $slope = self::slope($pts);
                    $tout = self::avg(array_column($cur, 'to'));
                    $tin = self::avg(array_column($cur, $z));
                    $heatEp[$b][] = array(
                        'start' => $cur[0]['t'], 'min' => count($cur) * 5, 'rate' => self::r($slope),
                        'to' => self::r($tout, 1), 'tin' => self::r($tin, 1), 'gap' => ($tout !== null) ? self::r($tin - $tout, 1) : null,
                        'hour' => intval(date('G', $cur[0]['t'])), 'sp' => self::r(self::avg(array_column($cur, 'sp')), 1),
                    );
                }
                $cur = ($i < $n && $rows[$i][$b] !== null && $rows[$i][$b] >= 0.5 && ($rows[$i][$o] === null || $rows[$i][$o] < 0.5) && $rows[$i][$z] !== null) ? array($rows[$i]) : array();
            }
        }
        // Sans chauffage : au moins 2 h, en ignorant les 30 min qui suivent
        // un arrêt (chaleur résiduelle), découpé en tranches de 3 h.
        $cur = array();
        $lastHeat = -1;
        for ($i = 0; $i <= $n; $i++) {
            $off = $i < $n && ($rows[$i]['b1'] === null || $rows[$i]['b1'] < 0.05) && ($rows[$i]['b2'] === null || $rows[$i]['b2'] < 0.05) && $rows[$i]['tb'] !== null;
            if ($i < $n && !$off && $rows[$i]['tb'] !== null) {
                $lastHeat = $rows[$i]['t'];
            }
            $usable = $off && ($lastHeat < 0 || $rows[$i]['t'] - $lastHeat >= 1800) && (empty($cur) || $contiguous($i));
            if ($usable && count($cur) < 36) {
                $cur[] = $rows[$i];
                continue;
            }
            if (count($cur) >= 24) {
                $pts = array();
                foreach ($cur as $r) {
                    $pts[] = array($r['t'], $r['tb']);
                }
                $slope = self::slope($pts);
                $tout = self::avg(array_column($cur, 'to'));
                $tin = self::avg(array_column($cur, 'tb'));
                $mid = intval(date('G', $cur[intval(count($cur) / 2)]['t']));
                $gap = ($tout !== null) ? $tin - $tout : null;
                $coolEp[] = array(
                    'start' => $cur[0]['t'], 'min' => count($cur) * 5, 'rate' => self::r($slope),
                    'to' => self::r($tout, 1), 'tin' => self::r($tin, 1), 'gap' => self::r($gap, 1),
                    'night' => ($mid >= 21 || $mid < 7), 'hour' => $mid, 'sp' => self::r(self::avg(array_column($cur, 'sp')), 1),
                    'sun' => self::r(self::avg(array_column($cur, 'sun')) !== null ? self::avg(array_column($cur, 'sun')) * 100 : null, 0),
                    'sky' => $cur[intval(count($cur) / 2)]['sky'],
                    'k' => ($gap !== null && $gap > 2 && $slope !== null) ? self::r(-$slope / $gap, 4) : null,
                );
            }
            $cur = $usable ? array($rows[$i]) : array();
        }
        $res['heat_episodes'] = $heatEp;
        $res['cool_episodes'] = $coolEp;

        // ---------- Modèle thermique par zone ----------
        $models = array();
        foreach (array('bas' => 'tb', 'haut' => 'th') as $zn => $z) {
            if ($z == 'th' && !$hasUp) {
                continue;
            }
            $X = array();
            $Y = array();
            $spSum = 0;
            for ($i = 3; $i < $n - 3; $i++) {
                if ($rows[$i + 3]['t'] - $rows[$i - 3]['t'] != 6 * self::STEP) {
                    continue;
                }
                $ta = $rows[$i - 3][$z];
                $tb = $rows[$i + 3][$z];
                if ($ta === null || $tb === null || $rows[$i][$z] === null) {
                    continue;
                }
                $b1 = $b2 = 0;
                $bad = false;
                for ($j = $i - 3; $j < $i + 3; $j++) {
                    $b1 += ($rows[$j]['b1'] !== null) ? $rows[$j]['b1'] : 0;
                    $b2 += ($rows[$j]['b2'] !== null) ? $rows[$j]['b2'] : 0;
                }
                $b1 /= 6;
                $b2 /= 6;
                $h = intval(date('G', $rows[$i]['t']));
                $day = ($h >= 10 && $h < 17) ? 1 : 0;
                // Soleil : ensoleillement moyen sur la fenêtre. Sans capteur,
                // seul le terme « jour » (10h-17h) approche les apports.
                $sunW = null;
                if ($hasSun) {
                    $sv = array();
                    for ($j = $i - 3; $j < $i + 3; $j++) {
                        if ($rows[$j]['sun'] !== null) {
                            $sv[] = $rows[$j]['sun'];
                        }
                    }
                    if (count($sv) < 3) {
                        continue;
                    }
                    $sunW = array_sum($sv) / count($sv);
                }
                $x = array($b1, $b2);
                if ($hasOut) {
                    if ($rows[$i]['to'] === null) {
                        continue;
                    }
                    $gap = $rows[$i]['to'] - $rows[$i][$z];
                    $sp = ($rows[$i]['sp'] !== null) ? $rows[$i]['sp'] : 2;
                    // Pertes = (k + kv × vitesse VMC) × (Text − Tint)
                    $x[] = $gap;
                    $x[] = $sp * $gap;
                    $spSum += $sp;
                    if ($hasBy) {
                        // Pertes en plus quand le bypass est ouvert (pas de récupération).
                        $x[] = ($rows[$i]['by'] !== null ? $rows[$i]['by'] : 0) * $gap;
                    }
                }
                if ($hasSun) {
                    $x[] = $sunW;
                }
                $x[] = $day;
                $x[] = 1;
                $X[] = $x;
                $Y[] = ($tb - $ta) / 0.5;
            }
            if (count($X) < 144) {
                $models[$zn] = array('ok' => false, 'n' => count($X), 'reason' => 'pas encore assez de données (il faut au moins une demi-journée continue)');
                continue;
            }
            $fit = self::regress($X, $Y);
            if ($fit === null) {
                $models[$zn] = array('ok' => false, 'n' => count($X), 'reason' => 'calcul impossible (données trop uniformes : aucune batterie n\'a encore chauffé ?)');
                continue;
            }
            $bt = $fit['beta'];
            $m = array('ok' => true, 'n' => $fit['n'], 'r2' => round($fit['r2'], 2), 'gain_b1' => round($bt[0], 3), 'gain_b2' => round($bt[1], 3));
            $col = 2;
            if ($hasOut) {
                $spMean = $spSum / count($X);
                $kMean = $bt[2] + $bt[3] * $spMean;
                $m['k'] = round($kMean, 4);
                $m['k0'] = round($bt[2], 4);
                $m['kv'] = round($bt[3], 4);
                $m['sp_mean'] = round($spMean, 2);
                $m['k_speed'] = array();
                for ($sv = 1; $sv <= 3; $sv++) {
                    $m['k_speed'][$sv] = round($bt[2] + $bt[3] * $sv, 4);
                }
                $m['tau'] = ($kMean > 0.0005) ? round(1 / $kMean) : null;
                $col = 4;
                $m['kb'] = null;
                if ($hasBy) {
                    $m['kb'] = round($bt[$col++], 4);
                }
            } else {
                $m['k'] = null;
                $m['tau'] = null;
            }
            // Apport du soleil en °C/h quand il tape à fond (ensoleillement 1).
            $m['sun'] = $hasSun ? round($bt[$col++], 3) : null;
            $m['day'] = round($bt[$col++], 3);
            $m['base'] = round($bt[$col], 3);
            $m['heat_samples'] = array('b1' => 0, 'b2' => 0);
            foreach ($X as $x) {
                $m['heat_samples']['b1'] += ($x[0] > 0.5) ? 1 : 0;
                $m['heat_samples']['b2'] += ($x[1] > 0.5) ? 1 : 0;
            }
            $models[$zn] = $m;
        }
        $res['models'] = $models;

        // ---------- Prévisions ----------
        $pred = array();
        $tin = 20;
        $outs = $hasOut ? array(-10, -5, 0, 5, 10, 15) : array(null);
        foreach (array('b1' => 'bas', 'b2' => 'haut') as $b => $zn) {
            $zm = isset($models[$zn]) ? $models[$zn] : (isset($models['bas']) ? $models['bas'] : null);
            if ($b == 'b2' && !$hasUp) {
                $zm = isset($models['bas']) ? $models['bas'] : null;
            }
            if (!$zm || empty($zm['ok']) || $zm['heat_samples'][$b] < 12) {
                continue;
            }
            $gain = $zm['gain_' . $b];
            $spOn = intval($cfg[$b . '_min_speed']);
            foreach ($outs as $o) {
                $kOn = ($zm['k'] !== null) ? (isset($zm['k_speed'][$spOn]) ? $zm['k_speed'][$spOn] : $zm['k']) : null;
                $lossOn = ($o !== null && $kOn !== null) ? $kOn * ($o - $tin) : 0;
                $lossOff = ($o !== null && $zm['k'] !== null) ? $zm['k'] * ($o - $tin) : 0;
                $rateOn = $gain + $lossOn + $zm['base'];
                $rateOff = $lossOff + $zm['base'];
                // Au soleil, sans chauffer : pertes + plein soleil + jour.
                $rateSun = ($zm['sun'] !== null) ? $lossOff + $zm['base'] + $zm['day'] + $zm['sun'] : null;
                $pred[$b][] = array(
                    'to' => $o,
                    'rate_on' => round($rateOn, 2),
                    'min_per_deg' => ($rateOn > 0.02) ? round(60 / $rateOn) : null,
                    'night_drop' => round($rateOff * 8, 1),
                    'rate_sun' => ($rateSun !== null) ? round($rateSun, 2) : null,
                );
            }
        }
        $res['predictions'] = $pred;

        // ---------- Profil horaire moyen ----------
        $prof = array();
        for ($hh = 0; $hh < 24; $hh++) {
            $prof[$hh] = array('tb' => array(), 'th' => array(), 'to' => array(), 'b1' => array(), 'b2' => array(), 'lxp' => array());
        }
        foreach ($rows as $r) {
            $hh = intval(date('G', $r['t']));
            foreach (array('tb', 'th', 'to', 'b1', 'b2', 'lxp') as $k) {
                if ($r[$k] !== null) {
                    $prof[$hh][$k][] = $r[$k];
                }
            }
        }
        $profile = array();
        foreach ($prof as $hh => $p) {
            $profile[] = array('h' => $hh, 'tb' => self::r(self::avg($p['tb']), 2), 'th' => self::r(self::avg($p['th']), 2), 'to' => self::r(self::avg($p['to']), 2),
                'b1' => self::r(self::avg($p['b1']) * 100, 0), 'b2' => self::r(self::avg($p['b2']) * 100, 0),
                'sun' => $p['lxp'] ? self::r(self::avg($p['lxp']) * 100, 0) : null);
        }
        $res['profile'] = $profile;

        // ---------- Influence de la vitesse de VMC sur les pertes ----------
        // Déperdition selon la vitesse (issue du modèle, qui sépare l'effet
        // de la VMC de celui du soleil et du chauffage).
        // Mesure directe : refroidissement des tranches de NUIT sans chauffage
        // (pas de soleil), à vitesse constante, ramené à l'écart dedans /
        // dehors. Médiane par vitesse (robuste aux nuits atypiques). Le modèle
        // global, qui doit séparer chauffage, soleil et VMC à la fois, donnait
        // des pertes négatives (impossibles) avec peu de jours.
        $speedFx = array();
        $byS = array();
        foreach ($coolEp as $e) {
            if (!$e['night'] || $e['k'] === null || $e['sp'] === null || abs($e['sp'] - round($e['sp'])) > 0.25) {
                continue;
            }
            $byS[intval(round($e['sp']))][] = $e['k'];
        }
        ksort($byS);
        foreach ($byS as $sv => $ks) {
            if (count($ks) < 2) {
                continue;
            }
            sort($ks);
            $m = count($ks);
            $med = ($m % 2) ? $ks[intval($m / 2)] : ($ks[$m / 2 - 1] + $ks[$m / 2]) / 2;
            if ($med <= 0) {
                continue;
            }
            $speedFx[] = array('speed' => $sv, 'k' => round($med, 5), 'loss10' => round($med * 10, 3), 'n' => $m);
        }
        $res['speed_effect'] = $speedFx;

        // ---------- Indicateurs « maison passive » ----------
        $res['signature'] = self::signature($daily);
        // Le réglage du capteur n'importe que s'il sert à classer les journées.
        $res['lux_check'] = ($skySrc['lux'] + $skySrc['both'] > 0) ? self::luxCheck($rows, $luxMax) : null;
        $res['noheat'] = self::noHeatDays($byDay, $sky, $hasUp);
        $res['comfort'] = self::comfort($rows, $hasUp);
        $res['air'] = self::airQuality($rows);

        // ---------- Effet du soleil ----------
        // Variation de température du bas (et du haut) sans chauffage, en
        // journée, selon l'ensoleillement : mesurée (moyenne par tranche) et
        // apport du soleil selon le modèle.
        $res['sun_effect'] = $hasSun ? self::dayTypes($rows, $daily, $models, $hasUp) : null;

        // ---------- Nuits sans chauffage (22h -> 6h) ----------
        $nights = array();
        $byT = array();
        foreach ($rows as $r) {
            $byT[$r['t']] = $r;
        }
        foreach ($byDay as $day => $list) {
            $startT = strtotime($day . ' 22:00');
            $seg = array();
            for ($t = $startT; $t <= $startT + 8 * 3600; $t += self::STEP) {
                if (isset($byT[$t])) {
                    $seg[] = $byT[$t];
                }
            }
            if (count($seg) < 90) {
                continue;
            }
            $heat = 0;
            foreach ($seg as $r) {
                $heat += ($r['b1'] ?: 0) + ($r['b2'] ?: 0);
            }
            if ($heat > 0.5 || $seg[0]['tb'] === null || end($seg)['tb'] === null) {
                continue;
            }
            $tavg = self::avg(array_column($seg, 'to'));
            $tin = self::avg(array_column($seg, 'tb'));
            $nights[] = array('date' => $day, 'drop' => round($seg[0]['tb'] - end($seg)['tb'], 2), 'to' => self::r($tavg, 1),
                'gap' => ($tavg !== null) ? self::r($tin - $tavg, 1) : null, 'md' => self::r(self::avg(array_column($seg, 'md')), 2), 'sp' => self::r(self::avg(array_column($seg, 'sp')), 1));
        }
        $res['nights'] = $nights;

        // ---------- Comparaison des modes de la VMC ----------
        $res['modes'] = self::compareModes($rows, $daily, $nights, $byDay, $cfg);

        // ---------- Chiffres clés ----------
        $tot = array('b1_min' => 0, 'b2_min' => 0, 'kwh' => 0, 'dju' => 0, 'days_dju' => 0);
        foreach ($daily as $d) {
            $tot['b1_min'] += $d['b1_min'];
            $tot['b2_min'] += $d['b2_min'];
            $tot['kwh'] += $d['kwh'];
            if ($d['dju'] !== null) {
                $tot['dju'] += $d['dju'];
                $tot['days_dju']++;
            }
        }
        $kpi = array(
            'days' => count($daily),
            'kwh_total' => round($tot['kwh'], 1),
            'kwh_per_day' => count($daily) ? round($tot['kwh'] / count($daily), 2) : null,
            'b1_hours' => round($tot['b1_min'] / 60, 1),
            'b2_hours' => round($tot['b2_min'] / 60, 1),
            'share_b1' => ($tot['b1_min'] * $power['b1'] + $tot['b2_min'] * $power['b2']) > 0 ? round(100 * $tot['b1_min'] * $power['b1'] / ($tot['b1_min'] * $power['b1'] + $tot['b2_min'] * $power['b2'])) : null,
            'dju' => $tot['days_dju'] ? round($tot['dju'], 1) : null,
            'kwh_per_dju' => ($tot['dju'] >= 1) ? round($tot['kwh'] / $tot['dju'], 2) : null,
            'tau' => isset($models['bas']['tau']) ? $models['bas']['tau'] : null,
            'k' => isset($models['bas']['k']) ? $models['bas']['k'] : null,
            'gain_b1' => (!empty($models['bas']['ok']) && $models['bas']['heat_samples']['b1'] >= 12) ? $models['bas']['gain_b1'] : null,
            'gain_b2' => $hasUp ? ((!empty($models['haut']['ok']) && $models['haut']['heat_samples']['b2'] >= 12) ? $models['haut']['gain_b2'] : null) : ((!empty($models['bas']['ok']) && $models['bas']['heat_samples']['b2'] >= 12) ? $models['bas']['gain_b2'] : null),
            'night_drop' => $nights ? round(array_sum(array_column($nights, 'drop')) / count($nights), 2) : null,
            'nights' => count($nights),
            'cool_night' => null,
            'cool_day' => null,
            'sun_hours' => null,
            'sky_days' => null,
            'solar_h' => null,
            'solar_days' => null,
            'solar_max' => null,
            'solar_to' => null,
            'sun_coef' => isset($models['bas']['sun']) && !empty($models['bas']['ok']) ? $models['bas']['sun'] : null,
            'sun_coef_up' => isset($models['haut']['sun']) && !empty($models['haut']['ok']) ? $models['haut']['sun'] : null,
            'sun_min_b1' => null,
            'sun_kwh' => null,
            'cool_sunny' => null,
            'cool_cloudy' => null,
            'bypass_open_h' => null,
            'bypass_heat_min' => null,
            'kb' => isset($models['bas']['kb']) && !empty($models['bas']['ok']) ? $models['bas']['kb'] : null,
            'ef_avg' => null,
            'ef_first' => null,
            'ef_last' => null,
            'ef_days' => 0,
            'ph_h' => null,
            'ph_days' => 0,
            'ph_to' => null,
            'rain_days' => null,
            'dry_days' => null,
            'rain_kwh_dju' => null,
            'dry_kwh_dju' => null,
        );
        // Météo : jours de pluie (au moins 1 h) contre jours secs.
        $wxDays = array_filter($daily, function ($d) {
            return $d['rain_h'] !== null;
        });
        if ($wxDays) {
            $wet = array_filter($wxDays, function ($d) {
                return $d['rain_h'] >= 1;
            });
            $dry = array_filter($wxDays, function ($d) {
                return $d['rain_h'] < 1;
            });
            $kpi['rain_days'] = count($wet);
            $kpi['dry_days'] = count($dry);
            $avgK = function ($list) {
                $v = array_filter(array_column($list, 'kwh_dju'), function ($x) {
                    return $x !== null;
                });
                return count($v) >= 2 ? round(array_sum($v) / count($v), 2) : null;
            };
            $kpi['rain_kwh_dju'] = $avgK($wet);
            $kpi['dry_kwh_dju'] = $avgK($dry);
        }
        // Échangeur : moyenne de la période, début contre fin (encrassement).
        $efDays = array_values(array_filter($daily, function ($d) {
            return $d['ef_avg'] !== null;
        }));
        if ($efDays) {
            $kpi['ef_days'] = count($efDays);
            $kpi['ef_avg'] = round(array_sum(array_column($efDays, 'ef_avg')) / count($efDays));
            if (count($efDays) >= 14) {
                $q = max(3, intval(floor(count($efDays) / 4)));
                $kpi['ef_first'] = round(array_sum(array_column(array_slice($efDays, 0, $q), 'ef_avg')) / $q);
                $kpi['ef_last'] = round(array_sum(array_column(array_slice($efDays, -$q), 'ef_avg')) / $q);
            }
        }
        // Préchauffage : heures, jours, température extérieure moyenne en marche.
        $phRows = array_filter($rows, function ($r) {
            return isset($r['ph']) && $r['ph'] !== null && $r['ph'] >= 0.5;
        });
        $phKnown = array_filter($rows, function ($r) {
            return isset($r['ph']) && $r['ph'] !== null;
        });
        if ($phKnown) {
            $kpi['ph_h'] = round(count($phRows) * 5 / 60, 1);
            $kpi['ph_days'] = count(array_filter($daily, function ($d) {
                return !empty($d['ph_min']);
            }));
            $kpi['ph_to'] = $phRows ? self::r(self::avg(array_column($phRows, 'to')), 1) : null;
        }
        if ($hasBy) {
            $bo = 0;
            $bh = 0;
            foreach ($rows as $r) {
                if ($r['by'] !== null) {
                    $bo += $r['by'] * 5;
                    $bh += $r['by'] * 5 * min(1, ($r['b1'] ?: 0) + ($r['b2'] ?: 0));
                }
            }
            $kpi['bypass_open_h'] = round($bo / 60, 1);
            $kpi['bypass_heat_min'] = round($bh);
        }
        if ($hasSun) {
            $kpi['sun_hours'] = round(array_sum(array_map(function ($d) {
                return $d['sun_hours'] ?: 0;
            }, $daily)), 1);
            $kpi['sky_days'] = array('sun' => 0, 'mixed' => 0, 'grey' => 0);
            foreach ($daily as $d) {
                if ($d['sky'] !== null) {
                    $kpi['sky_days'][$d['sky']]++;
                }
            }
            // Équivalence : 1 h de plein soleil = X min de batterie du bas,
            // et énergie de chauffage « offerte » par le soleil sur la période.
            if ($kpi['sun_coef'] !== null && $kpi['gain_b1'] !== null && $kpi['gain_b1'] > 0.02 && $kpi['sun_coef'] > 0) {
                $kpi['sun_min_b1'] = round(60 * $kpi['sun_coef'] / $kpi['gain_b1']);
                $sunH = 0;
                foreach ($rows as $r) {
                    $sunH += ($r['sun'] !== null) ? $r['sun'] * self::STEP / 3600 : 0;
                }
                $kpi['sun_kwh'] = round($sunH * $kpi['sun_coef'] / $kpi['gain_b1'] * $power['b1'] / 1000, 1);
            }
        }
        $solRows = array_filter($rows, function ($r) {
            return $r['sol'];
        });
        if ($solRows) {
            $kpi['solar_h'] = round(count($solRows) * 5 / 60, 1);
            $kpi['solar_days'] = count(array_filter($daily, function ($d) {
                return $d['solar_h'] >= 0.5;
            }));
            $mx = null;
            foreach ($solRows as $r) {
                if ($mx === null || $r['tb'] > $mx['tb']) {
                    $mx = $r;
                }
            }
            $kpi['solar_max'] = round($mx['tb'], 1);
            $kpi['solar_to'] = round($mx['to'], 1);
        }
        $cn = array_filter($coolEp, function ($e) {
            return $e['night'];
        });
        $cd = array_filter($coolEp, function ($e) {
            return !$e['night'];
        });
        if ($cn) {
            $kpi['cool_night'] = round(array_sum(array_column($cn, 'rate')) / count($cn), 3);
        }
        if ($cd) {
            $kpi['cool_day'] = round(array_sum(array_column($cd, 'rate')) / count($cd), 3);
            if ($hasSun) {
                $sunny = array_filter($cd, function ($e) {
                    return $e['sky'] == 'sun';
                });
                $cloudy = array_filter($cd, function ($e) {
                    return $e['sky'] == 'grey';
                });
                $kpi['cool_sunny'] = $sunny ? round(array_sum(array_column($sunny, 'rate')) / count($sunny), 3) : null;
                $kpi['cool_cloudy'] = $cloudy ? round(array_sum(array_column($cloudy, 'rate')) / count($cloudy), 3) : null;
            }
        }
        $res['kpi'] = $kpi;
        $res['insights'] = self::insights($res, $names);
        try {
            $res['advice'] = ThermoFlair325Advisor::advise($eq->getId(), $rows, $cfg, $res);
        } catch (\Throwable $e) {
            $res['advice'] = array('error' => $e->getMessage(), 'advice' => array(), 'periods' => array(), 'stability' => array());
        }
        return $res;
    }

    /*
     * Effet du type de journée (ensoleillée / mitigée / maussade) :
     *  - variation de température du bas (et du haut) entre 10h et 16h quand
     *    on ne chauffe pas : ce que le soleil apporte par les vitres ;
     *  - consommation de chauffage par degré-jour ;
     *  - dépassement de la consigne l'après-midi (12h-17h).
     */
    /*
     * Journées SANS chauffage : de combien la maison monte dans la journée,
     * selon le temps. Gain = température la plus haute entre 10h et 18h
     * moins la plus basse du matin (6h-10h). Une journée compte si aucune
     * batterie n'a chauffé de 4h à 18h et si les mesures couvrent la journée.
     * C'est la mesure la plus directe de l'apport du soleil.
     */
    public static function noHeatDays($byDay, $sky, $hasUp) {
        $days = array();
        foreach ($byDay as $day => $list) {
            $heat = false;
            $n = 0;
            $mor = array('tb' => array(), 'th' => array());
            $aft = array('tb' => array(), 'th' => array());
            $to = array();
            foreach ($list as $r) {
                $h = intval(date('G', $r['t']));
                if ($h >= 4 && $h < 18 && ((($r['b1'] ?: 0) > 0.05) || (($r['b2'] ?: 0) > 0.05))) {
                    $heat = true;
                    break;
                }
                if ($h >= 6 && $h < 18) {
                    $n++;
                }
                foreach (array('tb', 'th') as $z) {
                    if ($r[$z] === null) {
                        continue;
                    }
                    if ($h >= 6 && $h < 10) {
                        $mor[$z][] = $r[$z];
                    } elseif ($h >= 10 && $h < 18) {
                        $aft[$z][] = $r[$z];
                    }
                }
                if ($h >= 8 && $h < 18 && $r['to'] !== null) {
                    $to[] = $r['to'];
                }
            }
            if ($heat || $n < 110 || !$mor['tb'] || !$aft['tb']) {
                continue;
            }
            $d = array('date' => $day, 'sky' => isset($sky[$day]) ? $sky[$day]['type'] : null, 'score' => isset($sky[$day]) ? round(100 * $sky[$day]['score']) : null,
                'tmin' => round(min($mor['tb']), 1), 'tmax' => round(max($aft['tb']), 1), 'gain' => round(max($aft['tb']) - min($mor['tb']), 1),
                'gain_up' => ($hasUp && $mor['th'] && $aft['th']) ? round(max($aft['th']) - min($mor['th']), 1) : null,
                'to' => $to ? round(array_sum($to) / count($to), 1) : null);
            $days[] = $d;
        }
        $by = array();
        foreach (array('sun', 'mixed', 'grey') as $t) {
            $g = array_values(array_filter($days, function ($d) use ($t) {
                return $d['sky'] === $t;
            }));
            $by[$t] = array('days' => count($g), 'gain' => $g ? round(array_sum(array_column($g, 'gain')) / count($g), 1) : null,
                'to' => $g && array_filter(array_column($g, 'to'), 'is_numeric') ? round(array_sum(array_column($g, 'to')) / max(1, count(array_filter(array_column($g, 'to'), 'is_numeric'))), 1) : null);
        }
        return array('days' => $days, 'by_sky' => $by);
    }

    /*
     * Signature énergétique : énergie de chauffage par jour selon la
     * température extérieure moyenne. Droite de régression sur les jours
     * chauffés : la pente donne le coefficient de déperdition global (W/K,
     * pertes par les parois et l'air, hors apports), l'intersection avec 0
     * la température extérieure au-dessus de laquelle on ne chauffe plus.
     */
    /*
     * Le « maximum du capteur » est-il bien réglé ? Une journée compte comme
     * ensoleillée quand la luminosité passe du temps à 95 % de ce maximum.
     * S'il est trop haut (capteur qui plafonne plus bas), même les journées
     * de grand soleil sont classées maussades et les statistiques du soleil
     * s'inversent. On compare au plus haut atteint chaque jour.
     */
    public static function luxCheck($rows, $luxMax) {
        $dmax = array();
        foreach ($rows as $r) {
            if ($r['lx'] !== null) {
                $d = date('Y-m-d', $r['t']);
                $dmax[$d] = max(isset($dmax[$d]) ? $dmax[$d] : 0, $r['lx']);
            }
        }
        if (count($dmax) < 5) {
            return null;
        }
        $v = array_values($dmax);
        sort($v);
        $p90 = $v[intval(floor(0.9 * (count($v) - 1)))];
        $reach = count(array_filter($v, function ($x) use ($luxMax) {
            return $x >= 0.95 * $luxMax;
        }));
        $res = array('days' => count($v), 'reach' => $reach, 'p90' => round($p90), 'top' => round(max($v)), 'max' => $luxMax, 'suggest' => null);
        // Trop haut : moins d'un jour sur 10 l'atteint et les meilleurs jours plafonnent nettement en dessous.
        if ($reach < max(1, 0.1 * count($v)) && $p90 < 0.9 * $luxMax && $p90 > 0) {
            $res['suggest'] = max(10, intval(floor($p90 / 50) * 50));
        }
        return $res;
    }

    public static function signature($daily) {
        $pts = array();
        foreach ($daily as $d) {
            if ($d['to_avg'] !== null && $d['n'] >= 200) {
                $pts[] = array('date' => $d['date'], 'to' => $d['to_avg'], 'kwh' => $d['kwh'], 'sky' => $d['sky']);
            }
        }
        $res = array('points' => $pts, 'slope' => null, 'intercept' => null, 'balance' => null, 'ua' => null, 'r2' => null, 'n' => 0);
        $heated = array_values(array_filter($pts, function ($p) {
            return $p['kwh'] >= 0.2;
        }));
        $n = count($heated);
        $res['n'] = $n;
        if ($n < 5) {
            return $res;
        }
        $mx = array_sum(array_column($heated, 'to')) / $n;
        $my = array_sum(array_column($heated, 'kwh')) / $n;
        $sxx = 0;
        $sxy = 0;
        $syy = 0;
        foreach ($heated as $p) {
            $sxx += ($p['to'] - $mx) * ($p['to'] - $mx);
            $sxy += ($p['to'] - $mx) * ($p['kwh'] - $my);
            $syy += ($p['kwh'] - $my) * ($p['kwh'] - $my);
        }
        if ($sxx < 1 || $sxy >= 0) {
            return $res; // pas assez d'écart de température, ou pente incohérente
        }
        $a = $sxy / $sxx;
        $b = $my - $a * $mx;
        $res['slope'] = round($a, 3);
        $res['intercept'] = round($b, 2);
        $res['balance'] = round(-$b / $a, 1);
        $res['ua'] = round(-$a * 1000 / 24);
        $res['r2'] = ($syy > 0) ? round($sxy * $sxy / ($sxx * $syy), 2) : null;
        return $res;
    }

    /*
     * Confort : répartition du temps par tranche de 0,5 °C (bas et haut),
     * part du temps sous 20 °C, entre 20 et 25 °C et au-dessus de 25 °C
     * (critère maison passive : au-dessus de 25 °C moins de 10 % du temps).
     */
    public static function comfort($rows, $hasUp) {
        $out = array();
        foreach (array('tb' => true, 'th' => $hasUp) as $z => $on) {
            if (!$on) {
                continue;
            }
            $vals = array_values(array_filter(array_column($rows, $z), function ($v) {
                return $v !== null;
            }));
            if (count($vals) < 24) {
                continue;
            }
            $bins = array();
            foreach ($vals as $v) {
                $k = number_format(min(29, max(16, floor($v * 2) / 2)), 1, '.', '');
                $bins[$k] = (isset($bins[$k]) ? $bins[$k] : 0) + 1;
            }
            ksort($bins, SORT_NUMERIC);
            $n = count($vals);
            $hist = array();
            foreach ($bins as $k => $c) {
                $hist[] = array(floatval($k), round(100 * $c / $n, 1));
            }
            $lt = count(array_filter($vals, function ($v) {
                return $v < 20;
            }));
            $gt = count(array_filter($vals, function ($v) {
                return $v > 25;
            }));
            $out[$z] = array('hist' => $hist, 'lt20' => round(100 * $lt / $n, 1), 'gt25' => round(100 * $gt / $n, 1), 'ok' => round(100 * ($n - $lt - $gt) / $n, 1),
                'gt25_h' => round($gt * 5 / 60, 1), 'min' => round(min($vals), 1), 'max' => round(max($vals), 1), 'avg' => round(array_sum($vals) / $n, 1));
        }
        return $out;
    }

    /*
     * Qualité de l'air et ventilation : part du temps par tranche de CO2
     * et par vitesse de VMC.
     */
    public static function airQuality($rows) {
        $co2 = array_values(array_filter(array_column($rows, 'co2'), function ($v) {
            return $v !== null;
        }));
        $sp = array_values(array_filter(array_column($rows, 'sp'), function ($v) {
            return $v !== null;
        }));
        $res = array('co2' => null, 'speed' => null);
        if (count($co2) >= 24) {
            $bands = array(array('moins de 800 ppm', 0, 800), array('800 à 1000', 800, 1000), array('1000 à 1400', 1000, 1400), array('plus de 1400', 1400, 99999));
            $res['co2'] = array();
            foreach ($bands as $b) {
                $c = count(array_filter($co2, function ($v) use ($b) {
                    return $v >= $b[1] && $v < $b[2];
                }));
                $res['co2'][] = array('label' => $b[0], 'pct' => round(100 * $c / count($co2), 1));
            }
            $res['co2_avg'] = round(array_sum($co2) / count($co2));
        }
        if (count($sp) >= 24) {
            $res['speed'] = array();
            for ($i = 1; $i <= 4; $i++) {
                $c = count(array_filter($sp, function ($v) use ($i) {
                    return round($v) == $i;
                }));
                $res['speed'][] = array('speed' => $i, 'pct' => round(100 * $c / count($sp), 1));
            }
        }
        return $res;
    }

    public static function dayTypes($rows, $daily, $models, $hasUp) {
        $acc = array();
        foreach (self::SKY_LABELS as $t => $lab) {
            $acc[$t] = array('tb' => array(), 'th' => array(), 'over' => array(), 'kwh' => 0, 'dju' => 0, 'days' => 0, 'to' => array(), 'sp' => array());
        }
        foreach ($daily as $d) {
            if ($d['sky'] === null) {
                continue;
            }
            $acc[$d['sky']]['days']++;
            if ($d['dju'] !== null) {
                $acc[$d['sky']]['kwh'] += $d['kwh'];
                $acc[$d['sky']]['dju'] += $d['dju'];
            }
            if ($d['to_avg'] !== null) {
                $acc[$d['sky']]['to'][] = $d['to_avg'];
            }
        }
        $n = count($rows);
        for ($i = 3; $i < $n - 3; $i++) {
            $r = $rows[$i];
            if ($r['sky'] === null || $rows[$i + 3]['t'] - $rows[$i - 3]['t'] != 6 * self::STEP) {
                continue;
            }
            $hh = intval(date('G', $r['t']));
            if ($hh >= 12 && $hh < 17 && $r['c1'] !== null && $r['tb'] !== null) {
                $acc[$r['sky']]['over'][] = $r['tb'] - $r['c1'];
            }
            if ($hh < 10 || $hh >= 16) {
                continue;
            }
            // Pas de chauffage depuis au moins 2 h : une batterie (et ses
            // gaines) rend encore de la chaleur longtemps après l'arrêt, ce
            // qui gonflait la hausse des jours gris (où l'on chauffe le matin).
            $heat = false;
            for ($j = max(0, $i - 24); $j < $i + 3; $j++) {
                if (($rows[$j]['b1'] ?: 0) > 0.05 || ($rows[$j]['b2'] ?: 0) > 0.05) {
                    $heat = true;
                    break;
                }
            }
            if ($heat) {
                continue;
            }
            foreach (array('tb', 'th') as $z) {
                if ($rows[$i - 3][$z] !== null && $rows[$i + 3][$z] !== null) {
                    $acc[$r['sky']][$z][] = ($rows[$i + 3][$z] - $rows[$i - 3][$z]) / 0.5;
                }
            }
            if ($rows[$i]['sp'] !== null) {
                $acc[$r['sky']]['sp'][] = $rows[$i]['sp'];
            }
        }
        // Mesure PAR JOUR (prioritaire) : pente de la température entre 10h
        // et 16h, seulement les jours sans aucun chauffage de 8h à 16h. Chaque
        // journée pèse autant (les tranches isolées favorisaient les jours où
        // l'on n'avait de mesure qu'au moment où la maison se réchauffait).
        $perDay = array();
        $dayRows = array();
        foreach ($rows as $r) {
            $h = intval(date('G', $r['t']));
            if ($h >= 8 && $h < 16 && $r['sky'] !== null) {
                $dayRows[date('Y-m-d', $r['t'])][] = $r;
            }
        }
        foreach ($dayRows as $day => $list) {
            $heat = false;
            $pts = array('tb' => array(), 'th' => array());
            $spv = array();
            // La mesure s'arrête quand la VMC accélère ou que le bypass s'ouvre
            // pour évacuer la chaleur : sinon les jours de beau soleil (où la
            // maison atteint vite la température maximum) paraissent gagner
            // moins que les jours mitigés.
            $sp0 = null;
            $cut = false;
            foreach ($list as $r) {
                if ((($r['b1'] ?: 0) > 0.05) || (($r['b2'] ?: 0) > 0.05)) {
                    $heat = true;
                    break;
                }
                if (intval(date('G', $r['t'])) >= 10) {
                    if ($sp0 === null && $r['sp'] !== null) {
                        $sp0 = $r['sp'];
                    }
                    if (($sp0 !== null && $r['sp'] !== null && $r['sp'] > $sp0 + 0.5) || ($r['by'] !== null && $r['by'] >= 0.5)) {
                        $cut = true;
                    }
                    if ($cut) {
                        continue;
                    }
                    foreach (array('tb', 'th') as $z) {
                        if ($r[$z] !== null) {
                            $pts[$z][] = array($r['t'], $r[$z]);
                        }
                    }
                    if ($r['sp'] !== null) {
                        $spv[] = $r['sp'];
                    }
                }
            }
            // Au moins 2 h de mesure exploitable (24 points de 5 min).
            if ($heat || count($pts['tb']) < 24) {
                continue;
            }
            $sky0 = $list[0]['sky'];
            $perDay[$sky0][] = array('tb' => self::slope($pts['tb']), 'th' => count($pts['th']) >= 24 ? self::slope($pts['th']) : null, 'sp' => $spv ? self::avg($spv) : null,
                'cut' => $cut, 'h' => count($pts['tb']) / 12);
        }
        $out = array();
        foreach (self::SKY_LABELS as $t => $lab) {
            $a = $acc[$t];
            $pd = isset($perDay[$t]) ? $perDay[$t] : array();
            if ($pd) {
                $thv = array_values(array_filter(array_column($pd, 'th'), function ($v) {
                    return $v !== null;
                }));
                $spd = array_values(array_filter(array_column($pd, 'sp'), function ($v) {
                    return $v !== null;
                }));
                $out[] = array(
                    'type' => $t,
                    'label' => $lab,
                    'days' => count($pd),
                    'days_all' => $a['days'],
                    'method' => 'days',
                    'n' => count($pd),
                    'tb' => self::r(self::avg(array_column($pd, 'tb')), 3),
                    // Dispersion d'un jour à l'autre (°C/h) : un écart entre
                    // types plus petit que ça n'est pas encore significatif.
                    'tb_sd' => count($pd) >= 2 ? self::r(self::sd(array_column($pd, 'tb')), 3) : null,
                    'cut' => count(array_filter(array_column($pd, 'cut'))),
                    'th' => ($hasUp && $thv) ? self::r(self::avg($thv), 3) : null,
                    'hours' => round(array_sum(array_column($pd, 'h')), 1),
                    'sp' => $spd ? self::r(self::avg($spd), 1) : null,
                    'kwh_dju' => ($a['dju'] >= 3) ? round($a['kwh'] / $a['dju'], 2) : null,
                    'over' => count($a['over']) >= 12 ? self::r(self::avg($a['over']), 2) : null,
                    'to' => $a['to'] ? self::r(self::avg($a['to']), 1) : null,
                );
                continue;
            }
            $out[] = array(
                'type' => $t,
                'label' => $lab,
                'days' => $a['days'],
                'days_all' => $a['days'],
                'method' => 'windows',
                'n' => count($a['tb']),
                // Au moins 2 h de mesures sans chauffage pour donner un chiffre.
                'tb' => count($a['tb']) >= 24 ? self::r(self::avg($a['tb']), 3) : null,
                'th' => ($hasUp && count($a['th']) >= 24) ? self::r(self::avg($a['th']), 3) : null,
                'hours' => round(count($a['tb']) * 5 / 60, 1),
                'sp' => $a['sp'] ? self::r(self::avg($a['sp']), 1) : null,
                'kwh_dju' => ($a['dju'] >= 3) ? round($a['kwh'] / $a['dju'], 2) : null,
                'over' => count($a['over']) >= 12 ? self::r(self::avg($a['over']), 2) : null,
                'to' => $a['to'] ? self::r(self::avg($a['to']), 1) : null,
            );
        }
        return $out;
    }

    /*
     * Compare les deux modes de décision de la VMC (températures / bypass) :
     * chaque jour est attribué au mode le plus utilisé ce jour-là.
     */
    public static function compareModes($rows, $daily, $nights, $byDay, $cfg) {
        $labels = array(0 => 'températures', 1 => 'bypass');
        $acc = array();
        $dayMode = array();
        foreach ($byDay as $day => $list) {
            $m = self::avg(array_column($list, 'md'));
            if ($m !== null) {
                $dayMode[$day] = ($m >= 0.5) ? 1 : 0;
            }
        }
        if (empty($dayMode)) {
            return array();
        }
        $hot = floatval($cfg['s_t_hot']);
        foreach ($rows as $r) {
            if ($r['md'] === null) {
                continue;
            }
            $m = ($r['md'] >= 0.5) ? 1 : 0;
            if (!isset($acc[$m])) {
                $acc[$m] = array('n' => 0, 'sp' => array(), 'by' => array(), 'tb' => array(), 'over' => 0, 'summer' => 0);
            }
            $a = &$acc[$m];
            $a['n']++;
            if ($r['sp'] !== null) {
                $a['sp'][] = $r['sp'];
            }
            if ($r['by'] !== null) {
                $a['by'][] = $r['by'];
            }
            if ($r['tb'] !== null) {
                $a['tb'][] = $r['tb'];
                if (ThermoFlair325::seasonOf($cfg, $r['t']) == 'summer') {
                    $a['summer']++;
                    if ($r['tb'] > $hot) {
                        $a['over']++;
                    }
                }
            }
            unset($a);
        }
        $out = array();
        foreach (array(0, 1) as $m) {
            if (empty($acc[$m])) {
                continue;
            }
            $a = $acc[$m];
            $kwh = 0;
            $dju = 0;
            $nd = 0;
            foreach ($daily as $d) {
                if (isset($dayMode[$d['date']]) && $dayMode[$d['date']] == $m) {
                    $nd++;
                    if ($d['dju'] !== null) {
                        $kwh += $d['kwh'];
                        $dju += $d['dju'];
                    }
                }
            }
            $nl = array();
            foreach ($nights as $n) {
                if ($n['md'] !== null && (($n['md'] >= 0.5) ? 1 : 0) == $m && $n['gap'] !== null && $n['gap'] > 2) {
                    $nl[] = $n['drop'] / $n['gap'];
                }
            }
            $out[] = array(
                'mode' => $m,
                'label' => $labels[$m],
                'hours' => round($a['n'] * 5 / 60, 1),
                'days' => $nd,
                'sp_avg' => self::r(self::avg($a['sp']), 2),
                'by_open' => $a['by'] ? round(100 * self::avg($a['by'])) : null,
                'tb_avg' => self::r(self::avg($a['tb']), 1),
                'kwh_dju' => ($dju >= 5) ? round($kwh / $dju, 2) : null,
                'night_loss' => count($nl) >= 2 ? round(self::avg($nl), 4) : null,
                'nights' => count($nl),
                'overheat_h' => $a['summer'] >= 12 ? round($a['over'] * 5 / 60, 1) : null,
                'summer_h' => round($a['summer'] * 5 / 60, 1),
            );
        }
        return $out;
    }

    /* Phrases d'interprétation, pour lire les chiffres sans être thermicien. */
    public static function insights($res, $names) {
        $out = array();
        $k = $res['kpi'];
        $f = function ($v, $d = 1) {
            return ThermoFlair325::fmt($v, $d);
        };
        $lc = isset($res['lux_check']) ? $res['lux_check'] : null;
        if ($lc && $lc['suggest'] !== null) {
            $out[] = "⚠️ Capteur de luminosité : le maximum réglé (" . $lc['max'] . " lx) n'est atteint que " . $lc['reach'] . " jour(s) sur " . $lc['days'] . " ; les journées les plus lumineuses plafonnent vers " . $lc['p90'] . " lx. Des journées ensoleillées sont donc classées « maussades » et les statistiques du soleil sont faussées. Réglez le maximum sur environ " . $lc['suggest'] . " lx (onglet Maison, ou bouton Appliquer de la préconisation).";
        }
        $nh = isset($res['noheat']) ? $res['noheat'] : null;
        if ($nh && $nh['days']) {
            $parts = array();
            $nm = array('sun' => 'ensoleillé', 'mixed' => 'mitigé', 'grey' => 'maussade');
            foreach ($nh['by_sky'] as $t => $b) {
                if ($b['days']) {
                    $parts[] = '+' . $f($b['gain'], 1) . ' °C par jour ' . $nm[$t] . ' (' . $b['days'] . ' j' . ($b['to'] !== null ? ', ' . $f($b['to'], 0) . ' °C dehors' : '') . ')';
                }
            }
            $out[] = "☀️ Journées sans chauffage (" . count($nh['days']) . ") : la maison monte du matin à l'après-midi de " . ($parts ? implode(', ', $parts) : '—') . "."
                . ((isset($nh['by_sky']['sun']['gain'], $nh['by_sky']['grey']['gain']) && $nh['by_sky']['sun']['gain'] !== null && $nh['by_sky']['grey']['gain'] !== null) ? " Le soleil apporte donc environ " . $f($nh['by_sky']['sun']['gain'] - $nh['by_sky']['grey']['gain'], 1) . " °C de plus qu'un jour gris." : '');
        }
        $sg = isset($res['signature']) ? $res['signature'] : null;
        if ($sg && $sg['ua'] !== null) {
            $out[] = "📐 Signature énergétique : environ " . $sg['ua'] . " W par degré d'écart dedans / dehors (" . $f(-$sg['slope'], 2) . " kWh par jour et par degré). On n'a plus besoin de chauffer au-dessus d'environ " . $f($sg['balance'], 1) . " °C dehors en moyenne sur la journée" . ($sg['r2'] !== null ? " (fiabilité " . round($sg['r2'] * 100) . " %, " . $sg['n'] . " jours)" : '') . ".";
        }
        $cf = isset($res['comfort']['tb']) ? $res['comfort']['tb'] : null;
        if ($cf) {
            $out[] = "🌡️ Confort (bas) : " . $f($cf['ok'], 0) . " % du temps entre 20 et 25 °C, " . $f($cf['lt20'], 0) . " % sous 20 °C, " . $f($cf['gt25'], 0) . " % au-dessus de 25 °C" . ($cf['gt25'] > 10 ? " — au-dessus du critère maison passive (moins de 10 % au-delà de 25 °C) : protections solaires, aération nocturne." : '.');
        }
        if (!empty($res['air']['co2'])) {
            $hi = 0;
            foreach ($res['air']['co2'] as $b) {
                if ($b['label'] == '1000 à 1400' || $b['label'] == 'plus de 1400') {
                    $hi += $b['pct'];
                }
            }
            $out[] = "🫁 Qualité de l'air : CO2 moyen " . $res['air']['co2_avg'] . " ppm, au-dessus de 1000 ppm " . $f($hi, 0) . " % du temps" . ($hi > 10 ? " : la ventilation pourrait être augmentée aux heures concernées." : '.');
        }
        if ($k['rain_days'] !== null) {
            $t = "🌦️ Météo : " . $k['rain_days'] . " jour(s) de pluie ou de neige (au moins 1 h) et " . $k['dry_days'] . " jour(s) sec(s) sur la période.";
            if ($k['rain_kwh_dju'] !== null && $k['dry_kwh_dju'] !== null && $k['dry_kwh_dju'] > 0) {
                $pct = round(100 * ($k['rain_kwh_dju'] - $k['dry_kwh_dju']) / $k['dry_kwh_dju']);
                $t .= " Chauffage : " . $f($k['rain_kwh_dju'], 2) . " kWh par degré-jour les jours de pluie contre " . $f($k['dry_kwh_dju'], 2) . " les jours secs (" . ($pct >= 0 ? '+' : '') . $pct . " %).";
            }
            $src = isset($res['quality']['sky_source']) ? $res['quality']['sky_source'] : null;
            if ($src && ($src['meteo'] + $src['both']) > 0) {
                $t .= " Le type de journée (ensoleillée, mitigée, maussade) tient compte de la météo pour " . ($src['meteo'] + $src['both']) . " jour(s).";
            }
            $out[] = $t;
        }
        if (!empty($k['ef_avg'])) {
            $t = "♻️ Efficacité moyenne de l'échangeur : " . $k['ef_avg'] . " % (" . $k['ef_days'] . " jour(s) mesurés, bypass fermé et préchauffage arrêté).";
            if ($k['ef_avg'] < 70) {
                $t .= " C'est bas pour une Flair 325 (85 à 95 % attendus) : filtres encrassés, échangeur sale ou mesure à vérifier (registre 4036, Option #value# / 10).";
            }
            if ($k['ef_first'] !== null && $k['ef_last'] !== null && $k['ef_first'] - $k['ef_last'] >= 5) {
                $t .= " Elle baisse : " . $k['ef_first'] . " % au début de la période, " . $k['ef_last'] . " % à la fin. Pensez aux filtres et à l'entretien de l'échangeur.";
            }
            $out[] = $t;
        }
        if ($k['ph_h'] !== null) {
            $out[] = $k['ph_h'] > 0
                ? "❄️ Préchauffage antigel : " . $f($k['ph_h'], 1) . " h sur " . $k['ph_days'] . " jour(s)" . ($k['ph_to'] !== null ? ", en moyenne par " . $f($k['ph_to'], 1) . " °C dehors" : '') . ". Il consomme de l'électricité : c'est normal par grand froid, mais à surveiller s'il tourne souvent."
                : "❄️ Préchauffage antigel : il n'a pas fonctionné sur la période.";
        }
        if ($k['tau'] !== null) {
            $out[] = "Constante de temps de la maison : environ " . $k['tau'] . " h (" . $f($k['tau'] / 24) . " jours). Plus elle est grande, mieux la maison garde la chaleur.";
        }
        if ($k['k'] !== null) {
            $out[] = "Sans chauffage, la maison perd environ " . $f($k['k'] * 10, 2) . " °C par heure quand il fait 10 °C de moins dehors que dedans.";
        }
        if ($k['gain_b1'] !== null) {
            $out[] = "« " . $names['b1'] . " » seule fait monter le bas d'environ " . $f($k['gain_b1'], 2) . " °C/h (hors pertes)" . ($k['gain_b1'] < 0.15 ? " : c'est faible, elle doit chauffer longtemps — d'où l'intérêt de l'anticipation." : '.');
        }
        if ($k['gain_b2'] !== null) {
            $out[] = "« " . $names['b2'] . " » fait monter " . ($res['quality']['has_up'] ? 'le haut' : 'la maison') . " d'environ " . $f($k['gain_b2'], 2) . " °C/h.";
        }
        if (!empty($k['solar_h'])) {
            $out[] = "☀️ Surchauffe due au soleil : " . $f($k['solar_h'], 1) . " h sur " . $k['solar_days'] . " jour(s), jusqu'à " . $f($k['solar_max'], 1) . " °C dedans pour " . $f($k['solar_to'], 1) . " °C dehors, sans chauffage. C'est le soleil par les vitrages : sans chauffage, la maison ne monte pas aussi haut toute seule.";
        }
        if ($k['sun_coef'] !== null) {
            if ($k['sun_coef'] > 0.02) {
                $out[] = "☀️ Une journée ensoleillée apporte environ " . $f($k['sun_coef'], 2) . " °C/h au bas entre 10h et 16h" . ($k['sun_coef_up'] !== null ? " (" . $f($k['sun_coef_up'], 2) . " °C/h au haut)" : '') . ($k['sun_min_b1'] !== null ? " : chaque heure de ce soleil vaut environ " . $k['sun_min_b1'] . " min de « " . $names['b1'] . " »." : '.');
            } else {
                $out[] = "☀️ Pas d'apport du soleil mesurable pour l'instant (peu de journées ensoleillées sur la période, ou protections solaires fermées).";
            }
        }
        if ($k['sun_kwh'] !== null && $k['sun_kwh'] > 0) {
            $out[] = "Sur la période, le soleil a fourni l'équivalent d'environ " . $f($k['sun_kwh'], 1) . " kWh de chauffage" . (!empty($k['sky_days']) ? " (" . $k['sky_days']['sun'] . " journée(s) ensoleillée(s), " . $k['sky_days']['mixed'] . " mitigée(s), " . $k['sky_days']['grey'] . " maussade(s))" : '') . ".";
        }
        if ($k['cool_sunny'] !== null && $k['cool_cloudy'] !== null) {
            $out[] = "En journée sans chauffage : " . $f($k['cool_sunny'], 2) . " °C/h les jours ensoleillés contre " . $f($k['cool_cloudy'], 2) . " °C/h les jours maussades.";
        }
        $dt = isset($res['sun_effect']) && is_array($res['sun_effect']) ? $res['sun_effect'] : array();
        $byT = array();
        foreach ($dt as $x) {
            $byT[$x['type']] = $x;
        }
        if (!empty($byT['sun']['kwh_dju']) && !empty($byT['grey']['kwh_dju']) && $byT['grey']['kwh_dju'] > 0) {
            $out[] = "Chauffage par degré-jour : " . $f($byT['sun']['kwh_dju'], 2) . " kWh les jours ensoleillés, " . (!empty($byT['mixed']['kwh_dju']) ? $f($byT['mixed']['kwh_dju'], 2) . " les jours mitigés, " : '') . $f($byT['grey']['kwh_dju'], 2) . " les jours maussades (" . round(100 * (1 - $byT['sun']['kwh_dju'] / $byT['grey']['kwh_dju'])) . " % d'économie grâce au soleil).";
        }
        if ($k['cool_night'] !== null && $k['cool_day'] !== null) {
            $out[] = "Sans chauffage, la température varie de " . $f($k['cool_night'], 2) . " °C/h la nuit contre " . $f($k['cool_day'], 2) . " °C/h le jour : l'écart correspond aux apports du soleil et de la vie dans la maison.";
        }
        if ($k['night_drop'] !== null) {
            $out[] = "Sur " . $k['nights'] . " nuit(s) sans chauffage (22h → 6h), le bas a perdu en moyenne " . $f($k['night_drop'], 2) . " °C.";
        }
        if ($k['share_b1'] !== null) {
            $out[] = "« " . $names['b1'] . " » a fourni " . $k['share_b1'] . " % de l'énergie de chauffage sur la période.";
        }
        if ($k['kwh_per_dju'] !== null) {
            $out[] = "Consommation : " . $f($k['kwh_per_dju'], 2) . " kWh par degré-jour (base " . self::BASE_DJU . " °C) — l'indicateur à suivre d'un hiver à l'autre, il ne dépend pas de la météo.";
        }
        $se = $res['speed_effect'];
        if (count($se) >= 2 && $se[0]['k'] > 0) {
            $last = end($se);
            $pct = round(100 * ($last['k'] - $se[0]['k']) / $se[0]['k']);
            if ($pct > 0) {
                $out[] = "Effet de la VMC : en vitesse " . $last['speed'] . ", la maison perd environ " . $pct . " % de chaleur de plus qu'en vitesse " . $se[0]['speed'] . " (" . $f($last['loss10'], 2) . " contre " . $f($se[0]['loss10'], 2) . " °C/h pour 10 °C d'écart).";
            } else {
                $out[] = "Effet de la VMC : pas de différence mesurable de déperdition selon la vitesse (l'échangeur récupère bien la chaleur).";
            }
        }
        if ($k['kb'] !== null && $k['k'] !== null && $k['k'] > 0) {
            $out[] = "Bypass ouvert : la maison perd environ " . $f($k['kb'] * 10, 2) . " °C/h de plus pour 10 °C d'écart (soit " . round(100 * $k['kb'] / $k['k']) . " % de pertes en plus), car l'air neuf ne passe plus par l'échangeur.";
        }
        if ($k['bypass_heat_min'] !== null && $k['bypass_heat_min'] >= 30) {
            $out[] = "⚠️ Le bypass était ouvert pendant " . $k['bypass_heat_min'] . " min de chauffe sur la période : chaleur perdue, vérifiez le réglage du bypass de la VMC.";
        }
        $md = isset($res['modes']) ? $res['modes'] : array();
        if (count($md) == 2) {
            $a = $md[0];
            $b = $md[1];
            if ($a['kwh_dju'] !== null && $b['kwh_dju'] !== null && min($a['kwh_dju'], $b['kwh_dju']) > 0) {
                $best = ($a['kwh_dju'] <= $b['kwh_dju']) ? $a : $b;
                $other = ($best === $a) ? $b : $a;
                $pct = round(100 * (1 - $best['kwh_dju'] / $other['kwh_dju']));
                $out[] = ($pct < 10)
                    ? "Chauffage : pas de différence nette entre les modes « températures » et « bypass » (" . $pct . " % d'écart par degré-jour, dans la marge due à la météo)."
                    : "Chauffage : le mode « " . $best['label'] . " » consomme " . $pct . " % de moins par degré-jour que le mode « " . $other['label'] . " » (" . $f($best['kwh_dju'], 2) . " contre " . $f($other['kwh_dju'], 2) . " kWh).";
            }
            if ($a['night_loss'] !== null && $b['night_loss'] !== null && min($a['night_loss'], $b['night_loss']) > 0) {
                $best = ($a['night_loss'] <= $b['night_loss']) ? $a : $b;
                $other = ($best === $a) ? $b : $a;
                $out[] = ((1 - $best['night_loss'] / $other['night_loss']) < 0.1)
                    ? "Nuits sans chauffage : les deux modes gardent la chaleur à peu près pareil (" . $f($best['night_loss'], 3) . " et " . $f($other['night_loss'], 3) . " °C par nuit et par degré d'écart)."
                    : "Nuits sans chauffage : avec le mode « " . $best['label'] . " », la maison perd " . $f($best['night_loss'], 3) . " °C par nuit et par degré d'écart, contre " . $f($other['night_loss'], 3) . " avec « " . $other['label'] . " ».";
            }
            if ($a['overheat_h'] !== null && $b['overheat_h'] !== null && $a['summer_h'] > 0 && $b['summer_h'] > 0) {
                $out[] = "Été : maison trop chaude " . $f(100 * $a['overheat_h'] / $a['summer_h'], 0) . " % du temps en mode « " . $a['label'] . " », " . $f(100 * $b['overheat_h'] / $b['summer_h'], 0) . " % en mode « " . $b['label'] . " ».";
            }
        }
        if (!$res['quality']['has_out']) {
            $out[] = "Ajoutez une sonde extérieure pour obtenir la déperdition, la constante de temps et des prévisions selon la météo.";
        }
        if (!$res['quality']['has_sun']) {
            $out[] = "Ajoutez un capteur de luminosité extérieur (exposé au sud) pour mesurer l'apport du soleil.";
        }
        return $out;
    }
}
