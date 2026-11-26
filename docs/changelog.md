# Changelog ThermoFlair 325

## 0.9.6 (2026-11-26)
- **Ajouter le haut à un forçage en cours.** Pendant un forçage du bas, allumer le haut l'ajoute au forçage jusqu'à la même heure de fin : il chauffe lui aussi quelle que soit la température, et les deux batteries s'arrêtent ensemble. Trois façons de le faire : le bouton « + Haut » qui apparaît pendant le forçage (widget et récapitulatif), la commande « Forcer les deux batteries », ou l'allumage à la main de la batterie (interrupteur, commande Jeedom) si sa commande d'état est renseignée. Avant, le haut allumé à la main était éteint au cycle suivant si sa consigne était atteinte, et « Bas + Haut » relançait les deux pour une durée complète.

## 0.9.5 (2026-11-25)
- **Widget mobile** : le nom de l'équipement ne s'affiche plus deux fois dans le titre.
- **Widget ordinateur** : les boutons gardent leurs couleurs (orange pour un boost en vitesse 4, bleu pour une autre vitesse, rouge pour le forçage). Le thème du dashboard les mettait en gris.
- **Numéro de version** : `info.json` affichait encore 0.9.1 (Jeedom et `maj_plugin.sh` montraient une version fausse). La description parle maintenant des boosts à volonté.
- **Chauffage forcé : chauffe quelle que soit la température.** Avant, le forçage chauffait seulement jusqu'à la consigne Normal, et s'arrêtait donc tout de suite si elle était déjà atteinte. Maintenant, la batterie chauffe pendant toute la durée, consigne ignorée, même hors plage, en mode ECO, hors saison de chauffe ou au-dessus de la température extérieure maximum. Les sécurités l'arrêtent toujours : température de sécurité de la batterie, verrou de nuit, délestage, capteur illisible, pause de protection, vitesse minimum de la VMC. Le thermostat OFF empêche toujours de forcer.

## 0.9.4 (2026-11-07)
- **Un nouveau boost remplace bien celui en cours.** Juste après un ordre, la VMC peut encore remonter son ancienne position, ou celle de l'ordre précédent, quand MyModbus lit en retard. Le plugin prenait cet écart pour un réglage manuel, et ce faux réglage remplaçait le boost qu'on venait de lancer. Désormais, pendant 3 min après un ordre de vitesse ou le lancement d'un boost, un écart de position n'est plus pris pour un réglage manuel : le plugin renvoie simplement la bonne vitesse. Un vrai réglage manuel fait ensuite est toujours détecté.

- **Consigne Normal jusqu'au minimum réglé** : le curseur « Régler la consigne Normal » descend maintenant jusqu'à « Consigne réglable de » (avant, 0,5 °C au-dessus). La consigne Normal reste au moins 0,5 °C au-dessus de l'ECO.

- **Efficacité de l'échangeur corrigée (plus de 100 %)** : Brink mesure l'air soufflé et l'air rejeté aux ventilateurs, après l'échangeur. La chaleur des moteurs (environ 1 °C) faisait dépasser 100 % au calcul côté air soufflé et le faisait baisser côté air rejeté. Le plugin fait maintenant la moyenne des deux côtés, ce qui annule ce biais, et plafonne à 100 % quand une seule température est connue. Les statistiques repartent sur ce nouveau calcul : les anciennes valeurs sont ignorées, mais « Importer l'historique » les recalcule si les deux températures sont historisées.

- **« Pertes selon la vitesse de VMC » recalculé** : ce graphique donnait des pertes négatives (impossibles) pour les vitesses 1 et 2, car il venait du modèle global qui doit séparer à la fois chauffage, soleil et VMC. La perte est maintenant mesurée directement : refroidissement des tranches de nuit sans chauffage, à vitesse constante, ramené à 10 °C d'écart (médiane par vitesse, au moins 2 tranches). Le nombre de tranches est indiqué sous chaque barre.
- **« Ce que le soleil apporte » : la mesure s'arrête quand la VMC accélère ou que le bypass s'ouvre.** Par beau soleil, la maison atteint vite son maximum et le plugin évacue la chaleur, ce qui freinait la hausse justement les jours ensoleillés. Les traits noirs montrent la variation d'un jour à l'autre, et une remarque signale un écart pas encore significatif.

- **Rapport plus clair** : « Température stable (bas, ±0,5 °C) » devient « Bas à sa consigne (à ±0,5 °C près, pendant les plages Normal) ». La ligne donne aussi la répartition du reste du temps : au-dessus, dont la part due au soleil, et en dessous. « Surchauffe due au soleil » indique maintenant son seuil en degrés (consigne Normal + 2 °C), le nombre de jours, et la température maximum atteinte dedans pour la température dehors.

