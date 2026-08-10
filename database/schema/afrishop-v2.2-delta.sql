-- =====================================================================
--  AFRISHOP — DELTA v2.2
--  Liquidation · Services · Catégories réservées · Annuaire · Recherche
-- =====================================================================
--  S'applique APRÈS afrishop-v2.sql puis afrishop-v2.1-delta.sql.
--  MariaDB 10.6+ / MySQL 8.
--
--  COLLATION : utf8mb4_unicode_ci, EXPLICITEMENT, sur chaque table. Le
--  schéma v2 l'emploie ; omettre la clause laisse MariaDB retomber sur
--  utf8mb4_general_ci, et le premier JOIN ou LIKE entre une table v2 et
--  une table v2.2 échoue avec « Illegal mix of collations ». L'erreur
--  n'apparaît pas à la création : elle attend la première requête qui
--  traverse les deux générations de tables.
--
--  NE PAS CONFONDRE `liquidations` ET `promotions`. Les deux baissent un
--  prix, et c'est tout ce qu'elles ont en commun :
--
--    promotions    un CODE, appliqué à un PANIER, avec un financeur
--                  (plateforme, boutique ou partagé), des quotas d'usage
--                  et un montant minimum. C'est un outil marketing.
--
--    liquidations  un PRIX DE VARIANTE abaissé pour un MOTIF PUBLIC, avec
--                  une date de fin et, le cas échéant, un verrou de
--                  péremption. C'est un outil de gestion de stock.
--
--  Les fusionner obligerait à porter les quotas d'usage sur une remise
--  qui n'en a pas, et le verrou de péremption sur un code promo qui n'en
--  veut pas. Deux tables, deux vies.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. LIQUIDATION
-- ---------------------------------------------------------------------

