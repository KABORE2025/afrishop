-- =====================================================================
--  AFRISHOP — MODÈLE PHYSIQUE DE DONNÉES (v2, multi-pays UEMOA)
--  MySQL 8.0+ / MariaDB 10.6+ — InnoDB — utf8mb4
--  ---------------------------------------------------------------
--  Cette version 2 corrige les manques du registre et étend le modèle
--  aux 8 pays de l'UEMOA. Les décisions structurantes nouvelles :
--
--  1. MONNAIE UNIQUE. Les 8 pays de l'UEMOA partagent le franc CFA
--     (XOF), parité fixe avec l'euro. Le multi-pays ne crée donc PAS
--     de multi-devise : pas de table de taux de change, pas de
--     conversion. C'est la bonne nouvelle du périmètre UEMOA.
--     Ce qui varie par pays : la TVA, les opérateurs de paiement, la
--     retenue à la source, l'obligation de facture certifiée, le délai
--     de rétractation et l'autorité de protection des données.
--
--  2. GRAND LIVRE INTERNE. Puisque aucun agrégateur UEMOA n'offre de
--     sous-compte marchand avec séquestre, la plateforme encaisse sur
--     un compte unique et détient donc des fonds de tiers. Il faut un
--     journal de mouvements en écriture seule (`mouvements_compte`) :
--     c'est la seule base possible d'une réconciliation et d'un
--     cantonnement conformes à l'instruction BCEAO 001-01-2024.
--
--  3. FISCALITÉ HISTORISÉE. Les taux de taxe ne sont pas des colonnes
--     du pays mais des lignes datées (`taux_taxe`). Une facture émise
--     en 2026 doit rester recalculable avec le taux de 2026, même si
--     le taux change en 2027.
--
--  4. TOUT MONTANT EST UN ENTIER EN FRANCS CFA. Inchangé, et encore
--     plus important ici : la TVA, la commission et la retenue à la
--     source s'appliquent en cascade sur les mêmes sommes.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;


-- =====================================================================
--  1. RÉFÉRENTIELS GÉOGRAPHIQUES, FISCAUX ET LINGUISTIQUES
-- =====================================================================