- **« Refroidissement sans chauffage » : on n'y voit plus que de vraies pertes.** Deux sortes de tranches sont écartées. D'abord celles où le soleil réchauffe en même temps (journée ensoleillée ou mitigée, entre 9h et 17h). Ensuite celles où le plugin évacuait une surchauffe : bypass ouvert, surchauffe solaire, ou VMC accélérée alors que la maison était au-dessus de sa consigne. Ces tranches donnaient des « pertes » de plusieurs °C par heure, dans un sens comme dans l'autre, alors que ce n'étaient pas des pertes de la maison. Le nombre de tranches écartées est indiqué sous le titre. Les moyennes jour / nuit / ensoleillé / maussade et le graphique des pertes par vitesse suivent la même règle.

- **Graphique « Écart à la consigne, jour par jour » (rapport)** : il remplace « Température stable ». Chaque jour est une barre empilée de 100 % du temps passé en plage Normal, en quatre couleurs : trop froid (bleu), consigne ±0,5 °C (vert), de +0,5 à +5 °C (orange), plus de +5 °C (rouge). La ligne « objectif 85 % » disparaît : dans une maison passive, être au-dessus de la consigne grâce au soleil n'est pas un défaut.

## 0.9.3 (2026-10-30)
- **Boosts à volonté, dans un tableau** (onglet VMC) : chaque ligne a un nom, une vitesse (1 à 4), une durée (1 à 1440 min) et une case Widget. Chaque ligne crée une **commande action du même nom**, utilisable dans les scénarios. Renommer la ligne renomme la commande, et supprimer la ligne supprime la commande. Les boutons du widget suivent le tableau, avec le nom de la ligne.
- **Vitesse 4 au-delà de 15 min : avertissement** à côté de la ligne (bruit, air asséché, chaleur perdue en hiver). Ce n'est qu'un conseil : la durée n'est plus plafonnée à 60 min.
- **Reprise automatique à la mise à jour** : les Boost 1/2/3 et Vitesse 1/2/3 temporaire deviennent des lignes du tableau. Elles gardent les mêmes commandes, donc vos scénarios continuent de marcher, ainsi que les mêmes noms (même renommés) et les mêmes durées.
- **Renommer un boost renomme bien sa commande** quand on sauvegarde depuis la page. Avant ce correctif, Jeedom réenregistrait ensuite l'onglet Commandes avec les anciens noms. Les commandes des boosts sont maintenant mises à jour après cet enregistrement (`postAjax`). On peut aussi échanger deux noms, ou supprimer une ligne et en recréer une du même nom.
- **Onglet Récapitulatif** : les boutons de boost affichaient « ? min ». Ils reprennent maintenant les lignes du tableau cochées « Widget », avec leur nom, en orange pour la vitesse 4 et en bleu sinon. Le serveur accepte aussi ces commandes.
- **Remarques lisibles dans l'onglet Maison** : un clic sur « (N remarque(s)) » dans « État des modules » ouvre la liste juste en dessous. Il n'est plus nécessaire de passer par le Récapitulatif.
- **Une remarque par plage de silence** : quand une plage de silence limite la VMC sous la vitesse dont les batteries ont besoin, une seule remarque cite toutes les batteries concernées. Avant, il y en avait une par batterie et par plage.
- « Vitesse 4 au maximum pendant » devient « Vitesse 4 mise à la main : respectée au plus ». Ce réglage ne concerne plus que la vitesse 4 mise hors du plugin.

## 0.9.2 (2026-10-26)
- **Changements de vitesse de la VMC envoyés au canal journal seulement** : nouveau réglage dans l'onglet Notifications. Par défaut, chaque changement de vitesse ne part plus que vers les destinataires « Toutes les actions » : température, CO2, plage de renouvellement, silence, chauffage, boost et sa fin, réglage manuel. Avant, ces messages partaient aussi vers les destinataires « VMC ». La case « VMC » ne reçoit plus que ce qui demande de l'attention, comme une VMC sortie du mode ModBus. Le choix « Toutes les actions et VMC » rétablit l'ancien comportement. Le titre du message reste « VMC ».

