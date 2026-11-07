<?php

if (!defined('__ROOT__')) {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
}
require_once dirname(__FILE__) . '/ThermoFlair325Stats.class.php';
require_once dirname(__FILE__) . '/ThermoFlair325Advisor.class.php';
require_once dirname(__FILE__) . '/ThermoFlair325Report.class.php';
require_once dirname(__FILE__) . '/ThermoFlair325Brink.class.php';

/*
 * ThermoFlair325 — pilotage d'une maison très isolée : VMC double flux + batteries
 * de chauffe sur le réseau de soufflage + délestage Linky + filtre +
 * statistiques thermiques.
 *
 * Un équipement "Maison" regroupe les modules (activables séparément),
 * parce qu'ils sont liés physiquement :
 *  - une batterie chauffe l'air soufflé : elle ne chauffe correctement qu'à
 *    partir d'une certaine vitesse de VMC (2 ou 3, réglable par batterie).
 *    Le plugin monte donc d'abord la VMC, n'allume la batterie qu'une fois
 *    la vitesse confirmée, la coupe si la VMC redescend, et garde la VMC
 *    en marche quelques minutes après l'arrêt (chaleur résiduelle) ;
 *  - la nuit, la VMC est limitée (bruit) : une batterie qui a besoin d'une
 *    vitesse supérieure ne peut alors pas chauffer — on prévient ;
 *  - les deux batteries ensemble peuvent dépasser l'abonnement Linky :
 *    délestage dans l'ordre choisi, réagissant à chaque mesure de puissance.
 *
 * Le plugin ne parle jamais directement au matériel : il pilote les
 * commandes Jeedom choisies dans la configuration.
 *
 * Règles de conception (leçons RoboProg / LandroidRTK / LotoStat) :
 *  - AUCUNE propriété statique sur la classe (Jeedom les prendrait pour des
 *    colonnes SQL) : toutes les tables sont des constantes.
 *  - Une commande n'est envoyée que si l'état doit changer ; l'état réel est
 *    vérifié ensuite (relances limitées + alerte).
 *  - L'état dynamique (mode auto, consigne, boost, minuteries, délestage)
 *    est stocké dans la config du plugin (runtime::<id>), jamais dans
 *    l'équipement : pas d'écrasement d'une sauvegarde faite en même temps.
 *  - La vitesse 4 n'est jamais choisie par l'automatisme.
 */
class ThermoFlair325 extends eqLogic {

    const PLUGIN = 'ThermoFlair325';
    const VERSION = '0.9.4';

    const ALL_DAYS = array('1', '2', '3', '4', '5', '6', '7');

    const DEFAULTS = array(
        // ---------------- Général ----------------
        'custom_widget' => 1,
        'mod_vmc' => 1,
        'mod_heat' => 1,
        'mod_shed' => 0,
        'mod_filter' => 1,
        'mod_stats' => 1,
        'temp_in_cmd' => '',
        'temp_up_cmd' => '',
        'temp_out_cmd' => '',
        'lux_cmd' => '',
        'lux_max' => 2000,
        // Conditions météo en temps réel (code numérique d'un plugin météo :
        // OpenWeatherMap 200-804, WeatherAPI 1000-1282, Open-Meteo / WMO 0-99).
        'weather_cmd' => '',
        'weather_label_cmd' => '',
        'weather_source' => 'auto',
        // Classement des journées (ensoleillée / mitigée / maussade) :
        // 'weather' = météo d'abord (capteur si pas de météo), 'both' = moyenne,
        // 'lux' = capteur de luminosité seul.
        'sky_source' => 'weather',
        'season_force' => 'auto',
        'winter_start' => 10,
        'winter_start_day' => 1,
        'winter_end' => 3,
        'winter_end_day' => 31,

        // ---------------- VMC : commandes ----------------
        'vmc_s1_cmd' => '',
        'vmc_s2_cmd' => '',
        'vmc_s3_cmd' => '',
        'vmc_s4_cmd' => '',
        'vmc_pos_cmd' => '',
        // Registre 8001 de la Flair 325 : 0 = absence, 1 = bas, 2 = normal, 3 = haut.
        'vmc_p1' => '0',
        'vmc_p2' => '1',
        'vmc_p3' => '2',
        'vmc_p4' => '3',
        // Débit de chaque vitesse (m³/h), pour l'affichage seulement.
        'vmc_flow_1' => '',
        'vmc_flow_2' => '',
        'vmc_flow_3' => '',
        'vmc_flow_4' => '',
        'vmc_pre_cmd' => '',
        'vmc_pre_value' => '1',
        'vmc_pre_action_cmd' => '',
        // Renvoi du « Mode ModBus » (registre 8000) toutes les N minutes tant
        // que le plugin pilote la VMC (0 = jamais). Une coupure de courant
        // remet ce registre à 0 sur la Flair.
        'vmc_pre_every' => 15,
        // 1 = garder la VMC en mode ModBus en permanence, même quand le mode
        // automatique du plugin est coupé (sinon : seulement quand il pilote).
        'vmc_pre_always' => 1,
        // Commande « Cycle OK » de MyModbus : 0 = la VMC ne répond plus.
        'modbus_ok_cmd' => '',
        'modbus_fail_min' => 5,
        'co2_cmd' => '',
        // Températures mesurées par la VMC après l'échangeur (Flair : 4036
        // air soufflé, 4046 air rejeté) : servent au calcul de l'efficacité.
        'hx_supply_cmd' => '',
        'hx_exhaust_cmd' => '',
        // Préchauffage antigel de la VMC (Flair : 4060 état, 2 = actif ;
        // 4061 puissance en %).
        'preheat_cmd' => '',
        'preheat_on_value' => '2',
        'preheat_power_cmd' => '',
        // ---------------- VMC : comportement ----------------
        'vmc_temp_ref' => 'down',
        'vmc_auto_default' => 1,
        'vmc_off_speed' => 2,
        'vmc_fallback_speed' => 2,
        'vmc_dwell' => 15,
        'vmc_hyst' => 0.5,
        'vmc_delta' => 2,
        'vmc_cold_out' => 16,
        'co2_high' => 1000,
        'co2_low' => 850,
        'co2_speed' => 2,
        // Mode de décision par saison : 'temp' (températures) ou 'bypass'
        // (on suit le bypass de la VMC).
        'vmc_mode_w' => 'temp',
        'vmc_mode_s' => 'temp',
        'bypass_cmd' => '',
        // Registre 4050 : 1 = en cours d'ouverture, 3 = ouvert (4 = fermé).
        // Plusieurs valeurs possibles, séparées par des virgules.
        'bypass_open_value' => '1,3',
        // Vitesse par zone : _c = dehors plus frais, _n = dehors pas plus frais.
        // Zone chaude + dehors plus frais = cool_day / cool_night.
        'w_t_fresh' => 20,
        'w_t_hot' => 27,
        'w_sp_fresh_c' => 1,
        'w_sp_fresh_n' => 1,
        'w_sp_comf_c' => 1,
        'w_sp_comf_n' => 1,
        'w_sp_hot_n' => 1,
        'w_cool_day' => 2,
        'w_cool_night' => 2,
        's_t_fresh' => 23,
        's_t_hot' => 25,
        's_sp_fresh_c' => 2,
        's_sp_fresh_n' => 1,
        's_sp_comf_c' => 2,
        's_sp_comf_n' => 1,
        's_sp_hot_n' => 1,
        's_cool_day' => 3,
        's_cool_night' => 2,
        'flush_enabled' => 1,
        'flush_detect_start' => '07:00',
        'flush_detect_end' => '07:25',
        'flush_out' => 16,
        'flush_end' => '09:00',
        'flush_speed' => 3,
        'boost_max' => 60,
        // Boosts / vitesses temporaires : une commande action par ligne
        // (id = logicalId de la commande, stable même si on renomme).
        'vmc_boosts' => array(
            array('id' => 'boost_1', 'name' => 'Boost 1', 'speed' => '4', 'minutes' => '3', 'widget' => '1'),
            array('id' => 'boost_2', 'name' => 'Boost 2', 'speed' => '4', 'minutes' => '15', 'widget' => '1'),
            array('id' => 'boost_3', 'name' => 'Boost 3', 'speed' => '3', 'minutes' => '60', 'widget' => '1'),
            array('id' => 'force_1', 'name' => 'Vitesse 1 temporaire', 'speed' => '1', 'minutes' => '60', 'widget' => '0'),
            array('id' => 'force_2', 'name' => 'Vitesse 2 temporaire', 'speed' => '2', 'minutes' => '60', 'widget' => '0'),
            array('id' => 'force_3', 'name' => 'Vitesse 3 temporaire', 'speed' => '3', 'minutes' => '60', 'widget' => '0'),
        ),
        'vmc_manual_hold' => 60,
        // Changements de vitesse de la VMC (automatique, plages, boost, réglage
        // manuel) : 'log' = seulement « Toutes les actions » (canal journal),
        // 'vmc' = aussi les destinataires « VMC ».
        'vmc_speed_notif' => 'log',
        'vmc_windows' => array(
            array('label' => 'Renouvellement quotidien', 'season' => 'summer', 'days' => self::ALL_DAYS, 'start' => '12:00', 'end' => '16:00', 'speed' => '2', 'skip_hot' => '1'),
        ),
        // Calendrier de silence : la VMC ne dépasse pas "max" pendant ces plages
        // (sauf boost / réglage manuel, demandés par quelqu'un).
        'vmc_quiet' => array(
            array('label' => 'Nuit', 'days' => self::ALL_DAYS, 'start' => '23:00', 'end' => '07:00', 'max' => '2'),
            array('label' => 'Grasse matinée', 'days' => array('6', '7'), 'start' => '07:00', 'end' => '08:30', 'max' => '2'),
        ),

        // ---------------- Chauffage ----------------
        'heat_on_default' => 1,
        'heat_consigne' => 20.5,
        'heat_eco' => 19,
        'heat_mode_default' => 'normal',
        // Verrou de nuit : aucune batterie ne chauffe sur ce créneau, quoi
        // que disent les plages (sécurité contre une erreur de réglage).
        // Exception hors-gel : sous night_lock_frost, chauffe jusqu'à cette valeur.
        'night_lock_enabled' => 1,
        'night_lock_start' => '23:30',
        'night_lock_end' => '05:30',
        'night_lock_frost' => 16,
        // Chauffage forcé (commande « Forcer le chauffage ») : durée par défaut.
        'heat_force_default' => 60,
        'heat_c_min' => 16,
        'heat_c_max' => 24,
        'heat_hyst_low' => 0.2,
        'heat_hyst_high' => 0.3,
        'heat_only_winter' => 1,
        'heat_out_max' => '',
        'heat_off_when_disabled' => 1,
        'heat_postvent' => 2,
        'assist_enabled' => 1,
        'assist_after' => 60,
        'assist_gap' => 0.5,
        'b1_enabled' => 1,
        'b1_name' => 'Bas',
        'b1_sensor' => 'down',
        'b1_on_cmd' => '',
        'b1_off_cmd' => '',
        'b1_state_cmd' => '',
        'b1_power' => '', // puissance de VOTRE batterie (plaque signalétique)
        'b1_min_speed' => 3,
        'b1_outside' => 'eco',
        'b1_min_on' => 20,
        'b1_min_off' => 15,
        'b1_max_on' => 0,
        'b1_rest' => 15,
        'b1_safety' => 26,
        'b2_enabled' => 1,
        'b2_name' => 'Haut',
        'b2_sensor' => 'up',
        'b2_on_cmd' => '',
        'b2_off_cmd' => '',
        'b2_state_cmd' => '',
        'b2_power' => '',
        'b2_min_speed' => 3,
        'b2_outside' => 'off',
        'b2_min_on' => 20,
        'b2_min_off' => 15,
        'b2_max_on' => 0,
        'b2_rest' => 15,
        'b2_safety' => 26,
        'heat_slots' => array(
            array('label' => 'Journée', 'batteries' => array('b1'), 'days' => self::ALL_DAYS, 'start' => '06:30', 'end' => '22:30', 'mode' => 'normal', 'consigne' => ''),
            array('label' => 'Réveil', 'batteries' => array('b2'), 'days' => array('1', '2', '3', '4', '5'), 'start' => '06:30', 'end' => '07:30', 'mode' => 'consigne', 'consigne' => '19.5'),
            array('label' => 'Soirée', 'batteries' => array('b2'), 'days' => self::ALL_DAYS, 'start' => '21:30', 'end' => '23:00', 'mode' => 'consigne', 'consigne' => '19.5'),
        ),

        // ---------------- Délestage (Linky) ----------------
        'shed_power_cmd' => '',
        'shed_limit' => 8500,
        'shed_first' => 'b2',
        'shed_delay' => 15,
        'shed_margin' => 500,
        'shed_restore' => 5,

        // ---------------- Filtre ----------------
        'filter_cmd' => '',
        'filter_value' => '1',
        // Remise à zéro de l'avertissement filtre (registre 8010, écrire 1).
        'filter_reset_cmd' => '',
        'filter_notify_time' => '19:30',
        'filter_repeat' => 'daily',

        // ---------------- Notifications ----------------
        'rest_start' => '23:02',
        'rest_end' => '05:59',
        'notifications' => array(),

        // ---------------- Statistiques ----------------
        'stats_keep' => 400,
        // Rapport des statistiques envoyé aux jours et heures choisis.
        'report_enabled' => 0,
        'report_days' => 7,
        'report_charts' => 1,
        'report_chart_days' => 14,
        'report_schedule' => array(
            array('days' => array('3', '6'), 'time' => '08:00'),
        ),
        'report_recipients' => array(),
    );

    const ARRAY_KEYS = array('vmc_boosts', 'vmc_windows', 'vmc_quiet', 'heat_slots', 'notifications', 'report_schedule', 'report_recipients');

    /* Rôle de chaque commande, affiché dans l'onglet Commandes. */
    const CMD_HELP = array(
        'refresh' => 'Relit les capteurs et applique les décisions tout de suite.',
        'season' => 'Saison utilisée par le plugin : Hiver ou Été.',
        'temp_in' => 'Température lue en bas.',
        'temp_up' => 'Température lue en haut.',
        'sun' => 'Luminosité en % du maximum du capteur (100 % = capteur au maximum, jour clair).',
        'alert' => 'Texte de la dernière alerte (panne, configuration).',
        'vmc_speed' => 'Vitesse de la VMC en ce moment (lue sur la VMC).',
        'vmc_target' => 'Vitesse que le plugin veut. Différente de la vitesse actuelle pendant quelques secondes après un ordre.',
        'vmc_reason' => 'Pourquoi la VMC est à cette vitesse, en une phrase.',
        'vmc_auto' => '1 = le plugin pilote la VMC. 0 = mode automatique coupé.',
        'vmc_auto_on' => 'Rend la main au plugin pour la VMC.',
        'vmc_auto_off' => 'Coupe le pilotage automatique de la VMC (applique une fois la vitesse « hors auto »).',
        'vmc_mode' => 'Comment la VMC décide en ce moment : « Températures » (seuils du plugin) ou « Bypass » (suit le bypass de la VMC).',
        'bypass' => '1 = bypass de la VMC ouvert : l\'air neuf ne passe pas par l\'échangeur (rafraîchissement).',
        'vmc_override' => 'Boost ou vitesse imposée en cours, avec le temps restant. Vide sinon.',
        'override_stop' => 'Arrête tout de suite un boost ou une vitesse imposée.',
        'heat_on' => '1 = le thermostat est actif. 0 = chauffage coupé.',
        'heat_on_cmd' => 'Active le thermostat.',
        'heat_off_cmd' => 'Coupe le thermostat (et éteint les batteries si l\'option est cochée).',
        'heat_force' => 'Force le chauffage pour une durée. Titre = « bas » (batterie 1) ou « tout » (les deux) ; message = durée (ex. 90, 1h, 1h30). Chauffe jusqu\'à la consigne Normal, même hors plage et en mode ECO.',
        'heat_force_b1' => 'Force la batterie 1 (bas) pendant le nombre de minutes donné (curseur), jusqu\'à la consigne Normal.',
        'heat_force_all' => 'Force les deux batteries pendant le nombre de minutes donné (curseur), jusqu\'à la consigne Normal.',
        'heat_force_stop' => 'Arrête tout de suite le chauffage forcé.',
        'heat_force_state' => 'Chauffage forcé en cours (batteries et heure de fin), vide sinon.',
        'night_lock' => '1 = verrou de nuit actif : aucune batterie ne chauffe sur le créneau de nuit (sauf hors-gel).',
        'night_lock_on' => 'Active le verrou de nuit.',
        'night_lock_off' => 'Désactive le verrou de nuit (déconseillé, sauf besoin ponctuel).',
        'heat_mode' => 'Mode choisi : « Normal » ou « ECO ». C\'est un choix, pas une température.',
        'mode_normal' => 'Passe en mode Normal : chaque plage utilise sa propre consigne.',
        'mode_eco' => 'Passe en mode ECO (absence, vacances) : la maison est chauffée au plus à la consigne ECO.',
        'consigne' => 'Température voulue dans les plages « Normal » (réglée sur le widget). Ne change pas quand on passe en ECO.',
        'consigne_set' => 'Règle la consigne Normal (valeur en °C, par pas de 0,5).',
        'consigne_eco' => 'Température voulue en ECO : plages « ECO », hors plage si choisi, et partout en mode ECO. Toujours au moins 0,5 °C sous Normal.',
        'consigne_eco_set' => 'Règle la consigne ECO (valeur en °C). Elle reste au moins 0,5 °C sous Normal.',
        'b1_state' => '1 = la batterie 1 chauffe en ce moment.',
        'b1_target' => 'Consigne réellement appliquée à la batterie 1 maintenant (selon la plage et le mode).',
        'b1_status' => 'Pourquoi la batterie 1 chauffe ou non, en une phrase.',
        'b1_today' => 'Minutes de chauffe de la batterie 1 aujourd\'hui.',
        'b2_state' => '1 = la batterie 2 chauffe en ce moment.',
        'b2_target' => 'Consigne réellement appliquée à la batterie 2 maintenant (selon la plage et le mode).',
        'b2_status' => 'Pourquoi la batterie 2 chauffe ou non, en une phrase.',
        'b2_today' => 'Minutes de chauffe de la batterie 2 aujourd\'hui.',
        'energy_today' => 'Énergie consommée par les batteries aujourd\'hui.',
        'shed_state' => '1 = au moins une batterie est coupée par le délestage.',
        'filter_status' => '1 = filtre à nettoyer.',
        'filter_days' => 'Jours depuis le dernier nettoyage du filtre.',
        'filter_reset' => 'Remet à zéro l\'avertissement filtre de la VMC (registre 8010). À lancer après avoir nettoyé ou changé les filtres.',
        'modbus_ok' => '1 = la VMC répond en ModBus (d\'après « Cycle OK » de MyModbus).',
        'hx_eff' => 'Part de la chaleur de l\'air extrait rendue à l\'air neuf par l\'échangeur (%). Calculée seulement bypass fermé, préchauffage arrêté et avec au moins 5 °C d\'écart dedans / dehors.',
        'preheat_state' => '1 = le préchauffage antigel de la VMC chauffe l\'air neuf.',
        'preheat_today' => 'Minutes de préchauffage aujourd\'hui.',
    );