CREATE TABLE pays (
    id                      TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code_iso2               CHAR(2)      NOT NULL COMMENT 'BF, CI, SN, ML, NE, BJ, TG, GW',
    nom                     VARCHAR(60)  NOT NULL,
    devise                  CHAR(3)      NOT NULL DEFAULT 'XOF'
                            COMMENT 'XOF pour les 8 pays UEMOA — colonne prévue pour une sortie de zone',
    indicatif_telephonique  VARCHAR(6)   NOT NULL COMMENT '+226, +225…',
    langue_defaut           CHAR(5)      NOT NULL DEFAULT 'fr',

    -- Seuil de chiffre d'affaires au-dessus duquel un vendeur est
    -- assujetti à la TVA. Varie fortement : 200 M en Côte d'Ivoire,
    -- 50 M au Burkina, 10 M en Guinée-Bissau.
    seuil_assujettissement_tva_cfa BIGINT UNSIGNED NULL,

    -- Retenue à la source sur les sommes versées à un vendeur NON
    -- immatriculé. Burkina : 25 % — confiscatoire, c'est le principal
    -- argument pour pousser les vendeurs à obtenir un IFU.
    retenue_source_non_immatricule_pct DECIMAL(5,2) NULL,
    retenue_source_immatricule_pct     DECIMAL(5,2) NULL,
    retenue_source_seuil_cfa           INT UNSIGNED NULL COMMENT 'En dessous, pas de retenue',

    -- Facture certifiée par l'administration fiscale.
    facture_certifiee_obligatoire BOOLEAN NOT NULL DEFAULT 0,
    plateforme_facturation  VARCHAR(40)  NULL COMMENT 'e-MECeF, FEC, FNE, e-SECeF…',

    -- Délai légal de rétractation, en jours ouvrables. Sénégal : 7,
    -- porté à 90 si l'information n'a pas été donnée au client.
    retractation_jours_ouvrables SMALLINT UNSIGNED NULL,
    retractation_jours_si_defaut_info SMALLINT UNSIGNED NULL,

    autorite_donnees        VARCHAR(80)  NULL COMMENT 'CIL, ARTCI, CDP, APDP, IPDCP, HAPDP',
    transfert_donnees_autorisation_requise BOOLEAN NOT NULL DEFAULT 1
                            COMMENT 'Burkina : autorisation préalable de la CIL pour héberger à l''étranger',

    actif                   BOOLEAN      NOT NULL DEFAULT 0
                            COMMENT 'Un pays existe dans le référentiel avant d''être ouvert commercialement',
    ouvert_le               DATE         NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_pays_iso (code_iso2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Taux de taxe HISTORISÉS. On ne stocke jamais un taux directement sur
-- le pays : une facture de 2026 doit rester recalculable au taux de
-- 2026 même après une loi de finances qui le modifie.
CREATE TABLE taux_taxe (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pays_id      TINYINT UNSIGNED NOT NULL,
    code         VARCHAR(20)  NOT NULL COMMENT 'tva_normal, tva_reduit, tva_super_reduit',
    libelle      VARCHAR(80)  NOT NULL,
    taux_pct     DECIMAL(5,2) NOT NULL COMMENT '18.00, 19.00, 10.00, 9.00…',
    date_debut   DATE         NOT NULL,
    date_fin     DATE         NULL COMMENT 'NULL = toujours en vigueur',
    PRIMARY KEY (id),
    KEY idx_taux_pays (pays_id, code, date_debut),
    CONSTRAINT fk_taux_pays FOREIGN KEY (pays_id) REFERENCES pays(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE villes (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pays_id   TINYINT UNSIGNED NOT NULL,
    nom       VARCHAR(80)  NOT NULL,
    slug      VARCHAR(90)  NOT NULL,
    latitude  DECIMAL(9,6) NULL,
    longitude DECIMAL(9,6) NULL,
    active    BOOLEAN      NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_villes (pays_id, slug),
    CONSTRAINT fk_villes_pays FOREIGN KEY (pays_id) REFERENCES pays(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Les opérateurs varient réellement par pays : Wave est absent du Togo,
-- du Bénin et de la Guinée-Bissau ; Celtiis Cash n'existe qu'au Bénin.
-- Coder une liste en dur dans l'application serait faux dès le
-- deuxième pays ouvert.
CREATE TABLE operateurs_paiement (
    id                  SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pays_id             TINYINT UNSIGNED NOT NULL,
    code                VARCHAR(30)  NOT NULL COMMENT 'orange_money, wave, mtn_momo, moov_flooz, celtiis…',
    nom                 VARCHAR(60)  NOT NULL,
    type                ENUM('mobile_money','carte','virement','especes') NOT NULL DEFAULT 'mobile_money',

    -- Plafonds. Les plafonds réglementaires BCEAO (instruction
    -- 008-05-2015) s'appliquent au porteur : 200 000 F/mois si le
    -- portefeuille n'est pas identifié, 2 000 000 F de solde et
    -- 10 000 000 F de rechargement mensuel s'il l'est.
    -- Les plafonds PAR TRANSACTION sont contractuels et rarement
    -- publiés : la colonne existe pour être renseignée au cas par cas.
    plafond_transaction_cfa INT UNSIGNED NULL,
    plafond_mensuel_cfa     INT UNSIGNED NULL,

    frais_encaissement_pct  DECIMAL(5,2) NULL COMMENT 'Commission agrégateur, ordre de 1,5 à 3,5 %',
    frais_reversement_pct   DECIMAL(5,2) NULL COMMENT 'Ordre de 0,8 à 2 %',
    frais_reversement_fixe_cfa INT UNSIGNED NULL,

    logo_url            VARCHAR(255) NULL,
    ordre               SMALLINT     NOT NULL DEFAULT 0,
    actif               BOOLEAN      NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_operateurs (pays_id, code),
    CONSTRAINT fk_operateurs_pays FOREIGN KEY (pays_id) REFERENCES pays(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Prestataires de services de paiement. On stocke la référence
-- d'agrément BCEAO : il faut pouvoir prouver qu'on a vérifié que le
-- PSP est agréé avant de lui confier des fonds.
CREATE TABLE prestataires_paiement (
    id                   SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code                 VARCHAR(30)  NOT NULL COMMENT 'cinetpay, paydunya, fedapay, semoa…',
    nom                  VARCHAR(80)  NOT NULL,
    agrement_bceao_ref   VARCHAR(80)  NULL,
    agrement_verifie_le  DATE         NULL,
    supporte_payout      BOOLEAN      NOT NULL DEFAULT 1,
    supporte_split       BOOLEAN      NOT NULL DEFAULT 0
                         COMMENT 'Paiement partagé vers les vendeurs. Aucun PSP UEMOA ne le proposait au 08/2026',
    actif                BOOLEAN      NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_psp_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Traductions génériques. Une seule table plutôt qu'une colonne
-- `nom_en`, `nom_moore`… sur chaque table : ajouter une langue ne doit
-- pas demander de migration.
CREATE TABLE traductions (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entite       VARCHAR(40)  NOT NULL COMMENT 'categorie, produit, gabarit_notification…',
    entite_id    BIGINT UNSIGNED NOT NULL,
    champ        VARCHAR(40)  NOT NULL COMMENT 'nom, description…',
    langue       CHAR(5)      NOT NULL COMMENT 'fr, en, mos (mooré), dyu (dioula), bam (bambara)',
    valeur       TEXT         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_traductions (entite, entite_id, champ, langue)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  2. IDENTITÉ, CONSENTEMENTS ET CONFORMITÉ « DONNÉES PERSONNELLES »
--  ---------------------------------------------------------------
--  Les lois de la zone (Burkina 001-2021, Sénégal 2008-12, Côte
--  d'Ivoire 2013-450, Bénin Code du numérique…) imposent une formalité
--  préalable, un consentement traçable, une durée de conservation
--  limitée et un droit d'accès à réponse sous deux mois.
--  Ces obligations se modélisent, elles ne s'improvisent pas.
-- =====================================================================

CREATE TABLE utilisateurs (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pays_id               TINYINT UNSIGNED NOT NULL,
    nom                   VARCHAR(120) NOT NULL,
    telephone             VARCHAR(20)  NOT NULL COMMENT 'E.164 — identifiant de connexion',
    email                 VARCHAR(180) NULL,
    mot_de_passe          VARCHAR(255) NULL,
    langue                CHAR(5)      NOT NULL DEFAULT 'fr',
    role                  ENUM('client','vendeur','livreur','agent','admin') NOT NULL DEFAULT 'client',

    -- Niveau de connaissance client. Détermine les plafonds de
    -- monnaie électronique applicables (BCEAO 008-05-2015) et la
    -- possibilité d'être payé en tant que vendeur.
    kyc_niveau            ENUM('aucun','simplifie','complet') NOT NULL DEFAULT 'aucun',
    kyc_valide_le         DATETIME     NULL,

    telephone_verifie_le  DATETIME     NULL,
    derniere_connexion    DATETIME     NULL,
    cree_le               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Anonymisation plutôt que suppression : les commandes doivent
    -- rester comptablement exploitables après l'effacement du compte.
    anonymise_le          DATETIME     NULL,
    supprime_le           DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_utilisateurs_tel (telephone),
    UNIQUE KEY uk_utilisateurs_email (email),
    KEY idx_utilisateurs_pays (pays_id, role),
    CONSTRAINT fk_utilisateurs_pays FOREIGN KEY (pays_id) REFERENCES pays(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registre des traitements. Sert directement à remplir les formulaires
-- de déclaration auprès de la CIL, de la CDP ou de l'ARTCI, et à
-- répondre à un contrôle. Table de configuration, peu de lignes.
CREATE TABLE registre_traitements (
    id                  SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code                VARCHAR(40)  NOT NULL,
    finalite            VARCHAR(255) NOT NULL,
    base_legale         ENUM('consentement','contrat','obligation_legale','interet_legitime') NOT NULL,
    categories_donnees  TEXT         NOT NULL,
    destinataires       TEXT         NULL COMMENT 'PSP, transporteur, hébergeur…',
    duree_conservation_jours INT UNSIGNED NULL,
    pays_hebergement    CHAR(2)      NULL,
    declaration_ref     VARCHAR(80)  NULL COMMENT 'Numéro de récépissé de déclaration',
    declare_le          DATE         NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_traitements_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preuve du consentement. Un consentement dont on ne peut pas prouver
-- la date, la version du texte et le canal ne vaut rien en contrôle.
CREATE TABLE consentements (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    utilisateur_id  BIGINT UNSIGNED NULL COMMENT 'NULL si consentement anonyme avant création de compte',
    telephone       VARCHAR(20)  NULL,
    finalite        ENUM('cgu','confidentialite','prospection_sms','prospection_email','cookies') NOT NULL,
    version_texte   VARCHAR(20)  NOT NULL COMMENT 'Version du document affiché au moment du clic',
    canal           ENUM('web','mobile','sms','papier','telephone') NOT NULL,
    accorde         BOOLEAN      NOT NULL,
    ip_hachee       CHAR(64)     NULL,
    donne_le        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    retire_le       DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_consentements (utilisateur_id, finalite, donne_le),
    CONSTRAINT fk_consentements_utilisateur FOREIGN KEY (utilisateur_id)
        REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Demandes d'exercice des droits. Le délai de réponse est de deux mois
-- au Burkina : la colonne `echeance` permet d'alerter avant le
-- dépassement, qui est sanctionné.
CREATE TABLE demandes_droits (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    utilisateur_id  BIGINT UNSIGNED NULL,
    telephone       VARCHAR(20)  NOT NULL,
    type            ENUM('acces','rectification','effacement','opposition','portabilite') NOT NULL,
    detail          TEXT         NULL,
    statut          ENUM('recue','en_cours','satisfaite','refusee') NOT NULL DEFAULT 'recue',
    motif_refus     TEXT         NULL,
    traite_par_id   BIGINT UNSIGNED NULL,
    recue_le        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    echeance        DATE         NOT NULL COMMENT 'recue_le + 2 mois',
    traitee_le      DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_demandes_statut (statut, echeance)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal des accès aux données personnelles par le personnel.
-- Exigé au titre des mesures de sécurité, et seul moyen de répondre à
-- « qui a consulté mon dossier ? ».
CREATE TABLE journal_acces_donnees (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id          BIGINT UNSIGNED NOT NULL,
    personne_type     VARCHAR(30)  NOT NULL COMMENT 'utilisateur, commande, boutique',
    personne_id       BIGINT UNSIGNED NOT NULL,
    action            VARCHAR(40)  NOT NULL COMMENT 'consultation, export, modification',
    motif             VARCHAR(255) NULL,
    ip_hachee         CHAR(64)     NULL,
    cree_le           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_journal_acces (personne_type, personne_id, cree_le),
    KEY idx_journal_agent (agent_id, cree_le)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal des actions d'administration. Distinct du précédent : celui-ci
-- trace les DÉCISIONS (rembourser, suspendre, valider), pas les
-- consultations. Sans lui, impossible de savoir qui a remboursé quoi.
CREATE TABLE journal_administration (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id    BIGINT UNSIGNED NULL,
    action      VARCHAR(60)  NOT NULL,
    cible_type  VARCHAR(40)  NOT NULL,
    cible_id    BIGINT UNSIGNED NULL,
    avant       JSON         NULL,
    apres       JSON         NULL,
    motif       TEXT         NULL,
    ip_hachee   CHAR(64)     NULL,
    cree_le     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_journal_admin (cible_type, cible_id, cree_le)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  3. BOUTIQUES
-- =====================================================================

CREATE TABLE boutiques (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    utilisateur_id          BIGINT UNSIGNED NOT NULL,
    pays_id                 TINYINT UNSIGNED NOT NULL,
    ville_id                INT UNSIGNED NULL,
    code                    VARCHAR(12)  NOT NULL COMMENT 'BF-V001 — préfixé par le pays',
    nom                     VARCHAR(120) NOT NULL,
    slug                    VARCHAR(140) NOT NULL,
    emoji                   VARCHAR(8)   NULL,
    logo_media_id           BIGINT UNSIGNED NULL,
    description             TEXT         NULL,
    telephone               VARCHAR(20)  NOT NULL,

    taux_commission         DECIMAL(5,2) NOT NULL DEFAULT 10.00,

    statut                  ENUM('candidature','actif','suspendu','ferme') NOT NULL DEFAULT 'candidature',
    niveau                  ENUM('nouveau','verifie','premium') NOT NULL DEFAULT 'nouveau',

    -- ---- Situation fiscale : détermine la TVA, la facture et la retenue ----
    -- numero_fiscal = IFU au Burkina et au Bénin, NCC en Côte d'Ivoire,
    -- NINEA au Sénégal. Son ABSENCE coûte cher au vendeur : 25 % de
    -- retenue à la source au Burkina.
    numero_fiscal           VARCHAR(40)  NULL,
    numero_fiscal_verifie_le DATE        NULL,
    regime_fiscal           ENUM('non_immatricule','forfaitaire','simplifie','reel') NOT NULL DEFAULT 'non_immatricule'
                            COMMENT 'forfaitaire = CME/CGU/TPU/Entreprenant/impôt synthétique selon le pays',
    assujetti_tva           BOOLEAN      NOT NULL DEFAULT 0
                            COMMENT 'La plupart des artisans ne le sont pas : leurs ventes sont hors champ TVA',
    registre_commerce       VARCHAR(40)  NULL,

    -- ---- Compte de reversement ----
    operateur_paiement_id   SMALLINT UNSIGNED NULL,
    paiement_numero         VARCHAR(40)  NULL,
    paiement_titulaire      VARCHAR(120) NULL,
    paiement_verifie_le     DATETIME     NULL COMMENT 'Vérifié par micro-virement ou appel API',

    -- ---- Disponibilité ----
    fermee_du               DATE         NULL,
    fermee_au               DATE         NULL,
    message_fermeture       VARCHAR(255) NULL COMMENT 'Congés, deuil, rupture générale',

    valide_le               DATETIME     NULL,
    cree_le                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    supprime_le             DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_boutiques_code (code),
    UNIQUE KEY uk_boutiques_slug (slug),
    KEY idx_boutiques_statut (pays_id, statut),
    CONSTRAINT fk_boutiques_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE RESTRICT,
    CONSTRAINT fk_boutiques_pays FOREIGN KEY (pays_id) REFERENCES pays(id) ON DELETE RESTRICT,
    CONSTRAINT fk_boutiques_ville FOREIGN KEY (ville_id) REFERENCES villes(id) ON DELETE SET NULL,
    CONSTRAINT fk_boutiques_operateur FOREIGN KEY (operateur_paiement_id) REFERENCES operateurs_paiement(id) ON DELETE SET NULL,
    CONSTRAINT ck_boutiques_commission CHECK (taux_commission >= 0 AND taux_commission <= 50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pièces justificatives du vendeur. Le chemin de stockage n'est jamais
-- public : ces documents contiennent des données d'identité.
CREATE TABLE documents_boutique (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    boutique_id  BIGINT UNSIGNED NOT NULL,
    type         ENUM('piece_identite','registre_commerce','attestation_fiscale','rib','photo_local','autre') NOT NULL,
    chemin       VARCHAR(255) NOT NULL COMMENT 'Stockage privé, jamais servi directement',
    statut       ENUM('en_attente','valide','refuse') NOT NULL DEFAULT 'en_attente',
    motif_refus  TEXT         NULL,
    expire_le    DATE         NULL,
    depose_le    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verifie_le   DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_documents_boutique (boutique_id, type),
    CONSTRAINT fk_documents_boutique FOREIGN KEY (boutique_id) REFERENCES boutiques(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE candidatures (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pays_id       TINYINT UNSIGNED NOT NULL,
    nom_boutique  VARCHAR(120) NOT NULL,
    responsable   VARCHAR(120) NOT NULL,
    telephone     VARCHAR(20)  NOT NULL,
    ville_id      INT UNSIGNED NULL,
    categorie_id  INT UNSIGNED NULL,
    description   TEXT         NOT NULL,
    statut        ENUM('en_attente','en_verification','acceptee','refusee') NOT NULL DEFAULT 'en_attente',
    motif_refus   TEXT         NULL,
    boutique_id   BIGINT UNSIGNED NULL,
    traite_par_id BIGINT UNSIGNED NULL,
    cree_le       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    traite_le     DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_candidatures_statut (statut),
    CONSTRAINT fk_candidatures_pays FOREIGN KEY (pays_id) REFERENCES pays(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  4. CATALOGUE : CATÉGORIES, PRODUITS, VARIANTES, MÉDIAS, MODÉRATION
--  ---------------------------------------------------------------
--  Rappel de la règle qui n'a pas changé : UNE CATÉGORIE N'EST PAS UNE
--  BOUTIQUE. C'est le produit qui porte la catégorie ; plusieurs
--  boutiques se font concurrence dans la même.
--
--  Nouveauté v2 : le STOCK ET LE PRIX descendent au niveau de la
--  VARIANTE. Un pagne existe en trois tailles, un savon en deux
--  contenances. Gérer le stock au niveau du produit rendait impossible
--  de vendre autre chose que des pièces uniques.
-- =====================================================================

CREATE TABLE categories (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id    INT UNSIGNED NULL COMMENT 'Arborescence sur deux niveaux maximum en pratique',
    nom          VARCHAR(80)  NOT NULL,
    slug         VARCHAR(90)  NOT NULL,
    emoji        VARCHAR(8)   NULL,
    -- Code du taux de TVA applicable aux produits de cette catégorie.
    -- Le taux réel est résolu par pays et par date dans `taux_taxe` :
    -- les produits alimentaires ne sont pas taxés pareil partout.
    code_taxe    VARCHAR(20)  NOT NULL DEFAULT 'tva_normal',
    ordre        SMALLINT     NOT NULL DEFAULT 0,
    active       BOOLEAN      NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_categories_slug (slug),
    KEY idx_categories_parent (parent_id),
    CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE produits (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    boutique_id     BIGINT UNSIGNED NOT NULL,
    categorie_id    INT UNSIGNED NOT NULL,
    reference       VARCHAR(24)  NOT NULL,
    nom             VARCHAR(160) NOT NULL,
    slug            VARCHAR(180) NOT NULL,
    description     TEXT         NULL,

    -- Prix de référence, repris par les variantes qui ne définissent
    -- pas le leur. TOUJOURS TTC : les lois de la zone imposent
    -- l'affichage du prix toutes taxes comprises au consommateur.
    prix_ttc_cfa    INT UNSIGNED NOT NULL,

    poids_g         INT UNSIGNED NULL COMMENT 'Sert au calcul des frais de livraison au poids',
    actif           BOOLEAN      NOT NULL DEFAULT 1,
    tracable        BOOLEAN      NOT NULL DEFAULT 0 COMMENT 'Éligible aux étiquettes QR',

    -- ---- Modération ----
    -- Ironie qu'il fallait corriger : une plateforme qui vend de
    -- l'anti-contrefaçon ne peut pas laisser publier n'importe quoi
    -- dans son propre catalogue.
    statut_moderation ENUM('brouillon','en_attente','publie','rejete','retire') NOT NULL DEFAULT 'en_attente',
    motif_moderation  TEXT       NULL,
    modere_par_id     BIGINT UNSIGNED NULL,
    modere_le         DATETIME   NULL,

    vues            INT UNSIGNED NOT NULL DEFAULT 0,
    cree_le         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    supprime_le     DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_produits_reference (reference),
    KEY idx_produits_categorie (categorie_id, statut_moderation, actif),
    KEY idx_produits_boutique (boutique_id, actif),
    KEY idx_produits_prix (prix_ttc_cfa),
    FULLTEXT KEY ft_produits (nom, description),
    CONSTRAINT fk_produits_boutique FOREIGN KEY (boutique_id) REFERENCES boutiques(id) ON DELETE CASCADE,
    CONSTRAINT fk_produits_categorie FOREIGN KEY (categorie_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Une variante = ce qu'on met réellement dans le panier.
-- Un produit sans déclinaison a UNE variante par défaut : cela évite
-- deux chemins de code, un pour les produits simples et un pour les
-- autres — source classique de bugs de stock.
CREATE TABLE variantes_produit (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    produit_id    BIGINT UNSIGNED NOT NULL,
    sku           VARCHAR(40)  NOT NULL COMMENT 'Code article unique, imprimable en code-barres',
    libelle       VARCHAR(120) NOT NULL COMMENT '« Taille L — Bleu indigo », ou « Standard »',
    prix_ttc_cfa  INT UNSIGNED NULL COMMENT 'NULL = reprend le prix du produit',
    stock         INT          NOT NULL DEFAULT 0,
    seuil_alerte  INT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'Alerte le vendeur avant la rupture',
    poids_g       INT UNSIGNED NULL,
    defaut        BOOLEAN      NOT NULL DEFAULT 0,
    actif         BOOLEAN      NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_variantes_sku (sku),
    KEY idx_variantes_produit (produit_id, actif),
    CONSTRAINT fk_variantes_produit FOREIGN KEY (produit_id) REFERENCES produits(id) ON DELETE CASCADE,
    -- Le stock peut être négatif en cas de survente constatée : on
    -- préfère le voir en base plutôt que de le masquer à zéro.
    CONSTRAINT ck_variantes_prix CHECK (prix_ttc_cfa IS NULL OR prix_ttc_cfa >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attributs de déclinaison (Taille, Couleur, Contenance).
CREATE TABLE attributs (
    id     SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code   VARCHAR(30)  NOT NULL,
    nom    VARCHAR(60)  NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_attributs_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE variante_attributs (
    variante_id  BIGINT UNSIGNED NOT NULL,
    attribut_id  SMALLINT UNSIGNED NOT NULL,
    valeur       VARCHAR(80) NOT NULL,
    PRIMARY KEY (variante_id, attribut_id),
    CONSTRAINT fk_va_variante FOREIGN KEY (variante_id) REFERENCES variantes_produit(id) ON DELETE CASCADE,
    CONSTRAINT fk_va_attribut FOREIGN KEY (attribut_id) REFERENCES attributs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Médias. On stocke plusieurs formats préparés à l'avance plutôt que
-- de redimensionner à la volée : sur une connexion 2G, servir une photo
-- de 3 Mo prise au téléphone rend la vitrine inutilisable.
CREATE TABLE medias (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    proprietaire_type VARCHAR(30) NOT NULL COMMENT 'produit, variante, boutique, litige, retour',
    proprietaire_id   BIGINT UNSIGNED NOT NULL,
    chemin_original   VARCHAR(255) NOT NULL,
    chemin_grand      VARCHAR(255) NULL COMMENT '1200 px, fiche produit',
    chemin_vignette   VARCHAR(255) NULL COMMENT '400 px, grille de la vitrine',
    chemin_miniature  VARCHAR(255) NULL COMMENT '100 px, panier et listes',
    largeur           SMALLINT UNSIGNED NULL,
    hauteur           SMALLINT UNSIGNED NULL,
    poids_octets      INT UNSIGNED NULL,
    texte_alternatif  VARCHAR(255) NULL COMMENT 'Accessibilité et référencement',
    ordre             SMALLINT NOT NULL DEFAULT 0,
    cree_le           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_medias_proprietaire (proprietaire_type, proprietaire_id, ordre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  5. LIVRAISON
-- =====================================================================

CREATE TABLE zones_livraison (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pays_id                TINYINT UNSIGNED NOT NULL,
    ville_id               INT UNSIGNED NULL,
    quartier               VARCHAR(80)  NULL COMMENT 'NULL = tarif par défaut de la ville',
    frais_base_cfa         INT UNSIGNED NOT NULL,
    frais_boutique_sup_cfa INT UNSIGNED NOT NULL DEFAULT 0,
    frais_par_kg_cfa       INT UNSIGNED NOT NULL DEFAULT 0,
    delai_estime_jours     TINYINT UNSIGNED NOT NULL DEFAULT 2,
    paiement_livraison_autorise BOOLEAN NOT NULL DEFAULT 1
                           COMMENT 'Le paiement en espèces se refuse dans les zones à risque',
    active                 BOOLEAN      NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_zones (pays_id, ville_id, quartier),
    CONSTRAINT fk_zones_pays FOREIGN KEY (pays_id) REFERENCES pays(id) ON DELETE CASCADE,
    CONSTRAINT fk_zones_ville FOREIGN KEY (ville_id) REFERENCES villes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Points de retrait. Moins chers que la livraison à domicile et
-- particulièrement adaptés là où l'adressage postal n'existe pas :
-- le client connaît la boutique du quartier, pas son propre code postal.
CREATE TABLE points_relais (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pays_id        TINYINT UNSIGNED NOT NULL,
    ville_id       INT UNSIGNED NOT NULL,
    nom            VARCHAR(120) NOT NULL,
    adresse        VARCHAR(255) NOT NULL,
    repere         VARCHAR(255) NULL,
    telephone      VARCHAR(20)  NOT NULL,
    horaires       VARCHAR(255) NULL,
    latitude       DECIMAL(9,6) NULL,
    longitude      DECIMAL(9,6) NULL,
    frais_cfa      INT UNSIGNED NOT NULL DEFAULT 0,
    capacite_colis SMALLINT UNSIGNED NULL,
    actif          BOOLEAN      NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_relais_ville (ville_id, actif),
    CONSTRAINT fk_relais_pays FOREIGN KEY (pays_id) REFERENCES pays(id) ON DELETE CASCADE,
    CONSTRAINT fk_relais_ville FOREIGN KEY (ville_id) REFERENCES villes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transporteurs (
    id           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pays_id      TINYINT UNSIGNED NOT NULL,
    nom          VARCHAR(80)  NOT NULL,
    type         ENUM('interne','partenaire','vendeur') NOT NULL DEFAULT 'partenaire'
                 COMMENT 'vendeur = la boutique livre elle-même',
    telephone    VARCHAR(20)  NULL,
    -- Un transporteur qui encaisse des espèces manipule l'argent des
    -- vendeurs : il doit être suivi comme un compte, pas comme un
    -- simple prestataire.
    encaisse_especes BOOLEAN  NOT NULL DEFAULT 0,
    actif        BOOLEAN      NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    CONSTRAINT fk_transporteurs_pays FOREIGN KEY (pays_id) REFERENCES pays(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  6. PANIERS ET COMMANDES
-- =====================================================================

-- Panier persistant. Permet de retrouver son panier d'un appareil à
-- l'autre, et surtout de relancer un abandon — l'abandon de panier est
-- la première cause de perte de chiffre d'affaires en commerce en ligne.
CREATE TABLE paniers (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    utilisateur_id  BIGINT UNSIGNED NULL,
    jeton_session   CHAR(40)     NULL COMMENT 'Panier anonyme, rattaché au compte à la connexion',
    pays_id         TINYINT UNSIGNED NOT NULL,
    statut          ENUM('actif','abandonne','converti','expire') NOT NULL DEFAULT 'actif',
    relance_envoyee_le DATETIME  NULL,
    cree_le         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_paniers_utilisateur (utilisateur_id, statut),
    KEY idx_paniers_jeton (jeton_session),
    KEY idx_paniers_relance (statut, modifie_le)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lignes_panier (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    panier_id    BIGINT UNSIGNED NOT NULL,
    variante_id  BIGINT UNSIGNED NOT NULL,
    quantite     SMALLINT UNSIGNED NOT NULL,
    ajoute_le    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_lignes_panier (panier_id, variante_id),
    CONSTRAINT fk_lp_panier FOREIGN KEY (panier_id) REFERENCES paniers(id) ON DELETE CASCADE,
    CONSTRAINT fk_lp_variante FOREIGN KEY (variante_id) REFERENCES variantes_produit(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE commandes (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference          VARCHAR(24)  NOT NULL COMMENT 'BF-CMD-2026-000001',
    pays_id            TINYINT UNSIGNED NOT NULL,
    utilisateur_id     BIGINT UNSIGNED NULL,

    -- Coordonnées recopiées : la commande d'hier garde l'adresse d'hier.
    client_nom         VARCHAR(120) NOT NULL,
    client_telephone   VARCHAR(20)  NOT NULL,
    ville_id           INT UNSIGNED NULL,
    quartier           VARCHAR(120) NOT NULL,
    repere             VARCHAR(255) NULL COMMENT 'Indispensable : pas d''adressage postal fiable',

    mode_livraison     ENUM('domicile','point_relais','retrait_boutique') NOT NULL DEFAULT 'domicile',
    point_relais_id    INT UNSIGNED NULL,

    -- ---- Paiement ----
    -- « especes_livraison » est le mode dominant sur le marché : il
    -- devait exister dès la conception, pas être ajouté après coup.
    mode_paiement      ENUM('mobile_money','carte','virement','especes_livraison') NOT NULL,
    operateur_paiement_id SMALLINT UNSIGNED NULL,
    statut_paiement    ENUM('attente','autorise','encaisse','partiel','echoue','rembourse') NOT NULL DEFAULT 'attente',

    statut             ENUM('brouillon','confirmee','en_preparation','partiellement_livree',
                            'livree','retractee','annulee') NOT NULL DEFAULT 'brouillon',

    -- ---- Montants, tous entiers en francs CFA ----
    total_articles_ttc_cfa INT UNSIGNED NOT NULL DEFAULT 0,
    total_tva_cfa          INT UNSIGNED NOT NULL DEFAULT 0
                           COMMENT 'Ventilation informative : la TVA n''est due que sur les ventes des assujettis',
    total_frais_livraison_cfa INT UNSIGNED NOT NULL DEFAULT 0,
    total_remise_cfa       INT UNSIGNED NOT NULL DEFAULT 0,
    total_a_payer_cfa      INT UNSIGNED NOT NULL DEFAULT 0,

    promotion_id       INT UNSIGNED NULL,
    langue             CHAR(5)      NOT NULL DEFAULT 'fr',
    canal              ENUM('web','mobile','telephone','agent') NOT NULL DEFAULT 'web',

    -- ---- Conformité commerce électronique ----
    -- Le contrat doit être archivé et le récapitulatif accepté par le
    -- client conservé : c'est une obligation explicite au Sénégal.
    cgv_version        VARCHAR(20)  NULL,
    cgv_acceptees_le   DATETIME     NULL,
    retractation_avant DATE         NULL COMMENT 'Calculé selon le pays à la livraison',

    confirmee_le       DATETIME     NULL,
    cree_le            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_commandes_reference (reference),
    KEY idx_commandes_telephone (client_telephone),
    KEY idx_commandes_statut (pays_id, statut),
    CONSTRAINT fk_commandes_pays FOREIGN KEY (pays_id) REFERENCES pays(id) ON DELETE RESTRICT,
    CONSTRAINT fk_commandes_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CONSTRAINT fk_commandes_relais FOREIGN KEY (point_relais_id) REFERENCES points_relais(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sous_commandes (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    commande_id            BIGINT UNSIGNED NOT NULL,
    boutique_id            BIGINT UNSIGNED NOT NULL,
    reference              VARCHAR(32)  NOT NULL,

    statut                 ENUM('a_preparer','prete','expediee','livree','retournee','annulee')
                           NOT NULL DEFAULT 'a_preparer',

    -- État des fonds — toujours distinct du statut logistique.
    -- « attente_encaissement » est le cas du paiement à la livraison :
    -- rien n'a encore été encaissé, on ne peut donc rien séquestrer.
    etat_fonds             ENUM('attente_encaissement','sequestre','reverse','rembourse','impaye')
                           NOT NULL DEFAULT 'sequestre',

    -- ---- Décomposition financière ----
    montant_articles_ttc_cfa INT UNSIGNED NOT NULL DEFAULT 0,
    montant_tva_cfa          INT UNSIGNED NOT NULL DEFAULT 0,
    frais_livraison_cfa      INT UNSIGNED NOT NULL DEFAULT 0,
    remise_cfa               INT UNSIGNED NOT NULL DEFAULT 0,

    -- Taux figés à la commande : renégocier n'affecte pas le passé.
    taux_commission_pct      DECIMAL(5,2) NOT NULL,
    commission_cfa           INT UNSIGNED NOT NULL DEFAULT 0,

    -- Retenue à la source. Nouveauté v2, et sujet sensible : au Burkina
    -- un vendeur sans numéro fiscal subit 25 %. Le montant retenu n'est
    -- pas un revenu de la plateforme : il est dû au fisc.
    taux_retenue_source_pct  DECIMAL(5,2) NOT NULL DEFAULT 0,
    retenue_source_cfa       INT UNSIGNED NOT NULL DEFAULT 0,

    montant_net_cfa          INT UNSIGNED NOT NULL DEFAULT 0
                             COMMENT 'articles − commission − retenue à la source',

    expedie_le             DATETIME NULL,
    livre_le               DATETIME NULL,
    confirme_par_client_le DATETIME NULL,
    cree_le                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_sous_commandes_ref (reference),
    KEY idx_sc_boutique (boutique_id, statut),
    KEY idx_sc_fonds (etat_fonds),
    KEY idx_sc_commande (commande_id),
    CONSTRAINT fk_sc_commande FOREIGN KEY (commande_id) REFERENCES commandes(id) ON DELETE CASCADE,
    CONSTRAINT fk_sc_boutique FOREIGN KEY (boutique_id) REFERENCES boutiques(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lignes_commande (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sous_commande_id   BIGINT UNSIGNED NOT NULL,
    variante_id        BIGINT UNSIGNED NULL COMMENT 'NULL si la variante a été supprimée depuis',
    -- Tout est recopié : la facture d'hier doit rester juste.
    sku                VARCHAR(40)  NOT NULL,
    nom_produit        VARCHAR(160) NOT NULL,
    libelle_variante   VARCHAR(120) NOT NULL,
    prix_unitaire_ttc_cfa INT UNSIGNED NOT NULL,
    taux_tva_pct       DECIMAL(5,2) NOT NULL DEFAULT 0,
    quantite           SMALLINT UNSIGNED NOT NULL,
    total_ttc_cfa      INT UNSIGNED NOT NULL,
    total_tva_cfa      INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_lignes_sc (sous_commande_id),
    CONSTRAINT fk_lignes_sc FOREIGN KEY (sous_commande_id) REFERENCES sous_commandes(id) ON DELETE CASCADE,
    CONSTRAINT fk_lignes_variante FOREIGN KEY (variante_id) REFERENCES variantes_produit(id) ON DELETE SET NULL,
    CONSTRAINT ck_lignes_quantite CHECK (quantite > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Expédition et PREUVE DE LIVRAISON.
-- Sans preuve, un litige « je n'ai rien reçu » se tranche à pile ou
-- face. Le code à usage unique est la solution la plus simple : le
-- client le reçoit par SMS, le livreur le saisit devant lui.
CREATE TABLE expeditions (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sous_commande_id   BIGINT UNSIGNED NOT NULL,
    transporteur_id    SMALLINT UNSIGNED NULL,
    livreur_id         BIGINT UNSIGNED NULL COMMENT 'Utilisateur de rôle livreur',
    code_suivi         VARCHAR(60)  NULL,
    code_livraison     CHAR(6)      NULL COMMENT 'Code à usage unique envoyé au client par SMS',
    code_valide_le     DATETIME     NULL,
    preuve_media_id    BIGINT UNSIGNED NULL COMMENT 'Photo du colis remis',
    signature_nom      VARCHAR(120) NULL COMMENT 'Nom de la personne ayant réceptionné',
    tentatives         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    statut             ENUM('prevue','en_cours','livree','echouee','retour_expediteur') NOT NULL DEFAULT 'prevue',
    motif_echec        VARCHAR(255) NULL,
    expedie_le         DATETIME     NULL,
    livre_le           DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_expeditions_sc (sous_commande_id),
    CONSTRAINT fk_exp_sc FOREIGN KEY (sous_commande_id) REFERENCES sous_commandes(id) ON DELETE CASCADE,
    CONSTRAINT fk_exp_transporteur FOREIGN KEY (transporteur_id) REFERENCES transporteurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE evenements_commande (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sous_commande_id BIGINT UNSIGNED NOT NULL,
    type             VARCHAR(48)  NOT NULL,
    donnees          JSON         NULL,
    auteur_id        BIGINT UNSIGNED NULL,
    auteur_type      ENUM('systeme','client','vendeur','livreur','agent','admin') NOT NULL DEFAULT 'systeme',
    cree_le          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_evenements (sous_commande_id, cree_le),
    CONSTRAINT fk_ev_sc FOREIGN KEY (sous_commande_id) REFERENCES sous_commandes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RETOURS — à ne pas confondre avec les litiges.
-- Un litige : le produit est défectueux ou absent, il y a un tort.
-- Un retour : le produit est conforme, le client change d'avis. C'est
-- un droit dans plusieurs pays de la zone, et la question qui fâche est
-- « qui paie le transport retour ».
CREATE TABLE retours (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sous_commande_id   BIGINT UNSIGNED NOT NULL,
    reference          VARCHAR(32)  NOT NULL,
    type               ENUM('retractation','echange','remboursement') NOT NULL,
    motif              ENUM('ne_convient_pas','taille_incorrecte','erreur_commande','autre') NOT NULL,
    commentaire        TEXT         NULL,
    statut             ENUM('demande','accepte','refuse','en_transit','recu','rembourse','clos')
                       NOT NULL DEFAULT 'demande',
    motif_refus        TEXT         NULL,
    -- Qui supporte les frais du retour. En rétractation légale, c'est
    -- généralement le client ; en erreur du vendeur, c'est le vendeur.
    frais_a_la_charge  ENUM('client','boutique','plateforme') NOT NULL DEFAULT 'client',
    frais_retour_cfa   INT UNSIGNED NOT NULL DEFAULT 0,
    montant_rembourse_cfa INT UNSIGNED NOT NULL DEFAULT 0,
    demande_le         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    traite_le          DATETIME     NULL,
    recu_le            DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_retours_ref (reference),
    KEY idx_retours_statut (statut),
    CONSTRAINT fk_retours_sc FOREIGN KEY (sous_commande_id) REFERENCES sous_commandes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lignes_retour (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    retour_id       BIGINT UNSIGNED NOT NULL,
    ligne_commande_id BIGINT UNSIGNED NOT NULL,
    quantite        SMALLINT UNSIGNED NOT NULL,
    etat_constate   ENUM('neuf','ouvert','abime','incomplet') NULL COMMENT 'Renseigné à la réception',
    PRIMARY KEY (id),
    CONSTRAINT fk_lr_retour FOREIGN KEY (retour_id) REFERENCES retours(id) ON DELETE CASCADE,
    CONSTRAINT fk_lr_ligne FOREIGN KEY (ligne_commande_id) REFERENCES lignes_commande(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE litiges (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sous_commande_id BIGINT UNSIGNED NOT NULL,
    reference        VARCHAR(32)  NOT NULL,
    motif            ENUM('non_recu','endommage','non_conforme','incomplet','contrefacon','autre') NOT NULL,
    description      TEXT         NOT NULL,
    statut           ENUM('ouvert','en_examen','resolu_client','resolu_boutique','clos') NOT NULL DEFAULT 'ouvert',
    ouvert_par_id    BIGINT UNSIGNED NULL,
    traite_par_id    BIGINT UNSIGNED NULL,
    resolution       TEXT         NULL COMMENT 'Toujours motivée : visible des deux parties',
    -- Voie de recours du vendeur. Sans elle, un client de mauvaise foi
    -- peut abîmer durablement une boutique honnête, alors que la
    -- réputation est devenue un actif pour elle.
    conteste_par_boutique_le DATETIME NULL,
    argument_boutique TEXT        NULL,
    ouvert_le        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolu_le        DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_litiges_ref (reference),
    KEY idx_litiges_statut (statut),
    CONSTRAINT fk_litiges_sc FOREIGN KEY (sous_commande_id) REFERENCES sous_commandes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  7. ARGENT — TRANSACTIONS, GRAND LIVRE, CANTONNEMENT, REVERSEMENTS
--  ---------------------------------------------------------------
--  C'EST LE BLOC LE PLUS SENSIBLE DU MODÈLE, et celui qui a le plus
--  changé depuis la v1.
--
--  Constat : aucun agrégateur UEMOA ne propose de sous-compte marchand
--  avec séquestre. La plateforme encaisse donc sur un compte unique et
--  détient de fait des fonds appartenant aux boutiques.
--
--  Conséquences directement modélisées ici :
--   · un GRAND LIVRE interne en écriture seule (`mouvements_compte`),
--     qui est la seule source de vérité sur ce que doit la plateforme ;
--   · un COMPTE DE CANTONNEMENT rapproché quotidiennement, comme
--     l'exige l'instruction BCEAO 001-01-2024 (art. 48) ;
--   · une RÉCONCILIATION avec le prestataire, sans laquelle un écart se
--     découvre des mois plus tard.
--
--  Règle absolue : on ne modifie ni ne supprime jamais une ligne de
--  `mouvements_compte`. Une erreur se corrige par une écriture inverse,
--  jamais par un UPDATE.
-- =====================================================================

-- Chaque tentative d'encaissement, réussie ou non. Les échecs sont
-- aussi instructifs que les succès : un opérateur qui échoue à 30 %
-- doit être détecté.
CREATE TABLE transactions_paiement (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    commande_id           BIGINT UNSIGNED NOT NULL,
    prestataire_id        SMALLINT UNSIGNED NULL,
    operateur_paiement_id SMALLINT UNSIGNED NULL,
    sens                  ENUM('encaissement','remboursement') NOT NULL DEFAULT 'encaissement',
    montant_cfa           INT UNSIGNED NOT NULL,
    frais_psp_cfa         INT UNSIGNED NOT NULL DEFAULT 0,
    reference_externe     VARCHAR(100) NULL COMMENT 'Identifiant de transaction chez le PSP',
    statut                ENUM('initiee','en_attente','reussie','echouee','expiree','annulee') NOT NULL DEFAULT 'initiee',
    code_erreur           VARCHAR(60)  NULL,
    message_erreur        VARCHAR(255) NULL,
    -- Conservé pour rejouer un webhook et prouver ce que le PSP a
    -- réellement envoyé en cas de contestation.
    charge_utile_webhook  JSON         NULL,
    initiee_le            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finalisee_le          DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_tx_externe (prestataire_id, reference_externe),
    KEY idx_tx_commande (commande_id, statut),
    KEY idx_tx_statut (statut, initiee_le),
    CONSTRAINT fk_tx_commande FOREIGN KEY (commande_id) REFERENCES commandes(id) ON DELETE RESTRICT,
    CONSTRAINT fk_tx_psp FOREIGN KEY (prestataire_id) REFERENCES prestataires_paiement(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Encaissements en espèces à la livraison.
-- Le livreur détient physiquement de l'argent qui n'est pas le sien.
-- Sans suivi ligne à ligne, la démarque est invisible.
CREATE TABLE encaissements_especes (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    expedition_id    BIGINT UNSIGNED NOT NULL,
    livreur_id       BIGINT UNSIGNED NULL,
    transporteur_id  SMALLINT UNSIGNED NULL,
    montant_du_cfa   INT UNSIGNED NOT NULL,
    montant_percu_cfa INT UNSIGNED NOT NULL DEFAULT 0,
    statut           ENUM('a_encaisser','encaisse','remis','manquant','refuse_client')
                     NOT NULL DEFAULT 'a_encaisser',
    remis_le         DATETIME     NULL COMMENT 'Date de remise des fonds à la plateforme',
    bordereau_ref    VARCHAR(60)  NULL,
    ecart_commentaire VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_especes_statut (statut, remis_le),
    KEY idx_especes_livreur (livreur_id, statut),
    CONSTRAINT fk_especes_expedition FOREIGN KEY (expedition_id) REFERENCES expeditions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  GRAND LIVRE — table en écriture seule
--  Chaque ligne est un mouvement sur le compte interne d'une boutique
--  (ou de la plateforme). Le solde d'une boutique n'est JAMAIS une
--  colonne : c'est la somme de ses mouvements. Une colonne « solde »
--  finirait par diverger de son historique, et on ne saurait plus
--  laquelle des deux croire.
-- ---------------------------------------------------------------------
CREATE TABLE mouvements_compte (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    boutique_id       BIGINT UNSIGNED NULL COMMENT 'NULL = compte de la plateforme (commissions, frais)',
    type              ENUM('vente','commission','retenue_source','frais_psp','remboursement',
                          'reversement','ajustement','frais_retour','penalite') NOT NULL,
    -- Signe explicite : +1 crédit (la plateforme doit), -1 débit.
    -- Stocker un montant signé serait plus court mais rendrait les
    -- sommes en SQL faciles à écrire de travers.
    sens              ENUM('credit','debit') NOT NULL,
    montant_cfa       INT UNSIGNED NOT NULL,
    devise            CHAR(3)      NOT NULL DEFAULT 'XOF',

    -- Pièce justificative : à quoi ce mouvement se rattache.
    piece_type        VARCHAR(30)  NOT NULL COMMENT 'sous_commande, reversement, retour, litige…',
    piece_id          BIGINT UNSIGNED NOT NULL,
    libelle           VARCHAR(255) NOT NULL,

    -- Écriture de contrepassation : renseigné quand ce mouvement
    -- annule un mouvement antérieur.
    annule_mouvement_id BIGINT UNSIGNED NULL,

    cree_le           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cree_par_id       BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_mvt_boutique (boutique_id, cree_le),
    KEY idx_mvt_piece (piece_type, piece_id),
    CONSTRAINT fk_mvt_boutique FOREIGN KEY (boutique_id) REFERENCES boutiques(id) ON DELETE RESTRICT,
    CONSTRAINT ck_mvt_montant CHECK (montant_cfa > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- État quotidien du compte de cantonnement, rapproché du grand livre.
-- L'écart doit être nul. S'il ne l'est pas, on le voit le jour même —
-- c'est tout l'objet de cette table, et c'est une exigence BCEAO.
CREATE TABLE cantonnement_journalier (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    date_arrete            DATE         NOT NULL,
    pays_id                TINYINT UNSIGNED NOT NULL,
    solde_banque_cfa       BIGINT       NOT NULL COMMENT 'Relevé du compte de cantonnement',
    solde_grand_livre_cfa  BIGINT       NOT NULL COMMENT 'Somme des dettes envers les boutiques',
    ecart_cfa              BIGINT       NOT NULL DEFAULT 0,
    statut                 ENUM('conforme','ecart_a_expliquer','regularise') NOT NULL DEFAULT 'conforme',
    commentaire            TEXT         NULL,
    valide_par_id          BIGINT UNSIGNED NULL,
    cree_le                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_cantonnement (date_arrete, pays_id),
    CONSTRAINT fk_cant_pays FOREIGN KEY (pays_id) REFERENCES pays(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Réconciliation avec le prestataire : ce qu'il dit avoir encaissé
-- contre ce que nous avons enregistré.
CREATE TABLE reconciliations_psp (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    prestataire_id      SMALLINT UNSIGNED NOT NULL,
    date_arrete         DATE         NOT NULL,
    nb_operations_psp   INT UNSIGNED NOT NULL DEFAULT 0,
    montant_psp_cfa     BIGINT       NOT NULL DEFAULT 0,
    nb_operations_local INT UNSIGNED NOT NULL DEFAULT 0,
    montant_local_cfa   BIGINT       NOT NULL DEFAULT 0,
    ecart_montant_cfa   BIGINT       NOT NULL DEFAULT 0,
    statut              ENUM('en_cours','conforme','ecart','regularise') NOT NULL DEFAULT 'en_cours',
    detail_ecarts       JSON         NULL COMMENT 'Références des opérations non appariées',
    cree_le             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_reconciliation (prestataire_id, date_arrete),
    CONSTRAINT fk_recon_psp FOREIGN KEY (prestataire_id) REFERENCES prestataires_paiement(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reversements (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    boutique_id        BIGINT UNSIGNED NOT NULL,
    reference          VARCHAR(28)  NOT NULL,
    prestataire_id     SMALLINT UNSIGNED NULL,
    periode_debut      DATE         NOT NULL,
    periode_fin        DATE         NOT NULL,
    montant_brut_cfa   INT UNSIGNED NOT NULL,
    commission_cfa     INT UNSIGNED NOT NULL,
    retenue_source_cfa INT UNSIGNED NOT NULL DEFAULT 0,
    frais_transfert_cfa INT UNSIGNED NOT NULL DEFAULT 0,
    montant_net_cfa    INT UNSIGNED NOT NULL,
    statut             ENUM('a_payer','en_cours','paye','echoue','suspendu') NOT NULL DEFAULT 'a_payer',
    reference_externe  VARCHAR(100) NULL,
    motif_echec        TEXT         NULL,
    -- Un reversement peut être suspendu : plafond de portefeuille
    -- atteint, KYC incomplet, contrôle en cours.
    motif_suspension   VARCHAR(255) NULL,
    execute_le         DATETIME     NULL,
    cree_le            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_reversements_ref (reference),
    KEY idx_reversements (boutique_id, statut),
    CONSTRAINT fk_rev_boutique FOREIGN KEY (boutique_id) REFERENCES boutiques(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reversement_lignes (
    reversement_id   BIGINT UNSIGNED NOT NULL,
    sous_commande_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (reversement_id, sous_commande_id),
    -- Protection anti-double-paiement au niveau de la base : une vente
    -- ne peut figurer que dans un seul reversement, quoi que fasse
    -- l'application.
    UNIQUE KEY uk_une_vente_un_reversement (sous_commande_id),
    CONSTRAINT fk_rl_reversement FOREIGN KEY (reversement_id) REFERENCES reversements(id) ON DELETE CASCADE,
    CONSTRAINT fk_rl_sc FOREIGN KEY (sous_commande_id) REFERENCES sous_commandes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  8. FISCALITÉ ET FACTURATION
--  ---------------------------------------------------------------
--  Deux factures distinctes sur une même vente, et il ne faut pas les
--  confondre :
--    · la boutique facture le CLIENT (vente de marchandise) ;
--    · la plateforme facture la BOUTIQUE (commission de service).
--
--  Difficulté propre à la zone : au Bénin, au Burkina, en Côte d'Ivoire
--  et au Niger, une facture n'est valable que si elle sort d'un
--  dispositif certifié par l'État, rattaché au numéro fiscal de
--  l'émetteur. La plateforme ne peut donc pas auto-facturer librement
--  pour un vendeur non enrôlé.
-- =====================================================================

CREATE TABLE factures (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type                ENUM('vente_client','commission_boutique','avoir') NOT NULL,
    pays_id             TINYINT UNSIGNED NOT NULL,
    numero              VARCHAR(40)  NOT NULL COMMENT 'Séquence continue et sans trou, par pays et par type',
    -- Émetteur et destinataire, en clair : une facture doit rester
    -- lisible même si la boutique ferme ou si le client est anonymisé.
    emetteur_type       ENUM('boutique','plateforme') NOT NULL,
    emetteur_nom        VARCHAR(160) NOT NULL,
    emetteur_numero_fiscal VARCHAR(40) NULL,
    destinataire_nom    VARCHAR(160) NOT NULL,
    destinataire_numero_fiscal VARCHAR(40) NULL,
    destinataire_telephone VARCHAR(20) NULL,

    sous_commande_id    BIGINT UNSIGNED NULL,
    reversement_id      BIGINT UNSIGNED NULL,
    facture_origine_id  BIGINT UNSIGNED NULL COMMENT 'Pour un avoir : la facture annulée',

    montant_ht_cfa      INT UNSIGNED NOT NULL DEFAULT 0,
    montant_tva_cfa     INT UNSIGNED NOT NULL DEFAULT 0,
    taux_tva_pct        DECIMAL(5,2) NOT NULL DEFAULT 0,
    montant_ttc_cfa     INT UNSIGNED NOT NULL,

    -- Certification fiscale. Tant que `certifiee_le` est nul dans un
    -- pays qui l'exige, la facture n'a aucune valeur probante.
    certification_requise BOOLEAN    NOT NULL DEFAULT 0,
    certification_ref   VARCHAR(120) NULL COMMENT 'Identifiant renvoyé par e-MECeF / FEC / FNE / e-SECeF',
    certification_qr    VARCHAR(255) NULL,
    certifiee_le        DATETIME     NULL,
    certification_erreur VARCHAR(255) NULL,

    chemin_pdf          VARCHAR(255) NULL,
    emise_le            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_factures_numero (pays_id, type, numero),
    KEY idx_factures_sc (sous_commande_id),
    CONSTRAINT fk_factures_pays FOREIGN KEY (pays_id) REFERENCES pays(id) ON DELETE RESTRICT,
    CONSTRAINT fk_factures_sc FOREIGN KEY (sous_commande_id) REFERENCES sous_commandes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Retenues à la source opérées pour le compte du fisc.
-- Cet argent n'appartient à personne dans l'entreprise : il est
-- collecté puis reversé. Le suivre à part évite de le confondre avec
-- du chiffre d'affaires.
CREATE TABLE retenues_source (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pays_id            TINYINT UNSIGNED NOT NULL,
    boutique_id        BIGINT UNSIGNED NOT NULL,
    sous_commande_id   BIGINT UNSIGNED NULL,
    reversement_id     BIGINT UNSIGNED NULL,
    base_cfa           INT UNSIGNED NOT NULL COMMENT 'Montant sur lequel la retenue est calculée',
    taux_pct           DECIMAL(5,2) NOT NULL,
    montant_cfa        INT UNSIGNED NOT NULL,
    motif              ENUM('non_immatricule','immatricule','non_resident') NOT NULL,
    periode            CHAR(7)      NOT NULL COMMENT 'AAAA-MM de déclaration',
    statut             ENUM('a_reverser','declaree','reversee') NOT NULL DEFAULT 'a_reverser',
    declaration_ref    VARCHAR(80)  NULL,
    reversee_le        DATE         NULL,
    cree_le            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_retenues_periode (pays_id, periode, statut),
    KEY idx_retenues_boutique (boutique_id),
    CONSTRAINT fk_ret_pays FOREIGN KEY (pays_id) REFERENCES pays(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ret_boutique FOREIGN KEY (boutique_id) REFERENCES boutiques(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  9. RELATION CLIENT — AVIS, SUPPORT, NOTIFICATIONS
-- =====================================================================

-- Avis rédigés. La note calculée de la v1 (litiges, délais) reste, mais
-- elle ne remplace pas ce que les acheteurs lisent réellement.
-- Contrainte forte : seul un acheteur AYANT REÇU le produit peut
-- laisser un avis. Sans cela, la section se remplit de faux avis.
CREATE TABLE avis (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sous_commande_id   BIGINT UNSIGNED NOT NULL,
    produit_id         BIGINT UNSIGNED NULL,
    boutique_id        BIGINT UNSIGNED NOT NULL,
    utilisateur_id     BIGINT UNSIGNED NULL,
    auteur_affiche     VARCHAR(80)  NOT NULL COMMENT 'Prénom + initiale : « Aminata S. »',
    note               TINYINT UNSIGNED NOT NULL,
    titre              VARCHAR(120) NULL,
    commentaire        TEXT         NULL,
    statut             ENUM('en_attente','publie','rejete') NOT NULL DEFAULT 'en_attente',
    motif_rejet        VARCHAR(255) NULL,
    -- Droit de réponse du vendeur : un avis négatif sans réponse
    -- possible est perçu comme une condamnation sans procès.
    reponse_boutique   TEXT         NULL,
    reponse_le         DATETIME     NULL,
    cree_le            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Un seul avis par sous-commande et par produit.
    UNIQUE KEY uk_avis (sous_commande_id, produit_id),
    KEY idx_avis_produit (produit_id, statut),
    KEY idx_avis_boutique (boutique_id, statut),
    CONSTRAINT fk_avis_sc FOREIGN KEY (sous_commande_id) REFERENCES sous_commandes(id) ON DELETE CASCADE,
    CONSTRAINT fk_avis_boutique FOREIGN KEY (boutique_id) REFERENCES boutiques(id) ON DELETE CASCADE,
    CONSTRAINT ck_avis_note CHECK (note BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tickets_support (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference       VARCHAR(24)  NOT NULL,
    pays_id         TINYINT UNSIGNED NOT NULL,
    utilisateur_id  BIGINT UNSIGNED NULL,
    telephone       VARCHAR(20)  NOT NULL,
    canal           ENUM('telephone','whatsapp','sms','web','agent') NOT NULL DEFAULT 'telephone'
                    COMMENT 'Le téléphone est le canal principal sur ce marché, pas l''e-mail',
    sujet           VARCHAR(160) NOT NULL,
    commande_id     BIGINT UNSIGNED NULL,
    priorite        ENUM('basse','normale','haute','urgente') NOT NULL DEFAULT 'normale',
    statut          ENUM('ouvert','en_cours','attente_client','resolu','clos') NOT NULL DEFAULT 'ouvert',
    assigne_a_id    BIGINT UNSIGNED NULL,
    -- Engagement de délai. Affiché au client et mesuré : un délai
    -- annoncé mais non suivi est pire que pas de délai du tout.
    echeance_reponse DATETIME    NULL,
    premiere_reponse_le DATETIME NULL,
    cree_le         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    clos_le         DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_tickets_ref (reference),
    KEY idx_tickets_statut (statut, priorite, echeance_reponse)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages_ticket (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id    BIGINT UNSIGNED NOT NULL,
    auteur_id    BIGINT UNSIGNED NULL,
    auteur_type  ENUM('client','agent','systeme','vendeur') NOT NULL,
    corps        TEXT         NOT NULL,
    interne      BOOLEAN      NOT NULL DEFAULT 0 COMMENT 'Note interne, invisible du client',
    cree_le      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_messages_ticket (ticket_id, cree_le),
    CONSTRAINT fk_msg_ticket FOREIGN KEY (ticket_id) REFERENCES tickets_support(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gabarits de message, par code et par langue. Modifier un texte ne
-- doit jamais demander de redéployer l'application.
CREATE TABLE gabarits_notification (
    id           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code         VARCHAR(60)  NOT NULL COMMENT 'commande_confirmee, colis_expedie, code_livraison…',
    canal        ENUM('sms','push','email','whatsapp') NOT NULL,
    langue       CHAR(5)      NOT NULL DEFAULT 'fr',
    sujet        VARCHAR(160) NULL,
    corps        TEXT         NOT NULL COMMENT 'Variables entre accolades : {client_nom}, {reference}',
    -- Un SMS coûte à chaque envoi. Compter les caractères force à
    -- écrire court : au-delà de 160, on paie deux SMS.
    longueur_max SMALLINT UNSIGNED NULL,
    actif        BOOLEAN      NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_gabarits (code, canal, langue)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal des envois. Sert à trois choses : prouver qu'on a bien
-- prévenu le client, diagnostiquer les non-réceptions, et SURVEILLER
-- LE COÛT — c'est le poste de dépense récurrent le plus sous-estimé
-- d'une place de marché.
CREATE TABLE notifications (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    gabarit_id       SMALLINT UNSIGNED NULL,
    destinataire_id  BIGINT UNSIGNED NULL,
    telephone        VARCHAR(20)  NULL,
    email            VARCHAR(180) NULL,
    canal            ENUM('sms','push','email','whatsapp') NOT NULL,
    corps_envoye     TEXT         NOT NULL COMMENT 'Après substitution des variables',
    statut           ENUM('en_file','envoye','remis','echoue','rejete') NOT NULL DEFAULT 'en_file',
    fournisseur      VARCHAR(40)  NULL,
    reference_externe VARCHAR(100) NULL,
    cout_cfa         INT UNSIGNED NOT NULL DEFAULT 0,
    nb_segments      TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Un SMS long est facturé plusieurs fois',
    erreur           VARCHAR(255) NULL,
    cree_le          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    envoye_le        DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_notifications_statut (statut, cree_le),
    KEY idx_notifications_destinataire (destinataire_id, cree_le)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  10. PROMOTIONS
--  ---------------------------------------------------------------
--  La question qui fâche, et qui doit être tranchée dans le modèle :
--  QUI FINANCE LA REMISE ? Si c'est la plateforme, elle rogne sa
--  commission. Si c'est la boutique, elle doit l'avoir accepté.
--  Une remise imposée à un artisan sans son accord est le meilleur
--  moyen de le faire partir.
-- =====================================================================

CREATE TABLE promotions (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code                VARCHAR(30)  NULL COMMENT 'NULL = promotion automatique, sans code à saisir',
    libelle             VARCHAR(120) NOT NULL,
    pays_id             TINYINT UNSIGNED NULL COMMENT 'NULL = tous les pays ouverts',
    type                ENUM('pourcentage','montant_fixe','livraison_offerte') NOT NULL,
    valeur              INT UNSIGNED NOT NULL DEFAULT 0,
    financeur           ENUM('plateforme','boutique','partage') NOT NULL DEFAULT 'plateforme',
    part_boutique_pct   DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'Si financeur = partage',
    boutique_id         BIGINT UNSIGNED NULL COMMENT 'Promotion propre à une boutique',
    categorie_id        INT UNSIGNED NULL,
    montant_minimum_cfa INT UNSIGNED NOT NULL DEFAULT 0,
    plafond_remise_cfa  INT UNSIGNED NULL,
    usages_max          INT UNSIGNED NULL,
    usages_max_par_client SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    usages_actuels      INT UNSIGNED NOT NULL DEFAULT 0,
    debut_le            DATETIME     NOT NULL,
    fin_le              DATETIME     NOT NULL,
    accepte_par_boutique_le DATETIME NULL COMMENT 'Obligatoire si la boutique finance',
    active              BOOLEAN      NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_promotions_code (code),
    KEY idx_promotions_periode (active, debut_le, fin_le)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE utilisations_promotion (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    promotion_id   INT UNSIGNED NOT NULL,
    commande_id    BIGINT UNSIGNED NOT NULL,
    utilisateur_id BIGINT UNSIGNED NULL,
    remise_cfa     INT UNSIGNED NOT NULL,
    cree_le        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_utilisation (promotion_id, commande_id),
    KEY idx_utilisation_client (promotion_id, utilisateur_id),
    CONSTRAINT fk_up_promotion FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE,
    CONSTRAINT fk_up_commande FOREIGN KEY (commande_id) REFERENCES commandes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  11. TRAÇABILITÉ PAR QR CODE
--  Inchangée sur le principe : deux identifiants de nature opposée.
--    code_lisible (02/08/2026/0026) — séquentiel, IDENTIFIE
--    jeton        (K7M2P9QRXW4T)    — aléatoire, AUTHENTIFIE
-- =====================================================================

CREATE TABLE lots_qr (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    produit_id       BIGINT UNSIGNED NOT NULL,
    variante_id      BIGINT UNSIGNED NULL COMMENT 'Une contenance donnée peut avoir son propre lot',
    boutique_id      BIGINT UNSIGNED NOT NULL,
    pays_id          TINYINT UNSIGNED NOT NULL,
    reference        VARCHAR(30)  NOT NULL,
    date_fabrication DATE         NOT NULL,
    date_expiration  DATE         NOT NULL,
    fabricant        VARCHAR(160) NOT NULL,
    numero_debut     INT UNSIGNED NOT NULL,
    quantite         INT UNSIGNED NOT NULL,
    largeur_numero   TINYINT UNSIGNED NOT NULL DEFAULT 4,
    description      TEXT         NULL,
    statut           ENUM('demande','refuse','genere','imprime','en_circulation','rappele')
                     NOT NULL DEFAULT 'genere',
    motif_refus      TEXT         NULL,
    demande_par_id   BIGINT UNSIGNED NULL,
    cree_par_id      BIGINT UNSIGNED NOT NULL,
    cree_le          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_lots_ref (reference),
    KEY idx_lots_produit (produit_id),
    CONSTRAINT fk_lots_produit FOREIGN KEY (produit_id) REFERENCES produits(id) ON DELETE RESTRICT,
    CONSTRAINT fk_lots_boutique FOREIGN KEY (boutique_id) REFERENCES boutiques(id) ON DELETE RESTRICT,
    CONSTRAINT fk_lots_pays FOREIGN KEY (pays_id) REFERENCES pays(id) ON DELETE RESTRICT,
    CONSTRAINT ck_lots_dates CHECK (date_expiration > date_fabrication),
    CONSTRAINT ck_lots_quantite CHECK (quantite >= 1 AND quantite <= 100000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE codes_qr (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lot_qr_id       BIGINT UNSIGNED NOT NULL,
    numero          INT UNSIGNED NOT NULL,
    code_lisible    VARCHAR(30)  NOT NULL COMMENT 'JJ/MM/AAAA/NNNN — imprimé en clair, IDENTIFIE',
    jeton           VARCHAR(16)  NOT NULL COMMENT 'Aléatoire, encodé dans le QR seul, AUTHENTIFIE',
    statut          ENUM('genere','imprime','active','desactive','rappele') NOT NULL DEFAULT 'genere',
    nb_scans        INT UNSIGNED NOT NULL DEFAULT 0,
    premier_scan_le DATETIME     NULL,
    dernier_scan_le DATETIME     NULL,
    cree_le         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_codes_lisible (code_lisible),
    UNIQUE KEY uk_codes_jeton (jeton),
    UNIQUE KEY uk_codes_lot_numero (lot_qr_id, numero),
    CONSTRAINT fk_codes_lot FOREIGN KEY (lot_qr_id) REFERENCES lots_qr(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scans_qr (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code_qr_id    BIGINT UNSIGNED NOT NULL,
    scanne_le     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_hachee     CHAR(64)     NULL COMMENT 'Jamais en clair : hachage salé',
    agent_hache   CHAR(64)     NULL,
    ville_estimee VARCHAR(80)  NULL,
    pays_estime   CHAR(2)      NULL,
    PRIMARY KEY (id),
    KEY idx_scans_code (code_qr_id, scanne_le),
    CONSTRAINT fk_scans_code FOREIGN KEY (code_qr_id) REFERENCES codes_qr(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  12. PARAMÉTRAGE
--  Les règles négociables vivent en base, par pays, et non dans le code.
-- =====================================================================
CREATE TABLE parametres (
    id        SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pays_id   TINYINT UNSIGNED NULL COMMENT 'NULL = valeur par défaut, tous pays',
    cle       VARCHAR(60)  NOT NULL,
    valeur    VARCHAR(255) NOT NULL,
    type      ENUM('entier','decimal','booleen','texte','json') NOT NULL DEFAULT 'texte',
    libelle   VARCHAR(160) NOT NULL,
    modifie_le DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_parametres (pays_id, cle),
    CONSTRAINT fk_parametres_pays FOREIGN KEY (pays_id) REFERENCES pays(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
