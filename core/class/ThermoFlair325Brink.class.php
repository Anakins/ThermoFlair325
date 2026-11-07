<?php
/*
 * ThermoFlair 325 — aide ModBus pour la Brink Flair 325 et le plugin MyModbus.
 *
 *  - catalogue des registres ModBus de la Flair 325 (doc Brink 615676-B,
 *    chapitre 14), trié par catégorie, avec le réglage MyModbus conseillé ;
 *  - codes de panne de l'écran (chapitre 8) ;
 *  - analyse d'un équipement MyModbus : chaque commande est reconnue,
 *    vérifiée (fonction, format, valeur écrite) et sa valeur décodée.
 *
 * Les clés de configuration MyModbus utilisées (cmdAddress, cmdFctModbus,
 * cmdFormat, cmdWriteValue, cmdOption) sont celles du plugin MyModbus 3.x.
 */

class ThermoFlair325Brink {

    const CATEGORIES = array(
        'pilot' => array('icon' => 'fa-gamepad', 'title' => 'Pilotage de la VMC', 'help' => 'Ce dont ThermoFlair 325 a besoin pour piloter la VMC.'),
        'state' => array('icon' => 'fa-toggle-on', 'title' => 'États : bypass, filtre, préchauffage', 'help' => 'Lecture de l\'état de la VMC. Seule la remise à zéro du filtre écrit quelque chose.'),
        'measure' => array('icon' => 'fa-thermometer-half', 'title' => 'Mesures', 'help' => 'Pour l\'affichage et l\'historique. Le plugin ne s\'en sert pas pour décider.'),
        'id' => array('icon' => 'fa-id-card', 'title' => 'Identification', 'help' => 'Valeurs fixes : lisez-les rarement (« Lecture 1x sur » 1000).'),
        'settings' => array('icon' => 'fa-user-cog', 'title' => 'Réglages de l\'installateur', 'help' => 'Réglés à la mise en service. Lecture possible, modification déconseillée.'),
    );

    // Niveau d'intérêt d'un registre.
    const LEVELS = array(
        'need' => array('label' => 'Indispensable', 'class' => 'danger'),
        'rec' => array('label' => 'Conseillé', 'class' => 'warning'),
        'opt' => array('label' => 'Utile', 'class' => 'info'),
        'test' => array('label' => 'À tester', 'class' => 'default'),
        'avoid' => array('label' => 'Lecture seule', 'class' => 'default'),
        'never' => array('label' => 'Ne pas créer', 'class' => 'danger'),
    );

    const FCT = array('1' => '0x01', '2' => '0x02', '3' => '0x03 Read holding registers', '4' => '0x04 Read input registers', '5' => '0x05', '6' => '0x06 Write register', '15' => '0x0F', '16' => '0x10 Write registers');
    const FMT = array('H' => 'uint16', 'h' => 'int16', 'uint8' => 'uint8', 'uint8-msb' => 'uint8 MSB', 'i' => 'int32', 'I' => 'uint32', 'f' => 'float32', 'bit' => 'bit', 's' => 'chaîne', 'blob' => 'plage');

