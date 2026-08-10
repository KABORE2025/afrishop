# Registre des manques — ce que la conception v1 n'avait pas pris en compte

Inventaire établi le 2 août 2026, après vérification des règles UEMOA réelles.
Gravité : **B** bloquant · **M** majeur · **S** secondaire.

---

## A. Manques qui remettent en cause l'architecture

| # | Gravité | Manque | Conséquence |
|---|---|---|---|
| A1 | **B** | **Le séquestre sur sous-compte marchand n'existe pas en UEMOA.** Aucun agrégateur (CinetPay, PayDunya, FedaPay, Semoa, Hub2) ne propose de sous-comptes régulés à la Stripe Connect. Paystack et Flutterwave en ont, mais reversent vers des comptes bancaires et ne documentent ni la zone XOF ni la rétention de fonds. | Toute la v1 reposait dessus. Il faut encaisser sur **un compte marchand unique** et reverser par API de payout — donc **la plateforme détient des fonds de tiers**. |
| A2 | **B** | **Détenir des fonds de tiers déclenche des obligations BCEAO.** Instruction n°001-01-2024 : les services de paiement sont réservés aux entités agréées (capital 10 à 100 M FCFA), avec **cantonnement obligatoire** des fonds clients sur compte bancaire séparé (art. 48), rapprochement quotidien. Aucune exemption « agent commercial » comparable à la DSP2 européenne n'a été trouvée dans le texte. | Risque réglementaire majeur. À faire trancher par un juriste BCEAO **avant** la mise en service. Deux options : agrément, ou montage en split payment via un PSP agréé qui reverse directement. |
| A3 | **B** | **Retenue à la source sur les vendeurs non immatriculés.** Burkina : **25 %** sur les prestations versées à un résident sans IFU. Bénin : 5 % (AIB). Côte d'Ivoire : 5 % (AIRSI sur le secteur informel). | Un artisan sans IFU perd un quart de son revenu. Le modèle doit gérer la retenue, son reversement au fisc, et surtout **pousser les vendeurs à s'immatriculer**. |
| A4 | **B** | **Le paiement à la livraison (espèces) n'était pas modélisé.** C'est pourtant le mode dominant en Afrique de l'Ouest. | Un flux entièrement différent : pas d'encaissement préalable, réconciliation du cash livreur, risque de refus au seuil de la porte, commission à recouvrer autrement. |
| A5 | **M** | **Plafonds de monnaie électronique BCEAO.** 200 000 FCFA/mois pour un portefeuille non identifié ; 2 000 000 de solde et 10 000 000 de rechargement mensuel pour un portefeuille identifié. | Un panier peut dépasser le solde de l'acheteur. Un vendeur qui vend bien sature son portefeuille. Il faut contrôler au paiement, proposer un mode alternatif, et alerter le vendeur. |

## B. Manques fiscaux et juridiques

