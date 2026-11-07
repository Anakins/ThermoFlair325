<?php
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

function ThermoFlair325_install() {
    $pluginId = basename(realpath(__DIR__ . '/..'));
    // Clé API de secours pour l'ajax : générée une seule fois, jamais
    // régénérée (sinon le JS déjà chargé dans un navigateur serait refusé).
    ThermoFlair325::getApiKey();
    // Seuil de log propre au plugin (indépendant du réglage global).
    if (config::byKey('log::level::' . $pluginId, 'core', '') === '') {
        config::save('log::level::' . $pluginId, '{"100":"0","200":"1","300":"0","400":"0","1000":"0","default":"0"}');
    }
    log::add($pluginId, 'info', 'Installation du plugin ThermoFlair 325.');
}

function ThermoFlair325_update() {
    $pluginId = basename(realpath(__DIR__ . '/..'));
    ThermoFlair325::getApiKey();
    // Recrée les commandes ajoutées par une nouvelle version, sans toucher
    // aux commandes existantes (noms, affichage, historique).
    foreach (eqLogic::byType($pluginId) as $eq) {
        try {
            // 0.9.3 : boosts 1-3 et vitesses temporaires -> tableau des boosts
            // (mêmes commandes, mêmes noms, mêmes durées).
            if ($eq->getConfiguration('vmc_boosts', null) === null) {
                $eq->setConfiguration('vmc_boosts', ThermoFlair325::legacyBoosts($eq));
                foreach (array('boost1_min', 'boost2_min', 'boost3_min', 'force_duration') as $k) {
                    $eq->setConfiguration($k, null);
                }
                $eq->save(true);
                log::add($pluginId, 'info', $eq->getHumanName() . ' : boosts repris dans le nouveau tableau des boosts.');
            }
            $eq->syncCmds();
            $eq->syncListener();
        } catch (\Throwable $e) {
            log::add($pluginId, 'error', 'Mise à jour ' . $eq->getHumanName() . ' : ' . $e->getMessage());
        }
    }
    try {
        $n = ThermoFlair325::cleanOrphans();
        if ($n > 0) {
            log::add($pluginId, 'info', "Nettoyage : $n élément(s) d'équipements supprimés retiré(s).");
        }
    } catch (\Throwable $e) {
        log::add($pluginId, 'error', 'Nettoyage : ' . $e->getMessage());
    }
    log::add($pluginId, 'info', 'Mise à jour du plugin ThermoFlair 325.');
}

function ThermoFlair325_remove() {
    foreach (eqLogic::byType('ThermoFlair325') as $eq) {
        $listener = listener::byClassAndFunction('ThermoFlair325', 'onPower', array('eqLogic_id' => intval($eq->getId())));
        if (is_object($listener)) {
            $listener->remove();
        }
    }
    $pluginId = basename(realpath(__DIR__ . '/..'));
    foreach (config::searchKey('runtime::', 'ThermoFlair325') ?: array() as $row) {
        config::remove($row['key'], 'ThermoFlair325');
    }
    config::remove('api', $pluginId);
    config::remove('api::' . $pluginId . '::mode');
    config::remove('api::' . $pluginId . '::restricted');
}