    /*
     * Catalogue. dir : r = commande info (lecture), w = commande action
     * (écriture). value : valeur à écrire (Paramètres de MyModbus).
     * field : champ de ThermoFlair 325 qui utilise cette commande.
     */
    public static function catalog() {
        $speed = array(0 => 'vitesse 1 (absence)', 1 => 'vitesse 2 (bas)', 2 => 'vitesse 3 (normal)', 3 => 'vitesse 4 (haut)');
        return array(
            // ---------------- Pilotage ----------------
            array('addr' => 8000, 'dir' => 'w', 'fct' => '6', 'fmt' => 'H', 'value' => '1', 'cat' => 'pilot', 'level' => 'need', 'field' => 'vmc_pre_action_cmd',
                'name' => 'Mode ModBus ON', 'desc' => 'Passe la VMC en pilotage ModBus « interrupteur ». Sans lui, les vitesses sont ignorées. Remis à 0 par une coupure de courant : le plugin le renvoie régulièrement.'),
            array('addr' => 8000, 'dir' => 'r', 'fct' => '3', 'fmt' => 'H', 'cat' => 'pilot', 'level' => 'opt', 'field' => 'vmc_pre_cmd', 'expect' => '1',
                'name' => 'Mode ModBus (lecture)', 'decode' => array(0 => 'désactivé', 1 => 'interrupteur (vitesses)', 2 => 'débit'),
                'desc' => 'Facultatif. Brink : « la valeur lue est la dernière valeur acceptée ». Elle repasse à 0 après une coupure de courant, d\'où son intérêt ; elle ne voit pas tout, d\'où le renvoi régulier.'),
            array('addr' => 8001, 'dir' => 'w', 'fct' => '6', 'fmt' => 'H', 'value' => '0', 'cat' => 'pilot', 'level' => 'need', 'field' => 'vmc_s1_cmd',
                'name' => 'Vitesse 1 (absence)', 'desc' => 'Position 0 de la Flair (« vacances »).'),
            array('addr' => 8001, 'dir' => 'w', 'fct' => '6', 'fmt' => 'H', 'value' => '1', 'cat' => 'pilot', 'level' => 'need', 'field' => 'vmc_s2_cmd',
                'name' => 'Vitesse 2 (bas)', 'desc' => 'Position 1 de la Flair.'),
            array('addr' => 8001, 'dir' => 'w', 'fct' => '6', 'fmt' => 'H', 'value' => '2', 'cat' => 'pilot', 'level' => 'need', 'field' => 'vmc_s3_cmd',
                'name' => 'Vitesse 3 (normal)', 'desc' => 'Position 2 de la Flair.'),
            array('addr' => 8001, 'dir' => 'w', 'fct' => '6', 'fmt' => 'H', 'value' => '3', 'cat' => 'pilot', 'level' => 'rec', 'field' => 'vmc_s4_cmd',
                'name' => 'Vitesse 4 (boost)', 'desc' => 'Position 3 de la Flair (haut). Utilisée seulement par les boosts.'),
            array('addr' => 8001, 'dir' => 'r', 'fct' => '3', 'fmt' => 'H', 'cat' => 'pilot', 'level' => 'need', 'field' => 'vmc_pos_cmd', 'decode' => $speed,
                'name' => 'Position ventilation', 'desc' => 'Vitesse en cours (0 à 3). Dans ThermoFlair : « Valeur lue pour chaque vitesse » = 0, 1, 2, 3. C\'est la dernière position acceptée en ModBus.'),
            array('addr' => 8002, 'dir' => 'w', 'fct' => '6', 'fmt' => 'H', 'cat' => 'pilot', 'level' => 'avoid',
                'name' => 'Débit souhaité', 'desc' => 'Seulement en mode ModBus 2 (débit). Non utilisé : le plugin pilote par vitesses, dont les débits sont réglés par l\'installateur.'),
            array('addr' => 'cycle ok', 'dir' => 'r', 'special' => 1, 'cat' => 'pilot', 'level' => 'rec', 'field' => 'modbus_ok_cmd', 'decode' => array(0 => 'erreur de communication', 1 => 'ok'),
                'name' => 'Cycle OK', 'desc' => 'Commande créée d\'office par MyModbus. 0 = une lecture a échoué. Le plugin prévient si elle reste à 0.'),

            // ---------------- États ----------------
            array('addr' => 4050, 'dir' => 'r', 'fct' => '4', 'fmt' => 'H', 'cat' => 'state', 'level' => 'rec', 'field' => 'bypass_cmd',
                'decode' => array(0 => 'initialisation', 1 => 'en cours d\'ouverture', 2 => 'en cours de fermeture', 3 => 'ouvert', 4 => 'fermé', 255 => 'erreur'),
                'name' => 'Bypass statut', 'desc' => 'Dans ThermoFlair : « Valeur bypass ouvert » = 1,3.'),
            array('addr' => 6100, 'dir' => 'r', 'fct' => '3', 'fmt' => 'H', 'cat' => 'state', 'level' => 'opt',
                'decode' => array(0 => 'automatique', 1 => 'forcé fermé', 2 => 'forcé ouvert'),
                'name' => 'Bypass mode', 'desc' => 'Réglage du bypass (étape 2.1 du menu). Doit rester sur 0 (automatique).'),
            array('addr' => 6100, 'dir' => 'w', 'fct' => '6', 'fmt' => 'H', 'cat' => 'state', 'level' => 'avoid',
                'name' => 'Bypass forcé', 'desc' => 'Écrire 1 force le bypass fermé, 2 ouvert, en permanence. Pensez à remettre 0 (automatique) ensuite. Le plugin ne s\'en sert pas.'),
            array('addr' => 4100, 'dir' => 'r', 'fct' => '4', 'fmt' => 'H', 'cat' => 'state', 'level' => 'need', 'field' => 'filter_cmd', 'expect' => '1',
                'decode' => array(0 => 'propre', 1 => 'encrassé : à nettoyer'), 'name' => 'Filtre encrassé', 'desc' => 'Indispensable au module Filtre. Dans ThermoFlair : « Valeur à nettoyer » = 1.'),
            array('addr' => 8010, 'dir' => 'w', 'fct' => '6', 'fmt' => 'H', 'value' => '1', 'cat' => 'state', 'level' => 'opt', 'field' => 'filter_reset_cmd',
                'name' => 'Filtre remise à zéro', 'desc' => 'Remet à zéro l\'avertissement filtre (comme l\'étape 4.3 du menu). Seulement après avoir nettoyé ou changé les filtres.'),
            array('addr' => 8010, 'dir' => 'r', 'fct' => '3', 'fmt' => 'H', 'cat' => 'state', 'level' => 'opt',
                'decode' => array(0 => 'aucune action', 1 => 'remise à zéro faite', 255 => 'échec de la remise à zéro'),
                'name' => 'Filtre remise à zéro (résultat)', 'desc' => 'Résultat de la dernière remise à zéro.'),
            array('addr' => 4060, 'dir' => 'r', 'fct' => '4', 'fmt' => 'H', 'cat' => 'state', 'level' => 'opt', 'field' => 'preheat_cmd', 'expect' => '2',
                'decode' => array(0 => 'initialisation', 1 => 'inactif', 2 => 'actif', 3 => 'mode test'), 'name' => 'Préchauffage état', 'desc' => 'Le préchauffage protège l\'échangeur du gel. Dans ThermoFlair : « Valeur actif » = 2 ; notification quand il s\'allume et s\'éteint.'),
            array('addr' => 4061, 'dir' => 'r', 'fct' => '4', 'fmt' => 'H', 'unit' => '%', 'cat' => 'state', 'level' => 'opt', 'field' => 'preheat_power_cmd',
                'name' => 'Préchauffage puissance', 'desc' => 'En % de la puissance maximale.'),
            array('addr' => 4020, 'dir' => 'r', 'fct' => '4', 'fmt' => 'H', 'cat' => 'state', 'level' => 'test', 'decode' => array(0 => 'veille', 12 => 'piloté en ModBus'),
                'name' => 'Mode de fonctionnement', 'desc' => 'Absent de la doc Brink. Sur d\'autres installations, 12 = piloté en ModBus. Si votre VMC le confirme, il peut servir de « Lecture du mode ModBus » (valeur attendue 12).'),

            // ---------------- Mesures ----------------
            array('addr' => 4036, 'dir' => 'r', 'fct' => '4', 'fmt' => 'h', 'option' => '#value# / 10', 'unit' => '°C', 'cat' => 'measure', 'level' => 'opt', 'signed' => 1, 'div' => 10, 'field' => 'hx_supply_cmd',
                'name' => 'Température air soufflé', 'desc' => 'Air neuf après l\'échangeur, envoyé dans la maison (ce n\'est pas la température extérieure). Sert au calcul de l\'efficacité de l\'échangeur.'),
            array('addr' => 4046, 'dir' => 'r', 'fct' => '4', 'fmt' => 'h', 'option' => '#value# / 10', 'unit' => '°C', 'cat' => 'measure', 'level' => 'opt', 'signed' => 1, 'div' => 10, 'field' => 'hx_exhaust_cmd',
                'name' => 'Température air rejeté', 'desc' => 'Air de la maison après l\'échangeur, rejeté dehors (ce n\'est pas la température intérieure). Contrôle de l\'efficacité ; proche de 0 °C par grand froid = risque de gel.'),
            array('addr' => 4081, 'dir' => 'r', 'fct' => '4', 'fmt' => 'h', 'option' => '#value# / 10', 'unit' => '°C', 'cat' => 'measure', 'level' => 'opt', 'signed' => 1, 'div' => 10,
                'name' => 'Température NTC1 (air extérieur)', 'desc' => 'Sonde de l\'air neuf à l\'entrée (protection antigel). Faussée quand le préchauffage chauffe : préférez votre thermomètre extérieur.'),
            array('addr' => 4037, 'dir' => 'r', 'fct' => '4', 'fmt' => 'H', 'unit' => '%', 'cat' => 'measure', 'level' => 'opt',
                'name' => 'Humidité air soufflé', 'desc' => 'Absent de la doc Brink mais lu sur la Flair 325. Vérifiez l\'échelle (parfois en dixièmes).'),
            array('addr' => 4047, 'dir' => 'r', 'fct' => '4', 'fmt' => 'H', 'unit' => '%', 'cat' => 'measure', 'level' => 'opt',
                'name' => 'Humidité air rejeté', 'desc' => 'Absent de la doc Brink. L\'air rejeté, refroidi par l\'échangeur, est souvent très humide (80–90 %).'),
            array('addr' => 4023, 'dir' => 'r', 'fct' => '4', 'fmt' => 'h', 'option' => '#value# / 10', 'unit' => 'Pa', 'cat' => 'measure', 'level' => 'opt', 'signed' => 1, 'div' => 10,
                'name' => 'Pression soufflage', 'desc' => 'En dixièmes de pascal. Une pression qui monte peut signaler un filtre ou un conduit bouché.'),
            array('addr' => 4024, 'dir' => 'r', 'fct' => '4', 'fmt' => 'h', 'option' => '#value# / 10', 'unit' => 'Pa', 'cat' => 'measure', 'level' => 'opt', 'signed' => 1, 'div' => 10,
                'name' => 'Pression extraction', 'desc' => 'En dixièmes de pascal.'),
            array('addr' => 4031, 'dir' => 'r', 'fct' => '4', 'fmt' => 'H', 'unit' => 'm³/h', 'cat' => 'measure', 'level' => 'opt', 'name' => 'Débit soufflage demandé', 'desc' => 'Débit demandé au ventilateur d\'alimentation.'),
            array('addr' => 4032, 'dir' => 'r', 'fct' => '4', 'fmt' => 'H', 'unit' => 'm³/h', 'cat' => 'measure', 'level' => 'opt', 'name' => 'Débit soufflage réel', 'desc' => 'Débit mesuré ou calculé.'),
            array('addr' => 4041, 'dir' => 'r', 'fct' => '4', 'fmt' => 'H', 'unit' => 'm³/h', 'cat' => 'measure', 'level' => 'opt', 'name' => 'Débit extraction demandé', 'desc' => 'Débit demandé au ventilateur d\'évacuation.'),
            array('addr' => 4042, 'dir' => 'r', 'fct' => '4', 'fmt' => 'H', 'unit' => 'm³/h', 'cat' => 'measure', 'level' => 'opt', 'name' => 'Débit extraction réel', 'desc' => 'Débit mesuré ou calculé. Très inférieur au débit demandé : filtre ou conduit à vérifier (codes E1011 / E1012).'),
            array('addr' => 4201, 'dir' => 'r', 'fct' => '4', 'fmt' => 'H', 'unit' => 'ppm', 'cat' => 'measure', 'level' => 'opt', 'field' => 'co2_cmd', 'name' => 'CO2 capteur 1', 'desc' => 'Seulement avec un capteur de CO2 Brink eBus (4203, 4205, 4207 pour les capteurs 2 à 4).'),
            array('addr' => 4203, 'dir' => 'r', 'fct' => '4', 'fmt' => 'H', 'unit' => 'ppm', 'cat' => 'measure', 'level' => 'opt', 'name' => 'CO2 capteur 2', 'desc' => ''),
            array('addr' => 4205, 'dir' => 'r', 'fct' => '4', 'fmt' => 'H', 'unit' => 'ppm', 'cat' => 'measure', 'level' => 'opt', 'name' => 'CO2 capteur 3', 'desc' => ''),
            array('addr' => 4207, 'dir' => 'r', 'fct' => '4', 'fmt' => 'H', 'unit' => 'ppm', 'cat' => 'measure', 'level' => 'opt', 'name' => 'CO2 capteur 4', 'desc' => ''),

            // ---------------- Identification ----------------
            array('addr' => 4004, 'dir' => 'r', 'fct' => '4', 'fmt' => 'H', 'cat' => 'id', 'level' => 'opt', 'name' => 'Type d\'appareil', 'desc' => 'Numéro interne Brink.'),
            array('addr' => 4010, 'dir' => 'r', 'fct' => '4', 'fmt' => 'H', 'cat' => 'id', 'level' => 'opt', 'name' => 'N° de série 1/3', 'desc' => 'Chiffres 1 à 4 du numéro de série, codés en BCD.'),
            array('addr' => 4011, 'dir' => 'r', 'fct' => '4', 'fmt' => 'H', 'cat' => 'id', 'level' => 'opt', 'name' => 'N° de série 2/3', 'desc' => 'Chiffres 5 à 8.'),
            array('addr' => 4012, 'dir' => 'r', 'fct' => '4', 'fmt' => 'H', 'cat' => 'id', 'level' => 'opt', 'name' => 'N° de série 3/3', 'desc' => 'Chiffres 9 à 12.'),

            // ---------------- Réglages installateur ----------------
            array('addr' => 6033, 'dir' => 'r', 'fct' => '3', 'fmt' => 'H', 'cat' => 'settings', 'level' => 'avoid', 'decode' => array(0 => 'non autorisé', 1 => 'autorisé'), 'name' => 'Déséquilibre autorisé', 'desc' => 'Équilibrage soufflage / extraction.'),
            array('addr' => 6035, 'dir' => 'r', 'fct' => '3', 'fmt' => 'h', 'unit' => '%', 'cat' => 'settings', 'level' => 'avoid', 'signed' => 1, 'name' => 'Décalage déséquilibre soufflage', 'desc' => '-15 à +15 %.'),
            array('addr' => 6036, 'dir' => 'r', 'fct' => '3', 'fmt' => 'h', 'unit' => '%', 'cat' => 'settings', 'level' => 'avoid', 'signed' => 1, 'name' => 'Décalage déséquilibre extraction', 'desc' => '-15 à +15 %.'),
            array('addr' => 6150, 'dir' => 'r', 'fct' => '3', 'fmt' => 'H', 'cat' => 'settings', 'level' => 'avoid', 'decode' => array(0 => 'arrêt', 1 => 'marche'), 'name' => 'Mode capteur CO2', 'desc' => ''),
            array('addr' => 6240, 'dir' => 'r', 'fct' => '3', 'fmt' => 'H', 'cat' => 'settings', 'level' => 'avoid', 'decode' => array(0 => 'arrêt', 1 => 'marche'), 'name' => 'Échangeur géothermique', 'desc' => 'Seulement avec le circuit Plus (6241 / 6242 : températures mini / maxi, en dixièmes de °C).'),
            array('addr' => 7991, 'dir' => 'r', 'fct' => '3', 'fmt' => 'H', 'cat' => 'settings', 'level' => 'avoid', 'name' => 'Adresse esclave ModBus', 'desc' => '20 par défaut. La changer coupe la communication tant que MyModbus n\'a pas la même.'),
            array('addr' => 'w6033', 'match' => array(6033, 6035, 6036, 6150, 6240, 6241, 6242), 'dir' => 'w', 'fct' => '6', 'cat' => 'settings', 'level' => 'never',
                'name' => 'Écriture d\'un réglage installateur', 'desc' => 'Ne créez pas de commande action sur ces registres : un mauvais réglage dérègle les débits ou la protection antigel.'),
            array('addr' => 7991, 'dir' => 'w', 'fct' => '6', 'cat' => 'settings', 'level' => 'never', 'name' => 'Changer l\'adresse esclave', 'desc' => 'Coupe la communication. À faire, si besoin, sur l\'écran de la VMC (étape 14.2).'),
            array('addr' => 8011, 'dir' => 'w', 'fct' => '6', 'cat' => 'settings', 'level' => 'never', 'name' => 'Réinitialisation de l\'appareil', 'desc' => 'Redémarre la VMC. Aucune utilité au quotidien : ne créez pas cette commande.'),
        );
    }