| # | Gravité | Manque | Conséquence |
|---|---|---|---|
| B1 | **B** | **Aucune gestion de la TVA.** 18 % dans la plupart des pays, **19 % au Niger et en Guinée-Bissau**, taux réduits variables. | Le prix affiché doit être TTC, la TVA ventilée, et le taux dépend du pays **et** de la catégorie du produit. |
| B2 | **B** | **Aucune facturation.** Or la facture certifiée par l'État est obligatoire au Bénin (e-MECeF), au Burkina (FEC depuis janvier 2026), en Côte d'Ivoire (FNE), au Niger (e-SECeF). Le Mali et le Togo suivent. | Une plateforme **ne peut pas auto-facturer** pour un vendeur non enrôlé. Il faut modéliser qui émet quoi : facture vendeur → client, et facture plateforme → vendeur pour la commission. |
| B3 | **M** | **Régimes fiscaux des vendeurs non pris en compte.** La plupart des artisans relèvent de régimes forfaitaires hors champ TVA (CME au Burkina, CGU au Sénégal, TPU au Togo, Entreprenant en Côte d'Ivoire, impôt synthétique au Mali et au Niger). | Il ne faut **pas** facturer de TVA sur leurs ventes. Mais la plateforme est redevable de la TVA sur sa commission. Deux flux fiscaux sur une même transaction. |
| B4 | **B** | **Protection des données : formalité préalable obligatoire** (déclaration ou autorisation) auprès de la CIL, de l'ARTCI, de la CDP, de l'APDP selon le pays. Au Burkina, l'hébergement à l'étranger exige une **autorisation préalable** de la CIL. | Registre des traitements, table de consentements, politique de purge, journal des accès, points d'entrée pour les droits des personnes, réponse sous deux mois. |
| B5 | **M** | **Droit de rétractation ignoré.** Sénégal : 7 jours ouvrables, porté à **3 mois** si l'information n'a pas été donnée. | Un statut de commande dédié, un délai paramétré **par pays**, et la traçabilité du remboursement. |
| B6 | **M** | **Mentions légales et obligations d'information** du commerce électronique : prix TTC, frais de livraison, identité de l'exploitant, procédure de correction de saisie, archivage du contrat. | Génération dynamique par pays, et archivage horodaté des commandes. |

## C. Manques fonctionnels

| # | Gravité | Manque | Conséquence |
|---|---|---|---|
| C1 | **B** | **Aucune photo produit.** La v1 n'avait que des emojis. | Table de médias, redimensionnement, stockage objet, compression — décisif sur des connexions lentes. |
| C2 | **B** | **Aucune variante produit** (taille, couleur, contenance). | Le stock, le prix et le code article se gèrent au niveau de la variante, pas du produit. |
| C3 | **M** | **Aucune procédure de retour.** Seuls les litiges existaient. Un retour n'est pas un litige : le produit est conforme, le client change d'avis. | Qui paie le retour ? Sous quel délai ? Dans quel état ? |
| C4 | **M** | **Aucun avis client rédigé.** Seule une note calculée existait. | Les acheteurs lisent les avis ; les vendeurs les redoutent. Modération obligatoire. |
| C5 | **M** | **Aucune notification.** Ni SMS, ni push, ni e-mail. | C'est le poste de coût récurrent le plus sous-estimé. Modèles de messages par langue et par pays, journal des envois, coût unitaire. |
| C6 | **M** | **Aucune modération du catalogue.** Rien n'empêche un vendeur de publier un produit interdit ou contrefait. | Ironique pour une plateforme qui vend de l'anti-contrefaçon. File de modération, motifs, décisions. |
| C7 | **M** | **Aucune preuve de livraison.** Le vendeur déclare, on le croit. | Code à usage unique remis au livreur, photo, signature. Sans preuve, tout litige se tranche à pile ou face. |
| C8 | **M** | **Aucun panier persistant ni relance d'abandon.** | Le panier disparaît au rechargement. Aucune relance possible. |
| C9 | **S** | **Aucune promotion ni code promo.** | Et surtout : **qui finance la remise**, la plateforme ou la boutique ? |
| C10 | **S** | **Aucun point relais ni retrait.** | Pourtant moins cher que la livraison à domicile et adapté à l'absence d'adressage. |
| C11 | **S** | **Aucun mode « boutique fermée »** (congés, rupture générale, deuil). | Des commandes tombent chez un vendeur absent. |
| C12 | **S** | **Aucune gestion multilingue.** Français seul, alors que le mooré, le dioula, le bambara et l'anglais sont des réalités de la zone. | Table de traductions, langue par utilisateur. |
| C13 | **S** | **Aucun tableau de bord vendeur** ni export comptable. | Le vendeur ne sait pas ce qui se vend ; son comptable n'a rien à exploiter. |
| C14 | **S** | **Aucun support client structuré** : ni ticket, ni canal, ni délai de réponse annoncé. | |

## D. Manques techniques

| # | Gravité | Manque | Conséquence |
|---|---|---|---|
| D1 | **B** | **Aucun test automatisé.** Le séquestre, la commission et la retenue à la source manipulent de l'argent sans un seul test. | Une erreur d'arrondi passe inaperçue jusqu'à la réclamation. |
| D2 | **M** | **Aucun grand livre interne.** La v1 déduisait les soldes de l'état des sous-commandes. | Avec des fonds détenus et cantonnés, il faut un **journal de mouvements** en écriture seule : c'est la seule base d'une réconciliation. |
| D3 | **M** | **Aucune réconciliation avec le prestataire.** | Sans rapprochement quotidien, un écart se découvre des mois plus tard. Exigé par ailleurs par la BCEAO. |
| D4 | **M** | **Aucune file d'attente.** SMS, e-mails, génération de planches QR, appels au prestataire : tout serait synchrone. | Une requête client bloquée sur un SMS lent. |
| D5 | **M** | **Aucune journalisation des actions administrateur.** | Impossible de savoir qui a remboursé quoi. |
| D6 | **S** | **Aucun environnement de recette, aucune intégration continue, aucune supervision.** | |
| D7 | **S** | **Aucune documentation d'API** (OpenAPI). | |
| D8 | **S** | **Recherche en base seule.** Suffisant au démarrage, insuffisant passé quelques milliers de produits. | |

## E. Manques organisationnels et économiques

| # | Gravité | Manque | Conséquence |
|---|---|---|---|
| E1 | **B** | **Aucun modèle économique chiffré.** La commission couvre-t-elle les frais du prestataire (1,5 à 3,5 % à l'encaissement, 0,8 à 2 % au reversement), le SMS, l'hébergement et le support ? | À 10 % de commission, entre 3 et 5 points partent en frais de paiement. Le seuil de rentabilité n'a jamais été calculé. |
| E2 | **M** | **Aucun accompagnement des vendeurs.** Beaucoup d'artisans ne savent ni photographier un produit, ni rédiger une fiche. | Sans cela le catalogue sera médiocre et la plateforme paraîtra vide. |
| E3 | **M** | **Aucune stratégie de recrutement des premiers vendeurs** ni des premiers acheteurs. | Le problème classique de l'amorçage : sans vendeurs, pas d'acheteurs, et réciproquement. |
| E4 | **M** | **Aucun dimensionnement du support.** Qui répond au téléphone, quand, en combien de temps ? | Sur ce marché, le téléphone est le canal principal, pas l'e-mail. |
| E5 | **S** | **Aucun lotissement.** Tout a été conçu comme un bloc. | |

---

## Ce qui n'a pas pu être vérifié

À faire confirmer localement avant toute décision engageante :

- La **retenue à la source au Mali, au Niger et en Guinée-Bissau** : aucune source officielle accessible.
- Les **taux réduits de TVA au Niger et au Togo** : sources contradictoires.
- Le **délai de rétractation au Burkina Faso** : le texte de la loi 045-2009 n'est pas accessible en ligne dans sa section pertinente.
- L'existence d'une **exemption pour les places de marché** dans l'instruction BCEAO 001-01-2024 : absente du texte consulté, ce qui rend le statut juridique incertain. **C'est le point le plus risqué du dossier.**
- Le **statut d'agrément réel de JumiaPay** dans l'UEMOA, qui aurait servi de précédent.
- La **part réelle du paiement à la livraison** en 2026 : les seules données trouvées datent de 2018 et 2020, avant l'essor de Wave.