    const CMDS = array(
        'refresh'         => array('name' => 'Rafraîchir', 'type' => 'action', 'subType' => 'other', 'visible' => 1),
        'season'          => array('name' => 'Saison', 'type' => 'info', 'subType' => 'string', 'visible' => 0),
        'temp_in'         => array('name' => 'Température bas', 'type' => 'info', 'subType' => 'numeric', 'unite' => '°C', 'visible' => 1),
        'temp_up'         => array('name' => 'Température haut', 'type' => 'info', 'subType' => 'numeric', 'unite' => '°C', 'visible' => 1),
        'sun'             => array('name' => 'Luminosité', 'type' => 'info', 'subType' => 'numeric', 'unite' => '%', 'visible' => 0),
        'alert'           => array('name' => 'Dernière alerte', 'type' => 'info', 'subType' => 'string', 'visible' => 0),
        // VMC
        'vmc_speed'       => array('name' => 'VMC vitesse actuelle', 'type' => 'info', 'subType' => 'numeric', 'visible' => 1),
        'vmc_target'      => array('name' => 'VMC vitesse demandée', 'type' => 'info', 'subType' => 'numeric', 'visible' => 0),
        'vmc_reason'      => array('name' => 'VMC raison', 'type' => 'info', 'subType' => 'string', 'visible' => 1),
        'vmc_auto'        => array('name' => 'VMC automatique', 'type' => 'info', 'subType' => 'binary', 'visible' => 1),
        'vmc_auto_on'     => array('name' => 'VMC auto ON', 'type' => 'action', 'subType' => 'other', 'visible' => 1),
        'vmc_auto_off'    => array('name' => 'VMC auto OFF', 'type' => 'action', 'subType' => 'other', 'visible' => 1),
        'vmc_mode'        => array('name' => 'VMC mode de décision', 'type' => 'info', 'subType' => 'string', 'visible' => 0),
        'bypass'          => array('name' => 'VMC bypass ouvert', 'type' => 'info', 'subType' => 'binary', 'visible' => 0),
        'vmc_override'    => array('name' => 'VMC forçage en cours', 'type' => 'info', 'subType' => 'string', 'visible' => 0),
        'override_stop'   => array('name' => 'Reprendre l\'automatique', 'type' => 'action', 'subType' => 'other', 'visible' => 1),
        // Chauffage
        'heat_on'         => array('name' => 'Thermostat', 'type' => 'info', 'subType' => 'binary', 'visible' => 1),
        'heat_on_cmd'     => array('name' => 'Thermostat ON', 'type' => 'action', 'subType' => 'other', 'visible' => 1),
        'heat_off_cmd'    => array('name' => 'Thermostat OFF', 'type' => 'action', 'subType' => 'other', 'visible' => 1),
        'heat_force'      => array('name' => 'Forcer le chauffage', 'type' => 'action', 'subType' => 'message', 'visible' => 0),
        'heat_force_b1'   => array('name' => 'Forcer la batterie 1 (minutes)', 'type' => 'action', 'subType' => 'slider', 'visible' => 0),
        'heat_force_all'  => array('name' => 'Forcer les deux batteries (minutes)', 'type' => 'action', 'subType' => 'slider', 'visible' => 0),
        'heat_force_stop' => array('name' => 'Arrêter le chauffage forcé', 'type' => 'action', 'subType' => 'other', 'visible' => 1),
        'heat_force_state' => array('name' => 'Chauffage forcé', 'type' => 'info', 'subType' => 'string', 'visible' => 1),
        'night_lock'      => array('name' => 'Verrou de nuit', 'type' => 'info', 'subType' => 'binary', 'visible' => 1),
        'night_lock_on'   => array('name' => 'Verrou de nuit ON', 'type' => 'action', 'subType' => 'other', 'visible' => 1),
        'night_lock_off'  => array('name' => 'Verrou de nuit OFF', 'type' => 'action', 'subType' => 'other', 'visible' => 1),
        'heat_mode'       => array('name' => 'Mode chauffage', 'type' => 'info', 'subType' => 'string', 'visible' => 1),
        'mode_normal'     => array('name' => 'Mode Normal', 'type' => 'action', 'subType' => 'other', 'visible' => 1),
        'mode_eco'        => array('name' => 'Mode ECO', 'type' => 'action', 'subType' => 'other', 'visible' => 1),
        'consigne'        => array('name' => 'Consigne Normal', 'type' => 'info', 'subType' => 'numeric', 'unite' => '°C', 'visible' => 1),
        'consigne_set'    => array('name' => 'Régler la consigne Normal', 'type' => 'action', 'subType' => 'slider', 'visible' => 1),
        'consigne_eco'    => array('name' => 'Consigne ECO', 'type' => 'info', 'subType' => 'numeric', 'unite' => '°C', 'visible' => 1),
        'consigne_eco_set' => array('name' => 'Régler la consigne ECO', 'type' => 'action', 'subType' => 'slider', 'visible' => 1),
        'b1_state'        => array('name' => 'Batterie 1 en chauffe', 'type' => 'info', 'subType' => 'binary', 'visible' => 1),
        'b1_target'       => array('name' => 'Batterie 1 consigne', 'type' => 'info', 'subType' => 'numeric', 'unite' => '°C', 'visible' => 0),
        'b1_status'       => array('name' => 'Batterie 1 état', 'type' => 'info', 'subType' => 'string', 'visible' => 0),
        'b1_today'        => array('name' => 'Batterie 1 aujourd\'hui', 'type' => 'info', 'subType' => 'numeric', 'unite' => 'min', 'visible' => 0),
        'b2_state'        => array('name' => 'Batterie 2 en chauffe', 'type' => 'info', 'subType' => 'binary', 'visible' => 1),
        'b2_target'       => array('name' => 'Batterie 2 consigne', 'type' => 'info', 'subType' => 'numeric', 'unite' => '°C', 'visible' => 0),
        'b2_status'       => array('name' => 'Batterie 2 état', 'type' => 'info', 'subType' => 'string', 'visible' => 0),
        'b2_today'        => array('name' => 'Batterie 2 aujourd\'hui', 'type' => 'info', 'subType' => 'numeric', 'unite' => 'min', 'visible' => 0),
        'energy_today'    => array('name' => 'Énergie chauffage aujourd\'hui', 'type' => 'info', 'subType' => 'numeric', 'unite' => 'kWh', 'visible' => 0),
        // Délestage
        'shed_state'      => array('name' => 'Délestage en cours', 'type' => 'info', 'subType' => 'binary', 'visible' => 1),
        // Filtre
        'filter_status'   => array('name' => 'Filtre à nettoyer', 'type' => 'info', 'subType' => 'binary', 'visible' => 1),
        'filter_days'     => array('name' => 'Filtre jours depuis nettoyage', 'type' => 'info', 'subType' => 'numeric', 'unite' => 'j', 'visible' => 0),
        'filter_reset'    => array('name' => 'Filtre nettoyé (remise à zéro VMC)', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
        // Communication ModBus
        'modbus_ok'       => array('name' => 'Communication ModBus', 'type' => 'info', 'subType' => 'binary', 'visible' => 0),
        'hx_eff'          => array('name' => 'Efficacité échangeur', 'type' => 'info', 'subType' => 'numeric', 'unite' => '%', 'visible' => 0),
        'preheat_state'   => array('name' => 'Préchauffage actif', 'type' => 'info', 'subType' => 'binary', 'visible' => 0),
        'preheat_today'   => array('name' => 'Préchauffage aujourd\'hui', 'type' => 'info', 'subType' => 'numeric', 'unite' => 'min', 'visible' => 0),
    );

    // Noms des positions de la Flair 325 (registre 8001 : 0 à 3). Le débit
    // de chaque vitesse dépend du réglage de l'installateur : il se saisit,
    // si on veut l'afficher, dans l'onglet VMC (vmc_flow_1 à 4).
    const SPEED_LABELS = array(1 => 'absence', 2 => 'bas', 3 => 'normal', 4 => 'haut');

    public static function speedLabel($cfg, $i) {
        $t = isset(self::SPEED_LABELS[$i]) ? self::SPEED_LABELS[$i] : '';
        $f = isset($cfg['vmc_flow_' . $i]) ? trim((string) $cfg['vmc_flow_' . $i]) : '';
        return $t . ($f !== '' && is_numeric($f) ? ', ' . intval($f) . ' m³/h' : '');
    }
    const OPERATORS = array('==', '!=', '>', '>=', '<', '<=');
    const DAY_SHORT = array(1 => 'L', 2 => 'Ma', 3 => 'Me', 4 => 'J', 5 => 'V', 6 => 'S', 7 => 'D');
    const MONTHS = array(1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre');
    /* Catégories de notification. 'log' = tout le reste (changements de mode,
     * consigne, boosts...) : uniquement pour les destinataires "Toutes les
     * actions". Les catégories "urgentes" passent même pendant le repos. */
    const NOTIF_CATS = array('vmc', 'heat', 'shed', 'filter', 'preheat', 'alert', 'safety', 'log');
    const NOTIF_URGENT = array('alert', 'shed', 'safety');
    const NOTIF_TITLES = array('vmc' => 'VMC', 'heat' => 'CHAUFFAGE', 'shed' => 'DÉLESTAGE', 'filter' => 'FILTRE VMC', 'preheat' => 'PRÉCHAUFFAGE VMC', 'alert' => 'ALERTE', 'safety' => 'SÉCURITÉ', 'log' => 'THERMOFLAIR 325', 'report' => 'RAPPORT');

    const MAX_RETRIES = 3;
    /* Écart minimum entre la consigne ECO et la consigne Normal. */
    const ECO_GAP = 0.5;
    const CONFIRM_DELAY = 120;
    // Après un ordre de vitesse ou le lancement d'un boost, la position lue
    // peut encore être l'ancienne (ou celle d'un ordre précédent) : pas de
    // détection de réglage manuel pendant ce délai.
    const MANUAL_GRACE = 180;

    /* ================================================================ */
    /* Outils                                                            */
    /* ================================================================ */

    // Horloge du plugin. En production : time(). Le banc de test peut la
    // simuler (variable globale) pour rejouer des journées entières.
    public static function now() {
        return isset($GLOBALS['THERMOFLAIR325_FAKE_NOW']) ? intval($GLOBALS['THERMOFLAIR325_FAKE_NOW']) : time();
    }

    public static function getApiKey() {
        $key = config::byKey('api', self::PLUGIN, '');
        if ($key == '') {
            $key = config::genKey();
            config::save('api', $key, self::PLUGIN);
            config::save('api::' . self::PLUGIN . '::mode', 'localhost');
            config::save('api::' . self::PLUGIN . '::restricted', 1);
        }
        return $key;
    }

    // "7:25", "07:25", "07h25", "725", "0725" -> minutes depuis minuit
    public static function hm2min($s) {
        if ($s === null || is_array($s)) {
            return null;
        }
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2})[:hH](\d{2})$/', $s, $m)) {
            $h = intval($m[1]);
            $mi = intval($m[2]);
        } elseif (preg_match('/^\d{3,4}$/', $s)) {
            $h = intval(substr($s, 0, -2));
            $mi = intval(substr($s, -2));
        } else {
            return null;
        }
        if ($h > 23 || $mi > 59) {
            return null;
        }
        return $h * 60 + $mi;
    }

    public static function min2hm($m) {
        if ($m === null) {
            return '?';
        }
        $m = ((intval($m) % 1440) + 1440) % 1440;
        return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }

    public static function nowMin($now) {
        return intval(date('G', $now)) * 60 + intval(date('i', $now));
    }

    // Fenêtre [start, end[ ; gère le passage de minuit (start > end).
    public static function inWindow($now, $start, $end) {
        if ($start === null || $end === null || $start == $end) {
            return false;
        }
        if ($start < $end) {
            return $now >= $start && $now < $end;
        }
        return $now >= $start || $now < $end;
    }

    // Plage avec jours : pour une plage qui passe minuit, la partie après
    // minuit appartient au jour où la plage a COMMENCÉ.
    public static function slotActive($row, $nowMin, $dow) {
        $start = self::hm2min(isset($row['start']) ? $row['start'] : '');
        $end = self::hm2min(isset($row['end']) ? $row['end'] : '');
        if ($start === null || $end === null || $start == $end) {
            return false;
        }
        $days = isset($row['days']) && is_array($row['days']) ? array_map('strval', $row['days']) : array();
        if (empty($days)) {
            return false;
        }
        if ($start < $end) {
            return in_array((string) $dow, $days) && $nowMin >= $start && $nowMin < $end;
        }
        $prev = ($dow == 1) ? 7 : $dow - 1;
        return ($nowMin >= $start && in_array((string) $dow, $days)) || ($nowMin < $end && in_array((string) $prev, $days));
    }

    public static function fmt($v, $dec = 1) {
        if ($v === null || $v === '') {
            return '?';
        }
        $r = round(floatval($v), $dec);
        if ($r == 0) {
            $r = 0; // évite « -0 »
        }
        return str_replace('.', ',', (string) $r);
    }

    public static function daysLabel($days) {
        if (!is_array($days) || empty($days)) {
            return 'aucun jour';
        }
        $d = array_map('intval', $days);
        sort($d);
        if ($d == array(1, 2, 3, 4, 5, 6, 7)) {
            return 'tous les jours';
        }
        if ($d == array(1, 2, 3, 4, 5)) {
            return 'en semaine';
        }
        if ($d == array(6, 7)) {
            return 'le week-end';
        }
        $out = array();
        foreach ($d as $x) {
            if (isset(self::DAY_SHORT[$x])) {
                $out[] = self::DAY_SHORT[$x];
            }
        }
        return implode(' ', $out);
    }

    public static function rowName($row) {
        $se = self::min2hm(self::hm2min(isset($row['start']) ? $row['start'] : '')) . '–' . self::min2hm(self::hm2min(isset($row['end']) ? $row['end'] : ''));
        return !empty($row['label']) ? '« ' . $row['label'] . ' » (' . $se . ')' : $se;
    }

    /*
     * Résout une commande stockée dans la config :
     *  - "#1234#" : format interne (Jeedom convertit les tags à la sauvegarde) ;
     *  - "#[Objet][Équipement][Commande]#" : format lisible (saisie, tests).
     * Un nombre tapé seul est refusé.
     */
    public static function resolveCmd($input) {
        if ($input === null || $input === '' || is_array($input)) {
            return null;
        }
        $input = trim((string) $input);
        try {
            if (preg_match('/^#([0-9]+)#$/', $input, $m)) {
                $cmd = cmd::byId($m[1]);
                return is_object($cmd) ? $cmd : null;
            }
            if (!preg_match('/^#\[[^\]]*\]\[[^\]]*\]\[[^\]]*\]#$/', $input)) {
                return null;
            }
            $resolved = cmd::humanReadableToCmd($input);
            if ($resolved != $input && preg_match('/^#([0-9]+)#$/', trim($resolved), $m)) {
                $cmd = cmd::byId($m[1]);
                return is_object($cmd) ? $cmd : null;
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    public static function cmdId($input) {
        $c = self::resolveCmd($input);
        return is_object($c) ? $c->getId() : null;
    }

    public static function readRaw($input) {
        $cmd = self::resolveCmd($input);
        if (!is_object($cmd) || $cmd->getType() != 'info') {
            return null;
        }
        try {
            return $cmd->execCmd();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function readNum($input) {
        $v = self::readRaw($input);
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        return floatval($v);
    }

    /*
     * Vitesse actuelle (1 à 4) d'après "Position ventilation" et la table de
     * correspondance configurée. Valeur numérique hors table = 0 (arrêt /
     * absence). null = illisible.
     */
    public static function readSpeed($cfg) {
        $raw = self::readRaw($cfg['vmc_pos_cmd']);
        if ($raw === null || $raw === '') {
            return null;
        }
        for ($i = 1; $i <= 4; $i++) {
            if (trim((string) $cfg['vmc_p' . $i]) !== '' && self::compare($raw, '==', $cfg['vmc_p' . $i])) {
                return $i;
            }
        }
        return is_numeric($raw) ? 0 : null;
    }

    public static function compare($val, $op, $ref) {
        $val = trim((string) $val);
        $ref = trim((string) $ref);
        $num = is_numeric($val) && is_numeric($ref);
        $a = $num ? floatval($val) : 0;
        $b = $num ? floatval($ref) : 0;
        switch ($op) {
            case '==':
                return $num ? ($a == $b) : (strcasecmp($val, $ref) == 0);
            case '!=':
                return $num ? ($a != $b) : (strcasecmp($val, $ref) != 0);
            case '>':
                return $num && $a > $b;
            case '>=':
                return $num && $a >= $b;
            case '<':
                return $num && $a < $b;
            case '<=':
                return $num && $a <= $b;
        }
        return false;
    }

    // Booléen avec hystérésis, mémorisé dans $flags[$key].
    public static function hyst(&$flags, $key, $onTest, $offTest) {
        $prev = isset($flags[$key]) ? (bool) $flags[$key] : null;
        if ($prev === null) {
            $state = (bool) $onTest;
        } elseif ($prev) {
            $state = !$offTest;
        } else {
            $state = (bool) $onTest;
        }
        $flags[$key] = $state;
        return $state;
    }

    /* ================================================================ */
    /* Configuration                                                     */
    /* ================================================================ */

    // Durée conseillée au plus pour la vitesse 4 (bruit, air asséché, usure).
    const BOOST_V4_ADVISED = 15;

    public static function getCfg($eqLogic, $override = null) {
        $cfg = array();
        $legacyBoosts = (is_object($eqLogic) && $eqLogic->getConfiguration('vmc_boosts', null) === null && !(is_array($override) && array_key_exists('vmc_boosts', $override)))
            ? self::legacyBoosts($eqLogic) : null;
        foreach (self::DEFAULTS as $k => $v) {
            $val = is_object($eqLogic) ? $eqLogic->getConfiguration($k, null) : null;
            if (is_array($override) && array_key_exists($k, $override)) {
                $val = $override[$k];
            }
            if ($val === null || ($val === '' && $v !== '')) {
                $val = $v;
            }
            $cfg[$k] = $val;
        }
        foreach (self::ARRAY_KEYS as $k) {
            if (!is_array($cfg[$k])) {
                $decoded = is_string($cfg[$k]) ? json_decode($cfg[$k], true) : null;
                $cfg[$k] = is_array($decoded) ? $decoded : array();
            }
            $cfg[$k] = array_values($cfg[$k]);
        }
        if ($legacyBoosts !== null) {
            $cfg['vmc_boosts'] = $legacyBoosts;
        }
        $cfg['vmc_boosts'] = self::normBoosts($cfg['vmc_boosts']);
        return $cfg;
    }

    /*
     * Équipement d'avant le tableau des boosts : boosts 1 à 3 (vitesse 4,
     * durées boostN_min) et vitesses 1 à 3 temporaires (force_duration),
     * avec les noms et l'affichage des commandes existantes.
     */
    public static function legacyBoosts($eqLogic) {
        $rows = array();
        $defs = array();
        foreach (self::DEFAULTS['vmc_boosts'] as $d) {
            $defs[$d['id']] = $d;
        }
        $fd = $eqLogic->getConfiguration('force_duration', '');
        foreach (array('boost_1', 'boost_2', 'boost_3', 'force_1', 'force_2', 'force_3') as $id) {
            $i = substr($id, -1);
            $isBoost = (strpos($id, 'boost') === 0);
            $min = $isBoost ? $eqLogic->getConfiguration('boost' . $i . '_min', '') : $fd;
            $old = array('boost_1' => '3', 'boost_2' => '15', 'boost_3' => '60');
            $row = array(
                'id' => $id,
                'name' => $defs[$id]['name'],
                'speed' => $isBoost ? '4' : $i,
                'minutes' => (string) ($min !== '' && $min !== null ? $min : ($isBoost ? $old[$id] : '60')),
                'widget' => $isBoost ? '1' : '0',
            );
            $cmd = $eqLogic->getId() != '' ? $eqLogic->getCmd('action', $id) : null;
            if (is_object($cmd)) {
                $row['name'] = $cmd->getName();
            }
            $rows[] = $row;
        }
        return $rows;
    }

    // Nettoie les lignes : id stable, vitesse 1 à 4, durée entière.
    public static function normBoosts($rows) {
        $out = array();
        $seen = array();
        foreach (is_array($rows) ? $rows : array() as $r) {
            if (!is_array($r)) {
                continue;
            }
            $id = isset($r['id']) ? preg_replace('/[^a-z0-9_]/', '', strtolower((string) $r['id'])) : '';
            if ($id === '' || isset($seen[$id]) || (!preg_match('/^(boost|force)_/', $id))) {
                $id = 'boost_' . substr(md5(json_encode($r) . count($out) . microtime()), 0, 6);
            }
            $seen[$id] = true;
            $out[] = array(
                'id' => $id,
                'name' => trim(isset($r['name']) ? (string) $r['name'] : ''),
                'speed' => (string) (isset($r['speed']) ? $r['speed'] : '4'),
                'minutes' => trim((string) (isset($r['minutes']) ? $r['minutes'] : '')),
                'widget' => (isset($r['widget']) && $r['widget'] == '1') ? '1' : '0',
            );
        }
        return $out;
    }

    public static function boostById($cfg, $id) {
        foreach ($cfg['vmc_boosts'] as $b) {
            if ($b['id'] === $id) {
                return $b;
            }
        }
        return null;
    }

    public static function boostHelp($b) {
        return 'Vitesse ' . $b['speed'] . ' pendant ' . self::fmtDuration(intval($b['minutes'])) . ', puis retour automatique.'
            . (intval($b['speed']) == 4 && intval($b['minutes']) > self::BOOST_V4_ADVISED ? ' (Vitesse 4 : ' . self::BOOST_V4_ADVISED . ' min au plus conseillé.)' : '');
    }

    public static function seasonOf($cfg, $now) {
        if ($cfg['season_force'] == 'winter' || $cfg['season_force'] == 'summer') {
            return $cfg['season_force'];
        }
        // Hiver du jour/mois de début au jour/mois de fin inclus (MMJJ).
        $d = intval(date('n', $now)) * 100 + intval(date('j', $now));
        $ws = intval($cfg['winter_start']) * 100 + max(1, min(31, intval($cfg['winter_start_day'])));
        $we = intval($cfg['winter_end']) * 100 + max(1, min(31, intval($cfg['winter_end_day'])));
        $winter = ($ws <= $we) ? ($d >= $ws && $d <= $we) : ($d >= $ws || $d <= $we);
        return $winter ? 'winter' : 'summer';
    }

    public static function seasonLabel($s) {
        return $s == 'winter' ? 'Hiver' : 'Été';
    }

    public static function batteryEnabled($cfg, $b) {
        return $cfg['mod_heat'] == '1' && $cfg[$b . '_enabled'] == '1';
    }

    /*
     * Plafond de vitesse en cours (calendrier de silence) : la plus basse
     * des plages actives. null = pas de plafond.
     */
    // Bypass de la VMC : true = ouvert, false = fermé, null = inconnu.
    public static function readBypass($cfg) {
        if ($cfg['bypass_cmd'] === '') {
            return null;
        }
        $raw = self::readRaw($cfg['bypass_cmd']);
        if ($raw === null || $raw === '') {
            return null;
        }
        return self::inList($raw, $cfg['bypass_open_value']);
    }

    // Valeur égale à l'une des valeurs de la liste « 1,3 » (ou « 1 ; 3 »).
    public static function inList($raw, $list) {
        foreach (preg_split('/[,;]/', (string) $list) as $ref) {
            if (trim($ref) !== '' && self::compare($raw, '==', trim($ref))) {
                return true;
            }
        }
        return false;
    }

    // Durée saisie librement : « 90 », « 90 min », « 1h », « 1h30 », « 1,5 h ».
    public static function parseDuration($txt) {
        $t = mb_strtolower(str_replace(',', '.', trim((string) $txt)));
        if ($t === '') {
            return null;
        }
        if (preg_match('/^(\d+(?:\.\d+)?)\s*h\s*(\d+)?\s*(?:min|mn|m)?$/', $t, $m)) {
            return intval(round(floatval($m[1]) * 60 + (isset($m[2]) ? intval($m[2]) : 0)));
        }
        if (preg_match('/^(\d+)\s*(?:min|mn|m)?$/', $t, $m)) {
            return intval($m[1]);
        }
        return null;
    }

    public static function fmtDuration($min) {
        $min = intval($min);
        if ($min < 60) {
            return $min . ' min';
        }
        return intdiv($min, 60) . ' h' . ($min % 60 ? ' ' . str_pad($min % 60, 2, '0', STR_PAD_LEFT) : '');
    }

    // Verrou de nuit en cours ? array(start, end) ou null.
    public static function nightLock($cfg, $h, $now) {
        if (empty($h['lock'])) {
            return null;
        }
        $a = self::hm2min($cfg['night_lock_start']);
        $b = self::hm2min($cfg['night_lock_end']);
        if (!self::inWindow(self::nowMin($now), $a, $b)) {
            return null;
        }
        return array('start' => self::min2hm($a), 'end' => self::min2hm($b));
    }

    public static function quietCap($cfg, $now) {
        $nowMin = self::nowMin($now);
        $dow = intval(date('N', $now));
        $best = null;
        foreach ($cfg['vmc_quiet'] as $q) {
            if (!self::slotActive($q, $nowMin, $dow)) {
                continue;
            }
            $max = intval(isset($q['max']) ? $q['max'] : 2);
            if ($best === null || $max < $best['max']) {
                $best = array('max' => $max, 'label' => !empty($q['label']) ? $q['label'] : 'silence', 'end' => self::min2hm(self::hm2min($q['end'])), 'row' => $q);
            }
        }
        return $best;
    }

    /*
     * Vérifie toute la configuration. Retourne errors / warnings par module
     * (general, vmc, heat, shed, filter, stats, notif) et ok par module
     * pilotant. Un module en erreur ne pilote RIEN.
     */
    public static function checkConfig($cfg) {
        $mods = array('general', 'vmc', 'heat', 'shed', 'filter', 'stats', 'notif');
        $E = array_fill_keys($mods, array());
        $W = $E;

        $num = function ($key, $min, $max, $label, $module, $allowEmpty = false) use (&$cfg, &$E) {
            $v = $cfg[$key];
            if ($allowEmpty && ($v === '' || $v === null)) {
                return;
            }
            if ($v === '' || $v === null) {
                $E[$module][] = "$label : obligatoire (entre " . self::fmt($min, 2) . " et " . self::fmt($max, 2) . ").";
                return;
            }
            if (!is_numeric($v) || floatval($v) < $min || floatval($v) > $max) {
                $E[$module][] = "$label : doit être compris entre " . self::fmt($min, 2) . " et " . self::fmt($max, 2) . " (valeur actuelle : \"" . (is_array($v) ? '…' : $v) . "\").";
            }
        };
        $time = function ($key, $label, $module) use (&$cfg, &$E) {
            if (self::hm2min($cfg[$key]) === null) {
                $E[$module][] = "$label : heure invalide (format HH:MM attendu, valeur actuelle : \"" . $cfg[$key] . "\").";
            }
        };
        // Commande liée : existe dans Jeedom, bon type, bon sous-type,
        // valeur actuelle cohérente.
        $cmdCheck = function ($key, $label, $module, $type, $required, $opts = array()) use (&$cfg, &$E, &$W) {
            $raw = isset($opts['raw']) ? $opts['raw'] : $cfg[$key];
            if ($raw === '' || $raw === null) {
                if ($required) {
                    $E[$module][] = "$label : obligatoire, choisissez une commande " . ($type == 'action' ? 'action' : 'info') . " avec le bouton de sélection.";
                }
                return null;
            }
            $cmd = self::resolveCmd($raw);
            if (!is_object($cmd)) {
                $E[$module][] = "$label : ce n'est pas une commande Jeedom existante (supprimée, renommée, ou texte tapé à la main ?). Valeur saisie : \"$raw\".";
                return null;
            }
            if ($cmd->getType() != $type) {
                $E[$module][] = "$label : il faut une commande de type " . ($type == 'action' ? 'ACTION' : 'INFO') . ", « " . $cmd->getHumanName() . " » est de type " . $cmd->getType() . ".";
                return null;
            }
            if (!empty($opts['subtypes']) && !in_array($cmd->getSubType(), $opts['subtypes'])) {
                $E[$module][] = "$label : « " . $cmd->getHumanName() . " » est de sous-type « " . $cmd->getSubType() . " », il faut : " . implode(' ou ', $opts['subtypes']) . ".";
                return null;
            }
            if ($type == 'info' && isset($opts['min'])) {
                $v = $cmd->execCmd();
                if (!is_numeric($v)) {
                    $W[$module][] = "$label : la valeur actuelle (\"$v\") n'est pas un nombre.";
                } elseif (floatval($v) < $opts['min'] || floatval($v) > $opts['max']) {
                    $W[$module][] = "$label : valeur actuelle " . self::fmt($v) . " hors de la plage attendue (" . self::fmt($opts['min'], 0) . " à " . self::fmt($opts['max'], 0) . ").";
                }
            }
            if ($type == 'info' && !empty($opts['binary'])) {
                $v = $cmd->execCmd();
                if (!in_array(trim((string) $v), array('0', '1'), true)) {
                    $W[$module][] = "$label : la valeur actuelle est \"$v\", 0 ou 1 est attendu.";
                }
            }
            return $cmd;
        };
        $rowsCheck = function ($rows, $label, $module, $extra) use (&$E) {
            foreach ($rows as $i => $r) {
                $n = $label . ' #' . ($i + 1) . (!empty($r['label']) ? ' (' . $r['label'] . ')' : '');
                $a = self::hm2min(isset($r['start']) ? $r['start'] : '');
                $b = self::hm2min(isset($r['end']) ? $r['end'] : '');
                if ($a === null || $b === null) {
                    $E[$module][] = "$n : heures de début/fin invalides.";
                } elseif ($a == $b) {
                    $E[$module][] = "$n : l'heure de début et de fin sont identiques.";
                }
                if (empty($r['days']) || !is_array($r['days'])) {
                    $E[$module][] = "$n : cochez au moins un jour.";
                }
                $extra($r, $n);
            }
        };

        $on = function ($m) use (&$cfg) {
            return isset($cfg['mod_' . $m]) && $cfg['mod_' . $m] == '1';
        };
        $vmcOn = $on('vmc');
        $heatOn = $on('heat');
        if (!$vmcOn && !$heatOn && !$on('filter') && !$on('shed')) {
            $W['general'][] = "Aucun module de pilotage n'est activé : l'équipement ne pilote rien.";
        }

        // ---------------- Général ----------------
        $tempOpts = array('min' => -10, 'max' => 45, 'subtypes' => array('numeric'));
        $cmdCheck('temp_in_cmd', 'Température du bas', 'general', 'info', $vmcOn || $heatOn || $on('stats'), $tempOpts);
        $cmdCheck('temp_up_cmd', 'Température du haut', 'general', 'info', false, $tempOpts);
        $cmdCheck('bypass_cmd', 'Statut du bypass', 'vmc', 'info', false, array('subtypes' => array('binary', 'numeric', 'string')));
        if ($cfg['bypass_cmd'] !== '') {
            $bv = self::readRaw($cfg['bypass_cmd']);
            if ($bv !== null && is_numeric($bv) && in_array(intval($bv), array(2, 3, 4, 255)) && !preg_match('/(^|[,;\s])3([,;\s]|$)/', (string) $cfg['bypass_open_value'])) {
                $W['vmc'][] = "Statut du bypass : la valeur lue est $bv, c'est le registre 4050 de la Flair (3 = ouvert, 4 = fermé). Mettez « 1,3 » dans « Valeur bypass ouvert ».";
            }
            if ($bv !== null && is_numeric($bv) && intval($bv) == 255) {
                $W['vmc'][] = "Statut du bypass : la VMC signale une erreur du bypass (valeur 255).";
            }
        }
        $cmdCheck('temp_out_cmd', 'Température extérieure', 'general', 'info', $vmcOn, array('min' => -40, 'max' => 55, 'subtypes' => array('numeric')));
        $cmdCheck('lux_cmd', 'Luminosité extérieure', 'general', 'info', false, array('min' => 0, 'max' => 200000, 'subtypes' => array('numeric')));
        $cmdCheck('weather_cmd', 'Conditions météo', 'general', 'info', false, array('subtypes' => array('numeric', 'string')));
        $cmdCheck('weather_label_cmd', 'Libellé de la condition météo', 'general', 'info', false, array('subtypes' => array('string', 'numeric')));
        if ($cfg['weather_label_cmd'] !== '' && $cfg['weather_cmd'] === '') {
            $W['general'][] = "Libellé météo sans code météo : le libellé n'est qu'informatif, choisissez aussi la commande du code (c'est elle qui sert aux statistiques).";
        }
        if (!in_array($cfg['sky_source'], array('weather', 'both', 'lux'))) {
            $E['general'][] = "Classement des journées : choix invalide.";
        }
        if (!in_array($cfg['weather_source'], array('auto', 'owm', 'weatherapi', 'wmo'))) {
            $E['general'][] = "Conditions météo : source invalide.";
        }
        if ($cfg['weather_cmd'] !== '') {
            $wv = self::readRaw($cfg['weather_cmd']);
            if ($wv !== null && $wv !== '' && !is_numeric(trim((string) $wv))) {
                $E['general'][] = "Conditions météo : la commande doit donner un NUMÉRO de condition (ex. 800, 1003 ou 2), pas un texte (valeur actuelle : « $wv »). Choisissez la commande « id condition » / « code » de votre plugin météo.";
            } elseif ($wv !== null && $wv !== '' && self::mapWeather($wv, $cfg['weather_source']) === null) {
                $W['general'][] = "Conditions météo : le code $wv n'est pas reconnu pour la source choisie. Vérifiez la source (OpenWeatherMap 200-804, WeatherAPI 1000-1282, Open-Meteo 0-99).";
            }
        }
        if ($cfg['lux_cmd'] !== '') {
            $num('lux_max', 10, 200000, 'Luminosité de plein soleil', 'general');
        } elseif ($on('stats') && $cfg['weather_cmd'] === '') {
            $W['stats'][] = "Sans capteur de luminosité ni conditions météo, les journées ne sont pas classées (ensoleillée / mitigée / maussade) et l'apport du soleil est approché par l'heure de la journée.";
        }
        foreach (array('b1', 'b2') as $b) {
            if (!$heatOn || $cfg[$b . '_enabled'] != '1') {
                continue;
            }
            if (!in_array($cfg[$b . '_sensor'], array('down', 'up', 'avg'))) {
                $E['heat'][] = "Batterie « " . $cfg[$b . '_name'] . " » : thermomètre de régulation invalide.";
            } elseif ($cfg[$b . '_sensor'] != 'down' && $cfg['temp_up_cmd'] === '') {
                $W['general'][] = "Sans température du haut, la batterie « " . $cfg[$b . '_name'] . " » est régulée sur la température du bas.";
            }
        }
        if ($on('stats') && $cfg['temp_out_cmd'] === '') {
            $W['stats'][] = "Sans température extérieure, les statistiques sont limitées (pas de calcul de déperdition ni de prévision selon la météo).";
        }
        if (!in_array($cfg['season_force'], array('auto', 'winter', 'summer'))) {
            $E['general'][] = "Saison : choix invalide.";
        }
        $num('winter_start', 1, 12, "Début de l'hiver (mois)", 'general');
        $num('winter_end', 1, 12, "Fin de l'hiver (mois)", 'general');
        $num('winter_start_day', 1, 31, "Début de l'hiver (jour)", 'general');
        $num('winter_end_day', 1, 31, "Fin de l'hiver (jour)", 'general');
        foreach (array('start', 'end') as $k) {
            if (is_numeric($cfg['winter_' . $k]) && is_numeric($cfg['winter_' . $k . '_day']) && !checkdate(intval($cfg['winter_' . $k]), intval($cfg['winter_' . $k . '_day']), 2024)) {
                $E['general'][] = ($k == 'start' ? 'Début' : 'Fin') . " de l'hiver : le " . intval($cfg['winter_' . $k . '_day']) . " " . self::MONTHS[intval($cfg['winter_' . $k])] . " n'existe pas.";
            }
        }
        $num('stats_keep', 30, 1500, 'Conservation des statistiques', 'stats');

        // ---------------- Position VMC (nécessaire à la VMC ET au chauffage) ----------------
        if ($vmcOn || $heatOn) {
            $mod = $vmcOn ? 'vmc' : 'heat';
            $posCmd = $cmdCheck('vmc_pos_cmd', 'Position ventilation (vitesse actuelle de la VMC)', $mod, 'info', true);
            $seen = array();
            for ($i = 1; $i <= 4; $i++) {
                $pv = trim((string) $cfg['vmc_p' . $i]);
                if ($pv === '' && $i < 4) {
                    $E[$mod][] = "Correspondance position → vitesse $i : valeur obligatoire.";
                } elseif ($pv !== '' && in_array($pv, $seen, true)) {
                    $E[$mod][] = "Correspondance position → vitesse : la valeur \"$pv\" est utilisée pour deux vitesses.";
                }
                $seen[] = $pv;
            }
            if (is_object($posCmd) && self::readSpeed($cfg) === null) {
                $W[$mod][] = "Position ventilation : la valeur actuelle (\"" . $posCmd->execCmd() . "\") ne correspond à aucune vitesse connue.";
            }
        }
        if ($vmcOn) {
            for ($i = 1; $i <= 3; $i++) {
                $cmdCheck('vmc_s' . $i . '_cmd', "Commande vitesse $i", 'vmc', 'action', true, array('subtypes' => array('other')));
            }
            $cmdCheck('vmc_s4_cmd', 'Commande vitesse 4 (boost)', 'vmc', 'action', false, array('subtypes' => array('other')));
            if ($cfg['vmc_s4_cmd'] === '') {
                $W['vmc'][] = "Pas de commande vitesse 4 : les commandes Boost du plugin seront refusées.";
            }
            $cmdCheck('vmc_pre_cmd', 'Lecture du mode ModBus', 'vmc', 'info', false);
            $cmdCheck('vmc_pre_action_cmd', 'Passer en mode ModBus', 'vmc', 'action', false, array('subtypes' => array('other')));
            if ($cfg['vmc_pre_cmd'] !== '' && $cfg['vmc_pre_action_cmd'] === '') {
                $E['vmc'][] = "Mode ModBus : la lecture seule ne sert à rien, choisissez aussi la commande « Passer en mode ModBus » (registre 8000, valeur 1).";
            }
            if ($cfg['vmc_pre_action_cmd'] === '') {
                $W['vmc'][] = "Pas de commande « Passer en mode ModBus » : après une coupure de courant, la VMC ignorera les vitesses tant que le registre 8000 n'est pas remis à 1.";
            }
            $num('vmc_pre_every', 0, 240, 'Renvoi du mode ModBus', 'vmc');
            $cmdCheck('modbus_ok_cmd', 'Communication ModBus (Cycle OK)', 'vmc', 'info', false);
            $num('modbus_fail_min', 1, 60, 'Délai avant alerte ModBus', 'vmc');
            if ($cfg['vmc_pre_cmd'] !== '' && trim((string) $cfg['vmc_pre_value']) === '') {
                $E['vmc'][] = "Mode de pilotage (ModBus) : la valeur attendue est obligatoire.";
            }
            $cmdCheck('co2_cmd', 'CO2', 'vmc', 'info', false, array('min' => 300, 'max' => 5000, 'subtypes' => array('numeric')));
            $cmdCheck('hx_supply_cmd', 'Température air soufflé (après l\'échangeur)', 'vmc', 'info', false, array('min' => -30, 'max' => 60, 'subtypes' => array('numeric')));
            $cmdCheck('hx_exhaust_cmd', 'Température air rejeté (après l\'échangeur)', 'vmc', 'info', false, array('min' => -30, 'max' => 60, 'subtypes' => array('numeric')));
            if (($cfg['hx_supply_cmd'] !== '' || $cfg['hx_exhaust_cmd'] !== '') && $cfg['temp_out_cmd'] === '') {
                $W['vmc'][] = "Efficacité de l'échangeur : il faut aussi le thermomètre extérieur (onglet Maison).";
            }
            $cmdCheck('preheat_cmd', 'État du préchauffage', 'vmc', 'info', false, array('subtypes' => array('binary', 'numeric', 'string')));
            $cmdCheck('preheat_power_cmd', 'Puissance du préchauffage', 'vmc', 'info', false, array('min' => 0, 'max' => 100, 'subtypes' => array('numeric')));
            if ($cfg['preheat_cmd'] !== '' && trim((string) $cfg['preheat_on_value']) === '') {
                $E['vmc'][] = "Préchauffage : la valeur « actif » est obligatoire (2 pour le registre 4060 de la Flair).";
            }
            if (!in_array($cfg['vmc_temp_ref'], array('down', 'up', 'avg'))) {
                $E['vmc'][] = "Température de référence de la VMC : choix invalide.";
            } elseif ($cfg['vmc_temp_ref'] != 'down' && $cfg['temp_up_cmd'] === '') {
                $E['vmc'][] = "La VMC doit utiliser la température du haut (ou la moyenne), mais aucune température du haut n'est renseignée (onglet Maison).";
            }
            $num('vmc_off_speed', 1, 3, 'Vitesse quand on coupe le mode auto', 'vmc');
            $num('vmc_fallback_speed', 1, 3, 'Vitesse de repli', 'vmc');
            $num('vmc_dwell', 0, 120, 'Temps minimum entre deux changements', 'vmc');
            $num('vmc_hyst', 0.1, 2, 'Battement de température', 'vmc');
            $num('vmc_delta', 0, 10, 'Écart « dehors plus frais »', 'vmc');
            $num('vmc_cold_out', -10, 30, 'Seuil « dehors froid »', 'vmc');
            if ($cfg['co2_cmd'] !== '') {
                $num('co2_high', 500, 5000, 'Seuil CO2 haut', 'vmc');
                $num('co2_low', 400, 5000, 'Seuil CO2 de retour', 'vmc');
                if (is_numeric($cfg['co2_high']) && is_numeric($cfg['co2_low']) && floatval($cfg['co2_low']) >= floatval($cfg['co2_high'])) {
                    $E['vmc'][] = "CO2 : le seuil de retour (" . $cfg['co2_low'] . " ppm) doit être inférieur au seuil haut (" . $cfg['co2_high'] . " ppm).";
                }
                $num('co2_speed', 1, 3, 'Vitesse minimum si CO2 élevé', 'vmc');
            }
            foreach (array('w' => 'Hiver', 's' => 'Été') as $p => $lab) {
                $num($p . '_t_fresh', 15, 35, "$lab : seuil zone fraîche", 'vmc');
                foreach (array('fresh_c', 'fresh_n', 'comf_c', 'comf_n', 'hot_n') as $z) {
                    $num($p . '_sp_' . $z, 1, 3, "$lab : vitesse de zone", 'vmc');
                }
                if (!in_array($cfg['vmc_mode_' . $p], array('temp', 'bypass'))) {
                    $E['vmc'][] = "$lab : mode de décision invalide.";
                } elseif ($cfg['vmc_mode_' . $p] == 'bypass' && $cfg['bypass_cmd'] === '') {
                    $E['vmc'][] = "$lab : le mode « bypass » demande la commande de statut du bypass.";
                }
                if ($p == 'w') {
                    $wBase = max(intval($cfg['w_sp_comf_n']), intval($cfg['w_sp_fresh_n']));
                    foreach ($cfg['vmc_windows'] as $w) {
                        if ((!isset($w['season']) || $w['season'] != 'summer') && isset($w['speed']) && intval($w['speed']) > $wBase) {
                            $W['vmc'][] = "La plage de renouvellement " . self::rowName($w) . " s'applique aussi en hiver : la VMC passera en vitesse " . intval($w['speed']) . " sur ce créneau. Pour l'éviter : onglet VMC, cadre « Plages de renouvellement », colonne « Saison » de cette ligne → « Été ».";
                        }
                    }
                }
                $num($p . '_t_hot', 15, 35, "$lab : seuil zone chaude", 'vmc');
                $num($p . '_cool_day', 1, 3, "$lab : vitesse de rafraîchissement le jour", 'vmc');
                $num($p . '_cool_night', 1, 3, "$lab : vitesse de rafraîchissement en période calme", 'vmc');
                if (is_numeric($cfg[$p . '_t_fresh']) && is_numeric($cfg[$p . '_t_hot']) && floatval($cfg[$p . '_t_hot']) < floatval($cfg[$p . '_t_fresh'])) {
                    $E['vmc'][] = "$lab : le seuil de la zone chaude (" . self::fmt($cfg[$p . '_t_hot']) . " °C) doit être supérieur ou égal à celui de la zone fraîche (" . self::fmt($cfg[$p . '_t_fresh']) . " °C).";
                }
            }
            if ($cfg['flush_enabled'] == '1') {
                $time('flush_detect_start', 'Aération matinale : début de détection', 'vmc');
                $time('flush_detect_end', 'Aération matinale : fin de détection', 'vmc');
                $time('flush_end', "Aération matinale : fin de l'aération", 'vmc');
                $num('flush_out', 5, 35, 'Aération matinale : température extérieure', 'vmc');
                $num('flush_speed', 1, 3, 'Aération matinale : vitesse', 'vmc');
                $a = self::hm2min($cfg['flush_detect_start']);
                $b = self::hm2min($cfg['flush_detect_end']);
                $c = self::hm2min($cfg['flush_end']);
                if ($a !== null && $b !== null && $c !== null && !($a <= $b && $b <= $c)) {
                    $E['vmc'][] = "Aération matinale : il faut début de détection ≤ fin de détection ≤ fin de l'aération.";
                }
            }
            $num('boost_max', 1, 60, 'Vitesse 4 mise à la main : respectée au plus', 'vmc');
            $names = array();
            foreach ($cfg['vmc_boosts'] as $i => $b) {
                $n = 'Boost #' . ($i + 1) . ($b['name'] !== '' ? ' (' . $b['name'] . ')' : '');
                if ($b['name'] === '') {
                    $E['vmc'][] = "$n : donnez-lui un nom (c'est le nom de la commande).";
                } elseif (isset($names[mb_strtolower($b['name'])])) {
                    $E['vmc'][] = "$n : deux boosts ont le même nom (les commandes d'un équipement doivent avoir des noms différents).";
                } else {
                    $names[mb_strtolower($b['name'])] = true;
                    foreach (self::CMDS as $lid => $d) {
                        if (mb_strtolower($d['name']) === mb_strtolower($b['name'])) {
                            $E['vmc'][] = "$n : nom déjà pris par la commande « " . $d['name'] . " ».";
                        }
                    }
                }
                if (!in_array($b['speed'], array('1', '2', '3', '4'), true)) {
                    $E['vmc'][] = "$n : vitesse invalide (1 à 4).";
                }
                if (!is_numeric($b['minutes']) || intval($b['minutes']) != floatval($b['minutes']) || intval($b['minutes']) < 1 || intval($b['minutes']) > 1440) {
                    $E['vmc'][] = "$n : durée invalide (1 à 1440 min, en minutes entières).";
                }
            }
            $num('vmc_manual_hold', 0, 240, "Respect d'un réglage manuel", 'vmc');
            if (!in_array($cfg['vmc_speed_notif'], array('log', 'vmc'))) {
                $E['notif'][] = 'Changements de vitesse de la VMC : choix inconnu « ' . $cfg['vmc_speed_notif'] . ' ».';
            }
            $rowsCheck($cfg['vmc_windows'], 'Plage de renouvellement', 'vmc', function ($w, $n) use (&$E) {
                if (!isset($w['speed']) || !in_array((string) $w['speed'], array('1', '2', '3'))) {
                    $E['vmc'][] = "$n : vitesse invalide (1, 2 ou 3 — la vitesse 4 est réservée au boost).";
                }
                if (!isset($w['season']) || !in_array($w['season'], array('all', 'winter', 'summer'))) {
                    $E['vmc'][] = "$n : saison invalide.";
                }
            });
            $rowsCheck($cfg['vmc_quiet'], 'Plage de silence', 'vmc', function ($q, $n) use (&$E) {
                if (!isset($q['max']) || !in_array((string) $q['max'], array('1', '2', '3'))) {
                    $E['vmc'][] = "$n : vitesse maximum invalide (1, 2 ou 3).";
                }
            });
        }

        // ---------------- Chauffage ----------------
        if ($heatOn) {
            $any = false;
            $quietConflicts = array();
            foreach (array('b1', 'b2') as $b) {
                if ($cfg[$b . '_enabled'] != '1') {
                    continue;
                }
                $any = true;
                $nm = 'Batterie « ' . $cfg[$b . '_name'] . ' »';
                if (trim((string) $cfg[$b . '_name']) === '') {
                    $E['heat'][] = "Batterie " . substr($b, 1) . " : le nom est obligatoire.";
                }
                $cmdCheck($b . '_on_cmd', "$nm : commande d'allumage", 'heat', 'action', true, array('subtypes' => array('other')));
                $cmdCheck($b . '_off_cmd', "$nm : commande d'extinction", 'heat', 'action', true, array('subtypes' => array('other')));
                $cmdCheck($b . '_state_cmd', "$nm : état", 'heat', 'info', false, array('binary' => true, 'subtypes' => array('binary', 'numeric')));
                if ($cfg[$b . '_state_cmd'] === '') {
                    $W['heat'][] = "$nm : sans commande d'état, le plugin suppose que chaque ordre a fonctionné (pas de vérification ni de relance).";
                }
                $num($b . '_power', 0, 5000, "$nm : puissance en W (plaque signalétique de la batterie)", 'heat');
                if (!in_array((string) $cfg[$b . '_min_speed'], array('2', '3'))) {
                    $E['heat'][] = "$nm : vitesse VMC minimum invalide (2 ou 3 ; jamais 1).";
                }
                if (!in_array($cfg[$b . '_outside'], array('off', 'eco', 'normal', 'comfort'))) {
                    $E['heat'][] = "$nm : comportement hors plage invalide.";
                }
                $num($b . '_min_on', 0, 240, "$nm : durée minimum de marche", 'heat');
                $num($b . '_min_off', 0, 240, "$nm : durée minimum d'arrêt", 'heat');
                $num($b . '_max_on', 0, 1440, "$nm : durée maximum de marche continue", 'heat');
                $num($b . '_rest', 0, 240, "$nm : durée de la pause", 'heat');
                $num($b . '_safety', 15, 35, "$nm : température de sécurité", 'heat');
                if (is_numeric($cfg[$b . '_min_on']) && floatval($cfg[$b . '_min_on']) < 5) {
                    $W['heat'][] = "$nm : une durée minimum de marche sous 5 min peut faire cycler la batterie trop souvent.";
                }
                if (is_numeric($cfg[$b . '_max_on']) && floatval($cfg[$b . '_max_on']) > 0 && is_numeric($cfg[$b . '_min_on']) && floatval($cfg[$b . '_max_on']) < floatval($cfg[$b . '_min_on'])) {
                    $E['heat'][] = "$nm : la durée maximum de marche (" . $cfg[$b . '_max_on'] . " min) est plus courte que la durée minimum (" . $cfg[$b . '_min_on'] . " min).";
                }
                if (is_numeric($cfg[$b . '_safety']) && is_numeric($cfg['heat_c_max']) && floatval($cfg[$b . '_safety']) <= floatval($cfg['heat_c_max'])) {
                    $W['heat'][] = "$nm : la température de sécurité (" . self::fmt($cfg[$b . '_safety']) . " °C) n'est pas au-dessus de la consigne maximum (" . self::fmt($cfg['heat_c_max']) . " °C).";
                }
                // Conflit vitesse minimum / calendrier de silence.
                if ($vmcOn) {
                    foreach ($cfg['vmc_quiet'] as $qi => $q) {
                        if (isset($q['max']) && intval($q['max']) < intval($cfg[$b . '_min_speed'])) {
                            // Regroupé par plage après la boucle (une remarque par plage).
                            $quietConflicts[$qi][] = '« ' . $cfg[$b . '_name'] . ' » (vitesse ' . intval($cfg[$b . '_min_speed']) . ')';
                        }
                    }
                } else {
                    $W['heat'][] = "$nm : le module VMC est désactivé, le plugin ne montera pas la VMC à la vitesse " . intval($cfg[$b . '_min_speed']) . " : la batterie ne chauffera que si la VMC y est déjà.";
                }
            }
            foreach ($quietConflicts as $qi => $bats) {
                $q = $cfg['vmc_quiet'][$qi];
                $W['heat'][] = "Plage de silence " . self::rowName($q) . " (" . self::daysLabel(isset($q['days']) ? $q['days'] : array()) . ") : la VMC y est limitée à la vitesse " . intval($q['max'])
                    . ", donc " . (count($bats) > 1 ? 'les batteries ' . implode(' et ', $bats) . ' ne chaufferont' : 'la batterie ' . $bats[0] . ' ne chauffera') . " pas pendant cette plage.";
            }
            if (!$any) {
                $E['heat'][] = "Activez au moins une batterie.";
            }
            $num('heat_c_min', 5, 30, 'Consigne minimum', 'heat');
            $num('heat_c_max', 10, 30, 'Consigne maximum', 'heat');
            $num('heat_consigne', 5, 30, 'Consigne Normal de départ', 'heat');
            $num('heat_eco', 5, 30, 'Consigne ECO de départ', 'heat');
            $num('heat_force_default', 5, 720, 'Durée par défaut du chauffage forcé', 'heat');
            if ($cfg['night_lock_enabled'] == '1') {
                $la = self::hm2min($cfg['night_lock_start']);
                $lb = self::hm2min($cfg['night_lock_end']);
                if ($la === null || $lb === null || $la == $lb) {
                    $E['heat'][] = "Verrou de nuit : heures de début et de fin invalides.";
                } else {
                    if ($cfg['night_lock_frost'] !== '' && (!is_numeric($cfg['night_lock_frost']) || floatval($cfg['night_lock_frost']) < 5 || floatval($cfg['night_lock_frost']) > 20)) {
                        $E['heat'][] = "Verrou de nuit : la température hors-gel doit être entre 5 et 20 °C (ou vide).";
                    }
                    // Plages de chauffage qui chevauchent le verrou : elles ne chaufferont pas.
                    foreach ($cfg['heat_slots'] as $sl) {
                        if (!isset($sl['mode']) || $sl['mode'] == 'off') {
                            continue;
                        }
                        $sa = self::hm2min(isset($sl['start']) ? $sl['start'] : '');
                        $sb = self::hm2min(isset($sl['end']) ? $sl['end'] : '');
                        if ($sa === null || $sb === null || $sa == $sb) {
                            continue;
                        }
                        $overlap = false;
                        for ($m = $sa; $m != $sb; $m = ($m + 5) % 1440) {
                            if (self::inWindow($m, $la, $lb)) {
                                $overlap = true;
                                break;
                            }
                        }
                        if ($overlap) {
                            $W['heat'][] = "La plage " . self::rowName($sl) . " chevauche le verrou de nuit (" . self::min2hm($la) . "–" . self::min2hm($lb) . ") : elle ne chauffera pas sur ce créneau.";
                        }
                    }
                }
            } else {
                $W['heat'][] = "Verrou de nuit désactivé : rien n'empêche de chauffer la nuit en cas d'erreur de réglage.";
            }
            if (!in_array($cfg['heat_mode_default'], array('normal', 'eco'))) {
                $E['heat'][] = "Mode de chauffage au démarrage : choix invalide.";
            }
            if (is_numeric($cfg['heat_c_min']) && is_numeric($cfg['heat_c_max'])) {
                $cmin = floatval($cfg['heat_c_min']);
                $cmax = floatval($cfg['heat_c_max']);
                if ($cmin >= $cmax) {
                    $E['heat'][] = "La consigne minimum doit être inférieure à la consigne maximum.";
                } else {
                    foreach (array('heat_consigne' => 'Normal', 'heat_eco' => 'ECO') as $k => $lab) {
                        if (is_numeric($cfg[$k]) && (floatval($cfg[$k]) < $cmin || floatval($cfg[$k]) > $cmax)) {
                            $E['heat'][] = "La consigne $lab de départ (" . self::fmt($cfg[$k]) . " °C) doit être entre la consigne minimum et maximum (" . self::fmt($cmin) . " à " . self::fmt($cmax) . " °C).";
                        }
                    }
                    if ($cmax - $cmin < self::ECO_GAP) {
                        $E['heat'][] = "La plage de consignes est trop étroite pour avoir ECO sous Normal.";
                    }
                }
            }
            if (is_numeric($cfg['heat_consigne']) && is_numeric($cfg['heat_eco']) && floatval($cfg['heat_eco']) > floatval($cfg['heat_consigne']) - self::ECO_GAP) {
                $E['heat'][] = "La consigne ECO (" . self::fmt($cfg['heat_eco']) . " °C) doit être inférieure d'au moins " . self::fmt(self::ECO_GAP) . " °C à la consigne Normal (" . self::fmt($cfg['heat_consigne']) . " °C).";
            }
            $num('heat_hyst_low', 0, 2, 'Écart de démarrage sous la consigne', 'heat');
            $num('heat_hyst_high', 0, 2, "Écart d'arrêt au-dessus de la consigne", 'heat');
            $num('heat_postvent', 0, 15, 'Ventilation après arrêt', 'heat');
            $num('heat_out_max', -20, 30, 'Pas de chauffe au-dessus de (extérieur)', 'heat', true);
            if ($cfg['heat_out_max'] !== '' && $cfg['temp_out_cmd'] === '') {
                $E['heat'][] = "« Pas de chauffe au-dessus de » nécessite la température extérieure (onglet Maison).";
            }
            if ($cfg['assist_enabled'] == '1') {
                if ($cfg['b1_enabled'] != '1' || $cfg['b2_enabled'] != '1') {
                    $W['heat'][] = "L'appoint automatique nécessite les deux batteries : il est ignoré.";
                }
                $num('assist_after', 5, 600, "Appoint : délai avant d'aider", 'heat');
                $num('assist_gap', 0.1, 5, 'Appoint : écart sous la consigne', 'heat');
            }
            $rowsCheck($cfg['heat_slots'], 'Plage de chauffage', 'heat', function ($s, $n) use (&$E, &$W, &$cfg) {
                $bats = isset($s['batteries']) && is_array($s['batteries']) ? $s['batteries'] : array();
                if (empty($bats)) {
                    $E['heat'][] = "$n : cochez au moins une batterie (Bas et/ou Haut).";
                }
                foreach ($bats as $b) {
                    if (!in_array($b, array('b1', 'b2'))) {
                        $E['heat'][] = "$n : batterie invalide.";
                    } elseif ($cfg[$b . '_enabled'] != '1') {
                        $W['heat'][] = "$n : la batterie « " . $cfg[$b . '_name'] . " » est désactivée, ignorée pour cette plage.";
                    }
                }
                if (!isset($s['mode']) || !in_array($s['mode'], array('normal', 'eco', 'comfort', 'consigne', 'forced', 'off'))) {
                    $E['heat'][] = "$n : mode invalide.";
                } elseif ($s['mode'] == 'consigne' && (!isset($s['consigne']) || !is_numeric($s['consigne']) || floatval($s['consigne']) < 5 || floatval($s['consigne']) > 30)) {
                    $E['heat'][] = "$n : consigne invalide (entre 5 et 30 °C).";
                }
            });
        }

        // ---------------- Délestage ----------------
        if ($on('shed')) {
            $cmdCheck('shed_power_cmd', 'Puissance instantanée (Linky)', 'shed', 'info', true, array('min' => 0, 'max' => 36000, 'subtypes' => array('numeric')));
            $num('shed_limit', 500, 36000, 'Seuil de délestage', 'shed');
            $num('shed_delay', 5, 300, 'Délai avant la 2e coupure', 'shed');
            $num('shed_margin', 0, 5000, 'Marge de réenclenchement', 'shed');
            $num('shed_restore', 1, 120, 'Délai avant réenclenchement', 'shed');
            if (!in_array($cfg['shed_first'], array('b1', 'b2'))) {
                $E['shed'][] = "Ordre de délestage invalide.";
            }
            if (!$heatOn) {
                $W['shed'][] = "Le module chauffage est désactivé : le délestage n'a rien à couper.";
            }
            $p = floatval($cfg['b1_power']) + floatval($cfg['b2_power']);
            if (is_numeric($cfg['shed_limit']) && $p > 0 && $p > floatval($cfg['shed_limit'])) {
                $W['shed'][] = "Les deux batteries ensemble (" . round($p) . " W) dépassent à elles seules le seuil (" . $cfg['shed_limit'] . ") : le délestage interviendra très souvent.";
            }
        }

        // ---------------- Filtre ----------------
        if ($on('filter')) {
            $cmdCheck('filter_cmd', 'Statut du filtre', 'filter', 'info', true, array('binary' => true, 'subtypes' => array('binary', 'numeric', 'string')));
            if (trim((string) $cfg['filter_value']) === '') {
                $E['filter'][] = "Valeur « filtre à nettoyer » : obligatoire (en général 1).";
            }
            $time('filter_notify_time', 'Heure de la notification filtre', 'filter');
            $cmdCheck('filter_reset_cmd', 'Remise à zéro du filtre', 'filter', 'action', false, array('subtypes' => array('other')));
            if (!in_array($cfg['filter_repeat'], array('daily', 'once'))) {
                $E['filter'][] = "Répétition de la notification : choix invalide.";
            }
        }

        // ---------------- Notifications ----------------
        $time('rest_start', 'Début du repos', 'notif');
        $time('rest_end', 'Fin du repos', 'notif');
        foreach ($cfg['notifications'] as $i => $n) {
            $lab = 'Destinataire #' . ($i + 1);
            if (empty($n['cmd_id'])) {
                $E['notif'][] = "$lab : aucune commande choisie.";
                continue;
            }
            $cmdCheck('', $lab, 'notif', 'action', true, array('raw' => $n['cmd_id'], 'subtypes' => array('message')));
            $any = false;
            foreach (array_merge(array('all'), self::NOTIF_CATS) as $c) {
                if (!empty($n['n_' . $c]) && $n['n_' . $c] == '1') {
                    $any = true;
                }
            }
            if (!$any) {
                $W['notif'][] = "$lab : aucune case cochée, ce destinataire ne recevra rien.";
            }
        }
        if (empty($cfg['notifications'])) {
            $W['notif'][] = "Aucun destinataire : vous ne serez prévenu de rien (délestage, panne de batterie...).";
        }

        // ---------------- Rapport des statistiques ----------------
        if ($cfg['report_enabled'] == '1') {
            $num('report_days', 3, 90, 'Rapport : période analysée', 'stats');
            $num('report_chart_days', 7, 60, 'Rapport : durée des graphiques', 'stats');
            if (empty($cfg['report_schedule'])) {
                $E['stats'][] = "Rapport : aucun jour ni heure d'envoi.";
            }
            foreach ($cfg['report_schedule'] as $i => $r) {
                if (empty($r['days']) || ThermoFlair325::hm2min(isset($r['time']) ? $r['time'] : '') === null) {
                    $E['stats'][] = "Rapport : l'envoi #" . ($i + 1) . " n'a pas de jour ou d'heure valide.";
                }
            }
            if (empty($cfg['report_recipients'])) {
                $E['stats'][] = "Rapport : aucun destinataire (une commande mail par exemple).";
            }
            foreach ($cfg['report_recipients'] as $i => $n) {
                if (!empty($n['cmd_id'])) {
                    $cmdCheck('', 'Rapport : destinataire #' . ($i + 1), 'stats', 'action', true, array('raw' => $n['cmd_id'], 'subtypes' => array('message')));
                }
            }
            if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
                $W['stats'][] = "Rapport : l'extension PHP GD (avec FreeType) est absente, le rapport partira sans graphiques.";
            }
        }

        $ok = array();
        foreach (array('vmc', 'heat', 'shed', 'filter', 'stats') as $m) {
            $ok[$m] = empty($E['general']) && empty($E[$m]);
        }
        // Le délestage ne fonctionne que si le chauffage est valide.
        $ok['shed'] = $ok['shed'] && $ok['heat'];
        return array('errors' => $E, 'warnings' => $W, 'ok' => $ok);
    }

    /* ================================================================ */
    /* État dynamique (runtime)                                          */
    /* ================================================================ */

    public function getRuntime() {
        $r = config::byKey('runtime::' . $this->getId(), self::PLUGIN, array());
        if (is_string($r)) {
            $r = json_decode($r, true);
        }
        return is_array($r) ? $r : array();
    }

    public function saveRuntime($r) {
        config::save('runtime::' . $this->getId(), $r, self::PLUGIN);
    }

    public static function initRuntime($cfg, &$rt) {
        foreach (array('vmc', 'heat', 'filter', 'shed', 'alerts', 'acc', 'preheat', 'hx') as $k) {
            if (!isset($rt[$k]) || !is_array($rt[$k])) {
                $rt[$k] = array();
            }
        }
        $rt['vmc'] += array(
            'auto' => intval($cfg['vmc_auto_default']) ? 1 : 0,
            'auto_off_pending' => 0,
            'ovr' => null,
            'flags' => array(),
            'last_change' => 0,
            'last_sent' => null,
            'expect' => null,
            'expect_at' => 0,
            'retries' => 0,
            'pos_last' => null,
            'pre_sent_at' => 0,
            'target' => null,
            'reason' => '',
            'source' => '',
        );
        $rt['heat'] += array(
            'on' => intval($cfg['heat_on_default']) ? 1 : 0,
            'consigne' => floatval($cfg['heat_consigne']),
            'eco' => floatval($cfg['heat_eco']),
            'mode' => ($cfg['heat_mode_default'] == 'eco') ? 'eco' : 'normal',
            'lock' => intval($cfg['night_lock_enabled']) ? 1 : 0,
            'force' => array(),
            'flags' => array(),
            'assist' => false,
            'blocked_note' => array(),
            'lock_note' => array(),
        );
        // Le réglage « verrou de nuit » de la configuration s'applique dès
        // qu'il change ; entre-temps, les commandes ON/OFF ont la main.
        $lc = intval($cfg['night_lock_enabled']) ? 1 : 0;
        if (!isset($rt['heat']['lock_cfg']) || intval($rt['heat']['lock_cfg']) !== $lc) {
            $rt['heat']['lock'] = $lc;
            $rt['heat']['lock_cfg'] = $lc;
        }
        if (!is_array($rt['heat']['force'])) {
            $rt['heat']['force'] = array();
        }
        foreach (array('b1', 'b2') as $b) {
            if (!isset($rt['heat'][$b]) || !is_array($rt['heat'][$b])) {
                $rt['heat'][$b] = array();
            }
            $rt['heat'][$b] += array(
                'actual' => null,
                'cmd_state' => null,
                'since' => 0,
                'expect' => null,
                'expect_at' => 0,
                'retries' => 0,
                'rest_until' => 0,
                'postvent_until' => 0,
                'tick' => 0,
                'day' => '',
                'today_sec' => 0,
                'yesterday_sec' => 0,
                'reason' => '',
                'target' => null,
            );
        }
        $rt['filter'] += array('last' => null, 'cleaned' => null, 'notified_date' => null, 'dirty' => false);
        $rt['preheat'] += array('on' => null, 'since' => 0, 'pending' => null, 'pending_since' => 0, 'day' => '', 'today_sec' => 0, 'last_t' => 0, 'notified_on' => 0);
        $rt['hx'] += array('eff' => null, 'eff_exh' => null, 'why' => '');
        $rt['shed'] += array('active' => array(), 'at' => 0, 'power' => null);
    }

    /* ================================================================ */
    /* VMC : décision automatique (fonction pure, testable hors Jeedom)  */
    /* ================================================================ */

    /*
     * Surchauffe due au soleil : maison très isolée avec de grands vitrages.
     * Le bas ne peut pas monter à 26-28 °C tout seul quand il fait froid
     * dehors : si c'est le cas sans chauffage récent, c'est le soleil.
     * Conditions : aucune batterie en marche ni arrêtée depuis moins d'1 h,
     * bas ≥ consigne Normal + 2 °C, au moins 10 °C de plus que dehors,
     * en journée (capteur de lumière au moins à moitié, sinon 9h-19h).
     * Retourne une phrase, ou null.
     */
    // Libellés officiels OpenWeatherMap (anglais, stables : codés en dur,
    // comme dans RoboProg / LandroidRTK). Constante et non propriété
    // statique : Jeedom prendrait une propriété pour une colonne SQL.
    const OWM_CODE_LABELS = array(
        200 => 'thunderstorm with light rain', 201 => 'thunderstorm with rain', 202 => 'thunderstorm with heavy rain',
        210 => 'light thunderstorm', 211 => 'thunderstorm', 212 => 'heavy thunderstorm',
        221 => 'ragged thunderstorm', 230 => 'thunderstorm with light drizzle', 231 => 'thunderstorm with drizzle', 232 => 'thunderstorm with heavy drizzle',
        300 => 'light intensity drizzle', 301 => 'drizzle', 302 => 'heavy intensity drizzle',
        310 => 'light intensity drizzle rain', 311 => 'drizzle rain', 312 => 'heavy intensity drizzle rain',
        313 => 'shower rain and drizzle', 314 => 'heavy shower rain and drizzle', 321 => 'shower drizzle',
        500 => 'light rain', 501 => 'moderate rain', 502 => 'heavy intensity rain', 503 => 'very heavy rain',
        504 => 'extreme rain', 511 => 'freezing rain', 520 => 'light intensity shower rain',
        521 => 'shower rain', 522 => 'heavy intensity shower rain', 531 => 'ragged shower rain',
        600 => 'light snow', 601 => 'snow', 602 => 'heavy snow', 611 => 'sleet', 612 => 'light shower sleet',
        613 => 'shower sleet', 615 => 'light rain and snow', 616 => 'rain and snow',
        620 => 'light shower snow', 621 => 'shower snow', 622 => 'heavy shower snow',
        701 => 'mist', 711 => 'smoke', 721 => 'haze', 731 => 'sand/dust whirls', 741 => 'fog',
        751 => 'sand', 761 => 'dust', 762 => 'volcanic ash', 771 => 'squalls', 781 => 'tornado',
        800 => 'clear sky', 801 => 'few clouds', 802 => 'scattered clouds', 803 => 'broken clouds', 804 => 'overcast clouds',
    );

    // Libellés WeatherAPI (jour) en secours, si le JSON officiel est
    // injoignable et qu'aucune copie locale n'existe.
    const WEATHERAPI_CODE_LABELS = array(
        1000 => 'Sunny', 1003 => 'Partly cloudy', 1006 => 'Cloudy', 1009 => 'Overcast', 1030 => 'Mist',
        1063 => 'Patchy rain possible', 1066 => 'Patchy snow possible', 1069 => 'Patchy sleet possible', 1072 => 'Patchy freezing drizzle possible',
        1087 => 'Thundery outbreaks possible', 1114 => 'Blowing snow', 1117 => 'Blizzard', 1135 => 'Fog', 1147 => 'Freezing fog',
        1150 => 'Patchy light drizzle', 1153 => 'Light drizzle', 1168 => 'Freezing drizzle', 1171 => 'Heavy freezing drizzle',
        1180 => 'Patchy light rain', 1183 => 'Light rain', 1186 => 'Moderate rain at times', 1189 => 'Moderate rain',
        1192 => 'Heavy rain at times', 1195 => 'Heavy rain', 1198 => 'Light freezing rain', 1201 => 'Moderate or heavy freezing rain',
        1204 => 'Light sleet', 1207 => 'Moderate or heavy sleet', 1210 => 'Patchy light snow', 1213 => 'Light snow',
        1216 => 'Patchy moderate snow', 1219 => 'Moderate snow', 1222 => 'Patchy heavy snow', 1225 => 'Heavy snow', 1237 => 'Ice pellets',
        1240 => 'Light rain shower', 1243 => 'Moderate or heavy rain shower', 1246 => 'Torrential rain shower',
        1249 => 'Light sleet showers', 1252 => 'Moderate or heavy sleet showers', 1255 => 'Light snow showers', 1258 => 'Moderate or heavy snow showers',
        1261 => 'Light showers of ice pellets', 1264 => 'Moderate or heavy showers of ice pellets',
        1273 => 'Patchy light rain with thunder', 1276 => 'Moderate or heavy rain with thunder', 1279 => 'Patchy light snow with thunder', 1282 => 'Moderate or heavy snow with thunder',
    );

    // Codes WMO (Open-Meteo), en français.
    const WMO_CODE_LABELS = array(
        0 => 'ciel dégagé', 1 => 'principalement dégagé', 2 => 'partiellement nuageux', 3 => 'couvert', 45 => 'brouillard', 48 => 'brouillard givrant',
        51 => 'bruine légère', 53 => 'bruine modérée', 55 => 'bruine dense', 56 => 'bruine verglaçante légère', 57 => 'bruine verglaçante dense',
        61 => 'pluie faible', 63 => 'pluie modérée', 65 => 'pluie forte', 66 => 'pluie verglaçante légère', 67 => 'pluie verglaçante forte',
        71 => 'neige faible', 73 => 'neige modérée', 75 => 'neige forte', 77 => 'grains de neige', 80 => 'averses faibles', 81 => 'averses modérées',
        82 => 'averses violentes', 85 => 'averses de neige faibles', 86 => 'averses de neige fortes', 95 => 'orage', 96 => 'orage avec grêle faible', 99 => 'orage avec grêle forte',
    );

    /*
     * Libellés WeatherAPI : comme dans RoboProg / LandroidRTK, récupérés sur
     * le JSON officiel avec une copie locale d'une semaine. Si le service est
     * injoignable : la dernière copie locale, même ancienne, sinon la liste
     * en dur. Jamais appelé pendant un cycle (aperçu de la configuration et
     * messages de test seulement).
     */
    public static function getWeatherApiCodeLabels() {
        $file = jeedom::getTmpFolder(self::PLUGIN) . '/weatherapi_labels.json';
        $cached = null;
        if (file_exists($file)) {
            $cached = json_decode((string) @file_get_contents($file), true);
            if (is_array($cached) && !empty($cached) && (time() - filemtime($file)) < 7 * 86400) {
                return $cached;
            }
        }
        $ctx = stream_context_create(array('http' => array('timeout' => 5)));
        $raw = @file_get_contents('https://www.weatherapi.com/docs/weather_conditions.json', false, $ctx);
        if ($raw !== false) {
            $data = json_decode($raw, true);
            if (is_array($data) && !empty($data)) {
                $labels = array();
                foreach ($data as $e) {
                    if (isset($e['code']) && !empty($e['day'])) {
                        $labels[intval($e['code'])] = $e['day'];
                    }
                }
                if ($labels) {
                    @file_put_contents($file, json_encode($labels));
                    return $labels;
                }
            }
        }
        // Service injoignable : copie locale (même périmée), sinon liste en dur.
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }
        return self::WEATHERAPI_CODE_LABELS;
    }

    // Libellé officiel d'un code (anglais pour OWM / WeatherAPI, français
    // pour WMO), avec le nom du service. null si inconnu.
    public static function getConditionCodeLabel($code, $source = 'auto') {
        if ($code === null || $code === '' || !is_numeric(trim((string) $code))) {
            return null;
        }
        $c = intval(round(floatval($code)));
        $src = ($source && $source != 'auto') ? $source : (($c >= 1000) ? 'weatherapi' : (($c >= 200) ? 'owm' : 'wmo'));
        if ($src == 'owm') {
            return isset(self::OWM_CODE_LABELS[$c]) ? self::OWM_CODE_LABELS[$c] . ' (OpenWeatherMap)' : null;
        }
        if ($src == 'weatherapi') {
            $l = self::getWeatherApiCodeLabels();
            return isset($l[$c]) ? $l[$c] . ' (WeatherAPI)' : null;
        }
        return isset(self::WMO_CODE_LABELS[$c]) ? self::WMO_CODE_LABELS[$c] . ' (Open-Meteo)' : null;
    }

    /*
     * Code météo -> ciel. Reconnaît OpenWeatherMap (200 à 804), WeatherAPI
     * (1000 à 1282) et Open-Meteo / codes WMO (0 à 99) ; « auto » choisit
     * d'après la plage de valeurs (elles ne se chevauchent pas).
     * Retourne null si illisible, sinon :
     *   cat   : clear | few | partly | cloudy | overcast | fog | drizzle | rain | snow | storm
     *   cc    : nébulosité 0 (ciel clair) à 1 (couvert) ;
     *   wet   : 1 si précipitations ;
     *   src   : owm | weatherapi | wmo ; label : texte en français ; icon.
     */
    public static function mapWeather($raw, $source = 'auto') {
        if ($raw === null || $raw === '' || !is_numeric(trim((string) $raw))) {
            return null;
        }
        $c = intval(round(floatval($raw)));
        if ($source == 'auto' || $source === '') {
            $source = ($c >= 1000) ? 'weatherapi' : (($c >= 200) ? 'owm' : 'wmo');
        }
        $cat = null;
        if ($source == 'owm') {
            if ($c >= 200 && $c < 300) {
                $cat = 'storm';
            } elseif ($c >= 300 && $c < 400) {
                $cat = 'drizzle';
            } elseif ($c >= 500 && $c < 600) {
                $cat = 'rain';
            } elseif ($c >= 600 && $c < 700) {
                $cat = 'snow';
            } elseif ($c == 771 || $c == 781) {
                $cat = 'wind';
            } elseif ($c >= 700 && $c < 800) {
                $cat = 'fog';
            } elseif ($c == 800) {
                $cat = 'clear';
            } elseif ($c == 801) {
                $cat = 'few';
            } elseif ($c == 802) {
                $cat = 'partly';
            } elseif ($c == 803) {
                $cat = 'cloudy';
            } elseif ($c == 804) {
                $cat = 'overcast';
            }
        } elseif ($source == 'weatherapi') {
            $m = array(1000 => 'clear', 1003 => 'partly', 1006 => 'cloudy', 1009 => 'overcast', 1030 => 'fog', 1135 => 'fog', 1147 => 'fog',
                1087 => 'storm', 1273 => 'storm', 1276 => 'storm', 1279 => 'storm', 1282 => 'storm');
            if (isset($m[$c])) {
                $cat = $m[$c];
            } elseif (in_array($c, array(1063, 1150, 1153, 1168, 1171, 1180, 1183))) {
                $cat = 'drizzle';
            } elseif (in_array($c, array(1186, 1189, 1192, 1195, 1198, 1201, 1240, 1243, 1246))) {
                $cat = 'rain';
            } elseif (in_array($c, array(1066, 1069, 1072, 1114, 1117, 1204, 1207, 1210, 1213, 1216, 1219, 1222, 1225, 1237, 1249, 1252, 1255, 1258, 1261, 1264))) {
                $cat = 'snow';
            }
        } elseif ($source == 'wmo') {
            if ($c == 0) {
                $cat = 'clear';
            } elseif ($c == 1) {
                $cat = 'few';
            } elseif ($c == 2) {
                $cat = 'partly';
            } elseif ($c == 3) {
                $cat = 'overcast';
            } elseif ($c == 45 || $c == 48) {
                $cat = 'fog';
            } elseif ($c >= 51 && $c <= 57) {
                $cat = 'drizzle';
            } elseif (($c >= 61 && $c <= 67) || ($c >= 80 && $c <= 82)) {
                $cat = 'rain';
            } elseif (($c >= 71 && $c <= 77) || $c == 85 || $c == 86) {
                $cat = 'snow';
            } elseif ($c >= 95 && $c <= 99) {
                $cat = 'storm';
            }
        }
        if ($cat === null) {
            return null;
        }
        $info = array(
            'clear' => array(0, 0, 'ciel dégagé', '☀️'), 'few' => array(0.2, 0, 'quelques nuages', '🌤️'), 'partly' => array(0.45, 0, 'partiellement nuageux', '⛅'),
            'cloudy' => array(0.75, 0, 'nuageux', '🌥️'), 'overcast' => array(1, 0, 'couvert', '☁️'), 'fog' => array(1, 0, 'brume / brouillard', '😶‍🌫️'),
            'drizzle' => array(1, 1, 'bruine', '🌦️'), 'rain' => array(1, 1, 'pluie', '🌧️'), 'snow' => array(1, 1, 'neige', '❄️'), 'storm' => array(1, 1, 'orage', '⛈️'),
            'wind' => array(1, 1, 'grain / tornade', '🌪️'),
        );
        return array('code' => $c, 'src' => $source, 'cat' => $cat, 'cc' => $info[$cat][0], 'wet' => $info[$cat][1], 'label' => $info[$cat][2], 'icon' => $info[$cat][3]);
    }

    public static function solarOverheat($cfg, $ctx, $rt, $now) {
        $T = $ctx['temp_in'];
        $O = $ctx['temp_out'];
        if ($T === null || $O === null) {
            return null;
        }
        foreach (array('b1', 'b2') as $b) {
            $hb = isset($rt['heat'][$b]) ? $rt['heat'][$b] : array();
            if (!empty($hb['actual']) || (!empty($hb['since']) && ($now - intval($hb['since'])) < 3600 && isset($hb['actual']) && $hb['actual'] === false)) {
                return null;
            }
        }
        $c = isset($rt['heat']['consigne']) ? floatval($rt['heat']['consigne']) : floatval($cfg['heat_consigne']);
        if ($T < $c + 2 || $T - $O < 10) {
            return null;
        }
        $hh = intval(date('G', $now));
        $day = ($ctx['lux'] !== null) ? ($ctx['lux'] >= 0.5 * floatval($cfg['lux_max']) || ($hh >= 9 && $hh < 19)) : ($hh >= 9 && $hh < 19);
        if (!$day) {
            return null;
        }
        return '☀️ soleil par les vitrages : ' . self::fmt($T) . ' °C dedans pour ' . self::fmt($O) . ' °C dehors, sans chauffage';
    }

    // Température d'une zone : 'down' (bas), 'up' (haut, sinon bas), 'avg'.
    public static function zoneTemp($ctx, $ref) {
        if ($ref == 'up') {
            return ($ctx['temp_up'] !== null) ? $ctx['temp_up'] : $ctx['temp_in'];
        }
        if ($ref == 'avg') {
            return ($ctx['temp_in'] !== null && $ctx['temp_up'] !== null) ? ($ctx['temp_in'] + $ctx['temp_up']) / 2 : $ctx['temp_in'];
        }
        return $ctx['temp_in'];
    }

    public static function houseTemp($cfg, $ctx) {
        if ($cfg['vmc_temp_ref'] == 'up') {
            return $ctx['temp_up'];
        }
        if ($cfg['vmc_temp_ref'] == 'avg') {
            return ($ctx['temp_in'] !== null && $ctx['temp_up'] !== null) ? ($ctx['temp_in'] + $ctx['temp_up']) / 2 : null;
        }
        return $ctx['temp_in'];
    }

    /*
     * $ctx : temp_in, temp_up, temp_out, co2, sup, ext, season.
     * Retour : speed, reason, source (fallback|flush|window|thermal|co2),
     * rows (diagnostic), cap (plafond de silence en cours ou null).
     * Le plafond de silence est appliqué ICI à toutes les décisions
     * automatiques (y compris plages et aération).
     */
    public static function vmcDecide($cfg, $ctx, &$flags, $now) {
        $rows = array();
        $nowMin = self::nowMin($now);
        $dow = intval(date('N', $now));
        $today = date('Y-m-d', $now);
        $season = $ctx['season'];
        $p = ($season == 'summer') ? 's_' : 'w_';
        $T = self::houseTemp($cfg, $ctx);
        $O = $ctx['temp_out'];
        $h = floatval($cfg['vmc_hyst']);
        $cap = self::quietCap($cfg, $now);

        $rows[] = array('label' => 'Saison', 'state' => null, 'detail' => self::seasonLabel($season));
        $mode = ($cfg['vmc_mode_' . ($season == 'summer' ? 's' : 'w')] == 'bypass') ? 'bypass' : 'temp';
        $byp = isset($ctx['bypass']) ? $ctx['bypass'] : null;
        $useBypass = ($mode == 'bypass' && $byp !== null);
        $rows[] = array('label' => 'Mode de décision', 'state' => null, 'detail' => $mode == 'bypass'
            ? ($useBypass ? 'bypass de la VMC' : 'bypass de la VMC — statut illisible, on utilise les températures')
            : 'températures');
        if ($cfg['bypass_cmd'] !== '') {
            $rows[] = array('label' => 'Bypass de la VMC', 'state' => $byp, 'detail' => $byp === null ? 'statut illisible' : ($byp ? 'ouvert : l\'air neuf ne passe pas par l\'échangeur' : 'fermé : l\'échangeur récupère la chaleur'));
        }
        $rows[] = array('label' => 'Plage de silence', 'state' => $cap !== null,
            'detail' => $cap !== null ? '« ' . $cap['label'] . ' » jusqu\'à ' . $cap['end'] . ' : vitesse ' . $cap['max'] . ' maximum' : 'aucune en cours');

        $result = null;
        if (!$useBypass && ($T === null || $O === null)) {
            $rows[] = array('label' => 'Températures disponibles', 'state' => false, 'detail' => 'Maison : ' . self::fmt($T) . ' °C, extérieur : ' . self::fmt($O) . ' °C');
            $result = array('speed' => intval($cfg['vmc_fallback_speed']), 'reason' => 'capteur de température indisponible, vitesse de repli', 'source' => 'fallback');
        }

        $hotDay = false;
        $quiet = ($cap !== null);
        $sp = function ($k) use ($cfg, $p) {
            return max(1, min(3, intval($cfg[$p . $k])));
        };
        if ($result === null && $useBypass) {
            // --- Mode bypass : la VMC décide elle-même du rafraîchissement ---
            if ($byp) {
                $speed = $quiet ? $sp('cool_night') : $sp('cool_day');
                $why = 'bypass ouvert : la VMC rafraîchit la maison avec l\'air du dehors';
            } else {
                $speed = $sp('sp_comf_n');
                $why = 'bypass fermé : on garde ' . ($season == 'summer' ? 'la fraîcheur' : 'la chaleur');
            }
            $source = 'thermal';
            $rows[] = array('label' => 'Règle du bypass', 'state' => true, 'detail' => 'vitesse ' . $speed . ' : ' . $why);
        } elseif ($result === null) {
            // --- Zones de température (avec battement) ---
            $t1 = floatval($cfg[$p . 't_fresh']);
            $t2 = floatval($cfg[$p . 't_hot']);
            $zone = isset($flags['zone']) && in_array($flags['zone'], array(0, 1, 2), true) ? $flags['zone'] : null;
            if ($zone === null) {
                $zone = ($T >= $t2) ? 2 : (($T >= $t1) ? 1 : 0);
            }
            $thr = array($t1, $t2);
            for ($i = 0; $i < 3; $i++) {
                if ($zone < 2 && $T >= $thr[$zone] + $h) {
                    $zone++;
                } elseif ($zone > 0 && $T < $thr[$zone - 1]) {
                    $zone--;
                } else {
                    break;
                }
            }
            if ($zone == 1 && $t1 >= $t2) {
                $zone = ($T >= $t2) ? 2 : 0;
            }
            $flags['zone'] = $zone;
            $zoneNames = array('maison fraîche', 'bonne température', 'maison trop chaude');
            $zoneHelp = array(
                0 => 'devient « ' . ($t1 >= $t2 ? 'trop chaude' : 'bonne température') . ' » à ' . self::fmt($t1 + $h) . ' °C',
                1 => '« trop chaude » à ' . self::fmt($t2 + $h) . ' °C, « fraîche » sous ' . self::fmt($t1) . ' °C',
                2 => 'redevient « bonne température » sous ' . self::fmt($t2) . ' °C',
            );
            $rows[] = array('label' => 'Température de la maison', 'state' => null, 'detail' => self::fmt($T) . ' °C → ' . $zoneNames[$zone] . ' (' . $zoneHelp[$zone] . ')' . (!empty($ctx['solar']) ? ' — ' . $ctx['solar'] : ''));

            // --- Dehors plus frais que dedans ? ---
            $inRef = $T;
            $outRef = $O;
            $delta = floatval($cfg['vmc_delta']);
            $diff = $inRef - $outRef;
            $cooler = self::hyst($flags, 'cooler', $diff > $delta, $diff <= $delta - $h);
            $rows[] = array('label' => 'Air extérieur plus frais que dedans', 'state' => $cooler,
                'detail' => 'dehors ' . self::fmt($O) . ' °C / dedans ' . self::fmt($T) . ' °C'
                    . ' — écart ' . self::fmt($diff) . ' °C (oui au-delà de ' . self::fmt($delta) . ', non sous ' . self::fmt($delta - $h) . ')');

            $coldThr = floatval($cfg['vmc_cold_out']);
            $outCold = self::hyst($flags, 'outcold', $O < $coldThr, $O >= $coldThr + $h);
            $rows[] = array('label' => 'Dehors froid', 'state' => $outCold, 'detail' => self::fmt($O) . ' °C (oui sous ' . self::fmt($coldThr) . ', non à partir de ' . self::fmt($coldThr + $h) . ')');

            // Vitesse choisie dans le tableau des zones (onglet VMC).
            $keep = ($season == 'summer') ? 'la fraîcheur' : 'la chaleur';
            $txtIO = ' (' . self::fmt($T) . ' °C dedans, ' . self::fmt($O) . ' °C dehors)';
            if ($zone == 0) {
                if ($cooler && !$outCold) {
                    $speed = $sp('sp_fresh_c');
                    $why = 'maison fraîche, dehors plus frais mais doux' . $txtIO;
                } else {
                    $speed = $sp('sp_fresh_n');
                    $why = 'maison fraîche : on garde ' . $keep . $txtIO;
                }
            } elseif ($zone == 1) {
                if ($cooler) {
                    $speed = $sp('sp_comf_c');
                    $why = 'maison à bonne température, dehors plus frais' . $txtIO;
                } else {
                    $speed = $sp('sp_comf_n');
                    $why = 'maison à bonne température, dehors pas plus frais' . $txtIO;
                }
            } else {
                if ($cooler) {
                    $speed = $quiet ? $sp('cool_night') : $sp('cool_day');
                    $why = (!empty($ctx['solar']) ? 'maison trop chaude à cause du soleil, dehors plus frais : on rafraîchit' : 'maison trop chaude, dehors plus frais : on rafraîchit') . $txtIO;
                } else {
                    $speed = $sp('sp_hot_n');
                    $why = 'maison trop chaude' . (!empty($ctx['solar']) ? ' (soleil)' : '') . ' mais dehors pas plus frais : on garde ' . $keep . $txtIO;
                }
            }
            $source = 'thermal';
            $rows[] = array('label' => 'Règle de température', 'state' => true, 'detail' => 'vitesse ' . $speed . ' : ' . $why);
        }

        if ($result === null) {

            if ($ctx['co2'] !== null) {
                $hi = floatval($cfg['co2_high']);
                $lo = floatval($cfg['co2_low']);
                $co2 = self::hyst($flags, 'co2', $ctx['co2'] >= $hi, $ctx['co2'] < $lo);
                $rows[] = array('label' => 'CO2 élevé', 'state' => $co2, 'detail' => round($ctx['co2']) . ' ppm (alerte à ' . round($hi) . ', fin sous ' . round($lo) . ')');
                if ($co2 && $speed < intval($cfg['co2_speed'])) {
                    $speed = intval($cfg['co2_speed']);
                    $why = 'renouvellement de l\'air, CO2 trop élevé (' . round($ctx['co2']) . ' ppm)';
                    $source = 'co2';
                }
            } else {
                $rows[] = array('label' => 'CO2 élevé', 'state' => null, 'detail' => 'pas de capteur CO2');
            }
            $result = array('speed' => $speed, 'reason' => $why, 'source' => $source);

            // --- Aération matinale des jours chauds (été) ---
            if ($season == 'summer' && $cfg['flush_enabled'] == '1' && $O !== null) {
                $fa = self::hm2min($cfg['flush_detect_start']);
                $fb = self::hm2min($cfg['flush_detect_end']);
                $fc = self::hm2min($cfg['flush_end']);
                if ($nowMin >= $fa && $nowMin <= $fb && $O > floatval($cfg['flush_out'])) {
                    $flags['hotday'] = $today;
                }
                $hotDay = isset($flags['hotday']) && $flags['hotday'] == $today;
                $flushNow = $hotDay && $nowMin >= $fa && $nowMin < $fc;
                $rows[] = array('label' => 'Jour chaud (aération matinale)', 'state' => $flushNow,
                    'detail' => $hotDay ? 'oui : ' . self::fmt($O) . ' °C dehors au réveil, aération ' . self::min2hm($fa) . ' – ' . self::min2hm($fc) : 'détecté si dehors > ' . self::fmt($cfg['flush_out']) . ' °C entre ' . self::min2hm($fa) . ' et ' . self::min2hm($fb));
                if ($flushNow) {
                    $result = array('speed' => intval($cfg['flush_speed']), 'reason' => 'il fait déjà chaud dehors (' . self::fmt($O) . ' °C) : aération maximum le matin, puis on garde la fraîcheur', 'source' => 'flush');
                }
            } else {
                $rows[] = array('label' => 'Jour chaud (aération matinale)', 'state' => null, 'detail' => $season == 'summer' ? 'désactivée' : 'été uniquement');
            }

            // --- Plages de renouvellement ---
            if ($result['source'] != 'flush') {
                $winHit = null;
                foreach ($cfg['vmc_windows'] as $w) {
                    if (isset($w['season']) && $w['season'] != 'all' && $w['season'] != $season) {
                        continue;
                    }
                    if (!self::slotActive($w, $nowMin, $dow)) {
                        continue;
                    }
                    if (!empty($w['skip_hot']) && $w['skip_hot'] == '1' && $hotDay) {
                        $rows[] = array('label' => 'Plage ' . self::rowName($w), 'state' => false, 'detail' => 'ignorée : jour chaud, on garde la fraîcheur');
                        continue;
                    }
                    $winHit = $w;
                    break;
                }
                if ($winHit !== null) {
                    $sp = intval($winHit['speed']);
                    $rows[] = array('label' => 'Plage ' . self::rowName($winHit), 'state' => true, 'detail' => 'vitesse ' . $sp . ' imposée');
                    if (!($result['source'] == 'co2' && $result['speed'] > $sp)) {
                        $result = array('speed' => $sp, 'reason' => (!empty($winHit['label']) ? $winHit['label'] : 'plage horaire') . ' (' . self::min2hm(self::hm2min($winHit['start'])) . ' – ' . self::min2hm(self::hm2min($winHit['end'])) . ')', 'source' => 'window');
                    }
                } else {
                    $rows[] = array('label' => 'Plage de renouvellement', 'state' => false, 'detail' => empty($cfg['vmc_windows']) ? 'aucune plage' : 'aucune plage en cours');
                }
            }
        }

        // Plafond de silence : s'applique à toute décision automatique.
        if ($cap !== null && $result['speed'] > $cap['max']) {
            $result['speed'] = $cap['max'];
            $result['reason'] .= ' — plafonné à ' . $cap['max'] . ' (plage « ' . $cap['label'] . ' »)';
        }
        $result['rows'] = $rows;
        $result['cap'] = $cap;
        return $result;
    }

    /* ================================================================ */
    /* Chauffage : décision (fonction pure, testable hors Jeedom)        */
    /* ================================================================ */

    public static function activeSlot($cfg, $b, $nowMin, $dow) {
        $hits = array();
        foreach ($cfg['heat_slots'] as $s) {
            $bats = isset($s['batteries']) && is_array($s['batteries']) ? $s['batteries'] : array();
            if (!in_array($b, $bats)) {
                continue;
            }
            if (self::slotActive($s, $nowMin, $dow)) {
                $hits[] = $s;
            }
        }
        if (empty($hits)) {
            return null;
        }
        // Priorité : arrêt > marche forcée > Normal > consigne fixe la plus haute > ECO.
        foreach (array('off', 'forced', 'normal', 'comfort') as $mode) {
            foreach ($hits as $s) {
                if ($s['mode'] == $mode) {
                    return $s;
                }
            }
        }
        $fixed = array_values(array_filter($hits, function ($s) {
            return $s['mode'] == 'consigne';
        }));
        if (!empty($fixed)) {
            usort($fixed, function ($a, $b) {
                return floatval($b['consigne']) <=> floatval($a['consigne']);
            });
            return $fixed[0];
        }
        return $hits[0];
    }

    /*
     * $ctx : season, temp_out, b1_temp, b2_temp, shed (batteries délestées).
     * $h   : runtime chauffage (modifié : flags, assist, rest_until).
     * Retour par batterie : want (true/false/null), urgent, reason,
     * consigne, slot, mode.
     */
    public static function heatDecide($cfg, $ctx, &$h, $now) {
        $nowMin = self::nowMin($now);
        $dow = intval(date('N', $now));
        $res = array();
        $hl = floatval($cfg['heat_hyst_low']);
        $hh = floatval($cfg['heat_hyst_high']);
        $shed = isset($ctx['shed']) && is_array($ctx['shed']) ? $ctx['shed'] : array();

        $global = null;
        if (empty($h['on'])) {
            $global = ($cfg['heat_off_when_disabled'] == '1')
                ? array('want' => false, 'urgent' => true, 'reason' => 'thermostat désactivé')
                : array('want' => null, 'urgent' => false, 'reason' => 'thermostat désactivé (batteries laissées en manuel)');
        } elseif ($cfg['heat_only_winter'] == '1' && $ctx['season'] != 'winter') {
            $global = array('want' => false, 'urgent' => true, 'reason' => 'hors saison de chauffe (été)');
        } elseif ($cfg['heat_out_max'] !== '' && is_numeric($cfg['heat_out_max']) && $ctx['temp_out'] !== null) {
            $lim = floatval($cfg['heat_out_max']);
            if (self::hyst($h['flags'], 'outwarm', $ctx['temp_out'] > $lim, $ctx['temp_out'] <= $lim - 0.5)) {
                $global = array('want' => false, 'urgent' => false, 'reason' => 'il fait ' . self::fmt($ctx['temp_out']) . ' °C dehors (pas de chauffe au-dessus de ' . self::fmt($lim) . ' °C)');
            }
        }

        foreach (array('b1', 'b2') as $b) {
            if ($cfg[$b . '_enabled'] != '1') {
                continue;
            }
            $hb = $h[$b];
            $actual = $hb['actual'];
            $T = $ctx[$b . '_temp'];
            $safety = floatval($cfg[$b . '_safety']);
            $base = array('want' => false, 'urgent' => false, 'reason' => '', 'consigne' => null, 'slot' => null, 'mode' => null, 'lock' => false, 'lock_conflict' => null);
            $lock = self::nightLock($cfg, $h, $now);
            if ($lock !== null) {
                // Ce qui aurait demandé de chauffer : plage active ou forçage.
                $sl = self::activeSlot($cfg, $b, $nowMin, $dow);
                $conflict = null;
                if (!empty($h['force'][$b]) && $now < intval($h['force'][$b])) {
                    $conflict = 'chauffage forcé';
                } elseif ($sl !== null && $sl['mode'] != 'off') {
                    $conflict = 'plage ' . self::rowName($sl);
                }
                $frost = ($cfg['night_lock_frost'] !== '' && is_numeric($cfg['night_lock_frost'])) ? floatval($cfg['night_lock_frost']) : null;
                if ($frost !== null && $T !== null && ($T < $frost - $hl || ($actual === true && $T < $frost + $hh)) && $global === null && !in_array($b, $shed) && $T < $safety) {
                    $res[$b] = array_merge($base, array('want' => true, 'consigne' => $frost, 'mode' => 'frost', 'lock' => true, 'lock_conflict' => $conflict,
                        'reason' => 'verrou de nuit (' . $lock['start'] . '–' . $lock['end'] . ') : hors-gel, ' . self::fmt($T) . ' °C pour ' . self::fmt($frost) . ' °C'));
                } else {
                    $res[$b] = array_merge($base, array('urgent' => true, 'lock' => true, 'lock_conflict' => $conflict,
                        'reason' => 'verrou de nuit (' . $lock['start'] . '–' . $lock['end'] . ') : pas de chauffe' . ($conflict ? ' — ' . $conflict . ' ignorée' : '')));
                }
                continue;
            }
            if (in_array($b, $shed)) {
                $res[$b] = array_merge($base, array('urgent' => true, 'reason' => 'délestage : puissance Linky trop élevée'));
                continue;
            }
            if ($global !== null) {
                $res[$b] = array_merge($base, $global);
                continue;
            }
            if ($T === null) {
                $res[$b] = array_merge($base, array('urgent' => true, 'reason' => 'température de référence indisponible : arrêt par sécurité'));
                continue;
            }
            if ($T >= $safety) {
                $res[$b] = array_merge($base, array('urgent' => true, 'reason' => 'sécurité : ' . self::fmt($T) . ' °C ≥ ' . self::fmt($safety) . ' °C'));
                continue;
            }
            if (!empty($hb['rest_until']) && $now < $hb['rest_until']) {
                $res[$b] = array_merge($base, array('reason' => 'pause de protection jusqu\'à ' . date('H:i', $hb['rest_until'])));
                continue;
            }
            $r = $base;
            $slot = self::activeSlot($cfg, $b, $nowMin, $dow);
            $r['slot'] = $slot;
            // Mode global ECO (absence, vacances...) : tout ce qui chauffait en
            // Normal passe en ECO, une consigne fixe est plafonnée à ECO et la
            // marche forcée devient un thermostat ECO.
            $ecoMode = (isset($h['mode']) && $h['mode'] == 'eco');
            $cN = floatval($h['consigne']);
            $cE = isset($h['eco']) ? floatval($h['eco']) : floatval($cfg['heat_eco']);
            $cE = min($cE, $cN - self::ECO_GAP);
            $ecoTxt = $ecoMode ? ' — mode ECO' : '';
            $c = null;
            $cLabel = '';
            $level = null;
            if ($slot !== null) {
                $r['mode'] = $slot['mode'];
                $slotTxt = 'plage ' . self::rowName($slot);
                $m = ($slot['mode'] == 'comfort') ? 'normal' : $slot['mode'];
                if ($m == 'off') {
                    $r['reason'] = $slotTxt . ' : arrêt';
                } elseif ($m == 'forced' && !$ecoMode) {
                    $r['want'] = true;
                    $r['reason'] = $slotTxt . ' : marche forcée (' . self::fmt($T) . ' °C)';
                } elseif ($m == 'forced' || $m == 'eco' || ($m == 'normal' && $ecoMode)) {
                    $c = $cE;
                    $level = 'eco';
                    $cLabel = 'consigne ECO ' . self::fmt($c) . ' °C (' . $slotTxt . $ecoTxt . ')';
                } elseif ($m == 'normal') {
                    $c = $cN;
                    $level = 'normal';
                    $cLabel = 'consigne Normal ' . self::fmt($c) . ' °C (' . $slotTxt . ')';
                } else {
                    $c = floatval($slot['consigne']);
                    if ($ecoMode && $c > $cE) {
                        $c = $cE;
                    }
                    $cLabel = 'consigne ' . self::fmt($c) . ' °C (' . $slotTxt . $ecoTxt . ')';
                }
            } else {
                $out = $cfg[$b . '_outside'];
                $r['mode'] = 'outside_' . $out;
                if ($out == 'eco' || (($out == 'normal' || $out == 'comfort') && $ecoMode)) {
                    $c = $cE;
                    $level = 'eco';
                    $cLabel = 'consigne ECO ' . self::fmt($c) . ' °C (hors plage' . $ecoTxt . ')';
                } elseif ($out == 'normal' || $out == 'comfort') {
                    $c = $cN;
                    $level = 'normal';
                    $cLabel = 'consigne Normal ' . self::fmt($c) . ' °C (hors plage)';
                } else {
                    $r['reason'] = 'hors plage : éteinte';
                }
            }
            // Chauffage forcé (commande) : consigne Normal, même hors plage,
            // en mode ECO ou sur une plage « arrêt ».
            if (!empty($h['force'][$b]) && $now < intval($h['force'][$b])) {
                $c = floatval($h['consigne']);
                $level = 'normal';
                $r['want'] = false;
                $r['mode'] = 'user_force';
                $cLabel = 'consigne Normal ' . self::fmt($c) . ' °C (chauffage forcé jusqu\'à ' . date('H:i', intval($h['force'][$b])) . ')';
            }
            $r['level'] = $level;
            if ($c !== null) {
                $r['consigne'] = $c;
                if ($T < $c - $hl) {
                    $r['want'] = true;
                    $r['reason'] = self::fmt($T) . ' °C pour une ' . $cLabel;
                } elseif ($T >= $c + $hh) {
                    $r['reason'] = self::fmt($T) . ' °C : ' . $cLabel . ' atteinte' . (!empty($ctx['solar']) ? ' (chaleur du soleil)' : '');
                } else {
                    $r['want'] = ($actual === true);
                    $r['reason'] = self::fmt($T) . ' °C, entre ' . self::fmt($c - $hl, 2) . ' et ' . self::fmt($c + $hh, 2) . ' °C (battement) : on ' . ($actual === true ? 'continue' : 'reste à l\'arrêt') . ' — ' . $cLabel;
                }
            }
            $maxOn = floatval($cfg[$b . '_max_on']);
            if ($r['want'] && $actual === true && $maxOn > 0 && !empty($hb['since']) && ($now - $hb['since']) >= $maxOn * 60) {
                $h[$b]['rest_until'] = $now + intval($cfg[$b . '_rest']) * 60;
                $r['want'] = false;
                $r['reason'] = 'marche continue de ' . intval($maxOn) . ' min atteinte : pause de ' . intval($cfg[$b . '_rest']) . ' min';
            }
            $res[$b] = $r;
        }

        // --- Appoint : la batterie 2 aide la batterie 1 (jamais pendant sa
        // propre plage, ni en sécurité, ni en pause, ni délestée) ---
        $assistActive = false;
        $wasAssist = !empty($h['assist']);
        $b2Free = isset($res['b1'], $res['b2'])
            && $res['b2']['want'] === false
            && !$res['b2']['urgent']
            && $res['b2']['slot'] === null
            && (empty($h['b2']['rest_until']) || $now >= $h['b2']['rest_until']);
        if ($cfg['assist_enabled'] == '1' && $cfg['b1_enabled'] == '1' && $cfg['b2_enabled'] == '1' && $global === null && $b2Free) {
            $c1 = $res['b1']['consigne'];
            $T1 = $ctx['b1_temp'];
            $b1on = ($h['b1']['actual'] === true) && $res['b1']['want'];
            if ($c1 !== null && $T1 !== null && $b1on) {
                $runFor = !empty($h['b1']['since']) ? ($now - $h['b1']['since']) : 0;
                if (!empty($h['assist'])) {
                    $assistActive = ($T1 < $c1);
                } else {
                    $assistActive = ($runFor >= floatval($cfg['assist_after']) * 60) && ($T1 <= $c1 - floatval($cfg['assist_gap']));
                }
                if ($assistActive) {
                    $res['b2']['want'] = true;
                    $res['b2']['mode'] = 'assist';
                    $res['b2']['reason'] = 'appoint : « ' . $cfg['b1_name'] . ' » chauffe depuis ' . round($runFor / 60) . ' min et il fait ' . self::fmt($T1) . ' °C pour ' . self::fmt($c1) . ' °C';
                }
            }
        }
        if ($wasAssist && !$assistActive && isset($res['b2']) && $res['b2']['want'] === false && $res['b2']['slot'] === null && !$res['b2']['urgent']) {
            $res['b2']['reason'] = 'fin de l\'appoint (« ' . $cfg['b1_name'] . ' » a atteint sa consigne ou s\'est arrêtée)';
        }
        $h['assist'] = $assistActive;
        return $res;
    }

    /*
     * Lien batterie <-> VMC : une batterie ne chauffe correctement qu'à
     * partir de sa vitesse minimum. Retour : want/urgent/reason mis à jour,
     * demand (vitesse à demander à la VMC, ou null), blocked (plage de
     * silence incompatible).
     */
    public static function vmcGate($cfg, $b, $dec, $actual, $pos, $cap, $vmcPiloted) {
        $req = intval($cfg[$b . '_min_speed']);
        $out = $dec + array('demand' => null, 'blocked' => false);
        if ($dec['want'] === true) {
            if ($vmcPiloted && $cap !== null && $cap['max'] < $req) {
                $out['want'] = false;
                $out['urgent'] = true;
                $out['blocked'] = true;
                $out['reason'] = 'plage de silence « ' . $cap['label'] . ' » (VMC limitée à ' . $cap['max'] . ' jusqu\'à ' . $cap['end'] . ') : il faut la vitesse ' . $req . ' pour chauffer — ' . $dec['reason'];
                return $out;
            }
            $out['demand'] = $req;
            if ($pos === null) {
                $out['want'] = false;
                $out['urgent'] = true;
                $out['reason'] = 'vitesse de la VMC illisible : pas de chauffe par sécurité';
                $out['demand'] = null;
            } elseif ($pos < $req) {
                $out['want'] = false;
                $out['urgent'] = ($actual === true);
                $out['reason'] = ($vmcPiloted ? 'montée de la VMC en vitesse ' . $req . ' avant d\'allumer' : 'VMC en vitesse ' . $pos . ' (mode auto coupé) : il faut la vitesse ' . $req) . ' — ' . $dec['reason'];
            }
        } elseif ($actual === true) {
            if ($pos !== null && $pos < $req) {
                $out['urgent'] = true;
                $out['reason'] = 'VMC passée sous la vitesse ' . $req . ' — ' . $dec['reason'];
            } else {
                $out['demand'] = $req; // garder la VMC jusqu'à l'arrêt effectif
            }
        }
        return $out;
    }

    /*
     * Durées minimum de marche / d'arrêt. Retour : send ('on'|'off'|null)
     * et reason (complétée si on attend).
     */
    public static function timerGate($cfg, $b, $dec, $hb, $now) {
        $want = $dec['want'];
        $reason = $dec['reason'];
        if ($want === null) {
            return array('send' => null, 'reason' => $reason);
        }
        $actual = $hb['actual'];
        if ($actual === $want) {
            return array('send' => null, 'reason' => $reason);
        }
        $since = !empty($hb['since']) ? intval($hb['since']) : 0;
        if ($want) {
            $minOff = intval($cfg[$b . '_min_off']) * 60;
            if ($actual === false && $since > 0 && ($now - $since) < $minOff) {
                $left = ceil(($minOff - ($now - $since)) / 60);
                return array('send' => null, 'reason' => $reason . ' — démarrage dans ' . $left . ' min (arrêt minimum)');
            }
            return array('send' => 'on', 'reason' => $reason);
        }
        $minOn = intval($cfg[$b . '_min_on']) * 60;
        if (empty($dec['urgent']) && $actual === true && $since > 0 && ($now - $since) < $minOn) {
            $left = ceil(($minOn - ($now - $since)) / 60);
            return array('send' => null, 'reason' => $reason . ' — arrêt dans ' . $left . ' min (marche minimum)');
        }
        return array('send' => 'off', 'reason' => $reason);
    }

    /* ================================================================ */
    /* Délestage (fonction pure)                                         */
    /* ================================================================ */

    /*
     * $s : runtime délestage (active = batteries coupées dans l'ordre, at =
     * horodatage de la dernière coupure/remise). $on : batterie => allumée ?
     * Retour : action ('shed'|'restore'|null), battery, reason.
     */
    public static function shedDecide($cfg, $power, &$s, $on, $now) {
        if ($power === null) {
            return array('action' => null);
        }
        $limit = floatval($cfg['shed_limit']);
        $order = ($cfg['shed_first'] == 'b1') ? array('b1', 'b2') : array('b2', 'b1');
        $s['power'] = $power;
        if ($power >= $limit) {
            $candidates = array();
            foreach ($order as $b) {
                if (self::batteryEnabled($cfg, $b) && !in_array($b, $s['active']) && !empty($on[$b])) {
                    $candidates[] = $b;
                }
            }
            if (empty($candidates)) {
                return array('action' => null, 'reason' => 'seuil atteint mais aucune batterie en marche');
            }
            if (empty($s['active']) || ($now - intval($s['at'])) >= intval($cfg['shed_delay'])) {
                $b = $candidates[0];
                $s['active'][] = $b;
                $s['at'] = $now;
                return array('action' => 'shed', 'battery' => $b, 'reason' => round($power) . ' ≥ ' . round($limit));
            }
            return array('action' => null, 'reason' => 'attente de ' . intval($cfg['shed_delay']) . ' s avant la coupure suivante');
        }
        if (!empty($s['active']) && ($now - intval($s['at'])) >= intval($cfg['shed_restore']) * 60) {
            $b = end($s['active']);
            $p = floatval($cfg[$b . '_power']);
            if ($power + $p + floatval($cfg['shed_margin']) < $limit) {
                array_pop($s['active']);
                $s['at'] = $now;
                return array('action' => 'restore', 'battery' => $b, 'reason' => round($power) . ' + ' . round($p) . ' + marge ' . round(floatval($cfg['shed_margin'])) . ' < ' . round($limit));
            }
        }
        return array('action' => null);
    }

    /* ================================================================ */
    /* Cycle principal                                                   */
    /* ================================================================ */

    public static function cron() {
        foreach (self::byType(self::PLUGIN, true) as $eq) {
            try {
                $eq->syncListener(); // auto-réparation (même principe que RoboProg)
                $eq->evaluate('cron');
            } catch (\Throwable $e) {
                log::add(self::PLUGIN, 'error', $eq->getHumanName() . ' : ' . $e->getMessage());
            }
            // Rapport des statistiques planifié (une fois par minute prévue).
            try {
                $cfg = self::getCfg($eq);
                $now = self::now();
                if (ThermoFlair325Report::due($cfg, $now)) {
                    $key = 'ThermoFlair325::report::' . $eq->getId();
                    $stamp = date('Y-m-d H:i', $now);
                    if (cache::byKey($key)->getValue('') !== $stamp) {
                        cache::set($key, $stamp, 7200);
                        $res = ThermoFlair325Report::send($eq);
                        log::add(self::PLUGIN, 'info', $eq->getHumanName() . ' : rapport envoyé à ' . $res['sent'] . ' destinataire(s)' . ($res['detail'] ? ' — ' . $res['detail'] : ''));
                    }
                }
            } catch (\Throwable $e) {
                log::add(self::PLUGIN, 'error', $eq->getHumanName() . ' : rapport : ' . $e->getMessage());
            }
        }
    }

    public static function cronDaily() {
        foreach (self::byType(self::PLUGIN, true) as $eq) {
            try {
                ThermoFlair325Stats::purge($eq->getId(), intval(self::getCfg($eq)['stats_keep']));
                ThermoFlair325Advisor::record($eq->getId(), self::getCfg($eq), self::now());
            } catch (\Throwable $e) {
                log::add(self::PLUGIN, 'error', 'Purge statistiques : ' . $e->getMessage());
            }
        }
    }

    private function withLock($fn) {
        $dir = jeedom::getTmpFolder(self::PLUGIN);
        $fh = @fopen($dir . '/lock_' . $this->getId(), 'c');
        if ($fh) {
            flock($fh, LOCK_EX);
        }
        try {
            return $fn();
        } finally {
            if ($fh) {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        }
    }

    public function evaluate($why = 'cron') {
        return $this->withLock(function () use ($why) {
            return $this->evaluateLocked($why);
        });
    }

    private function readContext($cfg, $now) {
        return array(
            'season' => self::seasonOf($cfg, $now),
            'temp_in' => self::readNum($cfg['temp_in_cmd']),
            'temp_up' => self::readNum($cfg['temp_up_cmd']),
            'temp_out' => self::readNum($cfg['temp_out_cmd']),
            'lux' => self::readNum($cfg['lux_cmd']),
            'wx' => ($cfg['weather_cmd'] !== '') ? self::mapWeather(self::readRaw($cfg['weather_cmd']), $cfg['weather_source']) : null,
            'co2' => self::readNum($cfg['co2_cmd']),
            'hx_sup' => self::readNum($cfg['hx_supply_cmd']),
            'hx_exh' => self::readNum($cfg['hx_exhaust_cmd']),
            'preheat' => ($cfg['preheat_cmd'] !== '') ? self::readPreheat($cfg) : null,
            'preheat_pw' => self::readNum($cfg['preheat_power_cmd']),
            'bypass' => self::readBypass($cfg),
            'pos' => ($cfg['vmc_pos_cmd'] !== '') ? self::readSpeed($cfg) : null,
            'power' => ($cfg['shed_power_cmd'] !== '') ? self::readNum($cfg['shed_power_cmd']) : null,
        );
    }

    private function evaluateLocked($why) {
        $now = self::now();
        $cfg = self::getCfg($this);
        $check = self::checkConfig($cfg);
        $rt = $this->getRuntime();
        self::initRuntime($cfg, $rt);
        $msgs = array();
        $ctx = $this->readContext($cfg, $now);
        // Météo : si le plugin météo ne répond plus (valeur vide ou
        // illisible), on garde la dernière valeur connue pendant 3 h.
        if ($cfg['weather_cmd'] !== '') {
            // Valeur inutilisable : vide / 0 « parasite » (une commande
            // numérique vide vaut 0, qui serait lu comme « ciel dégagé » WMO
            // alors que la source habituelle est OpenWeatherMap ou WeatherAPI),
            // ou valeur figée depuis plus de 3 h (plugin météo arrêté).
            if ($ctx['wx'] !== null && $cfg['weather_source'] == 'auto' && $ctx['wx']['code'] == 0
                && !empty($rt['wx_last']['src']) && $rt['wx_last']['src'] != 'wmo') {
                $ctx['wx'] = null;
            }
            $wcmd = self::resolveCmd($cfg['weather_cmd']);
            if ($ctx['wx'] !== null && is_object($wcmd)) {
                $vd = strtotime((string) $wcmd->getValueDate());
                if ($vd && $now - $vd > 3 * 3600 && $now - $vd < 400 * 86400) {
                    $ctx['wx'] = null;
                }
            }
            if ($ctx['wx'] !== null) {
                $rt['wx_last'] = array('code' => $ctx['wx']['code'], 't' => $now, 'src' => $ctx['wx']['src']);
            } elseif (!empty($rt['wx_last']) && $now - intval($rt['wx_last']['t']) <= 3 * 3600) {
                $ctx['wx'] = self::mapWeather($rt['wx_last']['code'], !empty($rt['wx_last']['src']) ? $rt['wx_last']['src'] : $cfg['weather_source']);
                if ($ctx['wx'] !== null) {
                    $ctx['wx']['stale'] = intval($rt['wx_last']['t']);
                }
            }
        }
        // Surchauffe solaire : expliquée dans les raisons, notifiée une fois par jour.
        $ctx['solar'] = self::solarOverheat($cfg, $ctx, $rt, $now);
        if ($ctx['solar'] !== null) {
            $today = date('Y-m-d', $now);
            if (!isset($rt['alerts']['solar_note']) || $rt['alerts']['solar_note'] != $today) {
                $rt['alerts']['solar_note'] = $today;
                $msgs[] = array('cat' => 'log', 'text' => ucfirst($ctx['solar']) . '. Le soleil chauffe la maison par les vitrages : la chaleur ne vient pas des batteries.');
            }
        }
        $diag = array('time' => date('H:i:s', $now), 'season' => $ctx['season'], 'solar' => $ctx['solar'], 'check' => $check, 'vmc' => null, 'heat' => null, 'shed' => null, 'filter' => null);

        // Équipement tout juste créé (aucune commande choisie) : pas d'alerte,
        // le bandeau de la page suffit tant qu'on n'a rien configuré.
        $configured = false;
        foreach (array('temp_in_cmd', 'vmc_s1_cmd', 'vmc_pos_cmd', 'b1_on_cmd', 'filter_cmd', 'shed_power_cmd') as $k) {
            if ($cfg[$k] !== '') {
                $configured = true;
            }
        }
        // Configuration redevenue valide : l'ancienne alerte de configuration
        // n'est plus d'actualité.
        $allOk = true;
        foreach (array('vmc', 'heat', 'shed', 'filter') as $m) {
            if ($cfg['mod_' . $m] == '1' && !$check['ok'][$m]) {
                $allOk = false;
            }
        }
        if ($allOk && !empty($rt['last_alert']) && strpos($rt['last_alert'], 'configuration invalide') !== false) {
            $rt['last_alert'] = '';
        }
        foreach (array('vmc' => 'VMC', 'heat' => 'Chauffage', 'shed' => 'Délestage', 'filter' => 'Filtre') as $m => $lab) {
            if ($configured && $cfg['mod_' . $m] == '1' && !$check['ok'][$m]) {
                $errs = array_merge($check['errors']['general'], $check['errors'][$m]);
                $first = !empty($errs) ? $errs[0] : 'dépend du module chauffage';
                $this->raiseAlert($rt, $msgs, 'config_' . $m, "Module $lab arrêté : configuration invalide. Premier problème : " . $first, $now);
            }
        }
        $heatOk = ($cfg['mod_heat'] == '1' && $check['ok']['heat']);
        $vmcOk = ($cfg['mod_vmc'] == '1' && $check['ok']['vmc']);
        $shedOk = ($cfg['mod_shed'] == '1' && $check['ok']['shed']);

        // 0) Communication ModBus (commande « Cycle OK » de MyModbus).
        $ctx['modbus'] = $this->runModbusHealth($cfg, $rt, $now, $msgs);
        $diag['modbus'] = $ctx['modbus'];

        // 1) Délestage (traité aussi en temps réel par l'écouteur).
        if ($shedOk) {
            $diag['shed'] = $this->runShed($cfg, $rt, $ctx['power'], $now, $msgs);
        } else {
            $rt['shed']['active'] = array();
        }

        // 2) Chauffage : décisions, lien avec la VMC, ordres aux batteries.
        $demand = null;
        $vmcPiloted = $vmcOk && $this->vmcHasHand($rt);
        if ($heatOk) {
            $ctx['shed'] = $rt['shed']['active'];
            $cap = $vmcPiloted ? self::quietCap($cfg, $now) : null;
            $diag['heat'] = $this->runHeat($cfg, $rt, $ctx, $now, $msgs, $cap, $vmcPiloted, $demand);
        }

        // 3) VMC (avec la vitesse minimum demandée par les batteries).
        if ($vmcOk) {
            $diag['vmc'] = $this->runVmc($cfg, $rt, $ctx, $now, $msgs, $demand);
        }

        // 3 ter) Échangeur et préchauffage de la VMC (mesures, notifications).
        $diag['hx'] = $this->runHeatExchanger($cfg, $rt, $ctx, $now, $msgs);
        $ctx['hx_eff'] = $rt['hx']['eff'];
        if ($ctx['wx'] !== null && is_array($diag['vmc']) && isset($diag['vmc']['rows'])) {
            $srcName = array('owm' => 'OpenWeatherMap', 'weatherapi' => 'WeatherAPI', 'wmo' => 'Open-Meteo / WMO');
            $wl = ($cfg['weather_label_cmd'] !== '') ? trim((string) self::readRaw($cfg['weather_label_cmd'])) : '';
            $diag['vmc']['rows'][] = array('label' => 'Météo', 'state' => null, 'detail' => $ctx['wx']['icon'] . ' ' . ($wl !== '' ? $wl : $ctx['wx']['label']) . ' (code ' . $ctx['wx']['code'] . ', ' . $srcName[$ctx['wx']['src']] . ') — nébulosité ' . round($ctx['wx']['cc'] * 100) . ' %'
                . (!empty($ctx['wx']['stale']) ? ' — plugin météo muet : dernière valeur connue de ' . date('H:i', $ctx['wx']['stale']) : ''));
        }
        if (!empty($diag['hx']['rows']) && is_array($diag['vmc']) && isset($diag['vmc']['rows'])) {
            $diag['vmc']['rows'] = array_merge($diag['vmc']['rows'], $diag['hx']['rows']);
        }

        // 3 bis) Garde-fous : vérifie à chaque cycle que les règles de
        // sécurité sont respectées dans la réalité (états lus).
        $diag['guard'] = $this->runGuard($cfg, $rt, $ctx, $now, $msgs, $check, $vmcPiloted);

        // 4) Filtre.
        if ($cfg['mod_filter'] == '1' && $check['ok']['filter']) {
            $diag['filter'] = $this->runFilter($cfg, $rt, $now, $msgs);
        }

        // 5) Statistiques (échantillon toutes les 5 minutes).
        if ($cfg['mod_stats'] == '1' && $check['ok']['stats']) {
            try {
                ThermoFlair325Stats::accumulate($this->getId(), $cfg, $rt, $ctx, $now);
            } catch (\Throwable $e) {
                log::add(self::PLUGIN, 'error', 'Statistiques : ' . $e->getMessage());
            }
        }

        $this->saveRuntime($rt);
        $changed = $this->publishInfos($cfg, $rt, $ctx);
        $this->sendQueued($cfg, $msgs, $now);
        cache::set('ThermoFlair325::diag::' . $this->getId(), $diag, 600);
        if ($changed && $cfg['custom_widget'] == '1') {
            $this->refreshWidget();
        }
        return $diag;
    }

    // Le plugin a-t-il la main sur la VMC (mode auto, ou forçage en cours) ?
    /*
     * Garde-fous : contrôles indépendants des décisions, sur l'état réel.
     * Une violation qui dure 3 minutes déclenche une notification
     * « Sécurité » (une par heure et par règle) et, pour une batterie,
     * un nouvel ordre d'arrêt.
     */
    private function runGuard($cfg, &$rt, $ctx, $now, &$msgs, $check, $vmcPiloted) {
        $h = &$rt['heat'];
        if (!isset($rt['guard']) || !is_array($rt['guard'])) {
            $rt['guard'] = array();
        }
        $g = &$rt['guard'];
        $rows = array();
        $viol = array();
        $lock = self::nightLock($cfg, $h, $now);
        $pos = $ctx['pos'];
        $heatOn = ($cfg['mod_heat'] == '1');
        foreach (array('b1', 'b2') as $b) {
            if (!$heatOn || $cfg[$b . '_enabled'] != '1') {
                continue;
            }
            $name = $cfg[$b . '_name'];
            $on = ($h[$b]['actual'] === true);
            $T = self::zoneTemp($ctx, $cfg[$b . '_sensor']);
            $frost = ($cfg['night_lock_frost'] !== '' && is_numeric($cfg['night_lock_frost'])) ? floatval($cfg['night_lock_frost']) : null;
            $tests = array(
                'lock' => array('verrou de nuit respecté', $lock !== null && $on && !($frost !== null && $T !== null && $T < $frost + floatval($cfg['heat_hyst_high'])), "chauffe pendant le verrou de nuit"),
                'off' => array('thermostat OFF respecté', empty($h['on']) && $cfg['heat_off_when_disabled'] == '1' && $on, "chauffe alors que le thermostat est OFF"),
                'safety' => array('température de sécurité', $on && $T !== null && $T >= floatval($cfg[$b . '_safety']), "chauffe à " . self::fmt($T) . " °C, au-dessus de la sécurité (" . self::fmt($cfg[$b . '_safety']) . " °C)"),
                'speed' => array('vitesse de VMC suffisante', $on && $vmcPiloted && $pos !== null && $pos < intval($cfg[$b . '_min_speed']), "chauffe avec la VMC en vitesse $pos (minimum " . intval($cfg[$b . '_min_speed']) . ")"),
                'shed' => array('délestage respecté', $on && in_array($b, isset($rt['shed']['active']) ? $rt['shed']['active'] : array()), "chauffe alors qu'elle est délestée"),
            );
            foreach ($tests as $k => $t) {
                $key = $b . '_' . $k;
                $rows[] = array('label' => $name . ' : ' . $t[0], 'state' => !$t[1], 'detail' => $t[1] ? $t[2] : 'ok');
                if ($t[1]) {
                    $viol[$key] = array('text' => $t[2], 'bat' => $b);
                }
            }
        }
        // Vitesse 4 sans boost
        $v4 = ($pos === 4 && (empty($rt['vmc']['ovr']) || intval($rt['vmc']['ovr']['speed']) != 4));
        $rows[] = array('label' => 'VMC : pas de vitesse 4 hors boost', 'state' => !$v4, 'detail' => $v4 ? 'vitesse 4 sans boost en cours' : 'ok');
        if ($v4) {
            $viol['v4'] = array('text' => 'la VMC est en vitesse 4 sans boost', 'bat' => null);
        }
        // Consignes
        $ecoOk = floatval($h['eco']) <= floatval($h['consigne']) - self::ECO_GAP + 0.001;
        $rows[] = array('label' => 'Consigne ECO sous Normal', 'state' => $ecoOk, 'detail' => self::fmt($h['eco']) . ' / ' . self::fmt($h['consigne']) . ' °C');
        // Configuration
        $nErr = 0;
        foreach ($check['errors'] as $list) {
            $nErr += count($list);
        }
        $rows[] = array('label' => 'Configuration valide', 'state' => $nErr == 0, 'detail' => $nErr ? $nErr . ' problème(s)' : 'ok');
        if ($cfg['modbus_ok_cmd'] !== '') {
            $mb = isset($ctx['modbus']) ? $ctx['modbus'] : null;
            $since = isset($rt['modbus']['down_since']) ? $rt['modbus']['down_since'] : null;
            $rows[] = array('label' => 'Communication ModBus avec la VMC', 'state' => $mb !== false,
                'detail' => $mb === null ? 'Cycle OK illisible' : ($mb ? 'ok' : 'pas de réponse depuis ' . date('H:i', intval($since))));
        }
        $rows[] = array('label' => 'Verrou de nuit', 'state' => !empty($h['lock']), 'detail' => !empty($h['lock']) ? ($cfg['night_lock_start'] . '–' . $cfg['night_lock_end'] . ($lock !== null ? ' (en cours)' : '')) : 'DÉSACTIVÉ');

        foreach ($g as $key => $since) {
            if (!isset($viol[$key])) {
                unset($g[$key]);
            }
        }
        // Violations qui durent depuis 3 min : un message par batterie.
        $due = array();
        foreach ($viol as $key => $vv) {
            if (!isset($g[$key])) {
                $g[$key] = array('since' => $now, 'sent' => 0);
            }
            if ($now - $g[$key]['since'] >= 180 && $now - $g[$key]['sent'] >= 3600) {
                $g[$key]['sent'] = $now;
                $due[$vv['bat'] === null ? 'vmc' : $vv['bat']][] = $vv['text'];
            }
        }
        foreach ($due as $who => $texts) {
            if ($who == 'vmc') {
                $txt = '🛡️ Garde-fou : ' . implode(' ; ', $texts) . ' depuis plus de 3 min.';
            } else {
                $txt = '🛡️ Garde-fou : « ' . $cfg[$who . '_name'] . ' » ' . implode(' ; ', $texts) . ', depuis plus de 3 min. Nouvel ordre d\'arrêt envoyé (relais ou commande à vérifier).';
                $this->switchBattery($cfg, $h[$who], $who, false, $now);
            }
            $msgs[] = array('cat' => 'safety', 'text' => $txt);
            log::add(self::PLUGIN, 'warning', $this->getHumanName() . ' : ' . $txt);
        }
        return array('rows' => $rows, 'ok' => empty($viol));
    }

    private function vmcHasHand($rt) {
        return !empty($rt['vmc']['auto']) || !empty($rt['vmc']['ovr']);
    }

    /* ---------------------------------------------------------------- */
    /* Délestage                                                         */
    /* ---------------------------------------------------------------- */

    private function runShed($cfg, &$rt, $power, $now, &$msgs) {
        $on = array();
        foreach (array('b1', 'b2') as $b) {
            $on[$b] = ($rt['heat'][$b]['actual'] === true) || ($rt['heat'][$b]['cmd_state'] === true && $rt['heat'][$b]['actual'] === null);
        }
        if ($power === null) {
            $this->raiseAlert($rt, $msgs, 'shed_power', 'Délestage : puissance Linky illisible, le délestage ne peut pas fonctionner.', $now);
            return array('power' => null, 'active' => $rt['shed']['active']);
        }
        // Une batterie délestée qui est pourtant en marche (ordre perdu,
        // remise en route à la main) : nouvel ordre d'arrêt tout de suite.
        foreach ($rt['shed']['active'] as $b) {
            if (!empty($on[$b]) && $rt['heat'][$b]['expect'] !== false) {
                $this->switchBattery($cfg, $rt['heat'][$b], $b, false, $now);
                $rt['heat'][$b]['reason'] = 'délestage';
                log::add(self::PLUGIN, 'warning', $this->getHumanName() . ' : batterie « ' . $cfg[$b . '_name'] . ' » délestée mais en marche : nouvel ordre d\'arrêt.');
            }
        }
        $d = self::shedDecide($cfg, $power, $rt['shed'], $on, $now);
        if ($d['action'] == 'shed') {
            $b = $d['battery'];
            $name = $cfg[$b . '_name'];
            $this->switchBattery($cfg, $rt['heat'][$b], $b, false, $now);
            $rt['heat'][$b]['reason'] = 'délestage';
            $txt = "⚡ Délestage : " . round($power) . " ≥ seuil " . round(floatval($cfg['shed_limit'])) . " → batterie « $name » coupée.";
            log::add(self::PLUGIN, 'warning', $this->getHumanName() . ' : ' . $txt);
            $msgs[] = array('cat' => 'shed', 'text' => $txt);
        } elseif ($d['action'] == 'restore') {
            $name = $cfg[$d['battery'] . '_name'];
            $txt = "⚡ Fin du délestage pour « $name » (" . $d['reason'] . ") : elle peut de nouveau chauffer.";
            log::add(self::PLUGIN, 'info', $this->getHumanName() . ' : ' . $txt);
            $msgs[] = array('cat' => 'shed', 'text' => $txt);
        }
        return array('power' => $power, 'limit' => floatval($cfg['shed_limit']), 'active' => $rt['shed']['active'], 'reason' => isset($d['reason']) ? $d['reason'] : '');
    }

    /*
     * Écouteur temps réel sur la puissance Linky (créé par syncListener).
     * Jeedom l'exécute en arrière-plan à chaque nouvelle mesure. Après une
     * coupure, on attend le délai (15 s) PUIS on relit la puissance, même si
     * le compteur n'a pas envoyé de nouvelle valeur entre-temps.
     */
    public static function onPower($_option) {
        if (empty($_option['eqLogic_id'])) {
            return;
        }
        $eq = eqLogic::byId($_option['eqLogic_id']);
        if (!is_object($eq) || $eq->getIsEnable() != 1) {
            return;
        }
        try {
            $value = isset($_option['value']) && is_numeric($_option['value']) ? floatval($_option['value']) : null;
            $result = $eq->shedNow($value);
            if ($result == 'shed') {
                // Un seul processus attend (les mesures suivantes ne relancent pas d'attente).
                $fh = @fopen(jeedom::getTmpFolder(self::PLUGIN) . '/shedwait_' . $eq->getId(), 'c');
                if ($fh && flock($fh, LOCK_EX | LOCK_NB)) {
                    $cfg = self::getCfg($eq);
                    $enabled = ($cfg['b1_enabled'] == '1' ? 1 : 0) + ($cfg['b2_enabled'] == '1' ? 1 : 0);
                    for ($i = 0; $i < 2; $i++) {
                        if (count($eq->getRuntime()['shed']['active']) >= $enabled) {
                            break; // tout est déjà coupé
                        }
                        sleep(max(1, intval($cfg['shed_delay'])) + 1);
                        if ($eq->shedNow(null) != 'shed') {
                            break;
                        }
                    }
                    flock($fh, LOCK_UN);
                }
                if ($fh) {
                    fclose($fh);
                }
            }
        } catch (\Throwable $e) {
            log::add(self::PLUGIN, 'error', 'onPower() : ' . $e->getMessage());
        }
    }

    // Applique le délestage immédiatement ($power null = relire la commande).
    public function shedNow($power = null) {
        return $this->withLock(function () use ($power) {
            $now = self::now();
            $cfg = self::getCfg($this);
            if ($cfg['mod_shed'] != '1' || $cfg['mod_heat'] != '1') {
                return null;
            }
            if ($power === null) {
                $power = self::readNum($cfg['shed_power_cmd']);
            }
            $limit = floatval($cfg['shed_limit']);
            $rt = $this->getRuntime();
            // Chemin rapide : rien à faire sous le seuil sans délestage en cours.
            if ($power !== null && $power < $limit && empty($rt['shed']['active'])) {
                return null;
            }
            self::initRuntime($cfg, $rt);
            $msgs = array();
            $before = count($rt['shed']['active']);
            $this->runShed($cfg, $rt, $power, $now, $msgs);
            $this->saveRuntime($rt);
            $this->sendQueued($cfg, $msgs, $now);
            if (count($rt['shed']['active']) > $before) {
                $this->checkAndUpdateCmd('shed_state', 1);
                return 'shed';
            }
            return null;
        });
    }

    public function syncListener() {
        $listener = listener::byClassAndFunction(self::PLUGIN, 'onPower', array('eqLogic_id' => intval($this->getId())));
        $cfg = self::getCfg($this);
        $id = ($cfg['mod_shed'] == '1') ? self::cmdId($cfg['shed_power_cmd']) : null;
        if ($id === null) {
            if (is_object($listener)) {
                $listener->remove();
            }
            return;
        }
        if (is_object($listener)) {
            $ev = $listener->getEvent();
            if (is_array($ev) && count($ev) == 1 && $ev[0] == '#' . $id . '#') {
                return;
            }
            $listener->remove();
        }
        $listener = new listener();
        $listener->setClass(self::PLUGIN);
        $listener->setFunction('onPower');
        $listener->setOption(array('eqLogic_id' => intval($this->getId())));
        $listener->addEvent('#' . $id . '#');
        $listener->save();
    }

    /* ---------------------------------------------------------------- */
    /* VMC                                                               */
    /* ---------------------------------------------------------------- */

    private function runVmc($cfg, &$rt, $ctx, $now, &$msgs, $demand) {
        $v = &$rt['vmc'];
        $sc = ($cfg['vmc_speed_notif'] == 'vmc') ? 'vmc' : 'log';
        $pos = $ctx['pos'];
        $out = array('position' => $pos, 'rows' => array(), 'demand' => $demand);

        // Changement de vitesse fait hors plugin : respecté un temps donné
        // (vitesse 4 : jamais plus que la durée max ; arrêt compris).
        $grace = ($now - intval($v['expect_at'])) < self::MANUAL_GRACE
            || (!empty($v['ovr']) && $v['ovr']['source'] != 'manual' && ($now - intval($v['ovr']['started'])) < self::MANUAL_GRACE);
        if ($grace && $pos !== null && $v['pos_last'] !== null && $pos !== $v['pos_last'] && $pos !== $v['expect']) {
            log::add(self::PLUGIN, 'debug', $this->getHumanName() . " : position lue $pos juste après un ordre du plugin, pas prise pour un réglage manuel.");
        }
        if (!$grace && $pos !== null && $v['pos_last'] !== null && $pos !== $v['pos_last'] && $pos !== $v['expect']) {
            $hold = ($pos == 4) ? intval($cfg['boost_max']) : intval($cfg['vmc_manual_hold']);
            if ($hold > 0) {
                $v['ovr'] = array('speed' => $pos, 'until' => $now + $hold * 60, 'source' => 'manual', 'started' => $now);
                $what = ($pos == 0) ? 'VMC arrêtée manuellement' : "VMC réglée manuellement en vitesse $pos";
                log::add(self::PLUGIN, 'info', $this->getHumanName() . " : $what (hors plugin), respecté pendant $hold min.");
                $msgs[] = array('cat' => $sc, 'title' => self::NOTIF_TITLES['vmc'], 'text' => "💨 $what, l'automatique reprendra dans $hold min.");
            }
        }
        if ($pos !== null && $v['expect'] !== null && $pos === $v['expect']) {
            $v['expect'] = null;
            $v['retries'] = 0;
        }
        $v['pos_last'] = $pos;

        $endNote = null;
        // Tolérance de 30 s : le cycle tourne chaque minute, la fin tombe
        // ainsi au plus près de la durée demandée (± 30 s).
        if (!empty($v['ovr']) && $now >= intval($v['ovr']['until']) - 30) {
            $src = $v['ovr']['source'];
            $v['ovr'] = null;
            // Fin de forçage : la vitesse automatique est appliquée tout de
            // suite (l'anti-battement ne concerne que les décisions
            // automatiques entre elles).
            $v['last_change'] = 0;
            $endNote = 'fin du ' . ($src == 'boost' ? 'boost' : ($src == 'manual' ? 'réglage manuel' : 'forçage'));
            if (!$v['auto']) {
                $v['auto_off_pending'] = 1;
            }
        }

        $preOk = true;
        if ($v['auto'] || !empty($v['ovr']) || !empty($v['auto_off_pending'])) {
            $preOk = $this->vmcPrerequisite($cfg, $v, $now, $msgs);
        } elseif ($cfg['vmc_pre_always'] == '1') {
            // Mode auto coupé : on garde quand même la VMC en mode ModBus.
            $this->vmcPrerequisite($cfg, $v, $now, $msgs);
        }

        $target = null;
        $reason = '';
        $source = '';
        if (!empty($v['ovr'])) {
            $target = intval($v['ovr']['speed']);
            $left = max(1, ceil((intval($v['ovr']['until']) - $now) / 60));
            $names = array('boost' => 'boost', 'force' => 'vitesse temporaire', 'manual' => 'réglage manuel');
            $reason = (!empty($v['ovr']['label']) ? $v['ovr']['label'] : $names[$v['ovr']['source']]) . ', jusqu\'à ' . date('H:i', intval($v['ovr']['until']));
            $source = 'override';
            $out['rows'][] = array('label' => 'Forçage en cours', 'state' => true, 'detail' => 'vitesse ' . $target . ' — ' . $reason);
        } elseif (!$v['auto']) {
            $source = 'autooff';
            if (!empty($v['auto_off_pending'])) {
                $target = intval($cfg['vmc_off_speed']);
                $reason = 'mode automatique coupé : vitesse ' . $target;
            } else {
                $reason = 'mode automatique coupé (vitesse laissée en manuel)';
            }
            $out['rows'][] = array('label' => 'Mode automatique', 'state' => false, 'detail' => $reason);
        } else {
            $d = self::vmcDecide($cfg, $ctx, $v['flags'], $now);
            // Début / fin d'une plage de silence : appliqué à la minute près,
            // sans attendre l'anti-battement.
            $capKey = ($d['cap'] !== null) ? $d['cap']['label'] . '|' . $d['cap']['max'] : '';
            if (!isset($v['cap_key']) || $v['cap_key'] !== $capKey) {
                $v['last_change'] = 0;
            }
            $v['cap_key'] = $capKey;
            if ($d['cap'] !== null && $pos !== null && $pos > $d['cap']['max']) {
                $v['last_change'] = 0;
            }
            $target = $d['speed'];
            $reason = $d['reason'];
            $source = $d['source'];
            $out['rows'] = $d['rows'];
            if ($source == 'fallback') {
                $this->raiseAlert($rt, $msgs, 'vmc_sensor', "VMC : température de la maison ou extérieure illisible, vitesse de repli " . $target . ".", $now);
            }
            // Vitesse minimum demandée par les batteries (non plafonnée : si la
            // plage de silence l'interdit, la batterie n'a rien demandé, sauf
            // la courte ventilation après arrêt qui passe avant le silence).
            if ($demand !== null && $demand > $target) {
                $target = $demand;
                $reason = 'chauffage : les batteries ont besoin de la vitesse ' . $demand . ' (sinon : ' . $d['reason'] . ')';
                $source = 'heat';
            }
            if ($demand !== null && $ctx['bypass'] === true && (!empty($rt['heat']['b1']['actual']) || !empty($rt['heat']['b2']['actual']))) {
                $out['rows'][] = array('label' => 'Bypass pendant la chauffe', 'state' => false, 'detail' => 'le bypass est ouvert alors qu\'une batterie chauffe : une partie de la chaleur repart dehors (réglage du bypass de la VMC à vérifier)');
                $this->raiseAlert($rt, $msgs, 'bypass_heat', "Le bypass de la VMC est ouvert pendant que le chauffage tourne : une partie de la chaleur repart dehors. Vérifiez le réglage du bypass sur la VMC.", $now);
            }
            $out['rows'][] = array('label' => 'Demande du chauffage', 'state' => $demand !== null, 'detail' => $demand !== null ? 'vitesse ' . $demand . ' minimum' : 'aucune');
        }

        if ($target === 4 && !(!empty($v['ovr']) && intval($v['ovr']['speed']) == 4)) {
            $target = intval($cfg['vmc_off_speed']);
        }

        $send = $target;
        // L'anti-battement ne s'applique qu'entre deux décisions de température
        // ou de CO2 : après le chauffage, un boost ou une plage, la VMC revient
        // tout de suite à sa vitesse (sinon elle resterait en vitesse 3 jusqu'à
        // 15 min après l'arrêt des batteries, en perdant de la chaleur).
        $lastSrc = isset($v['last_src']) ? $v['last_src'] : 'thermal';
        if ($target !== null && $pos !== null && $target != $pos && $pos != 4 && in_array($source, array('thermal', 'co2')) && in_array($lastSrc, array('thermal', 'co2'))) {
            $dwell = intval($cfg['vmc_dwell']) * 60;
            if ($v['last_change'] > 0 && ($now - $v['last_change']) < $dwell) {
                $left = ceil(($dwell - ($now - $v['last_change'])) / 60);
                $reason .= ' — passage en vitesse ' . $target . ' dans ' . $left . ' min (anti-battement)';
                $send = null;
            }
        }

        $v['target'] = $target;
        $v['reason'] = $reason;
        $v['source'] = $source;

        if ($v['expect'] !== null && $send !== null && $v['expect'] !== $send) {
            $v['expect'] = null;
            $v['retries'] = 0;
        }

        $sentNow = false;
        if ($send !== null) {
            $needed = ($pos === null) ? ($v['last_sent'] !== $send) : ($send != $pos);
            if (!$needed) {
                if ($source == 'autooff') {
                    $v['auto_off_pending'] = 0;
                }
            } elseif ($v['expect'] === $send && ($now - $v['expect_at']) < self::CONFIRM_DELAY) {
                // Laisser le temps à la VMC.
            } elseif ($v['expect'] === $send && $v['retries'] >= self::MAX_RETRIES) {
                $this->raiseAlert($rt, $msgs, 'vmc_noack', "La VMC ne passe pas en vitesse $send (" . self::MAX_RETRIES . " essais, position lue : " . ($pos === null ? '?' : $pos) . ").", $now);
            } elseif ($preOk) {
                $v['retries'] = ($v['expect'] === $send) ? $v['retries'] + 1 : 0;
                if ($this->sendSpeed($cfg, $send)) {
                    $v['expect'] = $send;
                    $v['expect_at'] = $now;
                    $v['last_sent'] = $send;
                    $v['last_change'] = $now;
                    $v['last_src'] = $source;
                    $sentNow = true;
                    if ($source == 'autooff') {
                        $v['auto_off_pending'] = 0;
                    }
                    if ($v['retries'] == 0) {
                        log::add(self::PLUGIN, 'info', $this->getHumanName() . " : VMC vitesse $send — $reason" . ($endNote ? " ($endNote)" : ''));
                        $msgs[] = array('cat' => $sc, 'title' => self::NOTIF_TITLES['vmc'], 'text' => "💨 VMC → vitesse $send" . ($endNote ? " ($endNote)" : '') . " : $reason.");
                    } else {
                        log::add(self::PLUGIN, 'warning', $this->getHumanName() . " : VMC vitesse $send renvoyée (essai " . ($v['retries'] + 1) . ")");
                    }
                }
            }
        }
        // Boost / vitesse imposée demandés alors que la VMC y est déjà.
        if (!empty($v['ovr']['announce'])) {
            if (!$sentNow) {
                $msgs[] = array('cat' => $sc, 'title' => self::NOTIF_TITLES['vmc'], 'text' => '💨 VMC : ' . $reason . ' (déjà en vitesse ' . ($pos === null ? '?' : $pos) . ').');
            }
            unset($v['ovr']['announce']);
        }
        if ($endNote !== null && !$sentNow) {
            $msgs[] = array('cat' => $sc, 'title' => self::NOTIF_TITLES['vmc'], 'text' => '💨 VMC : ' . $endNote . ', retour ' . ($v['auto'] ? "à l'automatique" : 'en manuel') . ' (vitesse ' . ($pos === null ? '?' : $pos) . ').');
        }
        if (!$preOk) {
            $out['rows'][] = array('label' => 'Mode ModBus', 'state' => false, 'detail' => 'la VMC n\'est pas en mode ModBus : ordre renvoyé, aucune vitesse envoyée en attendant');
        }
        $out['target'] = $target;
        $out['reason'] = $reason;
        $out['source'] = $source;
        $out['auto'] = $v['auto'];
        $out['override'] = $v['ovr'];
        return $out;
    }

    /*
     * Mode ModBus de la VMC (registre 8000 = 1) : sans lui, la Flair ignore
     * les vitesses envoyées. Une coupure de courant le remet à 0.
     *  - avec une commande de lecture : si la valeur lue n'est pas la bonne,
     *    on renvoie l'ordre (au plus toutes les 5 min) et on attend ;
     *  - dans tous les cas : renvoi discret toutes les « vmc_pre_every »
     *    minutes tant que le plugin pilote la VMC (ce que faisaient souvent les scénarios).
     * Retourne false seulement quand la lecture dit que la VMC n'est pas en
     * mode ModBus.
     */
    private function vmcPrerequisite($cfg, &$v, $now, &$msgs) {
        if ($cfg['vmc_pre_action_cmd'] === '') {
            return true;
        }
        $every = intval($cfg['vmc_pre_every']) * 60;
        $last = intval($v['pre_sent_at']);
        $readOk = null;
        if ($cfg['vmc_pre_cmd'] !== '') {
            $val = self::readRaw($cfg['vmc_pre_cmd']);
            $readOk = ($val !== null && self::compare($val, '==', $cfg['vmc_pre_value']));
        }
        if ($readOk === false) {
            // Au plus un renvoi « correctif » toutes les 2 min (le temps que
            // MyModbus relise le registre).
            if (($now - intval(isset($v['pre_fix_at']) ? $v['pre_fix_at'] : 0)) >= 120) {
                $v['pre_fix_at'] = $now;
                if ($this->sendModbusMode($cfg, $v, $now)) {
                    log::add(self::PLUGIN, 'info', $this->getHumanName() . ' : la VMC n\'était plus en mode ModBus, ordre renvoyé.');
                    $msgs[] = array('cat' => 'vmc', 'text' => '💨 VMC : elle n\'était plus en mode ModBus (coupure de courant ?), ordre renvoyé.');
                }
            }
            return false;
        }
        // Premier passage (démarrage, mode auto activé) ou renvoi périodique.
        if ($last == 0 || ($every > 0 && ($now - $last) >= $every)) {
            if ($this->sendModbusMode($cfg, $v, $now)) {
                log::add(self::PLUGIN, 'debug', $this->getHumanName() . ' : mode ModBus renvoyé (entretien périodique).');
            }
        }
        return true;
    }

    private function sendModbusMode($cfg, &$v, $now) {
        $cmd = self::resolveCmd($cfg['vmc_pre_action_cmd']);
        if (!is_object($cmd)) {
            return false;
        }
        try {
            $cmd->execCmd();
            $v['pre_sent_at'] = $now;
            return true;
        } catch (\Throwable $e) {
            $v['pre_sent_at'] = $now; // pas de rafale d'essais
            log::add(self::PLUGIN, 'error', 'Passage en mode ModBus : ' . $e->getMessage());
            return false;
        }
    }

    // Préchauffage : true = actif, false = arrêté, null = illisible.
    public static function readPreheat($cfg) {
        $raw = self::readRaw($cfg['preheat_cmd']);
        if ($raw === null || $raw === '') {
            return null;
        }
        return self::inList($raw, $cfg['preheat_on_value']);
    }

    /*
     * Efficacité de l'échangeur (rendement en température, côté air neuf) :
     *   (air soufflé − dehors) / (dedans − dehors)
     * et, en contrôle, côté air rejeté : (dedans − air rejeté) / (dedans − dehors).
     * Seulement quand la mesure a un sens : bypass fermé, préchauffage arrêté,
     * au moins 5 °C d'écart entre dedans et dehors. Valeur lissée (moyenne
     * glissante d'environ 30 min). Retourne null si non calculable, avec la
     * raison dans $why.
     */
    public static function hxEfficiency($cfg, $ctx, &$why) {
        $why = '';
        $O = $ctx['temp_out'];
        $T = self::refTemp($cfg, $ctx);
        if ($ctx['hx_sup'] === null && $ctx['hx_exh'] === null) {
            $why = 'températures de la VMC non configurées';
            return null;
        }
        if ($O === null || $T === null) {
            $why = 'il manque la température extérieure ou intérieure';
            return null;
        }
        if ($ctx['bypass'] === true) {
            $why = 'bypass ouvert : l\'air neuf ne passe pas par l\'échangeur';
            return null;
        }
        if ($ctx['preheat'] === true) {
            $why = 'préchauffage en marche : l\'air neuf est réchauffé avant l\'échangeur';
            return null;
        }
        if ($T - $O < 5) {
            $why = 'écart dedans / dehors trop faible (' . self::fmt($T - $O) . ' °C, il faut au moins 5 °C)';
            return null;
        }
        $res = array('sup' => null, 'exh' => null);
        if ($ctx['hx_sup'] !== null) {
            $res['sup'] = max(0, min(110, 100 * ($ctx['hx_sup'] - $O) / ($T - $O)));
        }
        if ($ctx['hx_exh'] !== null) {
            $res['exh'] = max(0, min(110, 100 * ($T - $ctx['hx_exh']) / ($T - $O)));
        }
        return $res;
    }

    // Température « dedans » de référence (la même que pour la VMC).
    public static function refTemp($cfg, $ctx) {
        $b = $ctx['temp_in'];
        $u = $ctx['temp_up'];
        if ($cfg['vmc_temp_ref'] == 'up' && $u !== null) {
            return $u;
        }
        if ($cfg['vmc_temp_ref'] == 'avg' && $b !== null && $u !== null) {
            return ($b + $u) / 2;
        }
        return $b;
    }

    /*
     * Échangeur (efficacité) et préchauffage antigel : mesures, temps de
     * marche du jour, notifications (catégorie « Préchauffage », non urgente :
     * rien pendant la plage de repos).
     */
    private function runHeatExchanger($cfg, &$rt, $ctx, $now, &$msgs) {
        $rows = array();
        // ---- Efficacité ----
        $x = &$rt['hx'];
        if ($cfg['hx_supply_cmd'] !== '' || $cfg['hx_exhaust_cmd'] !== '') {
            $why = '';
            $e = self::hxEfficiency($cfg, $ctx, $why);
            if ($e !== null) {
                // Moyenne glissante (~30 cycles d'une minute).
                foreach (array('sup' => 'eff', 'exh' => 'eff_exh') as $k => $key) {
                    if ($e[$k] !== null) {
                        $x[$key] = ($x[$key] === null) ? $e[$k] : $x[$key] + ($e[$k] - $x[$key]) / 30;
                    }
                }
                if ($x['eff'] === null && $x['eff_exh'] !== null) {
                    $x['eff'] = $x['eff_exh'];
                }
                $x['why'] = '';
                $x['last_t'] = $now;
            } else {
                $x['why'] = $why;
            }
            $T = self::refTemp($cfg, $ctx);
            $detail = ($x['eff'] !== null ? round($x['eff']) . ' %' . ($x['eff_exh'] !== null && $ctx['hx_sup'] !== null ? ' (contrôle côté air rejeté : ' . round($x['eff_exh']) . ' %)' : '') : 'pas encore calculée')
                . ' — air soufflé ' . self::fmt($ctx['hx_sup']) . ' °C' . ($ctx['hx_exh'] !== null ? ', air rejeté ' . self::fmt($ctx['hx_exh']) . ' °C' : '')
                . ', dehors ' . self::fmt($ctx['temp_out']) . ' °C, dedans ' . self::fmt($T) . ' °C'
                . ($x['why'] !== '' ? ' — calcul en pause : ' . $x['why'] : '');
            $rows[] = array('label' => 'Efficacité de l\'échangeur', 'state' => null, 'detail' => $detail);
        }
        // ---- Préchauffage ----
        $p = &$rt['preheat'];
        $today = date('Y-m-d', $now);
        if ($p['day'] !== $today) {
            $p['day'] = $today;
            $p['today_sec'] = 0;
        }
        if ($cfg['preheat_cmd'] !== '') {
            $on = $ctx['preheat'];
            if ($p['on'] === true && $p['last_t'] > 0) {
                $p['today_sec'] += max(0, min(600, $now - $p['last_t']));
            }
            $p['last_t'] = $now;
            if ($on !== null) {
                if ($p['on'] === null) {
                    $p['on'] = $on;
                    $p['since'] = $now;
                } elseif ($on !== $p['on']) {
                    // Changement confirmé sur 2 cycles (évite les messages pour un battement).
                    if ($p['pending'] !== $on) {
                        $p['pending'] = $on;
                        $p['pending_since'] = $now;
                    } elseif ($now - $p['pending_since'] >= 90) {
                        $dur = $now - intval($p['since']);
                        $p['on'] = $on;
                        $p['since'] = $now;
                        $p['pending'] = null;
                        $pw = ($ctx['preheat_pw'] !== null) ? ', puissance ' . round($ctx['preheat_pw']) . ' %' : '';
                        if ($on) {
                            $p['notified_on'] = 1;
                            $msgs[] = array('cat' => 'preheat', 'text' => '❄️ Préchauffage antigel de la VMC allumé : ' . self::fmt($ctx['temp_out']) . ' °C dehors' . $pw . '. Il protège l\'échangeur du gel.');
                        } elseif (!empty($p['notified_on'])) {
                            $p['notified_on'] = 0;
                            $msgs[] = array('cat' => 'preheat', 'text' => '✅ Préchauffage antigel de la VMC éteint après ' . self::fmtDuration(max(1, round($dur / 60))) . ' (' . self::fmt($ctx['temp_out']) . ' °C dehors). Aujourd\'hui : ' . self::fmtDuration(round($p['today_sec'] / 60)) . '.');
                        }
                    }
                } else {
                    $p['pending'] = null;
                }
            }
            $rows[] = array('label' => 'Préchauffage antigel', 'state' => $p['on'],
                'detail' => ($on === null ? 'état illisible' : ($p['on'] ? 'en marche depuis ' . date('H:i', intval($p['since'])) . ($ctx['preheat_pw'] !== null ? ', puissance ' . round($ctx['preheat_pw']) . ' %' : '') : 'arrêté'))
                    . ' — aujourd\'hui : ' . self::fmtDuration(round($p['today_sec'] / 60)));
        }
        return array('rows' => $rows);
    }

    /*
     * Communication ModBus d'après la commande « Cycle OK » de MyModbus
     * (0 dès qu'une lecture échoue, 1 après un cycle complet sans erreur).
     * Alerte si elle reste à 0 plus de « modbus_fail_min » minutes, message
     * de retour quand elle revient. Retourne true / false / null (inconnu).
     */
    private function runModbusHealth($cfg, &$rt, $now, &$msgs) {
        if ($cfg['modbus_ok_cmd'] === '') {
            return null;
        }
        if (!isset($rt['modbus']) || !is_array($rt['modbus'])) {
            $rt['modbus'] = array('down_since' => null, 'alerted' => 0);
        }
        $m = &$rt['modbus'];
        $raw = self::readRaw($cfg['modbus_ok_cmd']);
        if ($raw === null || $raw === '') {
            return null;
        }
        $ok = (floatval($raw) != 0);
        if ($ok) {
            if (!empty($m['alerted'])) {
                $txt = '✅ Communication ModBus avec la VMC rétablie (coupure de ' . self::fmtDuration(max(1, round(($now - intval($m['down_since'])) / 60))) . ').';
                $msgs[] = array('cat' => 'alert', 'text' => $txt);
                log::add(self::PLUGIN, 'info', $this->getHumanName() . ' : ' . $txt);
            }
            // La VMC répond de nouveau (souvent après une coupure de courant,
            // qui remet le registre 8000 à 0) : mode ModBus renvoyé tout de suite.
            if (!empty($m['down_since'])) {
                $rt['vmc']['pre_sent_at'] = 0;
            }
            $m['down_since'] = null;
            $m['alerted'] = 0;
            unset($rt['alerts']['modbus']);
            return true;
        }
        if (empty($m['down_since'])) {
            $m['down_since'] = $now;
        }
        if (empty($m['alerted']) && ($now - intval($m['down_since'])) >= intval($cfg['modbus_fail_min']) * 60) {
            $m['alerted'] = 1;
            $this->raiseAlert($rt, $msgs, 'modbus', 'La VMC ne répond plus en ModBus depuis ' . intval($cfg['modbus_fail_min']) . ' min (« Cycle OK » de MyModbus à 0). Vérifiez le câble A-B-GND, le dongle USB et le démon MyModbus.', $now);
        }
        return false;
    }

    private function sendSpeed($cfg, $speed) {
        $cmd = self::resolveCmd($cfg['vmc_s' . intval($speed) . '_cmd']);
        if (!is_object($cmd) || $cmd->getType() != 'action') {
            log::add(self::PLUGIN, 'error', $this->getHumanName() . " : commande vitesse $speed introuvable.");
            return false;
        }
        try {
            $cmd->execCmd();
            return true;
        } catch (\Throwable $e) {
            log::add(self::PLUGIN, 'error', $this->getHumanName() . " : échec vitesse $speed : " . $e->getMessage());
            return false;
        }
    }

    /* ---------------------------------------------------------------- */
    /* Chauffage                                                         */
    /* ---------------------------------------------------------------- */

    // Envoie l'ordre à une batterie et met à jour son suivi.
    private function switchBattery($cfg, &$hb, $b, $on, $now) {
        $cmd = self::resolveCmd($cfg[$b . ($on ? '_on_cmd' : '_off_cmd')]);
        if (!is_object($cmd)) {
            return false;
        }
        try {
            $cmd->execCmd();
        } catch (\Throwable $e) {
            log::add(self::PLUGIN, 'error', $this->getHumanName() . ' : batterie ' . $cfg[$b . '_name'] . ' : ' . $e->getMessage());
            return false;
        }
        $hb['cmd_state'] = $on;
        $hb['expect_at'] = $now;
        if (!$on) {
            $hb['postvent_until'] = $now + intval($cfg['heat_postvent']) * 60;
        }
        if ($cfg[$b . '_state_cmd'] !== '') {
            $hb['expect'] = $on;
        } else {
            $hb['since'] = $now;
            $hb['actual'] = $on;
            $hb['expect'] = null;
        }
        return true;
    }

    private function runHeat($cfg, &$rt, $ctx, $now, &$msgs, $cap, $vmcPiloted, &$demand) {
        $h = &$rt['heat'];
        $today = date('Y-m-d', $now);
        $pos = $ctx['pos'];
        $ctx['b1_temp'] = self::zoneTemp($ctx, $cfg['b1_sensor']);
        $ctx['b2_temp'] = self::zoneTemp($ctx, $cfg['b2_sensor']);
        foreach (array('b1', 'b2') as $b) {
            if ($cfg[$b . '_enabled'] != '1') {
                continue;
            }
            $hb = &$h[$b];
            $actual = null;
            if ($cfg[$b . '_state_cmd'] !== '') {
                $raw = self::readRaw($cfg[$b . '_state_cmd']);
                if ($raw !== null && $raw !== '' && is_numeric($raw)) {
                    $actual = floatval($raw) > 0;
                }
            }
            if ($actual === null) {
                $actual = $hb['cmd_state'];
            }
            if ($actual !== $hb['actual']) {
                $hb['since'] = ($hb['actual'] === null) ? 0 : $now;
                if ($hb['actual'] === true && $actual === false) {
                    $hb['postvent_until'] = $now + intval($cfg['heat_postvent']) * 60;
                }
                $hb['actual'] = $actual;
            }
            if ($hb['expect'] !== null && $actual === $hb['expect']) {
                $hb['expect'] = null;
                $hb['retries'] = 0;
            }
            if ($hb['day'] != $today) {
                $hb['yesterday_sec'] = ($hb['day'] == date('Y-m-d', $now - 86400)) ? $hb['today_sec'] : 0;
                $hb['today_sec'] = 0;
                $hb['day'] = $today;
            }
            if ($actual === true && $hb['tick'] > 0) {
                $hb['today_sec'] += min(180, max(0, $now - $hb['tick']));
            }
            $hb['tick'] = $now;
            unset($hb);
        }

        // Fin du chauffage forcé.
        if (!empty($h['force'])) {
            $ended = array();
            foreach ($h['force'] as $b => $until) {
                if ($now >= intval($until)) {
                    $ended[] = '« ' . $cfg[$b . '_name'] . ' »';
                    unset($h['force'][$b]);
                }
            }
            if ($ended) {
                $msgs[] = array('cat' => 'log', 'text' => '🔥 Fin du chauffage forcé (' . implode(' et ', $ended) . ') : retour aux plages.');
            }
        }
        $dec = self::heatDecide($cfg, $ctx, $h, $now);
        $out = array('batteries' => array(), 'consigne' => $h['consigne'], 'on' => $h['on'], 'assist' => $h['assist'], 'cap' => $cap, 'pos' => $pos);
        foreach ($dec as $b => $d) {
            $hb = &$h[$b];
            $name = $cfg[$b . '_name'];
            $g = self::vmcGate($cfg, $b, $d, $hb['actual'], $pos, $cap, $vmcPiloted);
            // Verrou de nuit : prévenir (catégorie Sécurité, une fois par nuit)
            // s'il coupe une batterie en marche ou bloque une plage / un forçage.
            if (!empty($d['lock']) && ($hb['actual'] === true && $d['mode'] != 'frost' || $d['lock_conflict'] !== null)) {
                $night = date('Y-m-d', $now - 12 * 3600);
                if (empty($h['lock_note'][$b]) || $h['lock_note'][$b] != $night) {
                    $h['lock_note'][$b] = $night;
                    $msgs[] = array('cat' => 'safety', 'text' => "🛡️ Verrou de nuit (" . $cfg['night_lock_start'] . "–" . $cfg['night_lock_end'] . ") : « $name » "
                        . ($hb['actual'] === true ? 'chauffait et a été coupée' : 'ne chauffe pas')
                        . ($d['lock_conflict'] !== null ? ' alors que la ' . $d['lock_conflict'] . ' le demandait. Vérifiez la configuration (plages, forçage).' : '.'));
                }
            }
            // Prévenir (une fois par plage et par jour) quand une plage de
            // silence empêche une batterie de chauffer.
            if ($g['blocked']) {
                $key = $today . '|' . $cap['label'];
                if (empty($h['blocked_note'][$b]) || $h['blocked_note'][$b] != $key) {
                    $h['blocked_note'][$b] = $key;
                    $msgs[] = array('cat' => 'heat', 'text' => "🌙 Plage de silence « " . $cap['label'] . " » : la VMC est limitée à " . $cap['max'] . " jusqu'à " . $cap['end'] . ", la batterie « $name » (qui a besoin de la vitesse " . intval($cfg[$b . '_min_speed']) . ") ne chauffera pas pendant cette plage.");
                }
            }
            if ($g['demand'] !== null) {
                $demand = max((int) $demand, $g['demand']);
            }
            $gate = self::timerGate($cfg, $b, $g, $hb, $now);
            $hb['reason'] = $gate['reason'];
            $hb['target'] = $d['consigne'];
            // Consigne « de confort » (Normal, consigne fixe, marche forcée) :
            // sert aux statistiques de stabilité (pas l'ECO ni le hors plage).
            $hb['comfort'] = ($d['consigne'] !== null && ((isset($d['level']) && $d['level'] == 'normal') || $d['mode'] == 'consigne')) ? 1 : 0;
            if ($hb['expect'] !== null && ($gate['send'] === null || ($gate['send'] == 'on') !== $hb['expect'])) {
                $hb['expect'] = null;
                $hb['retries'] = 0;
            }
            if ($gate['send'] !== null) {
                $wantOn = ($gate['send'] == 'on');
                if ($hb['expect'] === $wantOn && ($now - $hb['expect_at']) < self::CONFIRM_DELAY) {
                    // en attente de confirmation
                } elseif ($hb['expect'] === $wantOn && $hb['retries'] >= self::MAX_RETRIES) {
                    $this->raiseAlert($rt, $msgs, $b . '_noack', "La batterie « $name » ne répond pas : elle devrait être " . ($wantOn ? 'allumée' : 'éteinte') . ' (' . self::MAX_RETRIES . ' essais).', $now);
                } else {
                    $retry = ($hb['expect'] === $wantOn);
                    $hb['retries'] = $retry ? $hb['retries'] + 1 : 0;
                    if ($this->switchBattery($cfg, $hb, $b, $wantOn, $now) && !$retry) {
                        log::add(self::PLUGIN, 'info', $this->getHumanName() . " : batterie $name " . ($wantOn ? 'allumée' : 'éteinte') . ' — ' . $gate['reason']);
                        $msgs[] = array('cat' => 'heat', 'text' => ($wantOn ? '🔥' : '❄️') . " Batterie « $name » " . ($wantOn ? 'allumée' : 'éteinte') . ' : ' . $gate['reason'] . '.');
                    }
                }
            }
            // Ventilation après arrêt : la VMC garde la vitesse quelques
            // minutes pour évacuer la chaleur résiduelle de la batterie.
            $postvent = ($hb['actual'] !== true && $now < intval($hb['postvent_until']));
            if ($postvent) {
                $demand = max((int) $demand, intval($cfg[$b . '_min_speed']));
            }
            $out['batteries'][$b] = array(
                'name' => $name,
                'actual' => $hb['actual'],
                'want' => $d['want'],
                'reason' => $gate['reason'],
                'consigne' => $d['consigne'],
                'mode' => $d['mode'],
                'temp' => $ctx[$b . '_temp'],
                'since' => $hb['since'],
                'min_speed' => intval($cfg[$b . '_min_speed']),
                'blocked' => $g['blocked'],
                'postvent' => $postvent,
                'today_min' => round($hb['today_sec'] / 60),
                'kwh' => round($hb['today_sec'] / 3600 * floatval($cfg[$b . '_power']) / 1000, 2),
            );
            unset($hb);
        }
        $out['assist'] = $h['assist'];
        $out['demand'] = $demand;
        return $out;
    }

    /* ---------------------------------------------------------------- */
    /* Filtre (binaire : 1 = à nettoyer)                                 */
    /* ---------------------------------------------------------------- */

    private function runFilter($cfg, &$rt, $now, &$msgs) {
        $f = &$rt['filter'];
        $value = self::readRaw($cfg['filter_cmd']);
        $dirty = ($value !== null && self::compare($value, '==', $cfg['filter_value']));
        $today = date('Y-m-d', $now);
        // Passage "à nettoyer" -> "propre" : filtre nettoyé/changé.
        if ($f['dirty'] && !$dirty && $value !== null) {
            $f['cleaned'] = $today;
            $f['notified_date'] = null;
            log::add(self::PLUGIN, 'info', $this->getHumanName() . ' : filtre nettoyé/changé (statut revenu à ' . $value . ').');
            $msgs[] = array('cat' => 'filter', 'text' => '🧹 Filtre de la VMC nettoyé : statut revenu à ' . $value . '.');
        }
        if (!$f['dirty'] && $dirty) {
            $f['dirty_since'] = $today;
        }
        $f['dirty'] = $dirty;
        $f['last'] = $value;
        $at = self::hm2min($cfg['filter_notify_time']);
        if ($dirty && $at !== null && self::nowMin($now) >= $at && $f['notified_date'] != $today) {
            if ($cfg['filter_repeat'] == 'daily' || empty($f['notified_once'])) {
                $since = !empty($f['dirty_since']) ? ' (depuis le ' . date('d/m', strtotime($f['dirty_since'])) . ')' : '';
                $msgs[] = array('cat' => 'filter', 'text' => '🧹 Il faut nettoyer le filtre de la VMC' . $since . '.');
                $f['notified_date'] = $today;
                $f['notified_once'] = 1;
            }
        }
        if (!$dirty) {
            $f['notified_once'] = 0;
        }
        $days = !empty($f['cleaned']) ? intval(floor((strtotime($today) - strtotime($f['cleaned'])) / 86400)) : null;
        $f['days'] = $days;
        return array('dirty' => $dirty, 'value' => $value, 'cleaned' => $f['cleaned'], 'days' => $days, 'dirty_since' => isset($f['dirty_since']) ? $f['dirty_since'] : null);
    }

    /* ---------------------------------------------------------------- */
    /* Alertes et notifications                                          */
    /* ---------------------------------------------------------------- */

    private function raiseAlert(&$rt, &$msgs, $key, $text, $now) {
        $today = date('Y-m-d', $now);
        if (isset($rt['alerts'][$key]) && $rt['alerts'][$key] == $today) {
            return;
        }
        $rt['alerts'][$key] = $today;
        $full = $this->getHumanName() . ' : ' . $text;
        log::add(self::PLUGIN, 'error', $full);
        try {
            message::add(self::PLUGIN, $full);
        } catch (\Throwable $e) {
        }
        $rt['last_alert'] = date('d/m H:i', $now) . ' ' . $text;
        $msgs[] = array('cat' => 'alert', 'text' => '⚠️ ' . $text);
    }

    // Journalise et notifie une action déclenchée par une commande (mode
    // auto, consigne, boost...) : catégorie "log" = "Toutes les actions".
    private function logAction(&$msgs, $text) {
        log::add(self::PLUGIN, 'info', $this->getHumanName() . ' : ' . $text);
        $msgs[] = array('cat' => 'log', 'text' => $text);
    }

    public static function stripTrailingSpace($line) {
        return (substr($line, -1) === ' ') ? substr($line, 0, -1) : $line;
    }

    public static function inRest($cfg, $now) {
        return self::inWindow(self::nowMin($now), self::hm2min($cfg['rest_start']), self::hm2min($cfg['rest_end']));
    }

    private function sendQueued($cfg, $msgs, $now) {
        if (empty($msgs)) {
            return;
        }
        $rest = self::inRest($cfg, $now);
        foreach ($msgs as $m) {
            $title = isset($m['title']) ? $m['title'] : self::NOTIF_TITLES[$m['cat']];
            self::sendNotifications($cfg['notifications'], $title, $m['text'], $m['cat'], $rest);
        }
    }

    /*
     * Messages de test : un par catégorie demandée, avec les valeurs réelles
     * du moment (lues sur les commandes, et l'état du dernier cycle).
     * $cats : liste parmi vmc, heat, shed, filter, alert, log.
     */
    public function testMessages($cats) {
        $cfg = self::getCfg($this);
        $now = self::now();
        $rt = $this->getRuntime();
        self::initRuntime($cfg, $rt);
        $ctx = $this->readContext($cfg, $now);
        $f = function ($v, $d = 1) {
            return self::fmt($v, $d);
        };
        $out = array();
        foreach ($cats as $cat) {
            $lines = array();
            switch ($cat) {
                case 'vmc':
                    if ($cfg['mod_vmc'] != '1') {
                        $lines[] = '💨 VMC : module désactivé (onglet Maison).';
                        break;
                    }
                    $v = $rt['vmc'];
                    $lines[] = '💨 VMC en vitesse ' . ($ctx['pos'] === null ? '?' : $ctx['pos']) . ($ctx['pos'] !== null && isset(self::SPEED_LABELS[$ctx['pos']]) ? ' (' . self::speedLabel($cfg, $ctx['pos']) . ')' : '') . ' : ' . ($v['reason'] !== '' && $v['reason'] !== null ? $v['reason'] : 'pas encore de décision (attendre un cycle)') . '.';
                    $lines[] = 'Mode automatique : ' . ($v['auto'] ? 'oui' : 'non') . ' — décision par ' . ($cfg['vmc_mode_' . ($ctx['season'] == 'summer' ? 's' : 'w')] == 'bypass' ? 'le bypass' : 'les températures') . ' (' . self::seasonLabel($ctx['season']) . ').';
                    if (!empty($v['ovr'])) {
                        $lines[] = '⏱ ' . self::overrideLabel($v['ovr']) . '.';
                    }
                    $extra = array();
                    if ($ctx['co2'] !== null) {
                        $extra[] = 'CO2 ' . round($ctx['co2']) . ' ppm';
                    }
                    if ($ctx['bypass'] !== null) {
                        $extra[] = 'bypass ' . ($ctx['bypass'] ? 'ouvert' : 'fermé');
                    }
                    if ($extra) {
                        $lines[] = ucfirst(implode(', ', $extra)) . '.';
                    }
                    break;
                case 'heat':
                    if ($cfg['mod_heat'] != '1') {
                        $lines[] = '🔥 Chauffage : module désactivé (onglet Maison).';
                        break;
                    }
                    $h = $rt['heat'];
                    $lines[] = '🔥 Thermostat ' . ($h['on'] ? 'ON' : 'OFF') . ', mode ' . ($h['mode'] == 'eco' ? 'ECO' : 'Normal') . ' (Normal ' . $f($h['consigne']) . ' °C, ECO ' . $f($h['eco']) . ' °C).';
                    foreach (array('b1', 'b2') as $b) {
                        if ($cfg[$b . '_enabled'] != '1') {
                            continue;
                        }
                        $T = self::zoneTemp($ctx, $cfg[$b . '_sensor']);
                        $hb = $h[$b];
                        $lines[] = '« ' . $cfg[$b . '_name'] . ' » ' . ($hb['actual'] === true ? 'chauffe' : 'éteinte') . ' — ' . $f($T) . ' °C' . ($hb['target'] !== null ? ', consigne ' . $f($hb['target']) . ' °C' : '') . ' — ' . ($hb['reason'] !== '' ? $hb['reason'] : '—') . ' (' . round($hb['today_sec'] / 60) . ' min aujourd\'hui).';
                    }
                    break;
                case 'shed':
                    if ($cfg['mod_shed'] != '1') {
                        $lines[] = '⚡ Délestage : module désactivé (onglet Maison).';
                        break;
                    }
                    $pw = self::readNum($cfg['shed_power_cmd']);
                    $lim = floatval($cfg['shed_limit']);
                    $lines[] = '⚡ Puissance ' . ($pw === null ? '?' : round($pw)) . ' / seuil ' . round($lim) . ($pw !== null && $lim > 0 ? ' (' . round(100 * $pw / $lim) . ' %)' : '') . '.';
                    $act = $rt['shed']['active'];
                    $lines[] = empty($act) ? 'Aucune batterie coupée.' : 'Coupée(s) : ' . implode(', ', array_map(function ($b) use ($cfg) {
                        return '« ' . $cfg[$b . '_name'] . ' »';
                    }, $act)) . '.';
                    break;
                case 'filter':
                    if ($cfg['mod_filter'] != '1') {
                        $lines[] = '🧹 Filtre : module désactivé (onglet Maison).';
                        break;
                    }
                    $raw = self::readRaw($cfg['filter_cmd']);
                    $dirty = ($raw !== null && self::compare($raw, '==', $cfg['filter_value']));
                    $cl = $rt['filter']['cleaned'];
                    $lines[] = '🧹 Filtre de la VMC : ' . ($raw === null ? 'statut illisible' : ($dirty ? 'À NETTOYER' : 'propre')) . '.';
                    $lines[] = $cl ? 'Dernier nettoyage le ' . date('d/m/Y', strtotime($cl)) . ' (' . intval(floor(($now - strtotime($cl)) / 86400)) . ' jours).' : 'Date du dernier nettoyage pas encore connue.';
                    break;
                case 'preheat':
                    if ($cfg['preheat_cmd'] === '') {
                        $lines[] = '❄️ Préchauffage : pas de commande d\'état choisie (onglet VMC, registre 4060).';
                        break;
                    }
                    $pon = self::readPreheat($cfg);
                    $ppw = self::readNum($cfg['preheat_power_cmd']);
                    $lines[] = '❄️ Préchauffage antigel de la VMC : ' . ($pon === null ? 'état illisible' : ($pon ? 'EN MARCHE' . ($ppw !== null ? ' (' . round($ppw) . ' %)' : '') : 'arrêté')) . ', ' . $f($ctx['temp_out']) . ' °C dehors.';
                    $lines[] = 'Aujourd\'hui : ' . self::fmtDuration(round((isset($rt['preheat']['today_sec']) ? $rt['preheat']['today_sec'] : 0) / 60)) . ' de préchauffage.'
                        . ((isset($rt['hx']['eff']) && $rt['hx']['eff'] !== null) ? ' Efficacité de l\'échangeur : ' . round($rt['hx']['eff']) . ' %.' : '');
                    break;
                case 'alert':
                    $lines[] = '⚠️ ' . (!empty($rt['last_alert']) ? 'Dernière alerte : ' . $rt['last_alert'] : 'Aucune alerte en cours.');
                    $chk = self::checkConfig($cfg);
                    $nErr = 0;
                    foreach ($chk['errors'] as $list) {
                        $nErr += count($list);
                    }
                    $lines[] = $nErr ? $nErr . ' problème(s) de configuration : voir l\'onglet Récapitulatif.' : 'Configuration complète.';
                    break;
                case 'safety':
                    $h = $rt['heat'];
                    $lock = self::nightLock($cfg, $h, $now);
                    $lines[] = '🛡️ Verrou de nuit : ' . (!empty($h['lock']) ? 'actif de ' . $cfg['night_lock_start'] . ' à ' . $cfg['night_lock_end'] . ($lock !== null ? ' (en cours)' : '') : 'DÉSACTIVÉ') . '.';
                    $fl = self::forceLabel($cfg, $h, $now);
                    $lines[] = 'Chauffage forcé : ' . ($fl !== '' ? $fl : 'aucun') . '.';
                    $cache = cache::byKey('ThermoFlair325::diag::' . $this->getId())->getValue(null);
                    if (is_array($cache) && !empty($cache['guard']['rows'])) {
                        $bad = array_filter($cache['guard']['rows'], function ($r) {
                            return $r['state'] === false;
                        });
                        $lines[] = $bad ? 'Garde-fous à vérifier : ' . implode(' ; ', array_map(function ($r) {
                            return $r['label'] . ' (' . $r['detail'] . ')';
                        }, $bad)) . '.' : 'Garde-fous : tout est respecté (' . count($cache['guard']['rows']) . ' contrôles).';
                    }
                    break;
                case 'log':
                    $parts = array('bas ' . $f($ctx['temp_in']) . ' °C');
                    if ($ctx['temp_up'] !== null) {
                        $parts[] = 'haut ' . $f($ctx['temp_up']) . ' °C';
                    }
                    if ($ctx['temp_out'] !== null) {
                        $parts[] = 'dehors ' . $f($ctx['temp_out']) . ' °C';
                    }
                    if ($ctx['lux'] !== null) {
                        $parts[] = 'luminosité ' . round($ctx['lux']) . ' lux';
                    }
                    $lines[] = '📒 Journal : ' . implode(', ', $parts) . '.';
                    $sun = self::solarOverheat($cfg, $ctx, $rt, $now);
                    if ($sun !== null) {
                        $lines[] = $sun . '.';
                    }
                    $lines[] = 'Exemples de ce qui arrive ici : changements de mode, de consigne, boosts, vitesses temporaires, thermostat ON/OFF.';
                    break;
            }
            if ($lines) {
                $out[] = array('cat' => $cat, 'title' => self::NOTIF_TITLES[$cat], 'text' => implode("\n", $lines));
            }
        }
        return $out;
    }

    /*
     * $cat : vmc | heat | shed | filter | alert | log | test.
     * Un destinataire reçoit le message si la case de la catégorie est
     * cochée, ou s'il a "Toutes les actions". Pendant le repos (si sa case
     * "Repos" est cochée), il ne reçoit que les alertes et le délestage.
     */
    public static function sendNotifications($notifications, $title, $text, $cat, $rest = false) {
        $sent = 0;
        $problems = array();
        if (!is_array($notifications)) {
            return array('sent' => 0, 'detail' => 'aucun destinataire');
        }
        $lines = array_map(array(__CLASS__, 'stripTrailingSpace'), explode("\n", $text));
        foreach ($notifications as $n) {
            if (empty($n['cmd_id'])) {
                continue;
            }
            if ($cat != 'test') {
                $flag = $cat;
                // Anciennes configurations sans case « Sécurité » : suivent « Alertes ».
                if ($cat == 'safety' && !isset($n['n_safety'])) {
                    $flag = 'alert';
                }
                // Anciennes configurations sans case « Préchauffage » : suivent « VMC ».
                if ($cat == 'preheat' && !isset($n['n_preheat'])) {
                    $flag = 'vmc';
                }
                $wants = (!empty($n['n_all']) && $n['n_all'] == '1') || (!empty($n['n_' . $flag]) && $n['n_' . $flag] == '1');
                if (!$wants) {
                    continue;
                }
                if ($rest && !empty($n['rest']) && $n['rest'] == '1' && !in_array($cat, self::NOTIF_URGENT)) {
                    continue;
                }
            }
            $cmd = self::resolveCmd($n['cmd_id']);
            if (!is_object($cmd) || $cmd->getType() != 'action') {
                $problems[] = 'commande introuvable ' . $n['cmd_id'];
                continue;
            }
            $useHtml = !empty($n['html']) && $n['html'] == '1';
            $t = !empty($n['title']) ? $n['title'] : $title;
            if ($cat == 'test') {
                $t = '[TEST] ' . $t;
            }
            try {
                $cmd->execCmd(array('title' => $t, 'message' => implode($useHtml ? '<br/>' : "\n", $lines)));
                $sent++;
            } catch (\Throwable $e) {
                $problems[] = $n['cmd_id'] . ' : ' . $e->getMessage();
                log::add(self::PLUGIN, 'error', 'Échec notification ' . $n['cmd_id'] . ' : ' . $e->getMessage());
            }
        }
        return array('sent' => $sent, 'detail' => implode(' ; ', $problems));
    }

    /* ---------------------------------------------------------------- */
    /* Commandes info                                                    */
    /* ---------------------------------------------------------------- */

    private function publishInfos($cfg, $rt, $ctx) {
        $c = false;
        $set = function ($lid, $val) use (&$c) {
            if ($this->checkAndUpdateCmd($lid, $val)) {
                $c = true;
            }
        };
        $set('season', self::seasonLabel($ctx['season']));
        if ($ctx['temp_in'] !== null) {
            $set('temp_in', round($ctx['temp_in'], 1));
        }
        if ($ctx['temp_up'] !== null) {
            $set('temp_up', round($ctx['temp_up'], 1));
        }
        $set('alert', !empty($rt['last_alert']) ? $rt['last_alert'] : '');
        $v = $rt['vmc'];
        $set('vmc_auto', $v['auto'] ? 1 : 0);
        if ($ctx['pos'] !== null) {
            $set('vmc_speed', $ctx['pos']);
        }
        if ($cfg['mod_vmc'] == '1') {
            if ($v['target'] !== null) {
                $set('vmc_target', $v['target']);
            }
            $set('vmc_reason', $v['reason'] !== '' ? $v['reason'] : '—');
            $set('vmc_override', self::overrideLabel($v['ovr']));
            $set('vmc_mode', ($cfg['vmc_mode_' . ($ctx['season'] == 'summer' ? 's' : 'w')] == 'bypass') ? 'Bypass' : 'Températures');
        }
        if ($ctx['bypass'] !== null) {
            $set('bypass', $ctx['bypass'] ? 1 : 0);
        }
        $h = $rt['heat'];
        $set('heat_on', $h['on'] ? 1 : 0);
        $set('consigne', round($h['consigne'], 1));
        $set('consigne_eco', round($h['eco'], 1));
        $set('heat_mode', $h['mode'] == 'eco' ? 'ECO' : 'Normal');
        $set('night_lock', !empty($h['lock']) ? 1 : 0);
        $set('heat_force_state', self::forceLabel($cfg, $h, self::now()));
        if ($ctx['lux'] !== null) {
            $set('sun', round(min(1, $ctx['lux'] / max(1, floatval($cfg['lux_max']))) * 100));
        }
        if ($cfg['mod_heat'] == '1') {
            $kwh = 0;
            foreach (array('b1', 'b2') as $b) {
                if ($cfg[$b . '_enabled'] != '1') {
                    continue;
                }
                $set($b . '_state', $h[$b]['actual'] === true ? 1 : 0);
                $set($b . '_status', $h[$b]['reason'] !== '' ? $h[$b]['reason'] : '—');
                $set($b . '_today', round($h[$b]['today_sec'] / 60));
                if ($h[$b]['target'] !== null) {
                    $set($b . '_target', round($h[$b]['target'], 1));
                }
                $kwh += $h[$b]['today_sec'] / 3600 * floatval($cfg[$b . '_power']) / 1000;
            }
            $set('energy_today', round($kwh, 2));
        }
        $set('shed_state', !empty($rt['shed']['active']) ? 1 : 0);
        if (isset($ctx['modbus']) && $ctx['modbus'] !== null) {
            $set('modbus_ok', $ctx['modbus'] ? 1 : 0);
        }
        if (isset($rt['hx']['eff']) && $rt['hx']['eff'] !== null) {
            $set('hx_eff', round($rt['hx']['eff']));
        }
        if ($cfg['preheat_cmd'] !== '' && isset($rt['preheat']['on']) && $rt['preheat']['on'] !== null) {
            $set('preheat_state', $rt['preheat']['on'] ? 1 : 0);
            $set('preheat_today', round($rt['preheat']['today_sec'] / 60));
        }
        if ($cfg['mod_filter'] == '1') {
            $set('filter_status', !empty($rt['filter']['dirty']) ? 1 : 0);
            if (isset($rt['filter']['days']) && $rt['filter']['days'] !== null) {
                $set('filter_days', $rt['filter']['days']);
            }
        }
        return $c;
    }

    public static function forceLabel($cfg, $h, $now) {
        $parts = array();
        $end = 0;
        foreach (array('b1', 'b2') as $b) {
            if (!empty($h['force'][$b]) && $now < intval($h['force'][$b])) {
                $parts[] = $cfg[$b . '_name'];
                $end = max($end, intval($h['force'][$b]));
            }
        }
        return $parts ? implode(' + ', $parts) . ' jusqu\'à ' . date('H:i', $end) : '';
    }

    public static function overrideLabel($ovr) {
        if (empty($ovr)) {
            return '';
        }
        $names = array('boost' => 'Boost', 'force' => 'Vitesse ' . $ovr['speed'] . ' temporaire', 'manual' => 'Réglage manuel (vitesse ' . $ovr['speed'] . ')');
        $lab = ($ovr['source'] != 'manual' && !empty($ovr['label'])) ? $ovr['label'] : $names[$ovr['source']];
        return $lab . ' jusqu\'à ' . date('H:i', intval($ovr['until']));
    }

    /* ================================================================ */
    /* Actions (commandes du plugin, widget, scénarios)                  */
    /* ================================================================ */

    public function handleAction($lid, $opt) {
        $this->withLock(function () use ($lid, $opt) {
            $now = self::now();
            $cfg = self::getCfg($this);
            $rt = $this->getRuntime();
            self::initRuntime($cfg, $rt);
            $msgs = array();
            $v = &$rt['vmc'];
            $h = &$rt['heat'];
            $value = null;
            if (is_array($opt)) {
                if (isset($opt['slider']) && $opt['slider'] !== '') {
                    $value = $opt['slider'];
                } elseif (isset($opt['message']) && is_numeric(trim($opt['message']))) {
                    $value = trim($opt['message']);
                }
            }
            switch ($lid) {
                case 'vmc_auto_on':
                    $v['auto'] = 1;
                    $v['pre_sent_at'] = 0; // renvoie le mode ModBus tout de suite
                    $v['auto_off_pending'] = 0;
                    $v['last_change'] = 0;
                    $this->logAction($msgs, '💨 VMC : mode automatique activé.');
                    break;
                case 'vmc_auto_off':
                    $v['auto'] = 0;
                    $v['auto_off_pending'] = 1;
                    $this->logAction($msgs, '💨 VMC : mode automatique coupé (vitesse ' . intval($cfg['vmc_off_speed']) . ').');
                    break;
                case (self::boostById($cfg, $lid) !== null ? $lid : '#none#'):
                    // Ligne du tableau des boosts : vitesse et durée de la ligne.
                    $bo = self::boostById($cfg, $lid);
                    $sp = max(1, min(4, intval($bo['speed'])));
                    $min = max(1, min(1440, intval($bo['minutes'])));
                    if ($cfg['vmc_s' . $sp . '_cmd'] === '' || !is_object(self::resolveCmd($cfg['vmc_s' . $sp . '_cmd']))) {
                        throw new Exception('« ' . $bo['name'] . ' » impossible : aucune commande vitesse ' . $sp . ' configurée (onglet VMC).');
                    }
                    // Un seul message : celui du changement de vitesse (cycle juste après).
                    $v['ovr'] = array('speed' => $sp, 'until' => $now + $min * 60, 'source' => $sp == 4 ? 'boost' : 'force', 'started' => $now,
                        'label' => $bo['name'] . ' (vitesse ' . $sp . ', ' . self::fmtDuration($min) . ')', 'announce' => 1);
                    break;
                case 'override_stop':
                    if (!empty($v['ovr'])) {
                        $v['ovr'] = null;
                        $v['last_change'] = 0;
                        if (!$v['auto']) {
                            $v['auto_off_pending'] = 1;
                        }
                        $this->logAction($msgs, '💨 VMC : forçage annulé, retour ' . ($v['auto'] ? "à l'automatique." : 'en manuel.'));
                    }
                    break;
                case 'heat_on_cmd':
                    $h['on'] = 1;
                    $this->logAction($msgs, '🔥 Thermostat activé (mode ' . ($h['mode'] == 'eco' ? 'ECO ' . self::fmt($h['eco']) : 'Normal ' . self::fmt($h['consigne'])) . ' °C).');
                    break;
                case 'heat_off_cmd':
                    $h['on'] = 0;
                    $this->logAction($msgs, '❄️ Thermostat coupé.');
                    break;
                case 'consigne_set':
                case 'consigne_eco_set':
                    if ($value === null || !is_numeric($value)) {
                        throw new Exception('Consigne invalide.');
                    }
                    // ECO toujours au moins ECO_GAP sous Normal : on borne la
                    // valeur demandée au lieu de déplacer l'autre consigne.
                    $cmin = floatval($cfg['heat_c_min']);
                    $cmax = floatval($cfg['heat_c_max']);
                    $c = round(floatval($value) * 2) / 2;
                    if ($lid == 'consigne_set') {
                        $c = max($cmin + self::ECO_GAP, max($h['eco'] + self::ECO_GAP, min($cmax, $c)));
                        if ($c != $h['consigne']) {
                            $h['consigne'] = $c;
                            $this->logAction($msgs, '🌡️ Consigne Normal réglée à ' . self::fmt($c) . ' °C.');
                        }
                    } else {
                        $c = max($cmin, min($cmax - self::ECO_GAP, min($h['consigne'] - self::ECO_GAP, $c)));
                        if ($c != $h['eco']) {
                            $h['eco'] = $c;
                            $this->logAction($msgs, '🌙 Consigne ECO réglée à ' . self::fmt($c) . ' °C.');
                        }
                    }
                    break;
                case 'heat_force':
                case 'heat_force_b1':
                case 'heat_force_all':
                    if (empty($h['on'])) {
                        throw new Exception('Thermostat OFF : activez-le d\'abord pour forcer le chauffage.');
                    }
                    if ($lid == 'heat_force') {
                        $t = mb_strtolower(trim(isset($opt['title']) ? $opt['title'] : ''));
                        $bats = (strpos($t, 'tout') !== false || strpos($t, 'deux') !== false || strpos($t, 'all') !== false || strpos($t, '2') !== false || strpos($t, 'haut') !== false) ? array('b1', 'b2') : array('b1');
                        $min = self::parseDuration(isset($opt['message']) ? $opt['message'] : '');
                    } else {
                        $bats = ($lid == 'heat_force_all') ? array('b1', 'b2') : array('b1');
                        $min = ($value !== null && is_numeric($value)) ? intval($value) : null;
                    }
                    if ($min === null || $min <= 0) {
                        $min = intval($cfg['heat_force_default']);
                    }
                    $min = max(5, min(720, $min));
                    $names = array();
                    foreach ($bats as $b) {
                        if ($cfg[$b . '_enabled'] == '1') {
                            $h['force'][$b] = $now + $min * 60;
                            $names[] = '« ' . $cfg[$b . '_name'] . ' »';
                        }
                    }
                    $this->logAction($msgs, '🔥 Chauffage forcé : ' . implode(' et ', $names) . ' pendant ' . self::fmtDuration($min) . ' (jusqu\'à ' . date('H:i', $now + $min * 60) . '), consigne Normal ' . self::fmt($h['consigne']) . ' °C.');
                    $lock = self::nightLock($cfg, $h, $now + 60);
                    if ($lock !== null) {
                        $msgs[] = array('cat' => 'safety', 'text' => '🛡️ Chauffage forcé demandé pendant le verrou de nuit (' . $lock['start'] . '–' . $lock['end'] . ') : il ne chauffera qu\'après ' . $lock['end'] . ', ou désactivez le verrou.');
                    }
                    break;
                case 'heat_force_stop':
                    if (!empty($h['force'])) {
                        $h['force'] = array();
                        $this->logAction($msgs, '🔥 Chauffage forcé arrêté : retour aux plages.');
                    }
                    break;
                case 'night_lock_on':
                case 'night_lock_off':
                    $nv = ($lid == 'night_lock_on') ? 1 : 0;
                    if (intval($h['lock']) != $nv) {
                        $h['lock'] = $nv;
                        $msgs[] = array('cat' => 'safety', 'text' => $nv ? '🛡️ Verrou de nuit activé (' . $cfg['night_lock_start'] . '–' . $cfg['night_lock_end'] . ').' : '🛡️ Verrou de nuit DÉSACTIVÉ : les batteries peuvent chauffer la nuit si une plage le demande.');
                    }
                    break;
                case 'mode_normal':
                case 'mode_eco':
                    $nm = ($lid == 'mode_eco') ? 'eco' : 'normal';
                    if ($h['mode'] != $nm) {
                        $h['mode'] = $nm;
                        $this->logAction($msgs, $nm == 'eco' ? '🌙 Chauffage en mode ECO (' . self::fmt($h['eco']) . ' °C).' : '☀️ Chauffage en mode Normal (' . self::fmt($h['consigne']) . ' °C).');
                    }
                    break;
                case 'filter_reset':
                    $rc = self::resolveCmd($cfg['filter_reset_cmd']);
                    if (!is_object($rc)) {
                        throw new Exception('Remise à zéro du filtre : choisissez d\'abord la commande (onglet Filtre, registre 8010 valeur 1).');
                    }
                    $rc->execCmd();
                    $this->logAction($msgs, '🧹 Avertissement filtre de la VMC remis à zéro.');
                    break;
                case 'refresh':
                    break;
                default:
                    return;
            }
            unset($v, $h);
            $this->saveRuntime($rt);
            $this->sendQueued($cfg, $msgs, $now);
        });
        $this->evaluate('action:' . $lid);
    }

    /* ================================================================ */
    /* Cycle de vie                                                      */
    /* ================================================================ */

    public function preInsert() {
        foreach (self::DEFAULTS as $k => $v) {
            if ($this->getConfiguration($k, null) === null) {
                $this->setConfiguration($k, $v);
            }
        }
    }

    public function postSave() {
        $this->syncCmds();
        if ($this->getId() != '') {
            try {
                ThermoFlair325Advisor::record($this->getId(), self::getCfg($this), self::now());
                $this->syncListener();
                if ($this->getIsEnable() == 1) {
                    $this->evaluate('save');
                }
            } catch (\Throwable $e) {
                log::add(self::PLUGIN, 'error', 'postSave ' . $this->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /*
     * Sauvegarde depuis la page de l'équipement : après postSave, le cœur de
     * Jeedom réenregistre toutes les commandes de l'onglet Commandes telles
     * qu'affichées (anciens noms, commande d'une ligne supprimée recréée).
     * On resynchronise donc les commandes des boosts une fois cela fait.
     */
    public function preAjax() {
        // Pendant la sauvegarde depuis la page, les commandes des boosts ne
        // sont touchées qu'à la fin (postAjax) : pas de conflit de noms avec
        // les commandes que le cœur réenregistre entre-temps.
        $GLOBALS['THERMOFLAIR325_AJAX_SAVE'] = true;
    }

    public function postAjax() {
        unset($GLOBALS['THERMOFLAIR325_AJAX_SAVE']);
        try {
            $this->syncCmds();
        } catch (\Throwable $e) {
            log::add(self::PLUGIN, 'error', 'postAjax ' . $this->getHumanName() . ' : ' . $e->getMessage());
        }
    }

    public function preRemove() {
        $listener = listener::byClassAndFunction(self::PLUGIN, 'onPower', array('eqLogic_id' => intval($this->getId())));
        if (is_object($listener)) {
            $listener->remove();
        }
        // Nettoyage fait ici : après la suppression, l'ID n'est plus connu.
        $id = intval($this->getId());
        if ($id > 0) {
            config::remove('runtime::' . $id, self::PLUGIN);
            cache::delete('ThermoFlair325::diag::' . $id);
            $dir = ThermoFlair325Stats::dataDir() . '/stats/' . $id;
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: array() as $f) {
                    @unlink($f);
                }
                @rmdir($dir);
            }
        }
    }

    public function postRemove() {
    }

    /*
     * Supprime ce qui reste d'équipements qui n'existent plus (état interne,
     * écouteurs, cache, statistiques). Lancé à l'installation et à chaque mise
     * à jour : la base reste propre même après une suppression interrompue.
     * Retourne le nombre d'éléments supprimés.
     */
    public static function cleanOrphans() {
        $ids = array();
        foreach (eqLogic::byType(self::PLUGIN) as $eq) {
            $ids[intval($eq->getId())] = true;
        }
        $n = 0;
        foreach (config::searchKey('runtime::', self::PLUGIN) ?: array() as $row) {
            $id = intval(substr($row['key'], strlen('runtime::')));
            if (!isset($ids[$id])) {
                config::remove($row['key'], self::PLUGIN);
                cache::delete('ThermoFlair325::diag::' . $id);
                $n++;
            }
        }
        foreach (listener::byClass(self::PLUGIN) ?: array() as $l) {
            $opt = $l->getOption();
            $id = is_array($opt) && isset($opt['eqLogic_id']) ? intval($opt['eqLogic_id']) : 0;
            if (!isset($ids[$id])) {
                $l->remove();
                $n++;
            }
        }
        $base = ThermoFlair325Stats::dataDir() . '/stats';
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: array() as $dir) {
            $id = intval(basename($dir));
            if ($id > 0 && !isset($ids[$id])) {
                foreach (glob($dir . '/*') ?: array() as $f) {
                    @unlink($f);
                }
                @rmdir($dir);
                $n++;
            }
        }
        return $n;
    }

    public function syncCmds() {
        $cfg = self::getCfg($this);
        // Commandes d'une ancienne version qui n'existent plus.
        $boostIds = array_column($cfg['vmc_boosts'], 'id');
        $boostSync = empty($GLOBALS['THERMOFLAIR325_AJAX_SAVE']);
        foreach ($this->getCmd() as $old) {
            if ($boostSync && !array_key_exists($old->getLogicalId(), self::CMDS) && !in_array($old->getLogicalId(), $boostIds, true)) {
                $old->remove();
            }
        }
        $order = 0;
        foreach (self::CMDS as $lid => $d) {
            $cmd = $this->getCmd(null, $lid);
            if (!is_object($cmd)) {
                $cmd = new ThermoFlair325Cmd();
                $cmd->setLogicalId($lid);
                $cmd->setEqLogic_id($this->getId());
                $name = $d['name'];
                if (preg_match('/^b([12])_/', $lid, $m)) {
                    $name = str_replace('Batterie ' . $m[1], 'Batterie ' . $cfg['b' . $m[1] . '_name'], $name);
                }
                $cmd->setName($name);
                $cmd->setIsVisible($d['visible']);
                $cmd->setOrder($order);
            }
            $cmd->setType($d['type']);
            $cmd->setSubType($d['subType']);
            if (isset($d['unite'])) {
                $cmd->setUnite($d['unite']);
            }
            if ($lid == 'heat_force_b1' || $lid == 'heat_force_all') {
                $cmd->setConfiguration('minValue', 5);
                $cmd->setConfiguration('maxValue', 720);
                $cmd->setDisplay('parameters', array('step' => 5));
                $cmd->setUnite('min');
            }
            if ($lid == 'heat_force') {
                $cmd->setDisplay('title_placeholder', 'bas ou tout');
                $cmd->setDisplay('message_placeholder', 'durée : 60, 1h, 1h30');
            }
            if ($lid == 'consigne_set' || $lid == 'consigne_eco_set') {
                $cmd->setConfiguration('minValue', floatval($cfg['heat_c_min']));
                $cmd->setConfiguration('maxValue', floatval($cfg['heat_c_max']));
                $cmd->setDisplay('parameters', array('step' => 0.5));
            }
            $cmd->save();
            $order++;
        }
        // Une commande action par ligne du tableau des boosts : nom, aide et
        // ordre suivent le tableau ; l'affichage (Afficher, icône) reste libre.
        if (!$boostSync) {
            return;
        }
        $taken = array();
        foreach ($this->getCmd() as $c) {
            if (!in_array($c->getLogicalId(), $boostIds, true)) {
                $taken[mb_strtolower($c->getName())] = true;
            }
        }
        // Nom temporaire d'abord pour les commandes renommées : permet
        // d'échanger deux noms sans doublon (refusé par Jeedom).
        foreach ($cfg['vmc_boosts'] as $b) {
            $cmd = $this->getCmd(null, $b['id']);
            if (is_object($cmd) && $cmd->getName() !== $b['name']) {
                $cmd->setName('~' . $b['id']);
                $cmd->save();
            }
        }
        foreach ($cfg['vmc_boosts'] as $b) {
            $cmd = $this->getCmd(null, $b['id']);
            if (!is_object($cmd)) {
                $cmd = new ThermoFlair325Cmd();
                $cmd->setLogicalId($b['id']);
                $cmd->setEqLogic_id($this->getId());
                $cmd->setIsVisible(0);
            }
            $name = $b['name'] !== '' ? $b['name'] : 'Boost ' . $b['id'];
            if (isset($taken[mb_strtolower($name)])) {
                $name .= ' (VMC)';
            }
            $taken[mb_strtolower($name)] = true;
            $cmd->setName(mb_substr($name, 0, 120));
            $cmd->setType('action');
            $cmd->setSubType('other');
            $cmd->setConfiguration('tf_help', self::boostHelp($b));
            $cmd->setOrder($order++);
            $cmd->save();
        }
        $links = array('consigne_set' => 'consigne', 'consigne_eco_set' => 'consigne_eco', 'vmc_auto_on' => 'vmc_auto', 'vmc_auto_off' => 'vmc_auto', 'heat_on_cmd' => 'heat_on', 'heat_off_cmd' => 'heat_on');
        foreach ($links as $a => $i) {
            $ca = $this->getCmd('action', $a);
            $ci = $this->getCmd('info', $i);
            if (is_object($ca) && is_object($ci) && $ca->getValue() != $ci->getId()) {
                $ca->setValue($ci->getId());
                $ca->save();
            }
        }
    }

    /* ================================================================ */
    /* Widget                                                            */
    /* ================================================================ */

    public function widgetValues() {
        $cfg = self::getCfg($this);
        $rt = $this->getRuntime();
        self::initRuntime($cfg, $rt);
        $now = self::now();
        $v = $rt['vmc'];
        $h = $rt['heat'];
        $esc = function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        };
        $r = array();

        $r['#vmc_display#'] = ($cfg['mod_vmc'] == '1') ? '' : 'display:none;';
        $pos = $v['pos_last'];
        $pills = '';
        for ($i = 1; $i <= 4; $i++) {
            $cls = 'tf-pill' . (($pos !== null && $pos == $i) ? ' tf-on' : '') . ($i == 4 ? ' tf-v4' : '') . (($v['target'] !== null && $v['target'] == $i && $pos != $i) ? ' tf-next' : '');
            $pills .= '<span class="' . $cls . '" title="Vitesse ' . $i . ' — ' . self::speedLabel($cfg, $i) . '">' . $i . '</span>';
        }
        $cap = self::quietCap($cfg, $now);
        if ($cap !== null) {
            $pills .= '<span class="tf-small" title="Plage de silence"> 🌙 max ' . $cap['max'] . ' jusqu\'à ' . $cap['end'] . '</span>';
        }
        $r['#vmc_pills#'] = $pills;
        $r['#vmc_reason#'] = $esc($v['reason'] !== '' ? $v['reason'] : '—');
        $byp = self::readBypass($cfg);
        $r['#bypass_display#'] = ($byp === null) ? 'display:none;' : '';
        $r['#bypass_txt#'] = ($byp === true) ? '↪️ bypass ouvert' : '🔁 échangeur actif (bypass fermé)'
            . ((isset($rt['hx']['eff']) && $rt['hx']['eff'] !== null && $byp !== true) ? ' — efficacité ' . round($rt['hx']['eff']) . ' %' : '');
        $ph = ($cfg['preheat_cmd'] !== '' && !empty($rt['preheat']['on']));
        $r['#preheat_display#'] = $ph ? '' : 'display:none;';
        $r['#preheat_txt#'] = $ph ? '❄️ préchauffage antigel en marche' . (($pw = self::readNum($cfg['preheat_power_cmd'])) !== null ? ' (' . round($pw) . ' %)' : '') : '';
        $r['#vmc_auto_class#'] = $v['auto'] ? 'tf-toggle-on' : '';
        $r['#vmc_auto_label#'] = $v['auto'] ? 'Auto' : 'Manuel';
        $ovr = self::overrideLabel($v['ovr']);
        $r['#vmc_override#'] = $esc($ovr);
        $r['#vmc_override_display#'] = $ovr === '' ? 'display:none;' : '';
        // Boutons des boosts cochés « Widget » (vitesse disponible seulement).
        $btns = '';
        foreach ($cfg['vmc_boosts'] as $b) {
            $sp = intval($b['speed']);
            if ($b['widget'] != '1' || $sp < 1 || $sp > 4 || $cfg['vmc_s' . $sp . '_cmd'] === '') {
                continue;
            }
            $c = $this->getCmd('action', $b['id']);
            if (!is_object($c)) {
                continue;
            }
            $btns .= '<a class="btn btn-xs ' . ($sp == 4 ? 'btn-warning' : 'btn-info') . ' tf-cmd" data-cmd_id="' . $c->getId() . '" title="' . $esc('Vitesse ' . $sp . ' pendant ' . self::fmtDuration(intval($b['minutes']))) . '">' . $esc($b['name']) . '</a> ';
        }
        $r['#boost_buttons#'] = $btns;
        $r['#boost_display#'] = $btns !== '' ? '' : 'display:none;';

        $r['#heat_display#'] = ($cfg['mod_heat'] == '1') ? '' : 'display:none;';
        $r['#heat_class#'] = $h['on'] ? 'tf-toggle-on' : '';
        $r['#heat_label#'] = $h['on'] ? 'Thermostat ON' : 'Thermostat OFF';
        $r['#consigne#'] = self::fmt($h['consigne']);
        $r['#consigne_raw#'] = floatval($h['consigne']);
        $r['#eco#'] = self::fmt($h['eco']);
        $r['#eco_raw#'] = floatval($h['eco']);
        $r['#eco_gap#'] = self::ECO_GAP;
        $r['#mode_label#'] = ($h['mode'] == 'eco') ? '🌙 ECO' : '☀️ Normal';
        $fm = intval($cfg['heat_force_default']);
        $r['#force_min#'] = $fm;
        $r['#force_txt#'] = self::fmtDuration($fm);
        $fl = self::forceLabel($cfg, $h, $now);
        $r['#force_state#'] = $esc($fl);
        $r['#force_state_display#'] = ($fl === '') ? 'display:none;' : '';
        $r['#b1_name#'] = $esc($cfg['b1_name'] !== '' ? $cfg['b1_name'] : 'Batterie 1');
        $r['#b2_name#'] = $esc($cfg['b2_name'] !== '' ? $cfg['b2_name'] : 'Batterie 2');
        $r['#force_b2_display#'] = ($cfg['b2_enabled'] == '1') ? '' : 'display:none;';
        $nl = self::nightLock($cfg, $h, $now);
        $r['#lock_txt#'] = !empty($h['lock']) ? '🛡️ verrou de nuit ' . $cfg['night_lock_start'] . '–' . $cfg['night_lock_end'] . ($nl !== null ? ' (en cours)' : '') : '⚠️ verrou de nuit désactivé';
        $r['#mode_state#'] = ($h['mode'] == 'eco') ? 1 : 0;
        $r['#normal_active#'] = ($h['mode'] == 'eco') ? '' : 'tf-active';
        $r['#eco_active#'] = ($h['mode'] == 'eco') ? 'tf-active' : '';
        $lux = self::readNum($cfg['lux_cmd']);
        $r['#sun#'] = ($lux !== null) ? round($lux) : '?';
        $r['#sun_icon#'] = ($lux !== null && $lux >= 0.95 * floatval($cfg['lux_max'])) ? '☀️' : '☁️';
        $r['#sun_display#'] = ($cfg['lux_cmd'] !== '') ? '' : 'display:none;';
        $wx = ($cfg['weather_cmd'] !== '') ? self::mapWeather(self::readRaw($cfg['weather_cmd']), $cfg['weather_source']) : null;
        $r['#wx_display#'] = ($wx === null) ? 'display:none;' : '';
        $r['#wx_icon#'] = ($wx === null) ? '' : $wx['icon'];
        $wlab = ($cfg['weather_label_cmd'] !== '') ? trim((string) self::readRaw($cfg['weather_label_cmd'])) : '';
        $r['#wx_label#'] = ($wx === null) ? '' : $esc($wlab !== '' ? $wlab : $wx['label']);
        $wctx = array('temp_in' => self::readNum($cfg['temp_in_cmd']), 'temp_out' => self::readNum($cfg['temp_out_cmd']), 'lux' => $lux);
        $sol = self::solarOverheat($cfg, $wctx, $rt, $now);
        $r['#solar_display#'] = ($sol === null) ? 'display:none;' : '';
        $r['#solar_txt#'] = ($sol === null) ? '' : $esc(ucfirst($sol));
        $r['#c_min#'] = floatval($cfg['heat_c_min']);
        $r['#c_max#'] = floatval($cfg['heat_c_max']);
        $r['#temp_in#'] = self::fmt(self::readNum($cfg['temp_in_cmd']));
        $r['#temp_up#'] = self::fmt(self::readNum($cfg['temp_up_cmd']));
        $r['#temp_up_display#'] = ($cfg['temp_up_cmd'] !== '') ? '' : 'display:none;';
        $r['#temp_out#'] = self::fmt(self::readNum($cfg['temp_out_cmd']));
        $r['#temp_out_display#'] = ($cfg['temp_out_cmd'] !== '') ? '' : 'display:none;';
        $bats = '';
        foreach (array('b1', 'b2') as $b) {
            if ($cfg[$b . '_enabled'] != '1') {
                continue;
            }
            $on = ($h[$b]['actual'] === true);
            $min = round($h[$b]['today_sec'] / 60);
            $k = $h[$b]['today_sec'] / 3600 * floatval($cfg[$b . '_power']) / 1000;
            $shed = in_array($b, $rt['shed']['active']);
            $bats .= '<div class="tf-bat ' . ($on ? 'tf-bat-on' : '') . ($shed ? ' tf-bat-shed' : '') . '">'
                . '<span class="tf-bat-name">' . ($shed ? '⚡' : ($on ? '🔥' : '○')) . ' ' . $esc($cfg[$b . '_name'])
                . ($h[$b]['target'] !== null ? ' <span class="tf-small">→ ' . self::fmt($h[$b]['target']) . ' °C</span>' : '') . '</span>'
                . '<span class="tf-bat-time" title="Temps de chauffe aujourd\'hui">' . sprintf('%dh%02d', intdiv($min, 60), $min % 60) . ' · ' . self::fmt($k, 2) . ' kWh</span>'
                . '<div class="tf-small">' . $esc($h[$b]['reason'] !== '' ? $h[$b]['reason'] : '—') . '</div>'
                . '</div>';
        }
        $r['#batteries#'] = $bats;
        $r['#shed_display#'] = !empty($rt['shed']['active']) ? '' : 'display:none;';
        $r['#shed_text#'] = !empty($rt['shed']['active']) ? 'Délestage en cours (' . (isset($rt['shed']['power']) ? round($rt['shed']['power']) : '?') . ' / ' . round(floatval($cfg['shed_limit'])) . ')' : '';

        $f = $rt['filter'];
        $r['#filter_display#'] = ($cfg['mod_filter'] == '1') ? '' : 'display:none;';
        $r['#filter_label#'] = !empty($f['dirty']) ? '🔴 Filtre à nettoyer' : '✅ Filtre OK';
        $r['#filter_detail#'] = $esc(!empty($f['cleaned']) ? 'nettoyé le ' . date('d/m/Y', strtotime($f['cleaned'])) : '');

        $r['#season#'] = (self::seasonOf($cfg, $now) == 'winter') ? '❄️ Hiver' : '☀️ Été';
        return $r;
    }

    public function toHtml($_version = 'dashboard') {
        $cfg = self::getCfg($this);
        if ($cfg['custom_widget'] != '1') {
            return parent::toHtml($_version);
        }
        $replace = $this->preToHtml($_version);
        if (!is_array($replace)) {
            return $replace;
        }
        $version = jeedom::versionAlias($_version);
        try {
            $replace = array_merge($replace, $this->widgetValues());
        } catch (\Throwable $e) {
            log::add(self::PLUGIN, 'error', 'toHtml ' . $this->getHumanName() . ' : ' . $e->getMessage());
        }
        foreach (array('vmc_auto_on', 'vmc_auto_off', 'override_stop', 'heat_on_cmd', 'heat_off_cmd', 'consigne_set', 'consigne_eco_set', 'mode_normal', 'mode_eco', 'heat_force_b1', 'heat_force_all', 'heat_force_stop', 'refresh') as $lid) {
            $c = $this->getCmd('action', $lid);
            $replace['#' . $lid . '_id#'] = is_object($c) ? $c->getId() : '';
        }
        $rt = $this->getRuntime();
        $replace['#vmc_auto_state#'] = isset($rt['vmc']['auto']) ? $rt['vmc']['auto'] : 1;
        $replace['#heat_on_state#'] = isset($rt['heat']['on']) ? $rt['heat']['on'] : 1;
        return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, 'ThermoFlair325', 'ThermoFlair325')));
    }
}

class ThermoFlair325Cmd extends cmd {

    public function dontRemoveCmd() {
        return true;
    }

    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic) || $this->getType() != 'action') {
            return;
        }
        $eqLogic->handleAction($this->getLogicalId(), $_options);
    }
}