    // Codes de panne de l'écran (doc Brink chapitre 8). * = panne bloquante.
    const ERRORS = array(
        array('E1000', '', 'Auto-test pas bon', 'Aucune action de l\'appareil.'),
        array('E1001', '*', 'Mémoire flash défectueuse', 'Remplacer le circuit imprimé UWA2-B.'),
        array('E1002', '', 'Mémoire EEPROM défectueuse', 'Retour aux réglages d\'usine, position 2. Remplacer UWA2-B.'),
        array('E1011', '', 'Débit d\'entrée demandé non atteint', 'Nettoyer ou remplacer les filtres, vérifier les conduits.'),
        array('E1012', '', 'Débit de sortie demandé non atteint', 'Nettoyer ou remplacer les filtres, vérifier les conduits.'),
        array('E1013', '*', 'Air extérieur trop chaud', 'Veille. Été : air pris sous les tuiles ? Hiver : sonde NTC à remplacer.'),
        array('E1100', '*', 'Ventilateur d\'alimentation défectueux', 'Veille. Remplacer le ventilateur.'),
        array('E1120', '*', 'Ventilateur d\'évacuation défectueux', 'Veille. Remplacer le ventilateur.'),
        array('E1200', '', 'Bypass défectueux', 'Vérifier le câblage, remplacer le bypass.'),
        array('E1300', '*', 'Sonde NTC1 défectueuse', 'Veille. Vérifier le câblage, remplacer la sonde.'),
        array('E1400', '', 'Capteur d\'humidité RHT défectueux', 'Plus de contrôle de l\'humidité.'),
        array('E1500', '', 'Commutateur de positions défectueux', 'Position 1. Remplacer le commutateur.'),
        array('E1600', '', 'Préchauffage défectueux', 'Antigel en mode balourd. Vérifier fusibles et câblage.'),
        array('E1700', '', 'Post-chauffage ou fusible externe', 'Débrancher, vérifier le fusible du préchauffage.'),
        array('E1800', '', 'Relais sortie 1 défectueux', 'Signal de sortie indisponible.'),
        array('E2000', '', 'Erreur de l\'écran tactile', 'Contrôler le câble de l\'écran.'),
        array('E2100', '', 'Erreur eBus', 'Accessoires eBus (Air Control…) hors service.'),
        array('E2200', '', 'Erreur BrinkBus', 'Accessoires BrinkBus hors service.'),
        array('E2300', '', 'Erreur interne ModBus', 'Veille. Vérifier les connexions sur UWA2-B et les ventilateurs.'),
        array('E2400', '', 'Erreur externe ModBus', 'La commande ModBus ne fonctionne pas : vérifier le câblage A-B-GND et les accessoires.'),
        array('E2500', '', 'Port USB', 'Interface USB inutilisable.'),
    );