## 0.9.1 (2026-10-25)
- **« Ce que le soleil apporte » mesuré par journée entière** : pente de la température de 10h à 16h, seulement les jours sans aucun chauffage de 8h à 16h. Chaque jour pèse autant. Avant, des tranches d'une demi-heure favorisaient les jours gris, où l'on ne mesurait que les moments où la maison se réchauffait. S'il n'y a aucun jour de ce type, l'ancienne estimation par tranches est utilisée et signalée (« tranches »).
- **Nouveau réglage « Classer les journées avec »** (onglet Maison) : **la météo d'abord** (par défaut si un code météo est configuré), la moyenne météo + capteur, ou le capteur seul. La nébulosité de la météo distingue un ciel voilé d'un plein soleil, alors qu'un capteur bon marché plafonne vite (2000 lx contre plus de 50 000 lx en plein soleil). Sans code météo, le capteur est utilisé.
- **Graphique « Journées sans chauffage » plus lisible** : sur la page, le temps, la date et la température extérieure sont empilés sous chaque barre, sans rotation ni chevauchement. Sur un écran étroit, seul le jour s'affiche, en gras au début du mois, et la période est indiquée sous l'axe. Dans le mail, un pictogramme du temps (soleil, soleil et nuage, nuage) est dessiné au-dessus de chaque barre, et le graphique couvre les 14 derniers jours (réglage « Jours des graphiques du rapport »), pour rester lisible sur un téléphone.
- **Période affichée sur les graphiques sans dates** (montée, refroidissement, journée type, vitesse de VMC, soleil, signature, confort, ventilation) : « 📅 Mesuré du 04/10 au 25/10 (22 jours de données) » sous le titre dans l'onglet, et sous chaque image dans le mail. Les dates sont celles des vraies mesures, pas seulement la période demandée.
- **Contrôle du capteur de luminosité** : si le maximum réglé n'est presque jamais atteint (les journées les plus lumineuses plafonnent plus bas), des journées ensoleillées sont classées « maussades » et les statistiques du soleil s'inversent. Le plugin le détecte, l'explique dans « Ce qu'on apprend » et propose une **préconisation avec bouton Appliquer** (nouveau maximum = plafond réel de vos meilleures journées).

## 0.9.0 (2026-10-25)
- **Effet du soleil corrigé.** Avant, une journée grise pouvait sembler « gagner » plus qu'une journée ensoleillée. La chaleur restituée par les batteries et les gaines après le chauffage du matin était comptée comme un apport. Les mesures exigent maintenant 2 h sans chauffage (au lieu de 45 min) et au moins 2 h de mesures par type de journée. La vitesse moyenne de la VMC est indiquée : sur une journée ensoleillée, la VMC qui accélère évacue une partie de l'apport.
- **Nouveau : journées sans chauffage.** Pour chaque journée sans aucun chauffage de 4h à 18h : hausse de température entre le minimum du matin (6h-10h) et le maximum de l'après-midi (10h-18h), barre colorée selon le temps du jour, et moyenne par type de journée. C'est la mesure la plus directe de l'apport du soleil (« le soleil apporte X °C de plus qu'un jour gris »). Graphique dans l'onglet et dans le rapport, phrase dans « Ce qu'on apprend de la maison ».
- **Graphiques deux fois plus grands** dans l'onglet Statistiques : 460 px de haut, deux par ligne sur un grand écran, un seul sinon, avec des textes plus lisibles. Les titres des graphiques s'affichent de nouveau. Courbes de température sur 14 jours.
- **Nouveaux graphiques « maison passive »**, dans l'onglet et dans le rapport :
  - **Signature énergétique** : kWh par jour selon la température extérieure moyenne, avec la droite de tendance. On en tire les **déperditions en W/K** et la température au-dessus de laquelle on ne chauffe plus.
  - **Confort** : répartition du temps par température (bas et haut), avec la part entre 20 et 25 °C et au-dessus de 25 °C. Critère maison passive : moins de 10 % au-dessus de 25 °C.
  - **Ventilation et qualité de l'air** : temps passé à chaque vitesse de VMC et dans chaque tranche de CO2.
  - **Échangeur et préchauffage** : efficacité par jour et minutes de préchauffage. Il existait déjà dans le rapport et s'affiche maintenant aussi dans l'onglet.
- Phrases correspondantes dans « Ce qu'on apprend de la maison » (déperditions, confort, CO2).
- Rapport : 12 graphiques plus hauts, avec des échelles aux valeurs rondes.

## 0.8.4 (2026-10-25)
- **Import de l'historique Jeedom** étendu aux nouvelles données :
  - code météo, converti en nébulosité et pluie, sans les 0 parasites ;
  - état et puissance du préchauffage ;
  - températures de l'échangeur, avec l'efficacité recalculée avec les mêmes règles qu'en direct.
- Les **jours déjà enregistrés sont complétés** là où une valeur manque, au lieu d'être ignorés. Une donnée ajoutée après coup (météo, préchauffage…) récupère donc son passé. Rien n'est jamais écrasé et un second import ne change rien.

