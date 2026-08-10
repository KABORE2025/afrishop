-- =====================================================================
--  AFRISHOP — EXTENSION v2.1 : COMPTOIR, TRANSITAIRE, INTERNATIONAL
--  ---------------------------------------------------------------
--  Trois ajouts, dictés par trois décisions de terrain :
--
--  1. LA VENTE AU COMPTOIR. Le client entre dans l'atelier, choisit,
--     paie en liquide, repart. C'est la majorité du chiffre d'affaires
--     réel des artisans. Sans elle, le stock en ligne est faux et le
--     vendeur n'a aucune raison d'ouvrir l'application chaque jour.
--
--  2. LA REMISE À TRANSITAIRE. La plateforme ne gère PAS le transport
--     international ni la douane. Soit le client désigne son propre
--     transitaire et on lui remet le colis, soit il demande un devis
--     et on s'en charge au cas par cas. La responsabilité de la
--     plateforme s'arrête à la remise, contre preuve.
--
--     Ce choix évite d'un coup : les codes tarifaires par produit, le
--     calcul des droits par pays, les seuils de franchise, le débat
--     DDP/DAP et les colis refusés en douane. C'est la simplification
--     la plus rentable de tout le dossier.
--
--  3. LE CLIENT À L'ÉTRANGER. Il commande depuis Paris ou Milan, mais
--     le règlement et souvent la remise se font localement, par un
--     mandataire présent sur place. L'adresse doit donc pouvoir être
--     internationale, et le payeur peut être une autre personne que
--     le client.
--
--  MONNAIE DE COMPTE — décision assumée : le franc CFA reste la
--  monnaie de compte de la plateforme. Les 53 colonnes en `_cfa` ne
--  sont pas renommées. Une opération dans une autre devise conserve
--  sa devise et son taux d'origine à côté du montant converti, comme
--  en comptabilité classique. Le jour où une boutique sera réellement
--  établie hors zone franc, il faudra rouvrir ce sujet — c'est une
--  dette technique consciente, pas un oubli.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;


-- ---------------------------------------------------------------------
--  1. PAYS — ouvrir le référentiel au monde entier
-- ---------------------------------------------------------------------
-- Le référentiel ne contenait que les 8 pays de l'UEMOA, et `pays_id`
-- était obligatoire sur les commandes : un client français ne pouvait
-- littéralement pas commander.
ALTER TABLE pays
  ADD COLUMN zone ENUM('uemoa','cedeao','afrique','europe','ameriques','asie','autre')
      NOT NULL DEFAULT 'autre' AFTER devise,
  ADD COLUMN ouvert_a_la_vente BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Un client de ce pays peut commander' AFTER actif,
  ADD COLUMN ouvert_aux_boutiques BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Une boutique peut y être établie' AFTER ouvert_a_la_vente,
  ADD COLUMN format_adresse ENUM('ouest_africain','postal','libre') NOT NULL DEFAULT 'postal'
      COMMENT 'ouest_africain = quartier + repère ; postal = rue + code postal';

