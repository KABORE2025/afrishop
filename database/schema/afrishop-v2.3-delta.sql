-- =====================================================================
--  AFRISHOP — DELTA v2.3 : la boutique régie
-- =====================================================================
--  S'applique APRÈS afrishop-v2.sql, v2.1-delta et v2.2-delta.
--
--  Afrishop tient un rayon en propre — les produits naturels — avec son
--  stock, ses commandes et sa caisse, comme n'importe quelle boutique.
--  Un seul champ l'en distingue : `est_regie`.
--
--  CE CHOIX A UN PRIX, ET LE SCHÉMA LE PAIE EXPLICITEMENT.
--
--  Sur ce rayon, Afrishop n'est plus un intermédiaire : il est VENDEUR.
--  La plateforme édite le classement et y figure. Six neutralisations en
--  découlent ; quatre sont posées ici, en base, là où elles tiennent même
--  si l'interface est contournée.
--
--    1. COMMISSION NULLE    se facturer une commission à soi-même est une
--                           écriture circulaire : elle gonfle le chiffre
--                           d'affaires sans qu'un franc n'entre.
--    2. AUCUNE RETENUE      la retenue à la source est opérée par un TIERS
--                           payeur. Payeur et vendeur confondus : pas de
--                           tiers, donc pas de retenue.
--    3. HORS GRAND LIVRE    « ce qu'Afrishop doit à Afrishop » n'est pas
--                           une dette. L'inscrire fausserait le total de
--                           ce qu'il doit réellement aux vendeurs — seul
--                           usage de ce total.
--    4. VERROU DE CATÉGORIE une catégorie réservée n'accepte QUE la régie.
--
--  Les deux autres — exclusion du comparateur et du tri, signalement du
--  conflit d'arbitrage — relèvent de la présentation.
--
--  CE QUE LE SCHÉMA NE PEUT PAS RÉGLER : devenir vendeur change le statut
--  fiscal d'Afrishop sur ce rayon. Ce n'est plus une commission
--  d'intermédiation, c'est une MARGE COMMERCIALE. [FISCALISTE]
-- =====================================================================

ALTER TABLE boutiques
  ADD COLUMN est_regie TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Boutique tenue par Afrishop lui-même : commission et retenue nulles, hors grand livre, exclue du comparateur et du tri'
    AFTER statut;

CREATE INDEX ix_boutique_regie ON boutiques (est_regie);

-- ---------------------------------------------------------------------
-- VERROU DE CATÉGORIE
-- ---------------------------------------------------------------------
-- Sur INSERT **et** sur UPDATE. Sans le second, il suffirait de créer le
-- produit dans une catégorie libre puis de le déplacer — le verrou ne
-- retiendrait que les distraits.
DELIMITER $$

CREATE TRIGGER trg_produit_categorie_reservee_insert BEFORE INSERT ON produits
FOR EACH ROW
BEGIN
    DECLARE v_reservee TINYINT DEFAULT 0;
    DECLARE v_regie    TINYINT DEFAULT 0;
    SELECT reservee INTO v_reservee FROM categories WHERE id = NEW.categorie_id;
    IF v_reservee = 1 THEN
        SELECT est_regie INTO v_regie FROM boutiques WHERE id = NEW.boutique_id;
        IF v_regie = 0 THEN
            SIGNAL SQLSTATE '45000'
              SET MESSAGE_TEXT = 'Categorie reservee : seule la regie Afrishop peut y publier';
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_produit_categorie_reservee_update BEFORE UPDATE ON produits
FOR EACH ROW
BEGIN
    DECLARE v_reservee TINYINT DEFAULT 0;
    DECLARE v_regie    TINYINT DEFAULT 0;
    SELECT reservee INTO v_reservee FROM categories WHERE id = NEW.categorie_id;
    IF v_reservee = 1 THEN
        SELECT est_regie INTO v_regie FROM boutiques WHERE id = NEW.boutique_id;
        IF v_regie = 0 THEN
            SIGNAL SQLSTATE '45000'
              SET MESSAGE_TEXT = 'Categorie reservee : seule la regie Afrishop peut y publier';
        END IF;
    END IF;
END$$

-- COMMISSION ET RETENUE NULLES, GARANTIES EN BASE.
-- La règle vit aussi dans l'application, et c'est très bien : ici elle
-- est le dernier filet. Un import de reprise ou un script d'ajustement
-- qui écrirait une commission sur une vente de la régie créerait une
-- dette d'Afrishop envers lui-même — invisible jusqu'au jour du
-- rapprochement bancaire.
CREATE TRIGGER trg_sous_commande_regie BEFORE INSERT ON sous_commandes
FOR EACH ROW
BEGIN
    DECLARE v_regie TINYINT DEFAULT 0;
    SELECT est_regie INTO v_regie FROM boutiques WHERE id = NEW.boutique_id;
    IF v_regie = 1 THEN
        SET NEW.commission_cfa = 0;
        SET NEW.taux_commission_pct = 0;
        SET NEW.retenue_source_cfa = 0;
        SET NEW.taux_retenue_source_pct = 0;
    END IF;
END$$

DELIMITER ;