## 0.8.3 (2026-10-25)
Météo : reprise de la gestion de RoboProg / LandroidRTK.
- Nouvelle commande facultative **« Libellé de la condition »** (texte, informatif), affichée dans le Récapitulatif et sur le widget.
- **Libellés officiels** des codes dans l'aperçu : OpenWeatherMap en dur, WeatherAPI depuis le JSON officiel avec copie locale d'une semaine. Service injoignable : dernière copie locale, même ancienne, sinon liste complète en dur. Open-Meteo traduit en français.
- **Émojis par famille** identiques à RoboProg (dont 😶‍🌫️ brume et 🌪️ grain / tornade). Les libellés sont des constantes de classe, pas des propriétés statiques (même piège Jeedom que dans RoboProg).
- **Plugin météo muet** : la dernière valeur connue est gardée 3 h et signalée. Un 0 « parasite » (commande numérique vide) n'est plus pris pour « ciel dégagé » quand la source habituelle n'est pas Open-Meteo. Une valeur figée depuis plus de 3 h est ignorée.

## 0.8.2 (2026-10-25)
- **Conditions météo en temps réel** (facultatif, onglet Maison) : commande info donnant le **code** de condition d'un plugin météo.
  - Sources reconnues automatiquement : OpenWeatherMap (200 à 804), WeatherAPI (1000 à 1282) ou Open-Meteo (WMO 0 à 99).
  - La vérification exige un nombre et signale un texte ou un code inconnu. Aperçu en direct (« nuageux, code 803, OpenWeatherMap »).
  - Statistiques : les journées sont classées avec la météo (moyenne avec le capteur de luminosité s'il existe, ou météo seule), avec le nombre de jours de pluie et la consommation les jours de pluie comparée aux jours secs.
  - Affichage dans le Récapitulatif (code, nébulosité) et icône sur le widget.

## 0.8.1 (2026-10-25)
- **Rapport** : les graphiques couvrent les **14 derniers jours** (nouveau réglage « Historique des graphiques », 7 à 60 jours), indépendamment de la période analysée.
- **Tous les graphiques** de l'onglet Statistiques sont dans le rapport, soit 9 au lieu de 3 ou 4 : températures, énergie par jour, stabilité, montée en température pendant la chauffe, refroidissement sans chauffage, journée type, pertes selon la vitesse de VMC, temps qu'il fait, échangeur et préchauffage.
- Graphique « temps qu'il fait » refait, sous le titre « Ce que le soleil apporte » : hausse de température du bas entre 10h et 16h, chauffage éteint, en °C sur les 6 heures (valeur écrite sur chaque barre). Sous chaque barre : nombre de jours et température extérieure moyenne ; la consommation par degré-jour n'apparaît que si elle n'est pas nulle. Barres pâles quand il y a moins de 3 jours d'un type. Un résultat contraire à l'attendu est signalé. Même présentation dans l'onglet Statistiques.
- **Images dans le corps du mail** avec le plugin Mail (images intégrées « cid », visibles dans Gmail, Outlook, Thunderbird, mobiles). Pièces jointes pour les autres commandes ou en cas d'impossibilité. L'aperçu de l'onglet Statistiques montre le mail tel qu'il sera reçu.

## 0.8.0 (2026-10-25)
- **Efficacité de l'échangeur** (facultatif) : nouveaux champs « Température air soufflé » (registre 4036) et « Température air rejeté » (4046). Le calcul (air soufflé − dehors) ÷ (dedans − dehors) n'est fait que bypass fermé, préchauffage arrêté et avec 5 °C d'écart au moins. La valeur est lissée. Elle apparaît dans le Récapitulatif, l'aperçu en direct, le widget et l'info « Efficacité échangeur ». Dans les statistiques : moyenne de la période et alerte en cas de baisse.
- **Préchauffage antigel** (facultatif) : état (4060, 2 = actif) et puissance (4061). Il est suivi dans le Récapitulatif, sur le widget, avec des minutes du jour et des statistiques (heures, température extérieure moyenne). **Nouvelle catégorie de notification « Préchauffage »** (allumé / éteint avec sa durée), non envoyée pendant la plage de repos. Les anciens destinataires la reçoivent s'ils avaient « VMC ».
- Suppression des champs « Température air neuf / air extrait », qui ne convenaient pas à la Flair 325 : la VMC décide toujours avec vos thermomètres.
- Aide ModBus : les registres 4036, 4046, 4060 et 4061 sont reconnus et remplis automatiquement.

## 0.7.4 (2026-10-25)
- **VMC toujours en mode ModBus** : nouvelle case « Garder la VMC en mode ModBus en permanence, même mode automatique coupé », cochée par défaut. Une VMC coupée (courant, disjoncteur, entretien) redémarre hors mode ModBus et ne le reprend pas d'elle-même : le plugin renvoie l'ordre toutes les 15 min (réglable), y compris quand son mode auto est coupé.
- Renvoi **immédiat** quand la VMC répond de nouveau après une coupure (« Cycle OK » de MyModbus qui repasse à 1), et dans les 2 min si la lecture du registre 8000 est configurée et ne vaut plus 1.

## 0.7.3 (2026-10-25)
Plugin rendu générique pour être distribué :
- **Boosts** : on ne parle plus du « bouton de la salle de bain » mais d'une **action extérieure** (bouton, interrupteur, détecteur d'humidité, scénario, widget), avec une bulle d'aide.
- **Vitesses** : elles portent les noms des positions de la Flair (absence, bas, normal, haut) et non plus des débits propres à une installation. Nouveau champ facultatif « Débit de chaque vitesse » (m³/h) pour l'affichage, réglé par l'installateur.
- **Batteries** : présentées comme « Batterie 1 (principale) » et « Batterie 2 (facultative : autre zone ou appoint) ». La puissance n'a plus de valeur par défaut : elle est obligatoire, à lire sur la plaque. Les boutons « Forcer » du widget affichent les noms choisis. Bulle d'aide sur le nom.
- **Plages par défaut** renommées : « Réveil » et « Soirée ».
- **Soleil** : « vitrages » au lieu de « baies vitrées plein sud ». Le capteur de luminosité doit être « côté soleil, au sud de préférence » et son maximum est à adapter à votre capteur.
- **Notifications** : « canal journal (Discord, Telegram, Slack…) » au lieu de Discord seul.
- Plus aucune référence à des scénarios personnels. La section du README devient « Si vous aviez déjà des scénarios », décrite par fonction.
- Maison « très isolée (passive ou BBC) » au lieu de « maison passive ». Exemple de port série générique.
- Script d'installation : il ne dépend plus d'un dossier personnel. Il cherche ThermoFlair325 (dossier ou .zip) à côté du script, dans le dossier courant ou dans le dossier de l'utilisateur qui lance sudo. Les sauvegardes sont faites à côté de la source.