    /* ---------------------------------------------------------------- */

    // Registre (et sens) d'une commande MyModbus. null = pas une commande registre.
    private static function cmdRegister($cmd) {
        $lid = $cmd->getLogicalId();
        if (in_array($lid, array('cycle ok', 'refresh', 'refresh time', 'polling'))) {
            return array('special' => $lid);
        }
        $addr = trim((string) $cmd->getConfiguration('cmdAddress', ''));
        if ($addr === '' || !preg_match('/^\d+$/', $addr)) {
            return array('addr' => $addr === '' ? null : $addr, 'raw' => true);
        }
        return array('addr' => intval($addr));
    }

    private static function findEntry($cat, $addr, $dir, $writeValue = null, $special = null) {
        $best = null;
        foreach ($cat as $e) {
            if ($special !== null) {
                if (!empty($e['special']) && $e['addr'] === $special) {
                    return $e;
                }
                continue;
            }
            if (!empty($e['special']) || $e['dir'] != $dir) {
                continue;
            }
            $match = isset($e['match']) ? in_array($addr, $e['match']) : ($e['addr'] === $addr);
            if (!$match) {
                continue;
            }
            if ($dir == 'w' && isset($e['value'])) {
                if ($writeValue !== null && trim((string) $writeValue) === $e['value']) {
                    return $e;
                }
                if ($best === null) {
                    $best = $e;
                }
                continue;
            }
            return $e;
        }
        return $best;
    }