CREATE TABLE motifs_liquidation (
  id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                     VARCHAR(30)  NOT NULL,
  libelle                  VARCHAR(80)  NOT NULL,
  aide_client              VARCHAR(200) NOT NULL
    COMMENT 'Phrase montrée à l''acheteur — elle explique la remise',
  date_limite_obligatoire  TINYINT(1)   NOT NULL DEFAULT 0
    COMMENT 'Si vrai, la liquidation exige une date et se ferme à cette date',
  ordre                    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uk_motif_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Motifs de liquidation. En table et non en ENUM : un ENUM impose un ALTER TABLE, donc un verrou, pour chaque ajout';

CREATE TABLE liquidations (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- LA LIQUIDATION PORTE SUR UNE VARIANTE, PAS SUR UN PRODUIT : le pot de
  -- 250 g peut être en fin de vie quand le 1 kg vient d'arriver.
  variante_id           BIGINT UNSIGNED NOT NULL,
  motif_id              INT UNSIGNED    NOT NULL,

  prix_liquide_cfa      INT UNSIGNED NOT NULL
    COMMENT 'Prix pendant la liquidation. Entier : le franc CFA n''a pas de sous-unité',
  prix_reference_cfa    INT UNSIGNED NOT NULL
    COMMENT 'Prix normal FIGÉ à la mise en liquidation. Recopié volontairement : si le vendeur change son prix demain, le prix barré ne doit pas bouger sous les yeux du client',

  debut_le              DATETIME NOT NULL,
  fin_le                DATETIME NOT NULL
    COMMENT 'Obligatoire : sans fin, une liquidation devient le prix normal',
  date_peremption       DATE NULL
    COMMENT 'Obligatoire si le motif l''exige. La vente se ferme à cette date',

  detail                VARCHAR(300) NOT NULL
    COMMENT 'Ce que le client doit savoir. Un défaut annoncé ne fonde pas un litige — c''est pour cela qu''on l''annonce',
  quantite_concernee    INT UNSIGNED NULL,
  cree_par_utilisateur_id BIGINT UNSIGNED NULL,
  created_at            DATETIME NULL,
  updated_at            DATETIME NULL,

  PRIMARY KEY (id),
  KEY ix_liq_variante (variante_id, debut_le, fin_le),
  KEY ix_liq_peremption (date_peremption),
  CONSTRAINT fk_liq_variante FOREIGN KEY (variante_id)
    REFERENCES variantes_produit (id) ON DELETE CASCADE,
  CONSTRAINT fk_liq_motif FOREIGN KEY (motif_id)
    REFERENCES motifs_liquidation (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GARDE-FOU EN BASE, PAS SEULEMENT DANS L'INTERFACE.
-- Une interface se contourne : appel d'API direct, import en masse,
-- script de reprise. Ces contrôles tiennent quel que soit le chemin.
-- En TRIGGER et non en CHECK parce que MariaDB n'évalue pas les
-- fonctions de date dans une contrainte CHECK.
DELIMITER $$
CREATE TRIGGER trg_liquidation_avant_insert BEFORE INSERT ON liquidations
FOR EACH ROW
BEGIN
    DECLARE v_exige TINYINT DEFAULT 0;

    IF NEW.fin_le <= NEW.debut_le THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Liquidation : la fin doit suivre le debut';
    END IF;

    IF NEW.prix_liquide_cfa >= NEW.prix_reference_cfa THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Liquidation : le prix liquide doit etre inferieur au prix de reference';
    END IF;

    SELECT date_limite_obligatoire INTO v_exige
      FROM motifs_liquidation WHERE id = NEW.motif_id;

    IF v_exige = 1 AND NEW.date_peremption IS NULL THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Liquidation pour peremption : la date limite est obligatoire';
    END IF;
END$$
DELIMITER ;

-- ---------------------------------------------------------------------
-- 2. SERVICES
-- ---------------------------------------------------------------------

CREATE TABLE familles_service (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                   VARCHAR(30) NOT NULL,
  libelle                VARCHAR(80) NOT NULL,
  devis_obligatoire      TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Un chantier ne se vend pas à prix catalogue',
  profession_reglementee TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Si vrai : annuaire seulement, aucun encaissement par la plateforme',
  PRIMARY KEY (id),
  UNIQUE KEY uk_famille_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE services (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  boutique_id        BIGINT UNSIGNED NOT NULL,
  famille_id         INT UNSIGNED    NOT NULL,

  nom                VARCHAR(180) NOT NULL,
  description        TEXT NOT NULL,
  unite              VARCHAR(40) NOT NULL COMMENT 'chantier, projet, participant, jour…',

  mode_vente         ENUM('prix_fixe','devis') NOT NULL
    COMMENT 'Une formation à 75 000 F se met au panier ; une maison R+1 non. Imposer l''un des deux partout perd la moitié du catalogue',

  prix_cfa           INT UNSIGNED NULL COMMENT 'Mode prix_fixe uniquement',
  fourchette_min_cfa INT UNSIGNED NULL COMMENT 'Mode devis : n''engage pas, évite les demandes hors budget',
  fourchette_max_cfa INT UNSIGNED NULL,

  delai_annonce      VARCHAR(60) NOT NULL,
  places             SMALLINT UNSIGNED NULL
    COMMENT 'Formations : une session a une capacité, pas un stock',

  actif              TINYINT(1) NOT NULL DEFAULT 1,
  created_at         DATETIME NULL,
  updated_at         DATETIME NULL,

  PRIMARY KEY (id),
  KEY ix_service_boutique (boutique_id, actif),
  CONSTRAINT fk_service_boutique FOREIGN KEY (boutique_id)
    REFERENCES boutiques (id) ON DELETE CASCADE,
  CONSTRAINT fk_service_famille FOREIGN KEY (famille_id)
    REFERENCES familles_service (id),
  -- Un prix fixe sans prix, ou un devis sans fourchette, produisent une
  -- fiche que le client ne peut pas lire.
  CONSTRAINT chk_service_mode CHECK (
      (mode_vente = 'prix_fixe' AND prix_cfa IS NOT NULL)
   OR (mode_vente = 'devis'     AND fourchette_min_cfa IS NOT NULL
                                AND fourchette_max_cfa >= fourchette_min_cfa)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE jalons_type (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_id   BIGINT UNSIGNED NOT NULL,
  ordre        TINYINT UNSIGNED NOT NULL,
  libelle      VARCHAR(120) NOT NULL,
  pourcentage  TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_jalon_type (service_id, ordre),
  CONSTRAINT fk_jalon_type_service FOREIGN KEY (service_id)
    REFERENCES services (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Trame par défaut. Le devis accepté en fera une copie figée : modifier la trame ne doit pas redécouper un chantier en cours';

-- ---------------------------------------------------------------------
-- 3. DEVIS ET JALONS D'EXÉCUTION
-- ---------------------------------------------------------------------

CREATE TABLE demandes_devis (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference            VARCHAR(24) NOT NULL,
  service_id           BIGINT UNSIGNED NOT NULL,
  boutique_id          BIGINT UNSIGNED NOT NULL,

  client_nom           VARCHAR(120) NOT NULL,
  client_telephone     VARCHAR(24)  NOT NULL,
  localisation         VARCHAR(160) NULL,
  budget_envisage_cfa  BIGINT UNSIGNED NULL,
  besoin               TEXT NOT NULL,
  echeance             ENUM('immediat','1_3_mois','3_6_mois','renseignement') NOT NULL,

  statut               ENUM('demande','rappele','visite','chiffre','accepte','refuse','sans_suite')
                       NOT NULL DEFAULT 'demande',
  created_at           DATETIME NULL,
  updated_at           DATETIME NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uk_demande_ref (reference),
  KEY ix_demande_boutique (boutique_id, statut),
  CONSTRAINT fk_demande_service FOREIGN KEY (service_id) REFERENCES services (id),
  CONSTRAINT fk_demande_boutique FOREIGN KEY (boutique_id) REFERENCES boutiques (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Une demande sans suite est une vente perdue qu''on n''aurait jamais vue sans cette table';

CREATE TABLE devis (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference         VARCHAR(24) NOT NULL,
  demande_id        BIGINT UNSIGNED NOT NULL,

  montant_cfa       BIGINT UNSIGNED NOT NULL,
  commission_cfa    BIGINT UNSIGNED NOT NULL
    COMMENT 'Calculée au barème dégressif et FIGÉE ici : si le barème change demain, un devis déjà émis ne doit pas changer de commission',
  taux_moyen_pct    DECIMAL(5,2) NOT NULL
    COMMENT 'Taux réellement supporté — le seul chiffre parlant pour un vendeur',

  valable_jusqu_au  DATE NOT NULL
    COMMENT 'Un devis sans validité engage indéfiniment sur des prix de matériaux qui, eux, bougent',
  detail            TEXT NOT NULL,

  statut            ENUM('emis','accepte','refuse','expire') NOT NULL DEFAULT 'emis',
  accepte_le        DATETIME NULL,
  created_at        DATETIME NULL,
  updated_at        DATETIME NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uk_devis_ref (reference),
  CONSTRAINT fk_devis_demande FOREIGN KEY (demande_id) REFERENCES demandes_devis (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- LE CŒUR DU DISPOSITIF SERVICES.
-- Chaque jalon est séquestré SÉPARÉMENT. À aucun moment la plateforme ne
-- détient le montant total du contrat : sur un chantier de 25 millions
-- découpé en quatre tranches, son exposition plafonne à la plus grosse
-- tranche, et n'y reste que le temps d'une étape.
CREATE TABLE jalons (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  devis_id     BIGINT UNSIGNED NOT NULL,
  ordre        TINYINT UNSIGNED NOT NULL,
  libelle      VARCHAR(120) NOT NULL,
  pourcentage  TINYINT UNSIGNED NOT NULL,
  montant_cfa  BIGINT UNSIGNED NOT NULL,

  statut       ENUM('a_venir','en_cours','a_valider','valide','conteste')
               NOT NULL DEFAULT 'a_venir',
  -- Même vocabulaire que les sous-commandes : un état de fonds reste un
  -- état de fonds, qu'il s'agisse d'un pot de karité ou d'une dalle.
  etat_fonds   ENUM('non_appele','attente_encaissement','sequestre','reverse','rembourse')
               NOT NULL DEFAULT 'non_appele',

  appele_le    DATETIME NULL,
  valide_le    DATETIME NULL,
  created_at   DATETIME NULL,
  updated_at   DATETIME NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uk_jalon (devis_id, ordre),
  KEY ix_jalon_statut (statut, etat_fonds),
  CONSTRAINT fk_jalon_devis FOREIGN KEY (devis_id) REFERENCES devis (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. BARÈME DE COMMISSION DÉGRESSIF
-- ---------------------------------------------------------------------
-- Calcul par tranches, comme un impôt sur le revenu : chaque tranche à
-- son taux, et non le taux de la dernière appliqué au tout. Avec un taux
-- unique par palier, franchir un seuil ferait BAISSER le revenu net du
-- prestataire, qui découperait alors son chantier en deux devis. Un
-- barème qui récompense la fraude est un barème raté.
CREATE TABLE bareme_commission (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  applique_a     VARCHAR(20) NOT NULL DEFAULT 'service',
  plafond_cfa    BIGINT UNSIGNED NULL COMMENT 'NULL = tranche supérieure, sans plafond',
  taux_pct       DECIMAL(5,2) NOT NULL,
  ordre          TINYINT UNSIGNED NOT NULL,
  en_vigueur_du  DATE NOT NULL,
  en_vigueur_au  DATE NULL,
  PRIMARY KEY (id),
  KEY ix_bareme (applique_a, ordre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. CATÉGORIES RÉSERVÉES ET AUTORISATIONS
-- ---------------------------------------------------------------------

ALTER TABLE categories
  ADD COLUMN reservee TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Une fiche de cette catégorie n''est visible qu''après autorisation' AFTER nom,
  ADD COLUMN note_reserve VARCHAR(300) NULL AFTER reservee;

-- LE SILENCE VAUT REFUS : sans ligne « accorde », la fiche reste
-- invisible. C'est l'inverse du réflexe « publier puis modérer », et
-- c'est volontaire — une fiche fautive vue une heure a déjà produit son
-- effet, et le retrait ne défait pas la lecture.
CREATE TABLE autorisations_publication (
  id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  produit_id                BIGINT UNSIGNED NOT NULL,
  statut                    ENUM('demande','accorde','refuse','revoque') NOT NULL DEFAULT 'demande',
  motif                     TEXT NULL
    COMMENT 'Envoyé au vendeur. Un refus sans motif se re-soumet à l''identique',
  termes_signales           JSON NULL
    COMMENT 'Ce que le détecteur a trouvé, gardé pour l''audit — pas pour décider',
  decide_par_utilisateur_id BIGINT UNSIGNED NULL,
  demande_le                DATETIME NOT NULL,
  decide_le                 DATETIME NULL,
  created_at                DATETIME NULL,
  updated_at                DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_autorisation_produit (produit_id),
  KEY ix_autorisation_file (statut, demande_le),
  CONSTRAINT fk_autorisation_produit FOREIGN KEY (produit_id)
    REFERENCES produits (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE termes_interdits (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  terme       VARCHAR(60) NOT NULL,
  gravite     ENUM('bloquant','a_verifier') NOT NULL DEFAULT 'a_verifier'
    COMMENT 'a_verifier : signalé au relecteur. bloquant : refusé dès la soumission, pour que le vendeur corrige pendant qu''il a sa fiche sous les yeux',
  explication VARCHAR(200) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_terme_interdit (terme)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. ANNUAIRE DES PROFESSIONS RÉGLEMENTÉES
-- ---------------------------------------------------------------------
-- AUCUNE CLÉ ÉTRANGÈRE VERS `commandes`, et c'est intentionnel : la
-- relation n'existe pas et ne doit pas pouvoir être créée par mégarde.
-- Afrishop vérifie et met en relation ; il n'encaisse aucun honoraire.
CREATE TABLE professionnels (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  profession                 VARCHAR(60) NOT NULL,
  nom                        VARCHAR(140) NOT NULL,
  pays_id                    TINYINT UNSIGNED NOT NULL,
  ville                      VARCHAR(80) NOT NULL,

  ordre_ou_association       VARCHAR(160) NOT NULL,
  numero_inscription         VARCHAR(60) NOT NULL,
  verifie_le                 DATE NULL,
  verifie_par_utilisateur_id BIGINT UNSIGNED NULL,
  verification_expire_le     DATE NOT NULL
    COMMENT 'Une vérification sans expiration devient un mensonge le jour où le professionnel est radié — et c''est la plateforme qui l''aura affirmé',

  telephone                  VARCHAR(24) NOT NULL,
  actes                      JSON NOT NULL,
  avertissement_sante        TINYINT(1) NOT NULL DEFAULT 0,
  publie                     TINYINT(1) NOT NULL DEFAULT 0,
  created_at                 DATETIME NULL,
  updated_at                 DATETIME NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uk_pro_inscription (ordre_ou_association, numero_inscription),
  KEY ix_pro_profession (profession, publie),
  CONSTRAINT fk_pro_pays FOREIGN KEY (pays_id) REFERENCES pays (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. RECHERCHE
-- ---------------------------------------------------------------------

CREATE TABLE termes_recherche (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  canon      VARCHAR(60) NOT NULL COMMENT 'Le terme retenu, celui du catalogue',
  variante   VARCHAR(60) NOT NULL COMMENT 'Synonyme, nom local, traduction, orthographe',
  nature     ENUM('synonyme','nom_local','traduction','orthographe','commercial')
             NOT NULL DEFAULT 'synonyme',
  langue     VARCHAR(20) NULL COMMENT 'moore, dioula, fulfulde, anglais…',
  actif      TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  -- L'équivalence est SYMÉTRIQUE : on interroge dans les deux sens. La
  -- déclarer deux fois créerait deux vérités à maintenir, donc tôt ou
  -- tard une divergence que personne ne saurait expliquer.
  UNIQUE KEY uk_terme (canon, variante),
  KEY ix_terme_variante (variante)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- LA TABLE LA PLUS RENTABLE DU SCHÉMA, ET LA PLUS DISCRÈTE.
-- Une recherche vide ne produit ni erreur, ni réclamation, ni ticket :
-- le client s'en va en pensant que la plateforme est vide. Cette table
-- est la seule chose qui rende la panne visible.
CREATE TABLE recherches_infructueuses (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  terme_normalise VARCHAR(120) NOT NULL,
  terme_original  VARCHAR(160) NOT NULL,
  occurrences     INT UNSIGNED NOT NULL DEFAULT 1,
  premiere_fois   DATETIME NOT NULL,
  derniere_fois   DATETIME NOT NULL,
  traitement      ENUM('a_traiter','synonyme_ajoute','produit_a_referencer','ignore')
                  NOT NULL DEFAULT 'a_traiter',
  note            VARCHAR(200) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_recherche_terme (terme_normalise),
  KEY ix_recherche_file (traitement, occurrences)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Index plein texte sur les colonnes réellement cherchées. Il ne
-- remplace pas le dictionnaire de synonymes : il accélère la couche
-- exacte, le dictionnaire fournit la couche sémantique.
ALTER TABLE produits ADD FULLTEXT ft_produits_recherche (nom, description);
ALTER TABLE services ADD FULLTEXT ft_services_recherche (nom, description);