## 0.7.2 (2026-10-25)
- **Nouvel onglet « Aide ModBus »** (le dernier) pour configurer MyModbus sans peine :
  - **analyse de votre équipement MyModbus** : chaque commande est reconnue d'après son registre et sa valeur traduite (ex. bypass 4 = fermé). Sont signalés : mauvaise fonction (ex. un registre 4xxx lu en 0x03, qui met « Cycle OK » à 0), format non signé pour une température, valeur écrite incorrecte, commande dangereuse (8011…). L'analyse liste aussi les commandes manquantes et propose des noms clairs ;
  - bouton **« Remplir les champs ThermoFlair »** ;
  - câblage A-B-GND (X15 / X06), cavaliers, menu 14 de la VMC, réglages MyModbus pas à pas, registres de la Flair 325 triés par catégorie, codes de panne, bon à savoir (filtres, bypass, antigel).
- **Mode ModBus** : la lecture du registre 8000 devient facultative. Le plugin renvoie « Mode ModBus ON » toutes les 15 min tant qu'il pilote (réglable, 0 = jamais), comme votre ancien scénario. Il le renvoie aussi tout de suite si la lecture n'est pas 1 (cas d'une coupure de courant).
- **Communication ModBus** : nouveau champ « Cycle OK » (MyModbus). Alerte si la VMC ne répond plus depuis 5 min (réglable), message à son retour, ligne dans les garde-fous, info « Communication ModBus ».
- **Filtre** : commande facultative de remise à zéro (registre 8010) et nouvelle commande « Filtre nettoyé (remise à zéro VMC) ».
- **Bypass** : « Valeur bypass ouvert » accepte plusieurs valeurs (« 1,3 » pour le registre 4050 de la Flair). Une configuration incohérente est signalée.
- Valeurs par défaut adaptées à la Flair 325 : position ventilation 0 / 1 / 2 / 3.
- Les températures « air neuf / extrait » de la VMC sont ignorées si elles s'écartent de plus de 5 °C du thermomètre extérieur. Sur la Flair, les registres 4036 et 4046 sont mesurés après l'échangeur.
- Widget : textes plus lisibles (11 px minimum), ligne des températures qui passe à la ligne proprement. Sur mobile, le widget prend toute la largeur et ses boutons sont bien dessinés.
- Mise à jour : nettoyage automatique de ce qui resterait d'équipements supprimés (état interne, écouteurs, statistiques).

## 0.7.1 (2026-10-25)
Corrections trouvées en testant le plugin dans un vrai Jeedom 4.6 (maison simulée) :
- Délestage : une batterie délestée retrouvée allumée (relais, scénario…) reçoit à nouveau un ordre d'arrêt.
- Un équipement neuf, pas encore configuré, n'envoie plus d'alertes « configuration invalide » ; l'alerte disparaît dès que la configuration est bonne.
- Boost et vitesse forcée : un seul message (au lieu de deux), avec l'heure de fin ; fin du boost à la minute près.
- VMC : après l'arrêt du chauffage, elle redescend tout de suite (elle restait 15 min en vitesse 3).
- Filtre : messages avec l'icône 🧹, « filtre nettoyé » dans la catégorie Filtre.
- Rapport : les écarts de pourcentage sont donnés en « points » ; les images temporaires sont nettoyées.
- Suppression d'un équipement : son état interne, son cache et ses statistiques sont effacés.
- Plus d'affichage « -0 °C ».

## 0.7.0 (2026-10-25)
- **Le plugin devient « ThermoFlair 325 »** (identifiant `ThermoFlair325`) : conçu pour la VMC Brink Flair 325, qui doit être reliée à Jeedom en **ModBus**. Le script `maj_thermoflair325.sh` migre automatiquement une installation « ThermoFlux » : même équipement, mêmes commandes (scénarios et JeedomConnect intacts), réglages et statistiques.
- Icône orange, comme les autres plugins de l'auteur.
- Plage par défaut « Coucher des enfants » (batterie du haut) : 21h30 → 23h.
- **Verrou de nuit** (23h30 → 5h30 par défaut) : aucune batterie ne chauffe, quoi que disent les plages ou un forçage. Exception hors-gel réglable. Commandes `Verrou de nuit ON / OFF`. La configuration signale les plages qui le chevauchent.
- **Garde-fous** vérifiés à chaque cycle sur l'état réel : verrou de nuit, thermostat OFF, température de sécurité, vitesse de VMC suffisante, délestage, pas de vitesse 4 hors boost, consignes, configuration. En cas d'écart pendant 3 min : notification et nouvel ordre d'arrêt. Carte « Garde-fous » dans le Récapitulatif.
- **Nouvelle catégorie de notifications « Sécurité »** (verrou de nuit déclenché, garde-fous, verrou activé / désactivé), envoyée même pendant le repos. Les anciens destinataires la reçoivent s'ils avaient « Alertes ».
- **Chauffage forcé à distance** : `Forcer la batterie 1 (minutes)`, `Forcer les deux batteries (minutes)` (curseur, idéal JeedomConnect), `Forcer le chauffage` (titre « bas » ou « tout », message = durée, ex. 1h30), `Arrêter le chauffage forcé`, info `Chauffage forcé`. Chauffe jusqu'à la consigne Normal, même hors plage et en mode ECO. Boutons sur le widget.
- **Rapport automatique** des statistiques aux jours et heures choisis (ex. mercredi et samedi à 8h et 19h), par mail ou notification : évolution par rapport à la période précédente, préconisations, ce qu'on apprend de la maison, et 4 graphiques PNG en pièces jointes. Aperçu et envoi immédiat depuis l'onglet Statistiques.

## 0.6.0 (2026-10-25)
- **Onglet Récapitulatif** (ancien Diagnostic) : premier onglet, ouvert par défaut.
- **Règles de vitesse expliquées clairement** : une seule vitesse quand les deux cas sont identiques (plus de « V1, sinon V1 »), explication de « l'air du dehors est plus frais », zones renommées « maison fraîche / bonne température / maison trop chaude » partout.
- **Thermomètre de régulation au choix pour chaque batterie** : bas, haut ou moyenne (par défaut : bas pour la batterie 1, haut pour la batterie 2). Pris en compte aussi par les statistiques et les préconisations.
- **Notifications** : bulle d'aide sur chaque case qui détaille ce qui est envoyé. Le bouton ✈️ envoie un message de test par sujet coché, avec les vraies valeurs du moment (vitesse et raison, batteries, puissance, filtre, alertes, journal). Message clair si aucune case n'est cochée.
- **Surchauffe due au soleil** reconnue : bas ≥ consigne Normal + 2 °C, au moins 10 °C de plus que dehors, en journée, sans chauffage depuis 1 h. Expliquée dans les raisons de la VMC et des batteries, dans le Récapitulatif et le widget, notifiée une fois par jour, et comptée dans les statistiques (heures, maximum, part du dépassement de consigne due au soleil).

## 0.5.1 (2026-10-25)
- **Luminosité : classement des journées** au lieu d'une intensité du soleil. Les capteurs comme le vôtre saturent (2000 lux) dès qu'il fait jour clair, même sans soleil direct. Chaque journée est donc classée selon le temps passé au maximum du capteur entre 9h et 17h : ensoleillée (60 % et plus), mitigée (15 à 60 %), maussade (moins de 15 %, le capteur reste sous son maximum).
- Statistiques par type de journée : variation de température entre 10h et 16h sans chauffer, kWh par degré-jour, dépassement de la consigne l'après-midi. Nouveau graphique « Selon le temps qu'il fait ». L'apport du soleil du modèle devient « apport d'une journée ensoleillée entre 10h et 16h ».
- Réglage « Maximum du capteur » (2000 lux par défaut) au lieu de « Plein soleil ». Le widget affiche la luminosité en lux, avec ☀️ quand le capteur est au maximum.

## 0.5.0 (2026-10-25)
- **Préconisations pour une température plus constante** (onglet Statistiques), calculées sur l'historique :
  - stabilité mesurée pendant les plages Normal et à consigne fixe : part du temps à ±0,5 °C de la consigne, trop froid / trop chaud, écart-type, démarrages par jour ;
  - inertie après l'arrêt d'une batterie et retard après l'allumage → battement conseillé ;
  - marche minimum qui fait surchauffer, démarrages trop nombreux → durées conseillées ;
  - retard au début des plages → heure de début conseillée, ou consigne ECO plus haute, ou alerte si le silence empêche de chauffer avant ;
  - bas qui n'arrive pas à suivre → appoint ;
  - vitesse 2 aussi efficace que la vitesse 3 pour une batterie, pertes selon la vitesse de VMC d'hiver, VMC qui change trop souvent ;
  - après-midi ensoleillés qui font dépasser la consigne, bypass ouvert pendant la chauffe, meilleur mode (températures / bypass) ;
  - zone au-dessus de sa consigne sans chauffer (chaleur venue d'ailleurs).
- Chaque conseil indique la valeur actuelle, la valeur conseillée, un niveau de confiance et le nombre de mesures. Un bouton « Appliquer » remplit le réglage (à sauvegarder ensuite).
- **Historique des réglages** : chaque sauvegarde qui change un réglage de stabilité ouvre une période. Le tableau « Vos réglages successifs » compare leurs résultats, et le plugin propose de revenir à des réglages qui donnaient une température plus stable.

## 0.4.1 (2026-10-25)
- Dates de l'hiver réglables au jour près (ex. du 15 octobre au 10 avril), au lieu du mois entier. Par défaut : du 1er octobre au 31 mars, comme avant. L'onglet Maison affiche la date du prochain changement de saison. Une date impossible (31 février) est signalée.

## 0.4.0 (2026-10-25)
- **Deux modes de décision de la VMC**, au choix pour l'hiver et pour l'été :
  - **Températures** : seuils du plugin (maison fraîche / bonne température / trop chaude) et comparaison dehors / dedans ;
  - **Bypass** : le plugin suit le bypass de la VMC (ouvert = rafraîchir, fermé = garder la chaleur ou la fraîcheur). Si le statut est illisible, retour automatique au mode températures.
- Nouvelle commande liée « Statut du bypass » (0/1, valeur « ouvert » réglable). Infos `VMC bypass ouvert` et `VMC mode de décision`. Alerte si le bypass est ouvert pendant que le chauffage tourne.
- **Vitesse réglable pour chaque zone** (maison fraîche, bonne température, trop chaude), selon que l'air du dehors est plus frais ou non, en hiver et en été. Le seuil « maison fraîche » est de nouveau réglable en hiver.
- **Plages de silence à la minute près** : le début et la fin d'une plage s'appliquent tout de suite, sans attendre le temps minimum entre deux changements.
- Boost 1 : 3 min par défaut.
- Statistiques : bypass et mode enregistrés toutes les 5 min (import de l'historique du bypass compris). Pertes en plus quand le bypass est ouvert, minutes de chauffe bypass ouvert, et tableau « Températures ou bypass » : kWh par degré-jour, perte d'une nuit par degré d'écart, temps trop chaud l'été, vitesse moyenne.

## 0.3.1 (2026-10-25)
- **Correction du boost** : à la fin d'un boost (ou d'une vitesse imposée, ou d'un réglage manuel), la VMC reprend tout de suite sa vitesse automatique. Avant, l'anti-battement pouvait la laisser en vitesse 4 jusqu'à 15 min de plus.
- **Hiver : vitesse habituelle réglable (1 par défaut)**. La VMC reste à cette vitesse pour garder la chaleur. Elle n'accélère que si une batterie chauffe, si la maison est trop chaude avec un air extérieur plus frais, si le CO2 est trop haut, ou pendant une plage de renouvellement active en hiver. Avant, la VMC passait en vitesse 2 dès que l'air du dehors était plus frais.
- La plage « Renouvellement quotidien » par défaut ne vaut plus qu'en été. Avertissement si une plage active en hiver dépasse la vitesse habituelle.
- Textes de configuration réécrits en phrases courtes. Explications dans des icônes « ? » avec info-bulle.
- Onglet Commandes : colonne « Rôle » pour chaque commande, et explication de la différence entre Mode chauffage, Consigne Normal et Consigne ECO.
- Cases des jours regroupées avec leur lettre. Jours mieux séparés dans l'aperçu de la semaine.

## 0.3.0 (2026-10-24)
- **Modes de chauffage Normal et ECO** : deux consignes réglables depuis le widget (± 0,5 °C), ECO toujours au moins 0,5 °C sous Normal (vérifié à la configuration et borné sur le widget et les commandes). Commandes `Mode Normal` / `Mode ECO`, `Régler la consigne Normal` / `Régler la consigne ECO`, infos `Mode chauffage`, `Consigne Normal`, `Consigne ECO`.
- Plages : modes Normal, ECO, Consigne fixe, Marche forcée, Arrêt. Hors plage : éteinte, ECO ou Normal. En mode ECO global (absence, vacances), Normal et Marche forcée passent à ECO et les consignes fixes sont plafonnées à ECO.
- Les consignes « éco » par batterie disparaissent au profit de la consigne ECO commune (les plages « Confort » existantes deviennent « Normal »).
- **Capteur de luminosité extérieur** (optionnel, plein sud) avec réglage du « plein soleil » (2000 lux par défaut). Ensoleillement affiché sur le widget et enregistré dans les statistiques.
- Statistiques solaires : apport du plein soleil en °C/h dans le modèle thermique (bas et haut), équivalence « 1 h de soleil = X min de batterie », kWh offerts par le soleil, heures de plein soleil par jour, refroidissement par temps ensoleillé / couvert, nouveau graphique « Effet du soleil », colonne « au soleil sans chauffer » dans les prévisions, soleil dans la journée type et les 7 derniers jours. Le soleil étant séparé du reste, la déperdition et l'effet de la VMC sont aussi mieux estimés.

## 0.2.0 (2026-10-24)
- **Vitesse de VMC minimum par batterie** (2 ou 3) : le plugin monte la VMC avant d'allumer une batterie, ne l'allume qu'une fois la vitesse confirmée, la coupe si la VMC redescend, et garde la VMC en marche quelques minutes après l'arrêt (chaleur résiduelle).
- **Calendrier de silence** (plages illimitées, vitesse maximum) remplaçant la notion de nuit ; conflit avec la vitesse minimum d'une batterie signalé à la configuration, dans l'aperçu de la semaine et par notification au moment voulu.
- **Délestage Linky** en temps réel (écouteur sur la puissance) : coupure dans l'ordre choisi, seconde coupure si le seuil est toujours atteint après 15 s, réenclenchement prudent, notifications.
- **Plages de chauffage multi-batteries** (bas et/ou haut) avec modes Confort / Consigne / Marche forcée / Arrêt ; hors plage : éteinte, éco ou confort. Thermomètres bas et haut dédiés.
- **3 boosts** à durée réglable (1 / 15 / 60 min) pour le bouton de la salle de bain.
- **Filtre** simplifié (binaire) avec enregistrement automatique de la date de nettoyage.
- **Notifications** : case « Toutes les actions » (journal Discord), catégorie Délestage, plage de repos (23:02–05:59) cochable par destinataire.
- **Statistiques** : enregistrement toutes les 5 min, import de l'historique Jeedom, bilan journalier, degrés-jours, modèle thermique par zone (apport de chaque batterie, déperdition, effet de la VMC, constante de temps), temps de chauffe pour gagner 1 °C selon la météo, 6 graphiques.
- Vérification renforcée des commandes liées : type ET sous-type (other / message / numeric / binaire).
- Icône au format des autres plugins (309×348).

## 0.1.0 (2026-10-24)
- Première version : remplace les scénarios VMC_MODE_AUTO, VMC_ACTIVATION_AUTO, VMC VITESSE 3 1H, Vérification Filtre et Température Auto.
- VMC : zones de température avec battement, test « dehors plus frais » et « dehors froid » avec hystérésis, CO2, plages de renouvellement illimitées, aération matinale des jours chauds, plafond de nuit et de matin de week-end, temps minimum entre deux changements, surveillance continue du mode de pilotage (ModBus).
- Vitesse 4 réservée au boost (60 min maximum) ; une vitesse 4 réglée hors plugin est aussi limitée à cette durée. Tout réglage manuel est respecté pendant un délai réglable, VMC arrêtée comprise.
- Chauffage : thermostat avec consigne réglable sur le widget, deux batteries (principale et appoint), durées minimum de marche et d'arrêt, marche continue maximum avec pause, plages illimitées (consigne, marche forcée, arrêt) avec aperçu de la semaine, appoint automatique de la 2e batterie.
- Sécurités : pas de chauffe si la VMC est à l'arrêt ou illisible, température maximum, saison, température extérieure ; vérification de chaque ordre avec 3 relances puis alerte.
- Filtre : seuils libres sur n'importe quelle valeur, rappel tous les N jours, bouton « filtre changé ».
- Notifications par destinataire et par catégorie (VMC, chauffage, filtre, alertes), plage sans notification.
- Onglet Diagnostic : explication de chaque décision, vérification de la configuration, exécution d'un cycle à la demande. Aperçu ✅/❌ de chaque commande liée.
- Widget dédié : vitesses, raison, mode auto, boost, thermostat, consigne ±0,5 °C, état et consommation de chaque batterie, filtre.
