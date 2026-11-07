# ThermoFlair 325 — documentation

ThermoFlair 325 est conçu pour la VMC double flux **Brink Flair 325**, qui doit être reliée à Jeedom en **ModBus RTU** (liaison RS-485 de la VMC) avec le plugin **MyModbus**. L'onglet **Aide ModBus** du plugin explique le câblage et la configuration de MyModbus, et vérifie votre équipement. Il pilote une maison très isolée (passive ou BBC) depuis un seul équipement : **VMC double flux**, **batteries de chauffe** sur le réseau de soufflage, **délestage Linky**, **filtre** de la VMC et **statistiques** thermiques.

La documentation complète (principes, règles de décision, plages, sécurités, commandes, reprise de scénarios existants) se trouve dans le [README du dépôt](https://github.com/Anakins/ThermoFlair325).

## Démarrage rapide

1. Activez le plugin et vérifiez que sa tâche **cron** est active. Configurez MyModbus (voir l'onglet **Aide ModBus**).
2. Créez **un** équipement « Maison ».
3. **Onglet Maison** : cochez les modules à utiliser, puis choisissez les thermomètres du bas, du haut et de l'extérieur, et le capteur de luminosité extérieur (optionnel) avec sa valeur de plein soleil.
4. **Onglet VMC** : choisissez les commandes Vitesse 1 à 4, Position ventilation et Passer en mode ModBus. Le plus simple : **Aide ModBus → Analyser → Remplir les champs ThermoFlair**.
5. **Onglet Chauffage** : pour chaque batterie, choisissez les commandes Allumer, Éteindre et État, puis la vitesse de VMC minimum (2 ou 3). Réglez ensuite les consignes Normal et ECO et les plages, cochées Bas et/ou Haut.
6. **Onglet Délestage** : choisissez la puissance Linky et le seuil.
7. **Onglet Filtre** : choisissez la commande de statut (binaire).
8. **Onglet Notifications** : ajoutez vos destinataires (cochez « Toutes les actions » pour un canal journal comme Discord ou Telegram) et testez-les avec le bouton ✈️.
9. Sauvegardez, puis ouvrez l'onglet **Récapitulatif**, puis **Statistiques** (avec l'import de l'historique).

Au quotidien, tout se règle depuis le **widget** : mode auto de la VMC, boosts (autant que vous voulez, réglés dans un tableau), thermostat ON/OFF, mode Normal / ECO et leurs deux consignes.