-- Devise d'affichage. Le règlement reste en francs CFA : ces taux
-- servent UNIQUEMENT à afficher un prix indicatif au visiteur
-- étranger. La plateforme ne prend donc aucun risque de change.
CREATE TABLE taux_change (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    devise        CHAR(3)      NOT NULL COMMENT 'EUR, USD, CNY, GBP…',
    xof_pour_une_unite DECIMAL(14,6) NOT NULL COMMENT '1 EUR = 655,957 XOF',
    source        VARCHAR(60)  NOT NULL COMMENT 'BCEAO (parité fixe), banque centrale, fournisseur',
    -- Marge de sécurité appliquée à l'affichage. Un taux affiché sans
    -- marge devient faux dès la première variation, et le client
    -- s'estime trompé.
    marge_pct     DECIMAL(5,2) NOT NULL DEFAULT 2.00,
    date_debut    DATETIME     NOT NULL,
    date_fin      DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_taux_change (devise, date_debut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  2. COMMANDES — adresse internationale, mandataire, canal comptoir
-- ---------------------------------------------------------------------
ALTER TABLE commandes
  -- Le pays de LIVRAISON peut différer du pays de la commande : un
  -- client parisien fait livrer chez sa mère à Ouagadougou.
  ADD COLUMN pays_livraison_id TINYINT UNSIGNED NULL AFTER pays_id,

  -- Adresse au format postal, pour tout ce qui n'est pas ouest-africain.
  -- `quartier` devient facultatif : une adresse parisienne n'a pas de
  -- quartier au sens burkinabè, et une adresse de Ouagadougou n'a pas
  -- de code postal utilisable.
  ADD COLUMN adresse_ligne1 VARCHAR(160) NULL AFTER quartier,
  ADD COLUMN adresse_ligne2 VARCHAR(160) NULL AFTER adresse_ligne1,
  ADD COLUMN code_postal    VARCHAR(20)  NULL AFTER adresse_ligne2,
  ADD COLUMN ville_texte    VARCHAR(120) NULL COMMENT 'Ville libre quand elle n''est pas au référentiel' AFTER code_postal,

  -- MANDATAIRE : la personne qui paie n'est pas toujours celle qui
  -- commande. La diaspora commande depuis l'étranger, un parent règle
  -- sur place en espèces. Sans ces colonnes, on ne sait plus qui a payé.
  ADD COLUMN paye_par_nom       VARCHAR(120) NULL AFTER client_telephone,
  ADD COLUMN paye_par_telephone VARCHAR(20)  NULL AFTER paye_par_nom,
  ADD COLUMN paye_par_lien      VARCHAR(60)  NULL COMMENT 'parent, ami, mandataire, coursier',

  -- Destinataire local : celui qui reçoit physiquement le colis.
  ADD COLUMN destinataire_nom       VARCHAR(120) NULL AFTER ville_texte,
  ADD COLUMN destinataire_telephone VARCHAR(20)  NULL AFTER destinataire_nom,

  -- Devise dans laquelle le prix a été AFFICHÉ au client, et le taux
  -- utilisé. Le montant réglé reste en francs CFA. On conserve les
  -- deux pour pouvoir répondre à « vous m'aviez annoncé 45 euros ».
  ADD COLUMN devise_affichage CHAR(3) NULL,
  ADD COLUMN taux_affichage   DECIMAL(14,6) NULL,

  ADD CONSTRAINT fk_commandes_pays_livraison
      FOREIGN KEY (pays_livraison_id) REFERENCES pays(id) ON DELETE RESTRICT;

-- `quartier` n'est plus obligatoire : il ne veut rien dire hors
-- d'Afrique de l'Ouest.
ALTER TABLE commandes MODIFY COLUMN quartier VARCHAR(120) NULL;

-- Nouveaux canaux et nouveaux modes.
ALTER TABLE commandes
  MODIFY COLUMN canal ENUM('web','mobile','telephone','agent','comptoir','whatsapp')
      NOT NULL DEFAULT 'web',
  MODIFY COLUMN mode_livraison ENUM('domicile','point_relais','retrait_boutique',
      'remise_transitaire','expedition_sur_devis','emporte')
      NOT NULL DEFAULT 'domicile'
      COMMENT 'emporte = vente au comptoir, le client repart avec',
  MODIFY COLUMN mode_paiement ENUM('mobile_money','carte','virement','especes_livraison',
      'especes_comptoir','mobile_money_comptoir')
      NOT NULL;

-- Un état de fonds de plus : l'argent d'une vente au comptoir n'a
-- JAMAIS transité par la plateforme. Il ne peut donc être ni
-- séquestré, ni reversé — seulement constaté.
ALTER TABLE sous_commandes
  MODIFY COLUMN etat_fonds ENUM('attente_encaissement','sequestre','reverse',
      'rembourse','impaye','hors_plateforme')
      NOT NULL DEFAULT 'sequestre',
  ADD COLUMN vente_comptoir BOOLEAN NOT NULL DEFAULT 0 COMMENT 'Vente réalisée en présentiel, hors circuit de paiement de la plateforme' AFTER statut,
  MODIFY COLUMN statut ENUM('a_preparer','prete','expediee','livree','retournee',
      'annulee','vendue_comptoir') NOT NULL DEFAULT 'a_preparer';


-- ---------------------------------------------------------------------
--  3. EXPÉDITION DÉLÉGUÉE — remise à transitaire et devis
-- ---------------------------------------------------------------------
-- Le transitaire désigné par le client. Ce n'est PAS un partenaire de
-- la plateforme : c'est un tiers que le client choisit et mandate, et
-- avec lequel Afrishop n'a aucun lien contractuel.
CREATE TABLE remises_transitaire (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    commande_id         BIGINT UNSIGNED NOT NULL,
    sous_commande_id    BIGINT UNSIGNED NULL COMMENT 'NULL = toute la commande',

    -- Coordonnées fournies PAR LE CLIENT. On ne les valide pas : on
    -- les recopie et on s'y tient.
    transitaire_nom     VARCHAR(160) NOT NULL,
    transitaire_contact VARCHAR(120) NULL,
    transitaire_telephone VARCHAR(20) NULL,
    adresse_remise      VARCHAR(255) NOT NULL,
    reference_client    VARCHAR(80)  NULL COMMENT 'Numéro de dossier donné par le client ou son transitaire',
    instructions        TEXT         NULL,

    statut              ENUM('a_remettre','remis','refuse_transitaire','annule')
                        NOT NULL DEFAULT 'a_remettre',

    -- PREUVE DE REMISE — c'est elle qui met fin à la responsabilité
    -- de la plateforme. Sans elle, un colis perdu en mer devient
    -- notre problème.
    remis_le            DATETIME     NULL,
    remis_par_id        BIGINT UNSIGNED NULL,
    recu_par_nom        VARCHAR(120) NULL,
    recu_par_piece      VARCHAR(60)  NULL COMMENT 'Référence de la pièce d''identité présentée',
    preuve_media_id     BIGINT UNSIGNED NULL COMMENT 'Photo du bordereau signé',
    nb_colis            SMALLINT UNSIGNED NULL,
    poids_total_g       INT UNSIGNED NULL,

    -- Décharge acceptée par le client au moment de la commande.
    -- Version conservée : les conditions changent, la commande d'hier
    -- reste régie par celles d'hier.
    decharge_version    VARCHAR(20)  NULL,
    decharge_acceptee_le DATETIME    NULL,

    cree_le             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_remises_commande (commande_id, statut),
    CONSTRAINT fk_remises_commande FOREIGN KEY (commande_id)
        REFERENCES commandes(id) ON DELETE CASCADE,
    CONSTRAINT fk_remises_sc FOREIGN KEY (sous_commande_id)
        REFERENCES sous_commandes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Devis d'expédition, quand le client préfère qu'Afrishop s'en charge.
-- Traitement MANUEL et assumé comme tel : on pèse, on mesure, on
-- consulte un transporteur, on propose un prix ferme. Aucun calcul
-- automatique de douane, aucune grille tarifaire à maintenir.
CREATE TABLE demandes_expedition (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    commande_id       BIGINT UNSIGNED NOT NULL,
    reference         VARCHAR(28)  NOT NULL,

    -- Ce que le client demande
    pays_destination_id TINYINT UNSIGNED NOT NULL,
    adresse_destination TEXT       NOT NULL,
    delai_souhaite    ENUM('economique','standard','express') NOT NULL DEFAULT 'standard',
    commentaire_client TEXT        NULL,

    -- Ce que la plateforme constate
    poids_estime_g    INT UNSIGNED NULL,
    dimensions_cm     VARCHAR(40)  NULL COMMENT 'L x l x h du colis groupé',
    transporteur_propose VARCHAR(80) NULL,
    delai_estime_jours SMALLINT UNSIGNED NULL,

    -- Le devis. Le montant est FERME : si le transport coûte plus cher
    -- que prévu, l'écart est pour la plateforme. Un devis révisable
    -- après acceptation détruit la confiance en une seule commande.
    montant_devis_cfa INT UNSIGNED NULL,
    devise_affichage  CHAR(3)      NULL,
    montant_affiche   DECIMAL(12,2) NULL,
    valable_jusqu_au  DATE         NULL,

    statut            ENUM('demande','en_evaluation','propose','accepte','refuse','expire','expedie')
                      NOT NULL DEFAULT 'demande',
    motif_refus       TEXT         NULL,

    -- Ce qui prouve que le colis est parti
    transporteur_reel VARCHAR(80)  NULL,
    numero_suivi      VARCHAR(80)  NULL,
    expedie_le        DATETIME     NULL,

    -- MENTION EXPLICITE : les droits et taxes à l'arrivée restent à la
    -- charge du destinataire. Écrit dans le devis, accepté par le
    -- client, conservé ici.
    droits_a_la_charge_du_client BOOLEAN NOT NULL DEFAULT 1,

    traite_par_id     BIGINT UNSIGNED NULL,
    demande_le        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    propose_le        DATETIME     NULL,
    repondu_le        DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_demandes_exp_ref (reference),
    KEY idx_demandes_exp_statut (statut, demande_le),
    CONSTRAINT fk_demexp_commande FOREIGN KEY (commande_id)
        REFERENCES commandes(id) ON DELETE CASCADE,
    CONSTRAINT fk_demexp_pays FOREIGN KEY (pays_destination_id)
        REFERENCES pays(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  4. BOUTIQUES — origine étrangère, exploitation locale
-- ---------------------------------------------------------------------
-- Un commerçant chinois installé à Ouagadougou tient une boutique
-- LOCALE : il est payé localement, en francs CFA, comme les autres.
-- Mais ses produits viennent d'ailleurs, et le client a le droit de le
-- savoir. Deux colonnes, deux notions distinctes.
ALTER TABLE boutiques
  ADD COLUMN pays_origine_id TINYINT UNSIGNED NULL COMMENT 'Provenance des produits — affiché au client. NULL = même pays que l''exploitation' AFTER pays_id,
  ADD COLUMN type_boutique ENUM('artisan','revendeur','importateur','cooperative')
      NOT NULL DEFAULT 'artisan' AFTER niveau,
  -- Une boutique peut vendre uniquement au comptoir : elle profite du
  -- catalogue et de la traçabilité sans vendre en ligne.
  ADD COLUMN vend_en_ligne  BOOLEAN NOT NULL DEFAULT 1 AFTER type_boutique,
  ADD COLUMN vend_au_comptoir BOOLEAN NOT NULL DEFAULT 1 AFTER vend_en_ligne,
  -- Commission réduite (souvent nulle) sur les ventes que la
  -- plateforme n'a pas apportées.
  ADD COLUMN taux_commission_comptoir DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER taux_commission,
  ADD CONSTRAINT fk_boutiques_pays_origine
      FOREIGN KEY (pays_origine_id) REFERENCES pays(id) ON DELETE SET NULL;


-- ---------------------------------------------------------------------
--  5. MOUVEMENTS DE STOCK — la vraie raison d'être du comptoir
-- ---------------------------------------------------------------------
-- Sans journal de stock, on constate un écart sans jamais savoir d'où
-- il vient. Avec lui, chaque variation a une cause et un auteur.
CREATE TABLE mouvements_stock (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    variante_id   BIGINT UNSIGNED NOT NULL,
    type          ENUM('vente_en_ligne','vente_comptoir','retour','reapprovisionnement',
                       'inventaire','casse','perte','correction') NOT NULL,
    quantite      INT          NOT NULL COMMENT 'Négatif pour une sortie',
    stock_avant   INT          NOT NULL,
    stock_apres   INT          NOT NULL,
    piece_type    VARCHAR(30)  NULL,
    piece_id      BIGINT UNSIGNED NULL,
    motif         VARCHAR(255) NULL,
    auteur_id     BIGINT UNSIGNED NULL,
    cree_le       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mvt_stock_variante (variante_id, cree_le),
    KEY idx_mvt_stock_type (type, cree_le),
    CONSTRAINT fk_mvtstock_variante FOREIGN KEY (variante_id)
        REFERENCES variantes_produit(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  6. PARAMÈTRES NOUVEAUX
-- ---------------------------------------------------------------------
INSERT INTO parametres (pays_id, cle, valeur, type, libelle) VALUES
-- Zéro par défaut, et c'est un choix : avec une commission sur le
-- comptoir, aucun vendeur ne déclarerait ses ventes présentielles, et
-- le stock en ligne resterait faux — ce qui coûte bien plus cher.
(NULL,'commission_vente_comptoir_pct','0','decimal',
 'Commission sur une vente non apportée par la plateforme'),
(NULL,'devise_affichage_defaut','XOF','texte','Devise d''affichage par défaut'),
(NULL,'marge_change_pct','2','decimal','Marge de sécurité sur les prix convertis affichés'),
(NULL,'devis_expedition_validite_jours','15','entier','Durée de validité d''un devis d''expédition'),
(NULL,'decharge_transitaire_version','v1.0','texte','Version courante de la décharge de responsabilité');

SET FOREIGN_KEY_CHECKS = 1;