    public static function decode($e, $val) {
        if ($val === null || $val === '' || !is_numeric($val)) {
            return '';
        }
        if (!empty($e['decode']) && isset($e['decode'][intval($val)])) {
            return $e['decode'][intval($val)];
        }
        return '';
    }

    // Équipements MyModbus présents (id => nom).
    public static function mymodbusList() {
        $out = array();
        try {
            if (!is_object(plugin::byId('mymodbus'))) {
                return $out;
            }
        } catch (\Throwable $e) {
            return $out;
        }
        foreach (eqLogic::byType('mymodbus') as $eq) {
            $out[] = array('id' => $eq->getId(), 'name' => $eq->getHumanName(), 'enable' => intval($eq->getIsEnable()));
        }
        return $out;
    }

    /*
     * Analyse d'un équipement MyModbus. $cfg : configuration de l'équipement
     * ThermoFlair (pour savoir quelles commandes il utilise déjà).
     */
    public static function scan($mbId, $cfg) {
        $eq = eqLogic::byId($mbId);
        if (!is_object($eq) || $eq->getEqType_name() != 'mymodbus') {
            throw new Exception('Équipement MyModbus introuvable.');
        }
        $cat = self::catalog();
        // Commandes utilisées par ThermoFlair : id => champ(s)
        $used = array();
        foreach ($cfg as $k => $v) {
            if (!is_string($v) || substr($k, -4) != '_cmd' || $v === '') {
                continue;
            }
            $c = ThermoFlair325::resolveCmd($v);
            if (is_object($c)) {
                $used[$c->getId()][] = $k;
            }
        }

        // ---- Équipement ----
        $conf = array();
        $eqNotes = array();
        $proto = $eq->getConfiguration('eqProtocol', '');
        $conf[] = array('Protocole', $proto);
        if ($proto == 'serial') {
            $conf[] = array('Interface', $eq->getConfiguration('eqPortSerial', $eq->getConfiguration('eqSerialInterface', '')));
            $conf[] = array('Transport', $eq->getConfiguration('eqSerialMethod', ''));
            $baud = $eq->getConfiguration('eqSerialBaudrate', '');
            $par = $eq->getConfiguration('eqSerialParity', '');
            $parTxt = array('N' => 'aucune', 'E' => 'paire (Even)', 'O' => 'impaire (Odd)');
            $conf[] = array('Vitesse', $baud . ' bauds');
            $conf[] = array('Parité', isset($parTxt[$par]) ? $parTxt[$par] : $par);
            $conf[] = array('Bits / stop', $eq->getConfiguration('eqSerialBytesize', '') . ' / ' . $eq->getConfiguration('eqSerialStopbits', ''));
            $eqNotes[] = array('info', 'La VMC doit avoir exactement les mêmes réglages (écran de la VMC, menu 14.3 vitesse et 14.4 parité ; d\'usine : 19 200 bauds, parité paire). Avec une mauvaise vitesse ou parité, aucune valeur ne remonte.');
        } elseif ($proto == 'shared_from') {
            $conf[] = array('Interface', 'partagée avec un autre équipement');
        } elseif ($proto !== '') {
            $eqNotes[] = array('info', 'Liaison réseau : passerelle ModBus TCP / RTU. Vérifiez qu\'elle transmet en RTU à la vitesse et la parité de la VMC.');
        }
        $refresh = $eq->getConfiguration('eqRefreshMode', '');
        $conf[] = array('Rafraîchissement', $refresh . ($refresh == 'polling' ? ' toutes les ' . $eq->getConfiguration('eqPolling', '') . ' s' : ''));
        if ($refresh == 'on_event') {
            $eqNotes[] = array('warning', 'Mode « Sur événement » : les valeurs ne sont lues que sur « Rafraîchir ». Choisissez « Polling » (5 à 30 s) pour que ThermoFlair voie la vitesse réelle.');
        } elseif ($refresh == 'polling' && floatval($eq->getConfiguration('eqPolling', 0)) > 60) {
            $eqNotes[] = array('warning', 'Polling supérieur à 60 s : la vitesse réelle est vue avec retard. 5 à 30 s conviennent.');
        }
        $oneId = $eq->getConfiguration('eqOneDevID', '');
        $devId = $eq->getConfiguration('eqDevID', '');
        if ($oneId == '1' && $devId !== '') {
            $conf[] = array('ID du serveur (toutes les commandes)', $devId);
        }
        if ($eq->getIsEnable() != 1) {
            $eqNotes[] = array('danger', 'Équipement MyModbus désactivé : aucune valeur ne remonte.');
        }

        // ---- Commandes ----
        $rows = array();
        $found = array();
        $ids = array();
        foreach ($eq->getCmd() as $cmd) {
            $reg = self::cmdRegister($cmd);
            $dir = $cmd->getType() == 'action' ? 'w' : 'r';
            $fct = (string) $cmd->getConfiguration('cmdFctModbus', '');
            $fmt = (string) $cmd->getConfiguration('cmdFormat', '');
            $wv = trim((string) $cmd->getConfiguration('cmdWriteValue', ''));
            $opt = trim((string) $cmd->getConfiguration('cmdOption', ''));
            $dev = trim((string) $cmd->getConfiguration('cmdDevID', ''));
            $row = array('id' => $cmd->getId(), 'name' => $cmd->getName(), 'human' => '#' . $cmd->getHumanName() . '#', 'type' => $cmd->getType(),
                'addr' => isset($reg['addr']) ? $reg['addr'] : '', 'fct' => isset(self::FCT[$fct]) ? self::FCT[$fct] : $fct, 'fmt' => isset(self::FMT[$fmt]) ? self::FMT[$fmt] : $fmt,
                'write' => $dir == 'w' ? $wv : '', 'option' => $opt, 'value' => '', 'decoded' => '', 'cat' => 'other', 'level' => '', 'suggest' => '',
                'field' => '', 'used' => isset($used[$cmd->getId()]) ? $used[$cmd->getId()] : array(), 'notes' => array(), 'status' => 'ok');
            if ($dev !== '' && $devId === '') {
                $ids[$dev] = true;
            }
            if ($dir == 'r') {
                try {
                    $val = $cmd->execCmd();
                    $row['value'] = ($val === null) ? '' : (string) $val;
                    $row['date'] = $cmd->getValueDate();
                } catch (\Throwable $e) {
                    $row['value'] = '';
                }
            }
            if (isset($reg['special'])) {
                $e = self::findEntry($cat, null, $dir, null, $reg['special']);
                $row['addr'] = '—';
                $row['fct'] = 'MyModbus';
                $row['fmt'] = '';
                if ($e === null) {
                    $row['cat'] = 'mymodbus';
                    $row['notes'][] = array('info', 'Commande interne de MyModbus.');
                    $rows[] = $row;
                    continue;
                }
            } elseif (!empty($reg['raw'])) {
                $row['notes'][] = array('info', $reg['addr'] === null ? 'Pas d\'adresse (commande interne ou plage).' : 'Adresse « ' . $reg['addr'] . ' » : plage ou chaîne, non analysée.');
                $row['cat'] = 'other';
                $rows[] = $row;
                continue;
            } else {
                $e = self::findEntry($cat, $reg['addr'], $dir, $wv);
            }
            if ($e === null) {
                $other = self::findEntry($cat, $reg['addr'], $dir == 'w' ? 'r' : 'w');
                $row['notes'][] = array('warning', $other !== null
                    ? ($dir == 'w' ? 'Ce registre est en lecture seule chez Brink : une commande action n\'a pas de sens.' : 'Registre d\'écriture : en lecture, il renvoie seulement la dernière valeur acceptée.')
                    : 'Registre absent de la doc Brink de la Flair 325 : vérifiez l\'adresse.');
                $row['status'] = 'warning';
                $rows[] = $row;
                continue;
            }
            $key = self::entryKey($e);
            $found[$key][] = $cmd->getId();
            $row['cat'] = $e['cat'];
            $row['level'] = $e['level'];
            $row['suggest'] = $e['name'];
            $row['field'] = isset($e['field']) ? $e['field'] : '';
            $row['desc'] = $e['desc'];
            $row['unit'] = isset($e['unit']) ? $e['unit'] : '';
            if ($dir == 'r') {
                $row['decoded'] = self::decode($e, $row['value']);
            }
            // --- Vérifications ---
            $bad = function ($lvl, $txt) use (&$row) {
                $row['notes'][] = array($lvl, $txt);
                if ($lvl == 'danger' || ($lvl == 'warning' && $row['status'] == 'ok')) {
                    $row['status'] = $lvl;
                }
            };
            if ($e['level'] == 'never') {
                $bad('danger', 'Commande à supprimer : ' . $e['desc']);
            } elseif ($e['level'] == 'avoid' && $dir == 'w') {
                $bad('warning', $e['desc']);
            }
            if (empty($e['special']) && isset($e['fct']) && $fct !== '' && $fct !== $e['fct'] && !($e['fct'] == '6' && $fct == '16')) {
                $bad('danger', 'Fonction ' . (isset(self::FCT[$fct]) ? self::FCT[$fct] : $fct) . ' : ce registre se ' . ($dir == 'w' ? 'règle' : 'lit') . ' en ' . self::FCT[$e['fct']] . '. Sans cela, ' . ($dir == 'w' ? 'l\'ordre est refusé.' : 'la lecture échoue (Cycle OK à 0).'));
            }
            if (!empty($e['signed']) && $fmt === 'H') {
                $bad('warning', 'Format uint16 : une valeur négative s\'affichera 65 535 au lieu de -0,1. Choisissez int16.');
            }
            if (!empty($e['div']) && $dir == 'r' && !preg_match('#/\s*' . $e['div'] . '\b#', $opt) && is_numeric($row['value'])) {
                $row['decoded'] = 'soit ' . str_replace('.', ',', (string) round(floatval($row['value']) / $e['div'], 1)) . ' ' . $row['unit'];
                $row['unit'] = '';
            }
            if (!empty($e['div']) && $dir == 'r' && !preg_match('#/\s*' . $e['div'] . '\b#', $opt)) {
                $bad('info', 'Valeur en dixièmes : mettez « #value# / ' . $e['div'] . ' » dans Option pour lire des ' . $e['unit'] . '.');
            }
            if ($dir == 'w' && isset($e['value']) && $wv !== $e['value']) {
                if ($e['addr'] === 8001) {
                    $bad('danger', 'Valeur « ' . $wv . ' » : la Flair accepte 0 (vitesse 1), 1, 2 ou 3 (vitesse 4).');
                } else {
                    $bad('warning', 'Valeur écrite « ' . $wv . ' » : la valeur attendue est ' . $e['value'] . '.');
                }
            }
            if ($e['addr'] === 8000 && $dir == 'r' && $row['value'] !== '' && $row['value'] != '1') {
                $bad('warning', 'La VMC n\'est pas en mode ModBus « interrupteur » : les vitesses envoyées sont ignorées. Lancez « Mode ModBus ON ».');
            }
            if ($e['addr'] === 4050 && $row['value'] == '255') {
                $bad('danger', 'La VMC signale une erreur du bypass (voir code E1200).');
            }
            if ($e['addr'] === 6100 && $dir == 'r' && $row['value'] !== '' && $row['value'] != '0') {
                $bad('warning', 'Bypass forcé (' . $row['decoded'] . ') : il ne réagit plus aux températures. Remettez 0 (automatique).');
            }
            if ($e['addr'] === 'cycle ok' && $row['value'] === '0') {
                $bad('danger', 'La dernière lecture a échoué : câblage, vitesse / parité ou adresse esclave à vérifier.');
            }
            if ($dir == 'r' && empty($e['special']) && $cmd->getSubType() == 'string' && $e['cat'] != 'id') {
                $bad('info', 'Sous-type « Autre » : choisissez « ' . (!empty($e['decode']) && array_keys($e['decode']) === array(0, 1) ? 'Binaire' : 'Numérique') . ' » pour pouvoir l\'historiser et en faire des graphiques.');
            }
            if ($row['field'] !== '' && !in_array($row['field'], $row['used']) && !empty($row['used'])) {
                $bad('info', 'Utilisée par ThermoFlair pour un autre champ.');
            }
            $rows[] = $row;
        }

        // « Cycle OK » à 0 : souvent une seule commande mal réglée.
        $culprits = array();
        foreach ($rows as $r) {
            foreach ($r['notes'] as $n) {
                if ($n[0] == 'danger' && strpos($n[1], 'Fonction ') === 0 && $r['type'] == 'info') {
                    $culprits[] = '« ' . $r['name'] . ' »';
                }
            }
        }
        foreach ($rows as &$r) {
            if ($r['field'] == 'modbus_ok_cmd' && $r['value'] === '0' && !empty($culprits)) {
                $r['notes'][] = array('danger', 'Cause probable : ' . implode(', ', array_unique($culprits)) . ' lit avec la mauvaise fonction. Une seule lecture en échec suffit à mettre Cycle OK à 0.');
            }
        }
        unset($r);

        // ---- Registres conseillés absents ----
        $missing = array();
        foreach ($cat as $e) {
            if (!in_array($e['level'], array('need', 'rec')) || isset($found[self::entryKey($e)])) {
                continue;
            }
            $missing[] = array('cat' => $e['cat'], 'level' => $e['level'], 'name' => $e['name'], 'how' => self::howTo($e), 'desc' => $e['desc'], 'field' => isset($e['field']) ? $e['field'] : '');
        }

        // ---- Propositions de remplissage des champs ThermoFlair ----
        $fill = array();
        foreach ($rows as $r) {
            if ($r['field'] === '' || isset($fill[$r['field']]) || $r['level'] == 'never') {
                continue;
            }
            $cfgErr = false;
            foreach ($r['notes'] as $n) {
                if ($n[0] == 'danger' && (strpos($n[1], 'Fonction ') === 0 || strpos($n[1], 'Valeur « ') === 0)) {
                    $cfgErr = true;
                }
            }
            if ($cfgErr) {
                continue;
            }
            $fill[$r['field']] = array('value' => $r['human'], 'name' => $r['name'], 'current' => isset($cfg[$r['field']]) ? $cfg[$r['field']] : '');
        }
        $extra = array();
        if (isset($fill['vmc_pos_cmd'])) {
            $extra['vmc_p1'] = '0';
            $extra['vmc_p2'] = '1';
            $extra['vmc_p3'] = '2';
            $extra['vmc_p4'] = '3';
        }
        if (isset($fill['vmc_pre_cmd'])) {
            $extra['vmc_pre_value'] = '1';
        }
        if (isset($fill['bypass_cmd'])) {
            $extra['bypass_open_value'] = '1,3';
        }
        if (isset($fill['preheat_cmd'])) {
            $extra['preheat_on_value'] = '2';
        }
        if (isset($fill['filter_cmd'])) {
            $extra['filter_value'] = '1';
        }

        $order = array_merge(array_keys(self::CATEGORIES), array('mymodbus', 'other'));
        usort($rows, function ($a, $b) use ($order) {
            $ca = array_search($a['cat'], $order);
            $cb = array_search($b['cat'], $order);
            if ($ca != $cb) {
                return $ca - $cb;
            }
            return strnatcmp((string) $a['addr'], (string) $b['addr']);
        });
        $nb = array('ok' => 0, 'info' => 0, 'warning' => 0, 'danger' => 0);
        foreach ($rows as $r) {
            $nb[$r['status']]++;
        }
        return array('eq' => array('id' => $eq->getId(), 'name' => $eq->getHumanName(), 'enable' => intval($eq->getIsEnable())), 'conf' => $conf, 'eqNotes' => $eqNotes,
            'rows' => $rows, 'missing' => $missing, 'fill' => $fill, 'extra' => $extra, 'count' => $nb, 'categories' => self::CATEGORIES, 'levels' => self::LEVELS);
    }

