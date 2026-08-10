-- =====================================================================
--  AFRISHOP — DELTA v2.4
--  Autorisation au cas par cas · Homologation · Péremption automatique
-- =====================================================================
--  S'applique APRÈS les deltas v2.1, v2.2 et v2.3.
--
--  TROIS CHANGEMENTS, DONT UN REVIREMENT ASSUMÉ.
--
--  1. LES CATÉGORIES RÉSERVÉES REDEVIENNENT OUVERTES AUX TIERS, sur
--     autorisation fiche par fiche. Le verrou « régie seule » de la v2.3
--     est retiré. Verrouiller protège en fermant, autoriser protège en
--     relisant : le second garde le rayon ouvert aux coopératives et aux
--     récoltants qui en font la valeur. Contrepartie explicite : Afrishop
--     engage sa responsabilité sur CE QU'IL VALIDE, et plus seulement sur
--     ce qu'il vend.
--
--     La colonne `est_regie` et ses neutralisations (commission et
--     retenue nulles) RESTENT : si Afrishop ouvre un jour sa propre
--     boutique, l'ossature est là. Seul le verrou de catégorie tombe.
--
--  2. L'HOMOLOGATION, ET LE CONTRESENS QU'ELLE PROVOQUE.
--
--     Le réflexe est de croire qu'un produit homologué se vend plus
--     facilement. POUR UN MTA C'EST L'INVERSE. Une autorisation de mise
--     sur le marché ne dit pas « vendez-le partout » : elle dit « ceci
--     EST un médicament ». Et un médicament relève du circuit
--     pharmaceutique. L'homologation ne lève pas le monopole, elle y
--     fait entrer.
--
--         non homologué + aucune allégation → aliment    → VENDU
--         non homologué + allégation        → médicament non autorisé
--                                                        → INTERDIT
--         homologué MTA                     → médicament → OFFICINE
--
--     Les trois figurent au catalogue, mais pas au même titre : le
--     troisième s'y RÉFÉRENCE, il ne s'y vend pas.
--
--     [PHARMACIEN / JURISTE] — périmètre exact du monopole de
--     dispensation et circuit autorisé pour les MTA à confirmer auprès de
--     l'agence nationale avant mise en ligne.
--
--  3. LA PÉREMPTION DEVIENT UNE PROPOSITION AUTOMATIQUE. Les lots QR
--     portent déjà une date, saisie pour la traçabilité. Elle sert donc
--     une seconde fois, sans travail supplémentaire : le système propose
--     d'écouler. Le surstock, lui, reste déclaré à la main — aucune
--     donnée ne permet de deviner qu'un produit se vend mal.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. RETRAIT DU VERROU « RÉGIE SEULE »
-- ---------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_produit_categorie_reservee_insert;
DROP TRIGGER IF EXISTS trg_produit_categorie_reservee_update;

-- Les neutralisations de la régie restent en place : elles ne gênent
-- personne tant qu'aucune boutique n'est marquée `est_regie`, et elles
-- protègent immédiatement si l'une l'est un jour.

-- ---------------------------------------------------------------------
-- 2. HOMOLOGATION
-- ---------------------------------------------------------------------
ALTER TABLE produits
  ADD COLUMN statut_homologation ENUM('non_homologue','homologue_mta')
    NOT NULL DEFAULT 'non_homologue'
    COMMENT 'homologue_mta = medicament au sens reglementaire : reference mais NON vendu ici'
    AFTER tracable,
  ADD COLUMN numero_homologation VARCHAR(40) NULL AFTER statut_homologation,
  ADD COLUMN homologation_fabricant VARCHAR(160) NULL AFTER numero_homologation,
  -- MariaDB impose l'ordre : COMMENT puis AFTER. L'inverse est refusé.
  ADD COLUMN homologation_expire_le DATE NULL
    COMMENT 'Une homologation expire. Afficher « homologué » après expiration serait faux'
    AFTER homologation_fabricant;

