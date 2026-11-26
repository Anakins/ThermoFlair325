# ThermoFlair 325

Plugin Jeedom conçu pour la **VMC double flux Brink Flair 325** dans une maison très bien isolée (**passive ou BBC**), chauffée par une ou deux **batteries de chauffe** placées sur le réseau de soufflage.

> ⚠️ **ModBus obligatoire.** Le plugin pilote la VMC par des commandes Jeedom : la Flair 325 doit être reliée à Jeedom en **ModBus RTU** (RS485), avec le plugin **[MyModbus](https://mrwaloo.github.io/jeedom-plugins-doc/fr_FR/mymodbus_doc)**. Il faut au minimum les commandes **vitesse 1 à 4**, **position ventilation** et **passer en mode ModBus**. L'onglet **Aide ModBus** du plugin détaille tout et vérifie votre équipement MyModbus.

### Brancher et configurer en bref

- **Câble** : connecteur rouge 3 pôles **X15** de la VMC (ou **X06** avec le circuit « Plus ») : **1 = GND, 2 = A, 3 = B**, vers un dongle USB-RS485. Paire torsadée pour A et B. Si rien ne remonte, inversez A et B.
- **Carte de la VMC** : retirez les cavaliers **X121** et **X122**. **X12** (résistance de fin de ligne 120 Ω) reste si la VMC est au bout du câble.
- **Écran de la VMC, menu 14** : 14.1 = ModBus, 14.2 adresse (20), 14.3 vitesse (19 200 d'usine), 14.4 parité (paire d'usine). **MyModbus doit avoir exactement les mêmes réglages.**
- **Équipement MyModbus** : protocole *serial*, RTU, polling 5 à 30 s, « ID du serveur identique à toutes les commandes » = 20. Laissez **décoché** « Équipement destiné à tester l'existence des registres » : coché, une sauvegarde efface toutes les commandes.
- **Commandes MyModbus utiles au plugin** :

| Rôle | Type | Fonction | Registre | Paramètres / Option |
|---|---|---|---|---|
| Mode ModBus ON | action | 0x06 | 8000 | 1 |
| Vitesse 1 (absence) à 4 (boost) | action | 0x06 | 8001 | 0, 1, 2, 3 |
| Position ventilation | info | 0x03 holding | 8001 | lue : 0 à 3 |
| Mode ModBus (lecture, facultatif) | info | 0x03 holding | 8000 | 1 = ok |
| Bypass statut | info | 0x04 input | 4050 | 3 = ouvert, 4 = fermé |
| Filtre encrassé | info | 0x04 input | 4100 | 1 = à nettoyer |
| Filtre remise à zéro (facultatif) | action | 0x06 | 8010 | 1 |
| Cycle OK | info | (créée par MyModbus) | — | 0 = la VMC ne répond plus |

- **Les registres 4xxx se lisent en 0x04** (input registers), les registres 6xxx et 8xxx en 0x03 (holding). Une seule commande avec la mauvaise fonction suffit à mettre « Cycle OK » à 0.
- **Températures 4036 / 4046** : air soufflé et air rejeté, mesurés **après l'échangeur** (ce ne sont ni l'air extérieur ni l'air de la maison). En dixièmes : format int16, `#value# / 10`. Elles servent au calcul de l'**efficacité de l'échangeur** (onglet VMC, facultatif).
- **Préchauffage 4060 / 4061** : état (2 = actif) et puissance (%). Facultatif : suivi et notification « Préchauffage » quand il s'allume ou s'éteint, hors plage de repos.
- **Coupure de la VMC** (courant, disjoncteur, entretien) : elle redémarre avec les registres 8000 à 8011 à 0, hors mode ModBus, et ne le reprend pas d'elle-même : elle ignore alors les vitesses. Le plugin renvoie « Mode ModBus ON » toutes les 15 min (réglable), **même quand son mode auto est coupé** (case cochée par défaut). Il le renvoie tout de suite quand « Cycle OK » repasse à 1, ou si la lecture du registre 8000 n'est pas 1.
- **Quand le ModBus pilote**, la vitesse ne se change plus sur l'écran ni avec un interrupteur, et le capteur d'humidité Brink ne fonctionne plus.
- **Ne créez pas d'action** sur 8011 (réinitialisation), 7991 (adresse) ni sur les réglages installateur (6033, 6035, 6036, 6150, 6240 à 6242). Le débit de chaque vitesse est réglé par l'installateur.
- **Pannes** : aucun registre ModBus documenté pour le code de panne (affiché sur l'écran). « Cycle OK » à 0 signale une VMC muette, et la sortie X19 (étape 16.1 « Filtre et condition d'erreur ») peut fermer un contact sec relié à Jeedom.

Depuis **un seul équipement « Maison »**, il gère :
- la VMC double flux Brink Flair 325 ;
- deux batteries de chauffe sur le réseau de soufflage (bas et haut) ;
- un délestage sur la puissance Linky ;
- le filtre de la VMC ;
- des statistiques thermiques.

Les modules s'activent séparément dans l'onglet **Maison**.

ThermoFlair 325 ne parle jamais directement au matériel. Il pilote les commandes Jeedom choisies dans chaque onglet, et vérifie en direct qu'il s'agit bien de commandes Jeedom existantes, du bon type (action ou info) et du bon sous-type (`other` pour les ordres, `message` pour les notifications, `numeric` pour les mesures, 0/1 pour les états).

---

## Principes

- **Une commande n'est envoyée que si l'état doit changer.** Le plugin vérifie ensuite que l'équipement a obéi. Sinon, il relance 3 fois puis lève une alerte.
- **Pas de yoyo.**
  - VMC : battement de température et temps minimum entre deux changements de vitesse.
  - Batteries : durées minimum de marche et d'arrêt.
- **Les batteries chauffent l'air soufflé.** Chaque batterie a une **vitesse de VMC minimum** (2 ou 3, jamais 1). Pour chauffer, le plugin :
  1. monte d'abord la VMC à cette vitesse ;
  2. allume la batterie une fois la vitesse confirmée ;
  3. la coupe aussitôt si la VMC redescend ;
  4. garde la VMC en marche 2 minutes après l'arrêt de la batterie, pour évacuer la chaleur résiduelle.
- **Calendrier de silence.** La nuit, la VMC ne dépasse pas la vitesse 2 (réglable, plages illimitées). Une batterie qui a besoin de la vitesse 3 ne peut donc pas chauffer pendant ces plages. Vous êtes prévenu :
  - à la configuration (bandeau, et bloc rouge dans l'aperçu de la semaine) ;
  - par une notification au moment où elle en aurait eu besoin.
- **La vitesse 4 n'est jamais choisie par l'automatisme.** Elle ne sert qu'aux boosts, toujours limités dans le temps (15 min au plus conseillé ; un avertissement s'affiche au-delà). Une vitesse 4 mise à la main ailleurs est coupée au bout de la durée réglée (60 min par défaut).
- **Une configuration incomplète ne pilote rien.** Un bandeau rouge liste les problèmes, et une alerte part une fois par jour.
- **Tout est expliqué.**
  - Onglet *Récapitulatif* (le premier, ouvert par défaut) : chaque règle et chaque décision.
  - Onglet *Statistiques* : comment la maison réagit au climat.

---

## VMC

Règles, par ordre de priorité :

1. **Forçage** : boost, vitesse temporaire, ou réglage manuel détecté (y compris l'arrêt de la VMC), respecté pendant *N* minutes.
2. **Mode auto coupé** : la vitesse « hors auto » est appliquée une fois.
3. **Aération matinale des jours chauds** (été).
4. **Plages de renouvellement** : vitesse imposée, par exemple de 12h à 16h en vitesse 2.
5. **Mode de décision** (au choix pour l'hiver et pour l'été) :
   - **Températures** : voir ci-dessous ;
   - **Bypass** : le plugin suit le bypass de la VMC. Ouvert → vitesse « rafraîchir » ; fermé → vitesse « bonne température ». Les statistiques comparent les deux modes (kWh par degré-jour, perte de nuit, temps trop chaud l'été).

   **Températures**, avec battement (vitesse de chaque zone réglable, selon que l'air du dehors est plus frais ou non) :
   - **hiver** : vitesse habituelle (1 par défaut) pour garder la chaleur. On ne rafraîchit que si la maison est trop chaude et l'air du dehors plus frais ;
   - **été** : maison fraîche → on garde la fraîcheur ; maison trop chaude et dehors plus frais → on rafraîchit ; dehors plus chaud → vitesse 1.
6. **CO2** : vitesse minimum au-dessus du seuil.
7. **Demande du chauffage** : vitesse minimum des batteries en marche.
8. **Calendrier de silence** : plafond appliqué à toutes les décisions automatiques. Seuls le boost et un réglage manuel passent outre.

Le mode ModBus de la VMC (registre 8000) est renvoyé toutes les 15 min tant que le plugin pilote (réglable), et tout de suite si sa lecture n'est pas 1. La commande « Cycle OK » de MyModbus est surveillée : alerte si la VMC ne répond plus.

### Boosts déclenchés de l'extérieur

Un boost est une action extérieure au plugin : bouton (salle de bain, cuisine…), interrupteur, détecteur d'humidité, scénario ou widget. Votre scénario lance simplement la commande du plugin, par exemple avec un bouton à appuis multiples :

```
SI #[Pièce][Mon bouton][Appui]# == "1x"  ALORS  [Maison][ThermoFlair 325][Boost 1]
SI #[Pièce][Mon bouton][Appui]# == "2x"  ALORS  [Maison][ThermoFlair 325][Boost 2]
SI #[Pièce][Mon bouton][Appui]# == "3x"  ALORS  [Maison][ThermoFlair 325][Boost 3]
```

Les boosts se règlent dans un **tableau** de l'onglet VMC : autant de lignes que vous voulez, chacune avec un **nom**, une **vitesse** (1 à 4), une **durée** et une case **Widget**. Chaque ligne crée une commande action du même nom, à utiliser dans vos scénarios. Renommer une ligne renomme sa commande ; supprimer la ligne supprime la commande. En vitesse 4 au-delà de 15 min, un avertissement s'affiche à côté de la ligne : pour aérer longtemps, la vitesse 3 est préférable. Les anciens Boost 1/2/3 et Vitesse 1/2/3 temporaire sont repris tels quels (mêmes commandes, mêmes durées) à la mise à jour. Le plugin gère la fin du boost et revient tout de suite à la vitesse automatique. Le scénario ne doit rien faire d'autre sur la VMC : pas de vitesse 4 directe, pas de `VMC AUTO OFF`, pas de bloc `DANS` ni de `remove_inat`.

---

## Chauffage

- **Batterie 1 (bas)** : régulée par défaut sur le thermomètre du bas.
- **Batterie 2 (haut)** : régulée par défaut sur le thermomètre du haut.
- Pour chaque batterie, le thermomètre de régulation se choisit : bas, haut ou moyenne des deux.
- **Deux consignes, Normal et ECO**, réglées depuis le widget (± 0,5 °C) ou par scénario (`Régler la consigne Normal` / `Régler la consigne ECO`). ECO est toujours au moins 0,5 °C sous Normal : la configuration le vérifie, et le widget comme les commandes bornent la valeur.
- **Mode global Normal / ECO** (widget, commandes `Mode Normal` / `Mode ECO`). En mode ECO (absence, vacances) : les plages Normal et la marche forcée passent à la consigne ECO, les consignes fixes sont plafonnées à ECO, l'arrêt reste l'arrêt.
- **Plages illimitées.** Chaque plage s'applique au **bas et/ou au haut**, avec un mode au choix :
  - **Normal** : consigne Normal ;
  - **ECO** : consigne ECO ;
  - **Consigne fixe** : par exemple les chambres à 19,5 °C ;
  - **Marche forcée** ;
  - **Arrêt**.

  Elles servent aux présences, au matin et au soir. Hors plage, chaque batterie est **éteinte**, en **ECO** ou en **Normal**. Par défaut :
  - le bas est en Normal (20,5 °C) de 6h30 à 22h30, et en ECO (19 °C) le reste du temps ;
  - le haut chauffe à 19,5 °C au lever (6h30 à 7h30 en semaine) et au coucher (19h45 à 21h), et reste éteint le reste du temps.
- **Anti court-cycle** : marche minimum, arrêt minimum, marche continue maximum avec pause.
- **Appoint automatique** : la batterie du haut aide celle du bas quand celle-ci n'y arrive pas. Jamais pendant sa propre plage.
- **Sécurités** :
  - température maximum, même en marche forcée ;
  - chauffe en hiver uniquement (optionnel) ;
  - pas de chauffe au-dessus d'une température extérieure (optionnel) ;
  - jamais de chauffe si la vitesse de VMC est illisible ou insuffisante.
- **Aperçu de la semaine** : les deux batteries, les plages de VMC et le silence. Les blocs bloqués par le silence sont entourés en rouge.

## Verrou de nuit (sécurité)

Sur un créneau de nuit (23h30 → 5h30 par défaut), **aucune batterie ne chauffe**, quoi que disent les plages ou un chauffage forcé. C'est un filet de sécurité en cas d'erreur de réglage. Exception hors-gel réglable : sous 16 °C, la batterie chauffe juste assez pour remonter à 16 °C.
- S'il coupe une batterie en marche, ou bloque une plage ou un forçage, une notification **Sécurité** part, une fois par nuit.
- La configuration signale les plages qui chevauchent le verrou.
- Commandes `Verrou de nuit ON` / `Verrou de nuit OFF` pour le couper ponctuellement.

## Garde-fous

À chaque cycle, le plugin vérifie sur l'**état réel** des équipements que les règles de sécurité sont respectées : verrou de nuit, thermostat OFF, température de sécurité, vitesse de VMC suffisante pour chaque batterie qui chauffe, délestage, pas de vitesse 4 hors boost, consigne ECO sous Normal, configuration valide. Le résultat s'affiche dans le Récapitulatif. Si une règle n'est pas respectée pendant 3 minutes, une notification **Sécurité** part et un nouvel ordre d'arrêt est envoyé à la batterie (relais collé, commande sans effet...).

## Chauffage forcé (à distance)

La batterie chauffe pendant toute la durée choisie, **quelle que soit la température** : la consigne est ignorée, même si elle est déjà atteinte, hors plage, en mode ECO ou hors saison. Ensuite, retour aux plages. Les sécurités restent actives : la température de sécurité arrête la batterie, et le verrou de nuit, le délestage et la vitesse minimum de la VMC s'appliquent.

Pendant un forçage du bas, allumer le haut l'ajoute au forçage : bouton « + Haut » du widget ou du récapitulatif, ou allumage à la main si la commande d'état de la batterie est renseignée. Le haut chauffe alors lui aussi quelle que soit la température, et les deux batteries s'arrêtent ensemble à la fin du forçage.
- `Forcer la batterie 1 (minutes)` / `Forcer les deux batteries (minutes)` : curseur = durée, idéal pour un bouton JeedomConnect.
- `Forcer le chauffage` : titre « bas » ou « tout », message = durée (`60`, `1h`, `1h30`).
- `Arrêter le chauffage forcé`. L'info `Chauffage forcé` indique ce qui est forcé et jusqu'à quand.

## Délestage (Linky)

Le plugin **écoute chaque nouvelle mesure** de puissance : c'est un écouteur Jeedom, pas une vérification chaque minute.

1. Seuil atteint : il coupe la première batterie (celle du **haut** par défaut).
2. Si le seuil est **toujours atteint 15 s plus tard**, il coupe la seconde. Il relit lui-même la puissance, même si le compteur n'a pas renvoyé de valeur entre-temps.
3. Réenclenchement : une batterie à la fois, après un délai minimum, uniquement si *puissance + puissance de la batterie + marge* reste sous le seuil.

Chaque coupure et chaque réenclenchement est notifié, même pendant le repos.

## Filtre

La commande de statut est un binaire : elle passe à 1 quand il faut nettoyer le filtre. Le plugin prévient à l'heure choisie, tous les jours ou une seule fois. Quand le statut repasse à 0, la date de nettoyage est enregistrée automatiquement.

## Notifications

Pour chaque destinataire, vous cochez ce qu'il reçoit :

| Case | Contenu |
|---|---|
| **Toutes les actions** | Absolument tout. Idéal pour un canal qui sert de journal (Discord, Telegram, Slack…) : vitesses, batteries, délestage, changements de mode, consigne, boosts, filtre, alertes. |
| **VMC** | Changements de vitesse |
| **Chauffage** | Allumages et extinctions des batteries |
| **Délestage** | Coupures et réenclenchements |
| **Filtre** | Rappel de nettoyage |
| **Préchauffage** | Préchauffage antigel de la VMC allumé / éteint (durée), hors plage de repos |
| **Alertes** | Pannes et problèmes de configuration |

La case **Repos** coupe les messages de ce destinataire pendant la plage de repos (23h02 à 5h59 par défaut). Le délestage et les alertes passent toujours.

## Surchauffe due au soleil

Dans une maison très isolée avec de grands vitrages, le soleil peut faire monter une pièce à 26-28 °C alors qu'il fait froid dehors, sans aucun chauffage. Quand le bas dépasse la consigne Normal de 2 °C, avec au moins 10 °C de plus que dehors, en journée et sans chauffage depuis 1 h, le plugin l'attribue au soleil :
- dans les raisons (VMC, batteries) et le Récapitulatif ;
- par une notification par jour (« Toutes les actions ») ;
- dans les statistiques (heures de surchauffe solaire, maximum atteint, part du dépassement de consigne due au soleil).

## Statistiques

Le plugin enregistre un point toutes les 5 minutes :
- températures du bas, du haut et de l'extérieur ;
- luminosité extérieure (si un capteur est configuré) ;
- part du temps où chaque batterie chauffe ;
- vitesse de VMC ;
- puissance et CO2.

Les données sont stockées dans `plugins/ThermoFlair325/data`, qui est conservé lors des mises à jour. Le bouton **Importer** reconstitue le passé à partir de l'historique Jeedom des commandes historisées.

L'onglet Statistiques calcule, sur 7, 30 ou 90 jours, ou 1 an :

- **Bilan par jour** : temps de chauffe et kWh de chaque batterie, températures, **degrés-jours**, **kWh par degré-jour**. Ce dernier indicateur est à comparer d'un hiver à l'autre, car il ne dépend pas de la météo.
- **Montée en température** pendant la chauffe, selon la température extérieure.
- **Refroidissement sans chauffage**, de nuit et de jour, selon l'écart intérieur − extérieur, et chute moyenne sur une nuit.
- **Modèle thermique** de chaque zone, obtenu par régression sur toutes les données :

  > dT/dt = apport bas × bas + apport haut × haut + (k + kv × vitesse VMC) × (Text − Tint) + soleil × ensoleillement + apports de jour + base

  Le modèle donne :
  - la **constante de temps** de la maison ;
  - sa **déperdition** ;
  - l'**effet de la vitesse de VMC** sur les pertes ;
  - l'apport de chaque batterie ;
  - l'**apport du soleil** quand il tape à fond (°C/h), et son équivalent en minutes de batterie.
- **« Combien de temps chauffer ? »** : temps nécessaire pour gagner 1 °C, pour chaque batterie, selon la température extérieure (−10 à +15 °C), et variation sur une nuit sans chauffage.
- **Journée type**, heure par heure.
- **Préconisations** pour une température plus constante : à partir de l'historique (inertie des batteries, retard au début des plages, cycles, soleil, VMC, bypass), le plugin propose des valeurs de réglage avec un niveau de confiance et un bouton « Appliquer ». Il garde aussi l'historique de vos réglages et compare la stabilité obtenue avec chacun.
- **Soleil** (avec un capteur de luminosité extérieur placé côté soleil, au sud de préférence) : beaucoup de capteurs saturent (ex. 2000 lux) dès qu'il fait jour clair. Chaque journée est donc classée selon le temps passé au maximum du capteur entre 9h et 17h : **ensoleillée** (60 % et plus), **mitigée** (15 à 60 %), **maussade** (moins). Pour chaque type : variation de température entre 10h et 16h sans chauffer, kWh par degré-jour, dépassement de la consigne l'après-midi. Le modèle donne l'apport d'une journée ensoleillée et son équivalent en minutes de batterie. Sans capteur, les apports de la journée sont approchés par l'heure.

Sans sonde extérieure, l'analyse se limite à ce qui ne dépend pas d'elle : apports des batteries, refroidissement jour et nuit, profil horaire.

---

### Indicateurs « maison passive »

- **Journées sans chauffage** : pour chaque jour sans aucun chauffage de 4h à 18h, la hausse de température entre le matin et l'après-midi, selon le temps (ensoleillé, mitigé, maussade). C'est la mesure directe de l'apport du soleil.
- **Signature énergétique** : chaque jour donne un point (kWh de chauffage selon la température extérieure moyenne). La pente de la droite donne les **déperditions de la maison en W/K**. Le point où elle touche zéro donne la température extérieure au-dessus de laquelle on ne chauffe plus.
- **Confort** : part du temps à chaque température, part entre 20 et 25 °C, et part au-dessus de 25 °C (critère maison passive : moins de 10 %).
- **Ventilation et qualité de l'air** : temps passé à chaque vitesse de VMC et, avec un capteur, dans chaque tranche de CO2 (moins de 1000 ppm = bon).
- **Échangeur et préchauffage** : efficacité par jour et minutes de préchauffage antigel.

## Rapport automatique

Aux jours et heures choisis (par exemple le mercredi et le samedi à 8h), le plugin envoie un **rapport** à une ou plusieurs commandes message, de préférence le plugin **Mail** :
- l'**évolution** par rapport à la période précédente de même durée : consommation, kWh par degré-jour, stabilité de la température, perte par nuit, surchauffe solaire ;
- les **préconisations** et ce qu'on apprend de la maison ;
- les **graphiques** de l'onglet Statistiques, sur les **14 derniers jours** (réglable de 7 à 60) : températures avec les périodes de chauffe, énergie par jour, stabilité jour par jour, montée en température pendant la chauffe, refroidissement sans chauffage, journée type, pertes selon la vitesse de VMC, effet du temps qu'il fait, échangeur et préchauffage. Un graphique sans données suffisantes n'est pas envoyé.
- Avec une commande du **plugin Mail** (SMTP, sendmail…), les graphiques sont **dans le corps du mail** (images intégrées, affichées aussi par Gmail). Le plugin reprend la configuration de l'équipement Mail. Avec les autres commandes (Discord, Telegram…) ou le mode Jeedom Cloud du plugin Mail, ils partent en pièces jointes.

Boutons **Aperçu** et **Envoyer maintenant** dans l'onglet Statistiques. Les graphiques sont dessinés avec l'extension PHP GD de Jeedom. Sans elle, le rapport part en texte seul.

## Commandes de l'équipement

| Commande | Rôle |
|---|---|
| `VMC auto ON` / `VMC auto OFF` | Active ou coupe le mode automatique de la VMC |
| Une commande par ligne du tableau des boosts (par défaut `Boost 1/2/3`, `Vitesse 1/2/3 temporaire`) | La vitesse de la ligne pendant sa durée, puis retour automatique |
| `Reprendre l'automatique` | Annule le boost ou le forçage |
| `Thermostat ON` / `Thermostat OFF` | Active ou coupe le chauffage |
| `Mode Normal` / `Mode ECO` | Change le mode de chauffage |
| `Forcer la batterie 1 (minutes)` / `Forcer les deux batteries (minutes)` / `Forcer le chauffage` / `Arrêter le chauffage forcé` | Chauffage forcé à distance |
| `Verrou de nuit ON` / `Verrou de nuit OFF` | Sécurité de nuit |
| `Régler la consigne Normal` / `Régler la consigne ECO` | Consigne en °C, par pas de 0,5 (ECO reste 0,5 °C sous Normal) |
| Infos | Températures, ensoleillement, vitesse actuelle et demandée, raison, mode chauffage, consignes Normal et ECO, état / consigne / temps de chauffe de chaque batterie, kWh du jour, délestage en cours, filtre à nettoyer, saison, dernière alerte |

## Si vous aviez déjà des scénarios

| Votre scénario faisait… | Dans ThermoFlair 325 |
|---|---|
| choisir la vitesse de la VMC selon les températures | Onglet VMC (mode automatique) |
| activer / couper ce mode automatique | Commandes `VMC auto ON/OFF` |
| mettre une vitesse pendant un temps donné | Commandes `Vitesse 1/2/3 temporaire` |
| relancer le mode ModBus de la VMC régulièrement | Onglet VMC, « Renvoyer le mode ModBus toutes les » |
| surveiller le filtre | Onglet Filtre + Notifications |
| allumer / éteindre les batteries selon une consigne | Onglet Chauffage |
| réagir à un bouton (boost) | À garder, avec les commandes `Boost 1/2/3` |

Désactivez vos scénarios VMC et chauffage existants, pour que deux automatismes ne se battent pas sur les mêmes commandes.

## Conditions météo (facultatif)

Onglet Maison, « Conditions météo (code) » : le **numéro** de la condition météo actuelle donné par un plugin météo, et non le texte. La vérification refuse une commande qui renvoie un texte. Trois familles de codes sont reconnues automatiquement :

| Source | Codes | Exemples |
|---|---|---|
| OpenWeatherMap | 200 à 804 | 800 ciel dégagé, 802 partiellement nuageux, 804 couvert, 5xx pluie |
| WeatherAPI | 1000 à 1282 | 1000 dégagé, 1003 partiellement nuageux, 1009 couvert, 1183 pluie |
| Open-Meteo (WMO) | 0 à 99 | 0 dégagé, 2 partiellement nuageux, 3 couvert, 61 pluie |

Comme dans RoboProg et LandroidRTK :
- **deux commandes** : le code (obligatoire pour la fonction) et le **libellé** texte (facultatif, informatif, affiché dans le Récapitulatif et sur le widget) ;
- **libellés officiels** du code dans l'aperçu : OpenWeatherMap codés en dur ; WeatherAPI récupérés sur le JSON officiel avec une **copie locale d'une semaine**. Si le service est injoignable, la dernière copie locale sert, même ancienne, sinon une liste en dur complète ;
- **émojis par famille** : ☀️ 🌤️ ⛅ 🌥️ ☁️ ⛈️ 🌦️ 🌧️ ❄️ 😶‍🌫️ 🌪️.

Si le plugin météo ne répond plus (valeur vide, 0 parasite, ou valeur figée depuis plus de 3 h), la **dernière valeur connue** est gardée pendant 3 h et signalée dans le Récapitulatif. Au-delà, la météo est ignorée jusqu'au retour du service.

Usages :
- Chaque code est converti en nébulosité (0 % à 100 %) et en précipitations (oui / non).
- Le type de chaque journée (ensoleillée, mitigée, maussade) devient plus fiable. Avec un capteur de luminosité, la moyenne des deux corrige la saturation du capteur ; sans capteur, la météo seule suffit.
- Les statistiques comptent les jours de pluie et comparent la consommation par degré-jour les jours de pluie et les jours secs.
- La météo s'affiche dans le Récapitulatif et sur le widget.

## Échangeur et préchauffage (facultatif)

- **Efficacité de l'échangeur** = (air soufflé − dehors) ÷ (dedans − dehors), avec l'air soufflé de la VMC (4036) et vos thermomètres. L'air rejeté (4046) donne une seconde mesure de contrôle : (dedans − air rejeté) ÷ (dedans − dehors). Le calcul n'est fait que bypass fermé, préchauffage arrêté et avec au moins 5 °C d'écart entre dedans et dehors. La valeur est lissée sur environ 30 min. Une Flair 325 en bon état est vers 85 à 95 %. Les statistiques donnent la moyenne de la période et signalent une baisse (filtres, encrassement). Info `Efficacité échangeur`.
- **Préchauffage antigel** : il réchauffe l'air neuf par grand froid pour que l'échangeur ne gèle pas. Le plugin note quand il s'allume et s'éteint (changement confirmé sur 2 cycles), compte ses minutes du jour et envoie une notification « Préchauffage » (non envoyée pendant la plage de repos si la case Repos est cochée). Infos `Préchauffage actif` et `Préchauffage aujourd'hui`. Les statistiques donnent ses heures de marche et la température extérieure moyenne quand il tourne.
- Ces mesures ne changent pas les décisions du plugin.

## Aide ModBus (dernier onglet)

- **Vérifier mon équipement MyModbus** : choisissez l'équipement et cliquez sur « Analyser ». Chaque commande est reconnue d'après son registre et sa valeur actuelle est traduite (ex. bypass 4 = fermé). Le plugin vérifie la fonction, le format et la valeur écrite, signale les commandes dangereuses et liste celles qui manquent. « Remplir les champs ThermoFlair » met ensuite les bonnes commandes dans les onglets VMC et Filtre.
- Câblage, réglages de MyModbus pas à pas, tous les registres de la Flair 325 triés par catégorie (pilotage, états, mesures, identification, réglages installateur), codes de panne, et rappels utiles (filtres, bypass, antigel).

## Installation

1. Copiez le dossier `ThermoFlair325` dans `plugins/` de Jeedom (ou utilisez le script `maj_thermoflair325.sh`), puis activez le plugin.
2. Vérifiez que la tâche **cron** du plugin est active.
3. Créez **un** équipement et renseignez les onglets.
4. Ouvrez l'onglet **Récapitulatif** : « Vérifier la configuration », puis « Exécuter un cycle maintenant ».
5. Dans l'onglet **Statistiques**, importez l'historique Jeedom des commandes historisées : thermomètres, batteries, vitesse de VMC, Linky, CO2, luminosité, bypass, **code météo**, **préchauffage** et **températures de l'échangeur** (l'efficacité est recalculée). Les jours absents sont créés. Les jours déjà enregistrés sont seulement **complétés** là où une valeur manque (par exemple la météo ajoutée après coup), sans rien écraser. Relancer l'import ne change rien de plus.

## Sources

- [Brink Flair 325 — fiche technique](https://www.brinkclimatesystems.nl/documenten/technical-sheet-flair-325.pdf)
- [Intégration ModBus de la Brink Flair (TapHome) : branchement RS-485, réglages ModBus, bypass](https://taphome.com/en/compatibility/brink-flair/)
- [Brink Flair 300 en ModBus (communauté openHAB)](https://community.openhab.org/t/brink-flair-300-modbus-integration/107814)