    private static function entryKey($e) {
        return $e['dir'] . ':' . (is_array($e['addr']) ? '' : $e['addr']) . ':' . (isset($e['value']) ? $e['value'] : '');
    }

    // Réglage MyModbus d'un registre, en une ligne.
    public static function howTo($e) {
        if (!empty($e['special'])) {
            return 'Créée automatiquement par MyModbus.';
        }
        if ($e['level'] == 'never') {
            return 'À ne pas créer.';
        }
        $t = ($e['dir'] == 'w' ? 'Action / Défaut' : 'Info / ' . (!empty($e['decode']) && array_keys($e['decode']) === array(0, 1) ? 'Binaire' : 'Numérique'))
            . ' · ' . self::FCT[$e['fct']] . ' · ' . (isset($e['fmt']) && isset(self::FMT[$e['fmt']]) ? self::FMT[$e['fmt']] : 'uint16')
            . ' · adresse ' . (isset($e['match']) ? implode(', ', $e['match']) : $e['addr']);
        if (isset($e['value'])) {
            $t .= ' · Paramètres : ' . $e['value'];
        }
        if (isset($e['option'])) {
            $t .= ' · Option : ' . $e['option'];
        }
        if ($e['cat'] == 'id') {
            $t .= ' · Lecture 1x sur : 1000';
        }
        return $t;
    }
}