-- COHÉRENCE : un produit déclaré homologué DOIT porter son numéro.
-- « Homologué » sans numéro est une affirmation invérifiable — c'est-à-
-- dire exactement ce qu'on cherche à empêcher sur ce rayon.
ALTER TABLE produits ADD CONSTRAINT chk_homologation CHECK (
    statut_homologation = 'non_homologue'
 OR (numero_homologation IS NOT NULL AND homologation_expire_le IS NOT NULL)
);

-- VERROU DE VENTE. Un MTA homologué ne peut pas entrer dans une ligne de
-- commande. La règle vit aussi dans l'application ; ici elle est le
-- dernier filet, celui qui tient face à un import ou à un appel d'API
-- direct. Vendre un médicament hors officine n'est pas une erreur qu'on
-- corrige au rapprochement du mois suivant.
DELIMITER $$
CREATE TRIGGER trg_ligne_commande_mta BEFORE INSERT ON lignes_commande
FOR EACH ROW
BEGIN
    DECLARE v_statut VARCHAR(20) DEFAULT 'non_homologue';
    SELECT p.statut_homologation INTO v_statut
      FROM variantes_produit v JOIN produits p ON p.id = v.produit_id
     WHERE v.id = NEW.variante_id;
    IF v_statut = 'homologue_mta' THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Medicament homologue : delivrance reservee au circuit pharmaceutique';
    END IF;
END$$
DELIMITER ;

-- ---------------------------------------------------------------------
-- 3. PÉREMPTION — PROPOSITION AUTOMATIQUE
-- ---------------------------------------------------------------------
-- Le seuil est un PARAMÈTRE, pas une constante dans le code : il se règle
-- sans déploiement, et il se règlera — 45 jours est une hypothèse, pas
-- une vérité.
INSERT INTO parametres (cle, valeur, type, libelle)
VALUES ('liquidation_seuil_peremption_jours', '45', 'entier',
        'Jours avant peremption declenchant une proposition d''ecoulement')
ON DUPLICATE KEY UPDATE valeur = VALUES(valeur);

-- Vue de travail du vendeur. Une vue plutôt qu'une table : rien à tenir
-- à jour, rien à désynchroniser. Elle se recalcule à chaque lecture, et
-- c'est très bien — elle est consultée quelques fois par jour, pas mille
-- fois par seconde.
CREATE OR REPLACE VIEW v_lots_a_ecouler AS
SELECT  p.boutique_id,
        p.id                              AS produit_id,
        p.nom                             AS produit,
        v.id                              AS variante_id,
        v.sku,
        v.libelle                         AS declinaison,
        v.prix_ttc_cfa                    AS prix_normal_cfa,
        v.stock,
        l.reference                       AS numero_lot,
        l.date_expiration,
        DATEDIFF(l.date_expiration, CURDATE()) AS jours_restants,
        -- Remise SUGGÉRÉE, jamais imposée : la marge n'est pas dans le
        -- système, et une remise calculée sans elle ferait vendre à perte.
        CASE
            WHEN DATEDIFF(l.date_expiration, CURDATE()) <=  7 THEN 50
            WHEN DATEDIFF(l.date_expiration, CURDATE()) <= 15 THEN 40
            WHEN DATEDIFF(l.date_expiration, CURDATE()) <= 30 THEN 30
            ELSE 20
        END                               AS remise_suggeree_pct
FROM lots_qr l
JOIN variantes_produit v ON v.id = l.variante_id
JOIN produits p          ON p.id = v.produit_id
WHERE l.date_expiration IS NOT NULL
  AND v.stock > 0
  AND DATEDIFF(l.date_expiration, CURDATE()) > 0
  AND DATEDIFF(l.date_expiration, CURDATE()) <=
      COALESCE((SELECT CAST(valeur AS UNSIGNED) FROM parametres
                 WHERE cle = 'liquidation_seuil_peremption_jours'), 45)
  -- Un lot déjà en liquidation n'est pas reproposé : une file qui
  -- redemande la même chose finit par ne plus être ouverte.
  AND NOT EXISTS (SELECT 1 FROM liquidations q
                   WHERE q.variante_id = v.id AND q.fin_le >= NOW())
ORDER BY jours_restants;
