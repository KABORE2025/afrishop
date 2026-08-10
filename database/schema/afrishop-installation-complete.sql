-- =====================================================================
--  AFRISHOP — INSTALLATION COMPLÈTE (MySQL / MariaDB)
-- =====================================================================
--  CE FICHIER SUFFIT. Ne jouez PAS les fichiers `-delta` en plus :
--  ils sont déjà tous inclus ici.
--
--      mysql -u root -e "CREATE DATABASE afrishop
--            CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
--      mysql -u root afrishop < afrishop-installation-complete.sql
--
--  IMPORTEZ EN LIGNE DE COMMANDE, PAS PAR phpMyAdmin. phpMyAdmin
--  reconvertit le fichier selon son menu déroulant avant que MySQL ne le
--  voie, et double-encode les accents : « Thiès » devient « ThiÃ¨s », de
--  façon irréversible. En ligne de commande les octets passent tels
--  quels, et le `SET NAMES utf8mb4` ci-dessous fait le reste — vérifié
--  en important avec un client volontairement réglé en latin1.
--
--  Contenu : 72 tables, 80 clés étrangères, et les données de référence
--  (pays, villes, zones de livraison, catégories, taux de taxe, barème
--  de commission, motifs de liquidation, termes interdits, paramètres).
--  AUCUNE donnée de démonstration : ni boutique, ni produit, ni commande.
--
--  Contrôle après import :
--      SELECT COUNT(*) FROM information_schema.tables
--        WHERE table_schema='afrishop' AND table_type='BASE TABLE';   -- 72
--      SELECT nom, HEX(nom) FROM villes WHERE nom LIKE 'Thi%';
--        -- doit donner « Thiès » et 546869C3A873
--        -- si vous lisez C383C2A8, les accents sont double-encodés :
--        -- videz la base et réimportez en ligne de commande.
-- =====================================================================

SET NAMES utf8mb4;


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `attributs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `attributs` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `nom` varchar(60) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_attributs_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `attributs` DISABLE KEYS */;
/*!40000 ALTER TABLE `attributs` ENABLE KEYS */;
DROP TABLE IF EXISTS `autorisations_publication`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `autorisations_publication` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `produit_id` bigint(20) unsigned NOT NULL,
  `statut` enum('demande','accorde','refuse','revoque') NOT NULL DEFAULT 'demande',
  `motif` text DEFAULT NULL COMMENT 'Envoyé au vendeur. Un refus sans motif se re-soumet à l''identique',
  `termes_signales` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Ce que le détecteur a trouvé, gardé pour l''audit — pas pour décider' CHECK (json_valid(`termes_signales`)),
  `decide_par_utilisateur_id` bigint(20) unsigned DEFAULT NULL,
  `demande_le` datetime NOT NULL,
  `decide_le` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_autorisation_produit` (`produit_id`),
  KEY `ix_autorisation_file` (`statut`,`demande_le`),
  CONSTRAINT `fk_autorisation_produit` FOREIGN KEY (`produit_id`) REFERENCES `produits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `autorisations_publication` DISABLE KEYS */;
/*!40000 ALTER TABLE `autorisations_publication` ENABLE KEYS */;
DROP TABLE IF EXISTS `avis`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `avis` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sous_commande_id` bigint(20) unsigned NOT NULL,
  `produit_id` bigint(20) unsigned DEFAULT NULL,
  `boutique_id` bigint(20) unsigned NOT NULL,
  `utilisateur_id` bigint(20) unsigned DEFAULT NULL,
  `auteur_affiche` varchar(80) NOT NULL COMMENT 'Prénom + initiale : « Aminata S. »',
  `note` tinyint(3) unsigned NOT NULL,
  `titre` varchar(120) DEFAULT NULL,
  `commentaire` text DEFAULT NULL,
  `statut` enum('en_attente','publie','rejete') NOT NULL DEFAULT 'en_attente',
  `motif_rejet` varchar(255) DEFAULT NULL,
  `reponse_boutique` text DEFAULT NULL,
  `reponse_le` datetime DEFAULT NULL,
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_avis` (`sous_commande_id`,`produit_id`),
  KEY `idx_avis_produit` (`produit_id`,`statut`),
  KEY `idx_avis_boutique` (`boutique_id`,`statut`),
  CONSTRAINT `fk_avis_boutique` FOREIGN KEY (`boutique_id`) REFERENCES `boutiques` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_avis_sc` FOREIGN KEY (`sous_commande_id`) REFERENCES `sous_commandes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_avis_note` CHECK (`note` between 1 and 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `avis` DISABLE KEYS */;
/*!40000 ALTER TABLE `avis` ENABLE KEYS */;
DROP TABLE IF EXISTS `bareme_commission`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bareme_commission` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `applique_a` varchar(20) NOT NULL DEFAULT 'service',
  `plafond_cfa` bigint(20) unsigned DEFAULT NULL COMMENT 'NULL = tranche supérieure, sans plafond',
  `taux_pct` decimal(5,2) NOT NULL,
  `ordre` tinyint(3) unsigned NOT NULL,
  `en_vigueur_du` date NOT NULL,
  `en_vigueur_au` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_bareme` (`applique_a`,`ordre`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `bareme_commission` DISABLE KEYS */;
INSERT INTO `bareme_commission` (`id`, `applique_a`, `plafond_cfa`, `taux_pct`, `ordre`, `en_vigueur_du`, `en_vigueur_au`) VALUES (1,'service',500000,10.00,1,'2026-01-01',NULL),
(2,'service',5000000,5.00,2,'2026-01-01',NULL),
(3,'service',NULL,1.00,3,'2026-01-01',NULL);
/*!40000 ALTER TABLE `bareme_commission` ENABLE KEYS */;
DROP TABLE IF EXISTS `boutiques`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `boutiques` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `utilisateur_id` bigint(20) unsigned NOT NULL,
  `pays_id` tinyint(3) unsigned NOT NULL,
  `pays_origine_id` tinyint(3) unsigned DEFAULT NULL COMMENT 'Provenance des produits — affiché au client. NULL = même pays que l''exploitation',
  `ville_id` int(10) unsigned DEFAULT NULL,
  `code` varchar(12) NOT NULL COMMENT 'BF-V001 — préfixé par le pays',
  `nom` varchar(120) NOT NULL,
  `slug` varchar(140) NOT NULL,
  `emoji` varchar(8) DEFAULT NULL,
  `logo_media_id` bigint(20) unsigned DEFAULT NULL,
  `description` text DEFAULT NULL,
  `telephone` varchar(20) NOT NULL,
  `taux_commission` decimal(5,2) NOT NULL DEFAULT 10.00,
  `taux_commission_comptoir` decimal(5,2) NOT NULL DEFAULT 0.00,
  `statut` enum('candidature','actif','suspendu','ferme') NOT NULL DEFAULT 'candidature',
  `est_regie` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Boutique tenue par Afrishop lui-même : commission et retenue nulles, hors grand livre, exclue du comparateur et du tri',
  `niveau` enum('nouveau','verifie','premium') NOT NULL DEFAULT 'nouveau',
  `type_boutique` enum('artisan','revendeur','importateur','cooperative') NOT NULL DEFAULT 'artisan',
  `vend_en_ligne` tinyint(1) NOT NULL DEFAULT 1,
  `vend_au_comptoir` tinyint(1) NOT NULL DEFAULT 1,
  `numero_fiscal` varchar(40) DEFAULT NULL,
  `numero_fiscal_verifie_le` date DEFAULT NULL,
  `regime_fiscal` enum('non_immatricule','forfaitaire','simplifie','reel') NOT NULL DEFAULT 'non_immatricule' COMMENT 'forfaitaire = CME/CGU/TPU/Entreprenant/impôt synthétique selon le pays',
  `assujetti_tva` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'La plupart des artisans ne le sont pas : leurs ventes sont hors champ TVA',
  `registre_commerce` varchar(40) DEFAULT NULL,
  `operateur_paiement_id` smallint(5) unsigned DEFAULT NULL,
  `paiement_numero` varchar(40) DEFAULT NULL,
  `paiement_titulaire` varchar(120) DEFAULT NULL,
  `paiement_verifie_le` datetime DEFAULT NULL COMMENT 'Vérifié par micro-virement ou appel API',
  `fermee_du` date DEFAULT NULL,
  `fermee_au` date DEFAULT NULL,
  `message_fermeture` varchar(255) DEFAULT NULL COMMENT 'Congés, deuil, rupture générale',
  `valide_le` datetime DEFAULT NULL,
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  `modifie_le` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `supprime_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_boutiques_code` (`code`),
  UNIQUE KEY `uk_boutiques_slug` (`slug`),
  KEY `idx_boutiques_statut` (`pays_id`,`statut`),
  KEY `fk_boutiques_utilisateur` (`utilisateur_id`),
  KEY `fk_boutiques_ville` (`ville_id`),
  KEY `fk_boutiques_operateur` (`operateur_paiement_id`),
  KEY `fk_boutiques_pays_origine` (`pays_origine_id`),
  KEY `ix_boutique_regie` (`est_regie`),
  CONSTRAINT `fk_boutiques_operateur` FOREIGN KEY (`operateur_paiement_id`) REFERENCES `operateurs_paiement` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_boutiques_pays` FOREIGN KEY (`pays_id`) REFERENCES `pays` (`id`),
  CONSTRAINT `fk_boutiques_pays_origine` FOREIGN KEY (`pays_origine_id`) REFERENCES `pays` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_boutiques_utilisateur` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`),
  CONSTRAINT `fk_boutiques_ville` FOREIGN KEY (`ville_id`) REFERENCES `villes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ck_boutiques_commission` CHECK (`taux_commission` >= 0 and `taux_commission` <= 50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `boutiques` DISABLE KEYS */;
/*!40000 ALTER TABLE `boutiques` ENABLE KEYS */;
DROP TABLE IF EXISTS `candidatures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `candidatures` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pays_id` tinyint(3) unsigned NOT NULL,
  `nom_boutique` varchar(120) NOT NULL,
  `responsable` varchar(120) NOT NULL,
  `telephone` varchar(20) NOT NULL,
  `ville_id` int(10) unsigned DEFAULT NULL,
  `categorie_id` int(10) unsigned DEFAULT NULL,
  `description` text NOT NULL,
  `statut` enum('en_attente','en_verification','acceptee','refusee') NOT NULL DEFAULT 'en_attente',
  `motif_refus` text DEFAULT NULL,
  `boutique_id` bigint(20) unsigned DEFAULT NULL,
  `traite_par_id` bigint(20) unsigned DEFAULT NULL,
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  `traite_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_candidatures_statut` (`statut`),
  KEY `fk_candidatures_pays` (`pays_id`),
  CONSTRAINT `fk_candidatures_pays` FOREIGN KEY (`pays_id`) REFERENCES `pays` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `candidatures` DISABLE KEYS */;
/*!40000 ALTER TABLE `candidatures` ENABLE KEYS */;
DROP TABLE IF EXISTS `cantonnement_journalier`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cantonnement_journalier` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `date_arrete` date NOT NULL,
  `pays_id` tinyint(3) unsigned NOT NULL,
  `solde_banque_cfa` bigint(20) NOT NULL COMMENT 'Relevé du compte de cantonnement',
  `solde_grand_livre_cfa` bigint(20) NOT NULL COMMENT 'Somme des dettes envers les boutiques',
  `ecart_cfa` bigint(20) NOT NULL DEFAULT 0,
  `statut` enum('conforme','ecart_a_expliquer','regularise') NOT NULL DEFAULT 'conforme',
  `commentaire` text DEFAULT NULL,
  `valide_par_id` bigint(20) unsigned DEFAULT NULL,
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cantonnement` (`date_arrete`,`pays_id`),
  KEY `fk_cant_pays` (`pays_id`),
  CONSTRAINT `fk_cant_pays` FOREIGN KEY (`pays_id`) REFERENCES `pays` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `cantonnement_journalier` DISABLE KEYS */;
/*!40000 ALTER TABLE `cantonnement_journalier` ENABLE KEYS */;
DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int(10) unsigned DEFAULT NULL COMMENT 'Arborescence sur deux niveaux maximum en pratique',
  `nom` varchar(80) NOT NULL,
  `reservee` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Une fiche de cette catégorie n''est visible qu''après autorisation',
  `note_reserve` varchar(300) DEFAULT NULL,
  `slug` varchar(90) NOT NULL,
  `emoji` varchar(8) DEFAULT NULL,
  `code_taxe` varchar(20) NOT NULL DEFAULT 'tva_normal',
  `ordre` smallint(6) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_categories_slug` (`slug`),
  KEY `idx_categories_parent` (`parent_id`),
  CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` (`id`, `parent_id`, `nom`, `reservee`, `note_reserve`, `slug`, `emoji`, `code_taxe`, `ordre`, `active`) VALUES (1,NULL,'Produits naturels',1,'Plantes, écorces, poudres et préparations traditionnelles. Chaque fiche est relue avant publication : aucune allégation thérapeutique n\'est acceptée.','produits-naturels','🌿','tva_normal',90,1),
(2,NULL,'Beauté',0,NULL,'beaute','💧','tva_normal',10,1),
(3,NULL,'Artisanat',0,NULL,'artisanat','🪡','tva_normal',20,1),
(4,NULL,'Textile',0,NULL,'textile','🧵','tva_normal',30,1),
(5,NULL,'Maison',0,NULL,'maison','🏠','tva_normal',40,1),
(6,NULL,'Épicerie',0,NULL,'epicerie','🌾','tva_reduit',50,1),
(7,NULL,'Bijoux',0,NULL,'bijoux','📿','tva_normal',60,1),
(8,NULL,'Services',0,NULL,'services','🔧','tva_normal',70,1);
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
DROP TABLE IF EXISTS `codes_qr`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `codes_qr` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lot_qr_id` bigint(20) unsigned NOT NULL,
  `numero` int(10) unsigned NOT NULL,
  `code_lisible` varchar(30) NOT NULL COMMENT 'JJ/MM/AAAA/NNNN — imprimé en clair, IDENTIFIE',
  `jeton` varchar(16) NOT NULL COMMENT 'Aléatoire, encodé dans le QR seul, AUTHENTIFIE',
  `statut` enum('genere','imprime','active','desactive','rappele') NOT NULL DEFAULT 'genere',
  `nb_scans` int(10) unsigned NOT NULL DEFAULT 0,
  `premier_scan_le` datetime DEFAULT NULL,
  `dernier_scan_le` datetime DEFAULT NULL,
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_codes_lisible` (`code_lisible`),
  UNIQUE KEY `uk_codes_jeton` (`jeton`),
  UNIQUE KEY `uk_codes_lot_numero` (`lot_qr_id`,`numero`),
  CONSTRAINT `fk_codes_lot` FOREIGN KEY (`lot_qr_id`) REFERENCES `lots_qr` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `codes_qr` DISABLE KEYS */;
/*!40000 ALTER TABLE `codes_qr` ENABLE KEYS */;
DROP TABLE IF EXISTS `commandes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `commandes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reference` varchar(24) NOT NULL COMMENT 'BF-CMD-2026-000001',
  `pays_id` tinyint(3) unsigned NOT NULL,
  `pays_livraison_id` tinyint(3) unsigned DEFAULT NULL,
  `utilisateur_id` bigint(20) unsigned DEFAULT NULL,
  `client_nom` varchar(120) NOT NULL,
  `client_telephone` varchar(20) NOT NULL,
  `paye_par_nom` varchar(120) DEFAULT NULL,
  `paye_par_telephone` varchar(20) DEFAULT NULL,
  `ville_id` int(10) unsigned DEFAULT NULL,
  `quartier` varchar(120) DEFAULT NULL,
  `adresse_ligne1` varchar(160) DEFAULT NULL,
  `adresse_ligne2` varchar(160) DEFAULT NULL,
  `code_postal` varchar(20) DEFAULT NULL,
  `ville_texte` varchar(120) DEFAULT NULL COMMENT 'Ville libre quand elle n''est pas au référentiel',
  `destinataire_nom` varchar(120) DEFAULT NULL,
  `destinataire_telephone` varchar(20) DEFAULT NULL,
  `repere` varchar(255) DEFAULT NULL COMMENT 'Indispensable : pas d''adressage postal fiable',
  `mode_livraison` enum('domicile','point_relais','retrait_boutique','remise_transitaire','expedition_sur_devis','emporte') NOT NULL DEFAULT 'domicile' COMMENT 'emporte = vente au comptoir, le client repart avec',
  `point_relais_id` int(10) unsigned DEFAULT NULL,
  `mode_paiement` enum('mobile_money','carte','virement','especes_livraison','especes_comptoir','mobile_money_comptoir') NOT NULL,
  `operateur_paiement_id` smallint(5) unsigned DEFAULT NULL,
  `statut_paiement` enum('attente','autorise','encaisse','partiel','echoue','rembourse') NOT NULL DEFAULT 'attente',
  `statut` enum('brouillon','confirmee','en_preparation','partiellement_livree','livree','retractee','annulee') NOT NULL DEFAULT 'brouillon',
  `total_articles_ttc_cfa` int(10) unsigned NOT NULL DEFAULT 0,
  `total_tva_cfa` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'Ventilation informative : la TVA n''est due que sur les ventes des assujettis',
  `total_frais_livraison_cfa` int(10) unsigned NOT NULL DEFAULT 0,
  `total_remise_cfa` int(10) unsigned NOT NULL DEFAULT 0,
  `total_a_payer_cfa` int(10) unsigned NOT NULL DEFAULT 0,
  `promotion_id` int(10) unsigned DEFAULT NULL,
  `langue` char(5) NOT NULL DEFAULT 'fr',
  `canal` enum('web','mobile','telephone','agent','comptoir','whatsapp') NOT NULL DEFAULT 'web',
  `cgv_version` varchar(20) DEFAULT NULL,
  `cgv_acceptees_le` datetime DEFAULT NULL,
  `retractation_avant` date DEFAULT NULL COMMENT 'Calculé selon le pays à la livraison',
  `confirmee_le` datetime DEFAULT NULL,
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  `modifie_le` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `paye_par_lien` varchar(60) DEFAULT NULL COMMENT 'parent, ami, mandataire, coursier',
  `devise_affichage` char(3) DEFAULT NULL,
  `taux_affichage` decimal(14,6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_commandes_reference` (`reference`),
  KEY `idx_commandes_telephone` (`client_telephone`),
  KEY `idx_commandes_statut` (`pays_id`,`statut`),
  KEY `fk_commandes_utilisateur` (`utilisateur_id`),
  KEY `fk_commandes_relais` (`point_relais_id`),
  KEY `fk_commandes_pays_livraison` (`pays_livraison_id`),
  CONSTRAINT `fk_commandes_pays` FOREIGN KEY (`pays_id`) REFERENCES `pays` (`id`),
  CONSTRAINT `fk_commandes_pays_livraison` FOREIGN KEY (`pays_livraison_id`) REFERENCES `pays` (`id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_commandes_relais` FOREIGN KEY (`point_relais_id`) REFERENCES `points_relais` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_commandes_utilisateur` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `commandes` DISABLE KEYS */;
/*!40000 ALTER TABLE `commandes` ENABLE KEYS */;
DROP TABLE IF EXISTS `consentements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `consentements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `utilisateur_id` bigint(20) unsigned DEFAULT NULL COMMENT 'NULL si consentement anonyme avant création de compte',
  `telephone` varchar(20) DEFAULT NULL,
  `finalite` enum('cgu','confidentialite','prospection_sms','prospection_email','cookies') NOT NULL,
  `version_texte` varchar(20) NOT NULL COMMENT 'Version du document affiché au moment du clic',
  `canal` enum('web','mobile','sms','papier','telephone') NOT NULL,
  `accorde` tinyint(1) NOT NULL,
  `ip_hachee` char(64) DEFAULT NULL,
  `donne_le` datetime NOT NULL DEFAULT current_timestamp(),
  `retire_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_consentements` (`utilisateur_id`,`finalite`,`donne_le`),
  CONSTRAINT `fk_consentements_utilisateur` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `consentements` DISABLE KEYS */;
/*!40000 ALTER TABLE `consentements` ENABLE KEYS */;
DROP TABLE IF EXISTS `demandes_devis`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `demandes_devis` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reference` varchar(24) NOT NULL,
  `service_id` bigint(20) unsigned NOT NULL,
  `boutique_id` bigint(20) unsigned NOT NULL,
  `client_nom` varchar(120) NOT NULL,
  `client_telephone` varchar(24) NOT NULL,
  `localisation` varchar(160) DEFAULT NULL,
  `budget_envisage_cfa` bigint(20) unsigned DEFAULT NULL,
  `besoin` text NOT NULL,
  `echeance` enum('immediat','1_3_mois','3_6_mois','renseignement') NOT NULL,
  `statut` enum('demande','rappele','visite','chiffre','accepte','refuse','sans_suite') NOT NULL DEFAULT 'demande',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_demande_ref` (`reference`),
  KEY `ix_demande_boutique` (`boutique_id`,`statut`),
  KEY `fk_demande_service` (`service_id`),
  CONSTRAINT `fk_demande_boutique` FOREIGN KEY (`boutique_id`) REFERENCES `boutiques` (`id`),
  CONSTRAINT `fk_demande_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Une demande sans suite est une vente perdue qu''on n''aurait jamais vue sans cette table';
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `demandes_devis` DISABLE KEYS */;
/*!40000 ALTER TABLE `demandes_devis` ENABLE KEYS */;
DROP TABLE IF EXISTS `demandes_droits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `demandes_droits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `utilisateur_id` bigint(20) unsigned DEFAULT NULL,
  `telephone` varchar(20) NOT NULL,
  `type` enum('acces','rectification','effacement','opposition','portabilite') NOT NULL,
  `detail` text DEFAULT NULL,
  `statut` enum('recue','en_cours','satisfaite','refusee') NOT NULL DEFAULT 'recue',
  `motif_refus` text DEFAULT NULL,
  `traite_par_id` bigint(20) unsigned DEFAULT NULL,
  `recue_le` datetime NOT NULL DEFAULT current_timestamp(),
  `echeance` date NOT NULL COMMENT 'recue_le + 2 mois',
  `traitee_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_demandes_statut` (`statut`,`echeance`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `demandes_droits` DISABLE KEYS */;
/*!40000 ALTER TABLE `demandes_droits` ENABLE KEYS */;
DROP TABLE IF EXISTS `demandes_expedition`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `demandes_expedition` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `commande_id` bigint(20) unsigned NOT NULL,
  `reference` varchar(28) NOT NULL,
  `pays_destination_id` tinyint(3) unsigned NOT NULL,
  `adresse_destination` text NOT NULL,
  `delai_souhaite` enum('economique','standard','express') NOT NULL DEFAULT 'standard',
  `commentaire_client` text DEFAULT NULL,
  `poids_estime_g` int(10) unsigned DEFAULT NULL,
  `dimensions_cm` varchar(40) DEFAULT NULL COMMENT 'L x l x h du colis groupé',
  `transporteur_propose` varchar(80) DEFAULT NULL,
  `delai_estime_jours` smallint(5) unsigned DEFAULT NULL,
  `montant_devis_cfa` int(10) unsigned DEFAULT NULL,
  `devise_affichage` char(3) DEFAULT NULL,
  `montant_affiche` decimal(12,2) DEFAULT NULL,
  `valable_jusqu_au` date DEFAULT NULL,
  `statut` enum('demande','en_evaluation','propose','accepte','refuse','expire','expedie') NOT NULL DEFAULT 'demande',
  `motif_refus` text DEFAULT NULL,
  `transporteur_reel` varchar(80) DEFAULT NULL,
  `numero_suivi` varchar(80) DEFAULT NULL,
  `expedie_le` datetime DEFAULT NULL,
  `droits_a_la_charge_du_client` tinyint(1) NOT NULL DEFAULT 1,
  `traite_par_id` bigint(20) unsigned DEFAULT NULL,
  `demande_le` datetime NOT NULL DEFAULT current_timestamp(),
  `propose_le` datetime DEFAULT NULL,
  `repondu_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_demandes_exp_ref` (`reference`),
  KEY `idx_demandes_exp_statut` (`statut`,`demande_le`),
  KEY `fk_demexp_commande` (`commande_id`),
  KEY `fk_demexp_pays` (`pays_destination_id`),
  CONSTRAINT `fk_demexp_commande` FOREIGN KEY (`commande_id`) REFERENCES `commandes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_demexp_pays` FOREIGN KEY (`pays_destination_id`) REFERENCES `pays` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `demandes_expedition` DISABLE KEYS */;
/*!40000 ALTER TABLE `demandes_expedition` ENABLE KEYS */;
DROP TABLE IF EXISTS `devis`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `devis` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reference` varchar(24) NOT NULL,
  `demande_id` bigint(20) unsigned NOT NULL,
  `montant_cfa` bigint(20) unsigned NOT NULL,
  `commission_cfa` bigint(20) unsigned NOT NULL COMMENT 'Calculée au barème dégressif et FIGÉE ici : si le barème change demain, un devis déjà émis ne doit pas changer de commission',
  `taux_moyen_pct` decimal(5,2) NOT NULL COMMENT 'Taux réellement supporté — le seul chiffre parlant pour un vendeur',
  `valable_jusqu_au` date NOT NULL COMMENT 'Un devis sans validité engage indéfiniment sur des prix de matériaux qui, eux, bougent',
  `detail` text NOT NULL,
  `statut` enum('emis','accepte','refuse','expire') NOT NULL DEFAULT 'emis',
  `accepte_le` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_devis_ref` (`reference`),
  KEY `fk_devis_demande` (`demande_id`),
  CONSTRAINT `fk_devis_demande` FOREIGN KEY (`demande_id`) REFERENCES `demandes_devis` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `devis` DISABLE KEYS */;
/*!40000 ALTER TABLE `devis` ENABLE KEYS */;
DROP TABLE IF EXISTS `documents_boutique`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `documents_boutique` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `boutique_id` bigint(20) unsigned NOT NULL,
  `type` enum('piece_identite','registre_commerce','attestation_fiscale','rib','photo_local','autre') NOT NULL,
  `chemin` varchar(255) NOT NULL COMMENT 'Stockage privé, jamais servi directement',
  `statut` enum('en_attente','valide','refuse') NOT NULL DEFAULT 'en_attente',
  `motif_refus` text DEFAULT NULL,
  `expire_le` date DEFAULT NULL,
  `depose_le` datetime NOT NULL DEFAULT current_timestamp(),
  `verifie_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_documents_boutique` (`boutique_id`,`type`),
  CONSTRAINT `fk_documents_boutique` FOREIGN KEY (`boutique_id`) REFERENCES `boutiques` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `documents_boutique` DISABLE KEYS */;
/*!40000 ALTER TABLE `documents_boutique` ENABLE KEYS */;
DROP TABLE IF EXISTS `encaissements_especes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `encaissements_especes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `expedition_id` bigint(20) unsigned NOT NULL,
  `livreur_id` bigint(20) unsigned DEFAULT NULL,
  `transporteur_id` smallint(5) unsigned DEFAULT NULL,
  `montant_du_cfa` int(10) unsigned NOT NULL,
  `montant_percu_cfa` int(10) unsigned NOT NULL DEFAULT 0,
  `statut` enum('a_encaisser','encaisse','remis','manquant','refuse_client') NOT NULL DEFAULT 'a_encaisser',
  `remis_le` datetime DEFAULT NULL COMMENT 'Date de remise des fonds à la plateforme',
  `bordereau_ref` varchar(60) DEFAULT NULL,
  `ecart_commentaire` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_especes_statut` (`statut`,`remis_le`),
  KEY `idx_especes_livreur` (`livreur_id`,`statut`),
  KEY `fk_especes_expedition` (`expedition_id`),
  CONSTRAINT `fk_especes_expedition` FOREIGN KEY (`expedition_id`) REFERENCES `expeditions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `encaissements_especes` DISABLE KEYS */;
/*!40000 ALTER TABLE `encaissements_especes` ENABLE KEYS */;
DROP TABLE IF EXISTS `evenements_commande`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `evenements_commande` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sous_commande_id` bigint(20) unsigned NOT NULL,
  `type` varchar(48) NOT NULL,
  `donnees` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`donnees`)),
  `auteur_id` bigint(20) unsigned DEFAULT NULL,
  `auteur_type` enum('systeme','client','vendeur','livreur','agent','admin') NOT NULL DEFAULT 'systeme',
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_evenements` (`sous_commande_id`,`cree_le`),
  CONSTRAINT `fk_ev_sc` FOREIGN KEY (`sous_commande_id`) REFERENCES `sous_commandes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `evenements_commande` DISABLE KEYS */;
/*!40000 ALTER TABLE `evenements_commande` ENABLE KEYS */;
DROP TABLE IF EXISTS `expeditions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `expeditions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sous_commande_id` bigint(20) unsigned NOT NULL,
  `transporteur_id` smallint(5) unsigned DEFAULT NULL,
  `livreur_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Utilisateur de rôle livreur',
  `code_suivi` varchar(60) DEFAULT NULL,
  `code_livraison` char(6) DEFAULT NULL COMMENT 'Code à usage unique envoyé au client par SMS',
  `code_valide_le` datetime DEFAULT NULL,
  `preuve_media_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Photo du colis remis',
  `signature_nom` varchar(120) DEFAULT NULL COMMENT 'Nom de la personne ayant réceptionné',
  `tentatives` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `statut` enum('prevue','en_cours','livree','echouee','retour_expediteur') NOT NULL DEFAULT 'prevue',
  `motif_echec` varchar(255) DEFAULT NULL,
  `expedie_le` datetime DEFAULT NULL,
  `livre_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_expeditions_sc` (`sous_commande_id`),
  KEY `fk_exp_transporteur` (`transporteur_id`),
  CONSTRAINT `fk_exp_sc` FOREIGN KEY (`sous_commande_id`) REFERENCES `sous_commandes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_exp_transporteur` FOREIGN KEY (`transporteur_id`) REFERENCES `transporteurs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `expeditions` DISABLE KEYS */;
/*!40000 ALTER TABLE `expeditions` ENABLE KEYS */;
DROP TABLE IF EXISTS `factures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `factures` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('vente_client','commission_boutique','avoir') NOT NULL,
  `pays_id` tinyint(3) unsigned NOT NULL,
  `numero` varchar(40) NOT NULL COMMENT 'Séquence continue et sans trou, par pays et par type',
  `emetteur_type` enum('boutique','plateforme') NOT NULL,
  `emetteur_nom` varchar(160) NOT NULL,
  `emetteur_numero_fiscal` varchar(40) DEFAULT NULL,
  `destinataire_nom` varchar(160) NOT NULL,
  `destinataire_numero_fiscal` varchar(40) DEFAULT NULL,
  `destinataire_telephone` varchar(20) DEFAULT NULL,
  `sous_commande_id` bigint(20) unsigned DEFAULT NULL,
  `reversement_id` bigint(20) unsigned DEFAULT NULL,
  `facture_origine_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Pour un avoir : la facture annulée',
  `montant_ht_cfa` int(10) unsigned NOT NULL DEFAULT 0,
  `montant_tva_cfa` int(10) unsigned NOT NULL DEFAULT 0,
  `taux_tva_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `montant_ttc_cfa` int(10) unsigned NOT NULL,
  `certification_requise` tinyint(1) NOT NULL DEFAULT 0,
  `certification_ref` varchar(120) DEFAULT NULL COMMENT 'Identifiant renvoyé par e-MECeF / FEC / FNE / e-SECeF',
  `certification_qr` varchar(255) DEFAULT NULL,
  `certifiee_le` datetime DEFAULT NULL,
  `certification_erreur` varchar(255) DEFAULT NULL,
  `chemin_pdf` varchar(255) DEFAULT NULL,
  `emise_le` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_factures_numero` (`pays_id`,`type`,`numero`),
  KEY `idx_factures_sc` (`sous_commande_id`),
  CONSTRAINT `fk_factures_pays` FOREIGN KEY (`pays_id`) REFERENCES `pays` (`id`),
  CONSTRAINT `fk_factures_sc` FOREIGN KEY (`sous_commande_id`) REFERENCES `sous_commandes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `factures` DISABLE KEYS */;
/*!40000 ALTER TABLE `factures` ENABLE KEYS */;
DROP TABLE IF EXISTS `familles_service`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `familles_service` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `libelle` varchar(80) NOT NULL,
  `devis_obligatoire` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Un chantier ne se vend pas à prix catalogue',
  `profession_reglementee` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Si vrai : annuaire seulement, aucun encaissement par la plateforme',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_famille_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `familles_service` DISABLE KEYS */;
INSERT INTO `familles_service` (`id`, `code`, `libelle`, `devis_obligatoire`, `profession_reglementee`) VALUES (1,'btp','BTP et construction',1,0),
(2,'logiciel','Génie logiciel',0,0),
(3,'formation','Formation',0,0),
(4,'juridique','Juridique et notarial',1,1),
(5,'autre','Autres prestations',0,0);
/*!40000 ALTER TABLE `familles_service` ENABLE KEYS */;
DROP TABLE IF EXISTS `gabarits_notification`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `gabarits_notification` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(60) NOT NULL COMMENT 'commande_confirmee, colis_expedie, code_livraison…',
  `canal` enum('sms','push','email','whatsapp') NOT NULL,
  `langue` char(5) NOT NULL DEFAULT 'fr',
  `sujet` varchar(160) DEFAULT NULL,
  `corps` text NOT NULL COMMENT 'Variables entre accolades : {client_nom}, {reference}',
  `longueur_max` smallint(5) unsigned DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_gabarits` (`code`,`canal`,`langue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `gabarits_notification` DISABLE KEYS */;
/*!40000 ALTER TABLE `gabarits_notification` ENABLE KEYS */;
DROP TABLE IF EXISTS `jalons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `jalons` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `devis_id` bigint(20) unsigned NOT NULL,
  `ordre` tinyint(3) unsigned NOT NULL,
  `libelle` varchar(120) NOT NULL,
  `pourcentage` tinyint(3) unsigned NOT NULL,
  `montant_cfa` bigint(20) unsigned NOT NULL,
  `statut` enum('a_venir','en_cours','a_valider','valide','conteste') NOT NULL DEFAULT 'a_venir',
  `etat_fonds` enum('non_appele','attente_encaissement','sequestre','reverse','rembourse','hors_plateforme') NOT NULL DEFAULT 'non_appele' COMMENT 'hors_plateforme = tranche au-dessus du plafond, reglee en direct',
  `appele_le` datetime DEFAULT NULL,
  `valide_le` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_jalon` (`devis_id`,`ordre`),
  KEY `ix_jalon_statut` (`statut`,`etat_fonds`),
  CONSTRAINT `fk_jalon_devis` FOREIGN KEY (`devis_id`) REFERENCES `devis` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `jalons` DISABLE KEYS */;
/*!40000 ALTER TABLE `jalons` ENABLE KEYS */;
DROP TABLE IF EXISTS `jalons_type`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `jalons_type` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `service_id` bigint(20) unsigned NOT NULL,
  `ordre` tinyint(3) unsigned NOT NULL,
  `libelle` varchar(120) NOT NULL,
  `pourcentage` tinyint(3) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_jalon_type` (`service_id`,`ordre`),
  CONSTRAINT `fk_jalon_type_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Trame par défaut. Le devis accepté en fera une copie figée : modifier la trame ne doit pas redécouper un chantier en cours';
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `jalons_type` DISABLE KEYS */;
/*!40000 ALTER TABLE `jalons_type` ENABLE KEYS */;
DROP TABLE IF EXISTS `journal_acces_donnees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `journal_acces_donnees` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` bigint(20) unsigned NOT NULL,
  `personne_type` varchar(30) NOT NULL COMMENT 'utilisateur, commande, boutique',
  `personne_id` bigint(20) unsigned NOT NULL,
  `action` varchar(40) NOT NULL COMMENT 'consultation, export, modification',
  `motif` varchar(255) DEFAULT NULL,
  `ip_hachee` char(64) DEFAULT NULL,
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_journal_acces` (`personne_type`,`personne_id`,`cree_le`),
  KEY `idx_journal_agent` (`agent_id`,`cree_le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `journal_acces_donnees` DISABLE KEYS */;
/*!40000 ALTER TABLE `journal_acces_donnees` ENABLE KEYS */;
DROP TABLE IF EXISTS `journal_administration`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `journal_administration` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `cible_type` varchar(40) NOT NULL,
  `cible_id` bigint(20) unsigned DEFAULT NULL,
  `avant` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`avant`)),
  `apres` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`apres`)),
  `motif` text DEFAULT NULL,
  `ip_hachee` char(64) DEFAULT NULL,
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_journal_admin` (`cible_type`,`cible_id`,`cree_le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `journal_administration` DISABLE KEYS */;
/*!40000 ALTER TABLE `journal_administration` ENABLE KEYS */;
DROP TABLE IF EXISTS `lignes_commande`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `lignes_commande` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sous_commande_id` bigint(20) unsigned NOT NULL,
  `variante_id` bigint(20) unsigned DEFAULT NULL COMMENT 'NULL si la variante a été supprimée depuis',
  `sku` varchar(40) NOT NULL,
  `nom_produit` varchar(160) NOT NULL,
  `libelle_variante` varchar(120) NOT NULL,
  `prix_unitaire_ttc_cfa` int(10) unsigned NOT NULL,
  `taux_tva_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `quantite` smallint(5) unsigned NOT NULL,
  `total_ttc_cfa` int(10) unsigned NOT NULL,
  `total_tva_cfa` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_lignes_sc` (`sous_commande_id`),
  KEY `fk_lignes_variante` (`variante_id`),
  CONSTRAINT `fk_lignes_sc` FOREIGN KEY (`sous_commande_id`) REFERENCES `sous_commandes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lignes_variante` FOREIGN KEY (`variante_id`) REFERENCES `variantes_produit` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ck_lignes_quantite` CHECK (`quantite` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `lignes_commande` DISABLE KEYS */;
/*!40000 ALTER TABLE `lignes_commande` ENABLE KEYS */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_ligne_commande_mta BEFORE INSERT ON lignes_commande
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
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `lignes_panier`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `lignes_panier` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `panier_id` bigint(20) unsigned NOT NULL,
  `variante_id` bigint(20) unsigned NOT NULL,
  `quantite` smallint(5) unsigned NOT NULL,
  `ajoute_le` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_lignes_panier` (`panier_id`,`variante_id`),
  KEY `fk_lp_variante` (`variante_id`),
  CONSTRAINT `fk_lp_panier` FOREIGN KEY (`panier_id`) REFERENCES `paniers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lp_variante` FOREIGN KEY (`variante_id`) REFERENCES `variantes_produit` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `lignes_panier` DISABLE KEYS */;
/*!40000 ALTER TABLE `lignes_panier` ENABLE KEYS */;
DROP TABLE IF EXISTS `lignes_retour`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `lignes_retour` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `retour_id` bigint(20) unsigned NOT NULL,
  `ligne_commande_id` bigint(20) unsigned NOT NULL,
  `quantite` smallint(5) unsigned NOT NULL,
  `etat_constate` enum('neuf','ouvert','abime','incomplet') DEFAULT NULL COMMENT 'Renseigné à la réception',
  PRIMARY KEY (`id`),
  KEY `fk_lr_retour` (`retour_id`),
  KEY `fk_lr_ligne` (`ligne_commande_id`),
  CONSTRAINT `fk_lr_ligne` FOREIGN KEY (`ligne_commande_id`) REFERENCES `lignes_commande` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lr_retour` FOREIGN KEY (`retour_id`) REFERENCES `retours` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `lignes_retour` DISABLE KEYS */;
/*!40000 ALTER TABLE `lignes_retour` ENABLE KEYS */;
DROP TABLE IF EXISTS `liquidations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `liquidations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `variante_id` bigint(20) unsigned NOT NULL,
  `motif_id` int(10) unsigned NOT NULL,
  `prix_liquide_cfa` int(10) unsigned NOT NULL COMMENT 'Prix pendant la liquidation. Entier : le franc CFA n''a pas de sous-unité',
  `prix_reference_cfa` int(10) unsigned NOT NULL COMMENT 'Prix normal FIGÉ à la mise en liquidation. Recopié volontairement : si le vendeur change son prix demain, le prix barré ne doit pas bouger sous les yeux du client',
  `debut_le` datetime NOT NULL,
  `fin_le` datetime NOT NULL COMMENT 'Obligatoire : sans fin, une liquidation devient le prix normal',
  `date_peremption` date DEFAULT NULL COMMENT 'Obligatoire si le motif l''exige. La vente se ferme à cette date',
  `detail` varchar(300) NOT NULL COMMENT 'Ce que le client doit savoir. Un défaut annoncé ne fonde pas un litige — c''est pour cela qu''on l''annonce',
  `quantite_concernee` int(10) unsigned DEFAULT NULL,
  `cree_par_utilisateur_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_liq_variante` (`variante_id`,`debut_le`,`fin_le`),
  KEY `ix_liq_peremption` (`date_peremption`),
  KEY `fk_liq_motif` (`motif_id`),
  CONSTRAINT `fk_liq_motif` FOREIGN KEY (`motif_id`) REFERENCES `motifs_liquidation` (`id`),
  CONSTRAINT `fk_liq_variante` FOREIGN KEY (`variante_id`) REFERENCES `variantes_produit` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `liquidations` DISABLE KEYS */;
/*!40000 ALTER TABLE `liquidations` ENABLE KEYS */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_liquidation_avant_insert BEFORE INSERT ON liquidations
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
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `litiges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `litiges` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sous_commande_id` bigint(20) unsigned NOT NULL,
  `reference` varchar(32) NOT NULL,
  `motif` enum('non_recu','endommage','non_conforme','incomplet','contrefacon','autre') NOT NULL,
  `description` text NOT NULL,
  `statut` enum('ouvert','en_examen','resolu_client','resolu_boutique','clos') NOT NULL DEFAULT 'ouvert',
  `ouvert_par_id` bigint(20) unsigned DEFAULT NULL,
  `traite_par_id` bigint(20) unsigned DEFAULT NULL,
  `resolution` text DEFAULT NULL COMMENT 'Toujours motivée : visible des deux parties',
  `conteste_par_boutique_le` datetime DEFAULT NULL,
  `argument_boutique` text DEFAULT NULL,
  `ouvert_le` datetime NOT NULL DEFAULT current_timestamp(),
  `resolu_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_litiges_ref` (`reference`),
  KEY `idx_litiges_statut` (`statut`),
  KEY `fk_litiges_sc` (`sous_commande_id`),
  CONSTRAINT `fk_litiges_sc` FOREIGN KEY (`sous_commande_id`) REFERENCES `sous_commandes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `litiges` DISABLE KEYS */;
/*!40000 ALTER TABLE `litiges` ENABLE KEYS */;
DROP TABLE IF EXISTS `lots_qr`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `lots_qr` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `produit_id` bigint(20) unsigned NOT NULL,
  `variante_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Une contenance donnée peut avoir son propre lot',
  `boutique_id` bigint(20) unsigned NOT NULL,
  `pays_id` tinyint(3) unsigned NOT NULL,
  `reference` varchar(30) NOT NULL,
  `date_fabrication` date NOT NULL,
  `date_expiration` date NOT NULL,
  `fabricant` varchar(160) NOT NULL,
  `numero_debut` int(10) unsigned NOT NULL,
  `quantite` int(10) unsigned NOT NULL,
  `largeur_numero` tinyint(3) unsigned NOT NULL DEFAULT 4,
  `description` text DEFAULT NULL,
  `statut` enum('demande','refuse','genere','imprime','en_circulation','rappele') NOT NULL DEFAULT 'genere',
  `motif_refus` text DEFAULT NULL,
  `demande_par_id` bigint(20) unsigned DEFAULT NULL,
  `cree_par_id` bigint(20) unsigned NOT NULL,
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  `modifie_le` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_lots_ref` (`reference`),
  KEY `idx_lots_produit` (`produit_id`),
  KEY `fk_lots_boutique` (`boutique_id`),
  KEY `fk_lots_pays` (`pays_id`),
  CONSTRAINT `fk_lots_boutique` FOREIGN KEY (`boutique_id`) REFERENCES `boutiques` (`id`),
  CONSTRAINT `fk_lots_pays` FOREIGN KEY (`pays_id`) REFERENCES `pays` (`id`),
  CONSTRAINT `fk_lots_produit` FOREIGN KEY (`produit_id`) REFERENCES `produits` (`id`),
  CONSTRAINT `ck_lots_dates` CHECK (`date_expiration` > `date_fabrication`),
  CONSTRAINT `ck_lots_quantite` CHECK (`quantite` >= 1 and `quantite` <= 100000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `lots_qr` DISABLE KEYS */;
/*!40000 ALTER TABLE `lots_qr` ENABLE KEYS */;
DROP TABLE IF EXISTS `medias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `medias` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `proprietaire_type` varchar(30) NOT NULL COMMENT 'produit, variante, boutique, litige, retour',
  `proprietaire_id` bigint(20) unsigned NOT NULL,
  `chemin_original` varchar(255) NOT NULL,
  `chemin_grand` varchar(255) DEFAULT NULL COMMENT '1200 px, fiche produit',
  `chemin_vignette` varchar(255) DEFAULT NULL COMMENT '400 px, grille de la vitrine',
  `chemin_miniature` varchar(255) DEFAULT NULL COMMENT '100 px, panier et listes',
  `largeur` smallint(5) unsigned DEFAULT NULL,
  `hauteur` smallint(5) unsigned DEFAULT NULL,
  `poids_octets` int(10) unsigned DEFAULT NULL,
  `texte_alternatif` varchar(255) DEFAULT NULL COMMENT 'Accessibilité et référencement',
  `ordre` smallint(6) NOT NULL DEFAULT 0,
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_medias_proprietaire` (`proprietaire_type`,`proprietaire_id`,`ordre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `medias` DISABLE KEYS */;
/*!40000 ALTER TABLE `medias` ENABLE KEYS */;
DROP TABLE IF EXISTS `messages_ticket`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages_ticket` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint(20) unsigned NOT NULL,
  `auteur_id` bigint(20) unsigned DEFAULT NULL,
  `auteur_type` enum('client','agent','systeme','vendeur') NOT NULL,
  `corps` text NOT NULL,
  `interne` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Note interne, invisible du client',
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_messages_ticket` (`ticket_id`,`cree_le`),
  CONSTRAINT `fk_msg_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets_support` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `messages_ticket` DISABLE KEYS */;
/*!40000 ALTER TABLE `messages_ticket` ENABLE KEYS */;
DROP TABLE IF EXISTS `motifs_liquidation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `motifs_liquidation` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `libelle` varchar(80) NOT NULL,
  `aide_client` varchar(200) NOT NULL COMMENT 'Phrase montrée à l''acheteur — elle explique la remise',
  `date_limite_obligatoire` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Si vrai, la liquidation exige une date et se ferme à cette date',
  `ordre` tinyint(3) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_motif_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Motifs de liquidation. En table et non en ENUM : un ENUM impose un ALTER TABLE, donc un verrou, pour chaque ajout';
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `motifs_liquidation` DISABLE KEYS */;
INSERT INTO `motifs_liquidation` (`id`, `code`, `libelle`, `aide_client`, `date_limite_obligatoire`, `ordre`) VALUES (1,'peremption','Date limite proche','Le lot approche sa date de consommation. Elle est affichée en clair, et la vente se ferme automatiquement à cette date.',1,1),
(2,'surstock','Surstock','Trop de stock, rotation lente. Le produit est neuf et parfaitement conforme.',0,2),
(3,'fin_serie','Fin de série','Dernières pièces, modèle arrêté. Aucun défaut.',0,3),
(4,'defaut','Défaut mineur','Petit défaut visible, décrit sur la fiche. Vendu en connaissance de cause.',0,4);
/*!40000 ALTER TABLE `motifs_liquidation` ENABLE KEYS */;
DROP TABLE IF EXISTS `mouvements_compte`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mouvements_compte` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `boutique_id` bigint(20) unsigned DEFAULT NULL COMMENT 'NULL = compte de la plateforme (commissions, frais)',
  `type` enum('vente','vente_service','commission','commission_a_facturer','retenue_source','frais_livraison','remboursement','versement','ajustement') NOT NULL COMMENT 'commission_a_facturer = due mais non prelevee (jalon hors plateforme)',
  `sens` enum('credit','debit') NOT NULL,
  `montant_cfa` int(10) unsigned NOT NULL,
  `devise` char(3) NOT NULL DEFAULT 'XOF',
  `piece_type` varchar(30) NOT NULL COMMENT 'sous_commande, reversement, retour, litige…',
  `piece_id` bigint(20) unsigned NOT NULL,
  `libelle` varchar(255) NOT NULL,
  `annule_mouvement_id` bigint(20) unsigned DEFAULT NULL,
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  `cree_par_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mvt_boutique` (`boutique_id`,`cree_le`),
  KEY `idx_mvt_piece` (`piece_type`,`piece_id`),
  CONSTRAINT `fk_mvt_boutique` FOREIGN KEY (`boutique_id`) REFERENCES `boutiques` (`id`),
  CONSTRAINT `ck_mvt_montant` CHECK (`montant_cfa` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `mouvements_compte` DISABLE KEYS */;
/*!40000 ALTER TABLE `mouvements_compte` ENABLE KEYS */;
DROP TABLE IF EXISTS `mouvements_stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mouvements_stock` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `variante_id` bigint(20) unsigned NOT NULL,
  `type` enum('vente_en_ligne','vente_comptoir','retour','reapprovisionnement','inventaire','casse','perte','correction') NOT NULL,
  `quantite` int(11) NOT NULL COMMENT 'Négatif pour une sortie',
  `stock_avant` int(11) NOT NULL,
  `stock_apres` int(11) NOT NULL,
  `piece_type` varchar(30) DEFAULT NULL,
  `piece_id` bigint(20) unsigned DEFAULT NULL,
  `motif` varchar(255) DEFAULT NULL,
  `auteur_id` bigint(20) unsigned DEFAULT NULL,
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_mvt_stock_variante` (`variante_id`,`cree_le`),
  KEY `idx_mvt_stock_type` (`type`,`cree_le`),
  CONSTRAINT `fk_mvtstock_variante` FOREIGN KEY (`variante_id`) REFERENCES `variantes_produit` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `mouvements_stock` DISABLE KEYS */;
/*!40000 ALTER TABLE `mouvements_stock` ENABLE KEYS */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `gabarit_id` smallint(5) unsigned DEFAULT NULL,
  `destinataire_id` bigint(20) unsigned DEFAULT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `email` varchar(180) DEFAULT NULL,
  `canal` enum('sms','push','email','whatsapp') NOT NULL,
  `corps_envoye` text NOT NULL COMMENT 'Après substitution des variables',
  `statut` enum('en_file','envoye','remis','echoue','rejete') NOT NULL DEFAULT 'en_file',
  `fournisseur` varchar(40) DEFAULT NULL,
  `reference_externe` varchar(100) DEFAULT NULL,
  `cout_cfa` int(10) unsigned NOT NULL DEFAULT 0,
  `nb_segments` tinyint(3) unsigned NOT NULL DEFAULT 1 COMMENT 'Un SMS long est facturé plusieurs fois',
  `erreur` varchar(255) DEFAULT NULL,
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  `envoye_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_statut` (`statut`,`cree_le`),
  KEY `idx_notifications_destinataire` (`destinataire_id`,`cree_le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
DROP TABLE IF EXISTS `operateurs_paiement`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `operateurs_paiement` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `pays_id` tinyint(3) unsigned NOT NULL,
  `code` varchar(30) NOT NULL COMMENT 'orange_money, wave, mtn_momo, moov_flooz, celtiis…',
  `nom` varchar(60) NOT NULL,
  `type` enum('mobile_money','carte','virement','especes') NOT NULL DEFAULT 'mobile_money',
  `plafond_transaction_cfa` int(10) unsigned DEFAULT NULL,
  `plafond_mensuel_cfa` int(10) unsigned DEFAULT NULL,
  `frais_encaissement_pct` decimal(5,2) DEFAULT NULL COMMENT 'Commission agrégateur, ordre de 1,5 à 3,5 %',
  `frais_reversement_pct` decimal(5,2) DEFAULT NULL COMMENT 'Ordre de 0,8 à 2 %',
  `frais_reversement_fixe_cfa` int(10) unsigned DEFAULT NULL,
  `logo_url` varchar(255) DEFAULT NULL,
  `ordre` smallint(6) NOT NULL DEFAULT 0,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_operateurs` (`pays_id`,`code`),
  CONSTRAINT `fk_operateurs_pays` FOREIGN KEY (`pays_id`) REFERENCES `pays` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `operateurs_paiement` DISABLE KEYS */;
INSERT INTO `operateurs_paiement` (`id`, `pays_id`, `code`, `nom`, `type`, `plafond_transaction_cfa`, `plafond_mensuel_cfa`, `frais_encaissement_pct`, `frais_reversement_pct`, `frais_reversement_fixe_cfa`, `logo_url`, `ordre`, `actif`) VALUES (1,1,'orange_money','Orange Money','mobile_money',NULL,NULL,NULL,NULL,NULL,NULL,1,1),
(2,1,'wave','Wave','mobile_money',NULL,NULL,NULL,NULL,NULL,NULL,2,1),
(3,1,'moov_flooz','Moov Money (Flooz)','mobile_money',NULL,NULL,NULL,NULL,NULL,NULL,3,1),
(4,1,'coris_money','Coris Money','mobile_money',NULL,NULL,NULL,NULL,NULL,NULL,4,1),
(5,1,'especes','Paiement à la livraison','especes',NULL,NULL,NULL,NULL,NULL,NULL,9,1),
(6,2,'orange_money','Orange Money','mobile_money',NULL,NULL,NULL,NULL,NULL,NULL,1,1),
(7,2,'wave','Wave','mobile_money',NULL,NULL,NULL,NULL,NULL,NULL,2,1),
(8,2,'mtn_momo','MTN MoMo','mobile_money',NULL,NULL,NULL,NULL,NULL,NULL,3,1),
(9,2,'moov_flooz','Moov Money','mobile_money',NULL,NULL,NULL,NULL,NULL,NULL,4,1),
(10,2,'especes','Paiement à la livraison','especes',NULL,NULL,NULL,NULL,NULL,NULL,9,1),
(11,3,'wave','Wave','mobile_money',NULL,NULL,NULL,NULL,NULL,NULL,1,1),
(12,3,'orange_money','Orange Money','mobile_money',NULL,NULL,NULL,NULL,NULL,NULL,2,1),
(13,3,'free_money','Free Money','mobile_money',NULL,NULL,NULL,NULL,NULL,NULL,3,1),
(14,3,'especes','Paiement à la livraison','especes',NULL,NULL,NULL,NULL,NULL,NULL,9,1),
(15,7,'mixx_yas','Mixx by Yas','mobile_money',NULL,NULL,NULL,NULL,NULL,NULL,1,1),
(16,7,'moov_flooz','Moov Money (Flooz)','mobile_money',NULL,NULL,NULL,NULL,NULL,NULL,2,1),
(17,7,'especes','Paiement à la livraison','especes',NULL,NULL,NULL,NULL,NULL,NULL,9,1),
(18,6,'mtn_momo','MTN MoMo','mobile_money',NULL,NULL,NULL,NULL,NULL,NULL,1,1),
(19,6,'moov_flooz','Moov Money (Flooz)','mobile_money',NULL,NULL,NULL,NULL,NULL,NULL,2,1),
(20,6,'celtiis_cash','Celtiis Cash','mobile_money',NULL,NULL,NULL,NULL,NULL,NULL,3,1),
(21,6,'especes','Paiement à la livraison','especes',NULL,NULL,NULL,NULL,NULL,NULL,9,1),
(22,4,'orange_money','Orange Money','mobile_money',NULL,NULL,NULL,NULL,NULL,NULL,1,1),
(23,4,'wave','Wave','mobile_money',NULL,NULL,NULL,NULL,NULL,NULL,2,1),
(24,4,'moov_flooz','Moov Money','mobile_money',NULL,NULL,NULL,NULL,NULL,NULL,3,1),
(25,4,'especes','Paiement à la livraison','especes',NULL,NULL,NULL,NULL,NULL,NULL,9,1),
(26,5,'airtel_money','Airtel Money','mobile_money',NULL,NULL,NULL,NULL,NULL,NULL,1,1),
(27,5,'moov_flooz','Moov Money (Flooz)','mobile_money',NULL,NULL,NULL,NULL,NULL,NULL,2,1),
(28,5,'wave','Wave','mobile_money',NULL,NULL,NULL,NULL,NULL,NULL,3,1),
(29,5,'especes','Paiement à la livraison','especes',NULL,NULL,NULL,NULL,NULL,NULL,9,1),
(30,8,'orange_money','Orange Money','mobile_money',NULL,NULL,NULL,NULL,NULL,NULL,1,1),
(31,8,'especes','Paiement à la livraison','especes',NULL,NULL,NULL,NULL,NULL,NULL,9,1);
/*!40000 ALTER TABLE `operateurs_paiement` ENABLE KEYS */;
DROP TABLE IF EXISTS `paniers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `paniers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `utilisateur_id` bigint(20) unsigned DEFAULT NULL,
  `jeton_session` char(40) DEFAULT NULL COMMENT 'Panier anonyme, rattaché au compte à la connexion',
  `pays_id` tinyint(3) unsigned NOT NULL,
  `statut` enum('actif','abandonne','converti','expire') NOT NULL DEFAULT 'actif',
  `relance_envoyee_le` datetime DEFAULT NULL,
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  `modifie_le` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_paniers_utilisateur` (`utilisateur_id`,`statut`),
  KEY `idx_paniers_jeton` (`jeton_session`),
  KEY `idx_paniers_relance` (`statut`,`modifie_le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `paniers` DISABLE KEYS */;
/*!40000 ALTER TABLE `paniers` ENABLE KEYS */;
DROP TABLE IF EXISTS `parametres`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `parametres` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `pays_id` tinyint(3) unsigned DEFAULT NULL COMMENT 'NULL = valeur par défaut, tous pays',
  `cle` varchar(60) NOT NULL,
  `valeur` varchar(255) NOT NULL,
  `type` enum('entier','decimal','booleen','texte','json') NOT NULL DEFAULT 'texte',
  `libelle` varchar(160) NOT NULL,
  `modifie_le` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_parametres` (`pays_id`,`cle`),
  CONSTRAINT `fk_parametres_pays` FOREIGN KEY (`pays_id`) REFERENCES `pays` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `parametres` DISABLE KEYS */;
INSERT INTO `parametres` (`id`, `pays_id`, `cle`, `valeur`, `type`, `libelle`, `modifie_le`) VALUES (1,NULL,'commission_vente_comptoir_pct','0','decimal','Commission sur une vente non apportée par la plateforme','2026-08-07 00:08:16'),
(2,NULL,'devise_affichage_defaut','XOF','texte','Devise d\'affichage par défaut','2026-08-07 00:08:16'),
(3,NULL,'marge_change_pct','2','decimal','Marge de sécurité sur les prix convertis affichés','2026-08-07 00:08:16'),
(4,NULL,'devis_expedition_validite_jours','15','entier','Durée de validité d\'un devis d\'expédition','2026-08-07 00:08:16'),
(5,NULL,'decharge_transitaire_version','v1.0','texte','Version courante de la décharge de responsabilité','2026-08-07 00:08:16'),
(6,NULL,'liquidation_seuil_peremption_jours','45','entier','Jours avant peremption declenchant une proposition d\'ecoulement','2026-08-07 00:08:16'),
(7,NULL,'delai_confirmation_auto_jours','3','entier','Libération automatique des fonds après livraison','2026-08-07 00:08:16'),
(8,NULL,'commission_defaut_pct','10','decimal','Commission d\'une nouvelle boutique','2026-08-07 00:08:16'),
(9,NULL,'reversement_minimum_cfa','5000','entier','En dessous, on attend le cycle suivant','2026-08-07 00:08:16'),
(10,NULL,'reputation_seuil_commandes','3','entier','Avant, affichage « Nouvelle boutique »','2026-08-07 00:08:16'),
(11,NULL,'plafond_wallet_identifie_cfa','2000000','entier','Plafond BCEAO de solde — instruction 008-05-2015','2026-08-07 00:08:16'),
(12,NULL,'plafond_wallet_non_identifie_mensuel_cfa','200000','entier','Plafond BCEAO mensuel sans identification','2026-08-07 00:08:16'),
(13,NULL,'panier_max_mobile_money_cfa','1500000','entier','Au-delà, bascule vers espèces ou virement','2026-08-07 00:08:16'),
(14,NULL,'qr_longueur_jeton','12','entier','Entropie de l\'anti-contrefaçon','2026-08-07 00:08:16'),
(15,NULL,'qr_seuil_scans_suspects','50','entier','Déclenche le verdict « étiquette à vérifier »','2026-08-07 00:08:16'),
(16,NULL,'delai_reponse_droits_jours','60','entier','Délai légal de réponse à une demande d\'accès','2026-08-07 00:08:16'),
(17,1,'commission_defaut_pct','10','decimal','Commission Burkina','2026-08-07 00:08:16'),
(18,2,'commission_defaut_pct','12','decimal','Commission Côte d\'Ivoire','2026-08-07 00:08:16'),
(19,NULL,'services.plafond_sequestre_jalon_cfa','2000000','entier','Au-dela, le jalon est regle en direct au prestataire','2026-08-07 00:08:16'),
(20,NULL,'services.commission_facturee_a_part','1','booleen','Commission sur jalon hors plateforme : facturee, pas prelevee','2026-08-07 00:08:16'),
(21,NULL,'liquidation.seuil_alerte_peremption_jours','45','entier','Declenche la proposition de liquidation au vendeur','2026-08-07 00:08:16'),
(22,NULL,'liquidation.duree_max_jours','90','entier','Au-dela, le prix barre ne veut plus rien dire','2026-08-07 00:08:16');
/*!40000 ALTER TABLE `parametres` ENABLE KEYS */;
DROP TABLE IF EXISTS `pays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pays` (
  `id` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `code_iso2` char(2) NOT NULL COMMENT 'BF, CI, SN, ML, NE, BJ, TG, GW',
  `nom` varchar(60) NOT NULL,
  `devise` char(3) NOT NULL DEFAULT 'XOF' COMMENT 'XOF pour les 8 pays UEMOA — colonne prévue pour une sortie de zone',
  `zone` enum('uemoa','cedeao','afrique','europe','ameriques','asie','autre') NOT NULL DEFAULT 'autre',
  `indicatif_telephonique` varchar(6) NOT NULL COMMENT '+226, +225…',
  `langue_defaut` char(5) NOT NULL DEFAULT 'fr',
  `seuil_assujettissement_tva_cfa` bigint(20) unsigned DEFAULT NULL,
  `retenue_source_non_immatricule_pct` decimal(5,2) DEFAULT NULL,
  `retenue_source_immatricule_pct` decimal(5,2) DEFAULT NULL,
  `retenue_source_seuil_cfa` int(10) unsigned DEFAULT NULL COMMENT 'En dessous, pas de retenue',
  `facture_certifiee_obligatoire` tinyint(1) NOT NULL DEFAULT 0,
  `plateforme_facturation` varchar(40) DEFAULT NULL COMMENT 'e-MECeF, FEC, FNE, e-SECeF…',
  `retractation_jours_ouvrables` smallint(5) unsigned DEFAULT NULL,
  `retractation_jours_si_defaut_info` smallint(5) unsigned DEFAULT NULL,
  `autorite_donnees` varchar(80) DEFAULT NULL COMMENT 'CIL, ARTCI, CDP, APDP, IPDCP, HAPDP',
  `transfert_donnees_autorisation_requise` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Burkina : autorisation préalable de la CIL pour héberger à l''étranger',
  `actif` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Un pays existe dans le référentiel avant d''être ouvert commercialement',
  `ouvert_a_la_vente` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Un client de ce pays peut commander',
  `ouvert_aux_boutiques` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Une boutique peut y être établie',
  `ouvert_le` date DEFAULT NULL,
  `format_adresse` enum('ouest_africain','postal','libre') NOT NULL DEFAULT 'postal' COMMENT 'ouest_africain = quartier + repère ; postal = rue + code postal',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pays_iso` (`code_iso2`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `pays` DISABLE KEYS */;
INSERT INTO `pays` (`id`, `code_iso2`, `nom`, `devise`, `zone`, `indicatif_telephonique`, `langue_defaut`, `seuil_assujettissement_tva_cfa`, `retenue_source_non_immatricule_pct`, `retenue_source_immatricule_pct`, `retenue_source_seuil_cfa`, `facture_certifiee_obligatoire`, `plateforme_facturation`, `retractation_jours_ouvrables`, `retractation_jours_si_defaut_info`, `autorite_donnees`, `transfert_donnees_autorisation_requise`, `actif`, `ouvert_a_la_vente`, `ouvert_aux_boutiques`, `ouvert_le`, `format_adresse`) VALUES (1,'BF','Burkina Faso','XOF','uemoa','+226','fr',50000000,25.00,5.00,50000,1,'FEC',NULL,NULL,'CIL',1,1,1,1,'2026-09-01','ouest_africain'),
(2,'CI','Côte d\'Ivoire','XOF','uemoa','+225','fr',200000000,5.00,7.50,NULL,1,'FNE',NULL,NULL,'ARTCI',1,0,1,1,NULL,'ouest_africain'),
(3,'SN','Sénégal','XOF','uemoa','+221','fr',50000000,5.00,5.00,NULL,0,NULL,7,90,'CDP',1,0,1,1,NULL,'ouest_africain'),
(4,'ML','Mali','XOF','uemoa','+223','fr',50000000,NULL,NULL,NULL,1,'Facture normalisée',NULL,NULL,'APDP',1,0,1,1,NULL,'ouest_africain'),
(5,'NE','Niger','XOF','uemoa','+227','fr',NULL,NULL,NULL,NULL,1,'e-SECeF',NULL,NULL,'HAPDP',1,0,1,1,NULL,'ouest_africain'),
(6,'BJ','Bénin','XOF','uemoa','+229','fr',50000000,5.00,3.00,NULL,1,'e-MECeF',NULL,NULL,'APDP',1,0,1,1,NULL,'ouest_africain'),
(7,'TG','Togo','XOF','uemoa','+228','fr',60000000,NULL,NULL,NULL,0,'FEC (B2B)',NULL,NULL,'IPDCP',1,0,1,1,NULL,'ouest_africain'),
(8,'GW','Guinée-Bissau','XOF','uemoa','+245','pt',10000000,NULL,NULL,NULL,1,'Facture normalisée',NULL,NULL,NULL,1,0,1,1,NULL,'ouest_africain'),
(9,'FR','France','EUR','europe','+33','fr',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'CNIL',0,1,1,0,NULL,'postal'),
(10,'BE','Belgique','EUR','europe','+32','fr',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'APD',0,1,1,0,NULL,'postal'),
(11,'IT','Italie','EUR','europe','+39','it',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Garante',0,1,1,0,NULL,'postal'),
(12,'ES','Espagne','EUR','europe','+34','es',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'AEPD',0,1,1,0,NULL,'postal'),
(13,'DE','Allemagne','EUR','europe','+49','de',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'BfDI',0,1,1,0,NULL,'postal'),
(14,'GB','Royaume-Uni','GBP','europe','+44','en',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'ICO',0,1,1,0,NULL,'postal'),
(15,'CH','Suisse','CHF','europe','+41','fr',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'PFPDT',0,1,1,0,NULL,'postal'),
(16,'US','États-Unis','USD','ameriques','+1','en',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,0,1,1,0,NULL,'postal'),
(17,'CA','Canada','CAD','ameriques','+1','fr',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'CPVP',0,1,1,0,NULL,'postal'),
(18,'CN','Chine','CNY','asie','+86','zh',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,0,1,1,1,NULL,'libre'),
(19,'AE','Émirats arabes unis','AED','asie','+971','ar',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,0,1,1,0,NULL,'libre'),
(20,'TR','Turquie','TRY','asie','+90','tr',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'KVKK',0,1,1,0,NULL,'postal'),
(21,'GH','Ghana','GHS','cedeao','+233','en',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'DPC',0,1,1,0,NULL,'libre'),
(22,'NG','Nigéria','NGN','cedeao','+234','en',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'NDPC',0,1,1,0,NULL,'libre'),
(23,'MA','Maroc','MAD','afrique','+212','fr',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'CNDP',0,1,1,0,NULL,'postal');
/*!40000 ALTER TABLE `pays` ENABLE KEYS */;
DROP TABLE IF EXISTS `points_relais`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `points_relais` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `pays_id` tinyint(3) unsigned NOT NULL,
  `ville_id` int(10) unsigned NOT NULL,
  `nom` varchar(120) NOT NULL,
  `adresse` varchar(255) NOT NULL,
  `repere` varchar(255) DEFAULT NULL,
  `telephone` varchar(20) NOT NULL,
  `horaires` varchar(255) DEFAULT NULL,
  `latitude` decimal(9,6) DEFAULT NULL,
  `longitude` decimal(9,6) DEFAULT NULL,
  `frais_cfa` int(10) unsigned NOT NULL DEFAULT 0,
  `capacite_colis` smallint(5) unsigned DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_relais_ville` (`ville_id`,`actif`),
  KEY `fk_relais_pays` (`pays_id`),
  CONSTRAINT `fk_relais_pays` FOREIGN KEY (`pays_id`) REFERENCES `pays` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_relais_ville` FOREIGN KEY (`ville_id`) REFERENCES `villes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `points_relais` DISABLE KEYS */;
/*!40000 ALTER TABLE `points_relais` ENABLE KEYS */;
DROP TABLE IF EXISTS `prestataires_paiement`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `prestataires_paiement` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL COMMENT 'cinetpay, paydunya, fedapay, semoa…',
  `nom` varchar(80) NOT NULL,
  `agrement_bceao_ref` varchar(80) DEFAULT NULL,
  `agrement_verifie_le` date DEFAULT NULL,
  `supporte_payout` tinyint(1) NOT NULL DEFAULT 1,
  `supporte_split` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Paiement partagé vers les vendeurs. Aucun PSP UEMOA ne le proposait au 08/2026',
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_psp_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `prestataires_paiement` DISABLE KEYS */;
INSERT INTO `prestataires_paiement` (`id`, `code`, `nom`, `agrement_bceao_ref`, `agrement_verifie_le`, `supporte_payout`, `supporte_split`, `actif`) VALUES (1,'cinetpay','CinetPay',NULL,NULL,1,0,1),
(2,'paydunya','PayDunya',NULL,NULL,1,0,1),
(3,'fedapay','FedaPay',NULL,NULL,1,0,0),
(4,'semoa','Semoa',NULL,NULL,1,0,0);
/*!40000 ALTER TABLE `prestataires_paiement` ENABLE KEYS */;
DROP TABLE IF EXISTS `produits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `produits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `boutique_id` bigint(20) unsigned NOT NULL,
  `categorie_id` int(10) unsigned NOT NULL,
  `reference` varchar(24) NOT NULL,
  `nom` varchar(160) NOT NULL,
  `slug` varchar(180) NOT NULL,
  `description` text DEFAULT NULL,
  `prix_ttc_cfa` int(10) unsigned NOT NULL,
  `poids_g` int(10) unsigned DEFAULT NULL COMMENT 'Sert au calcul des frais de livraison au poids',
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `tracable` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Éligible aux étiquettes QR',
  `statut_homologation` enum('non_homologue','homologue_mta') NOT NULL DEFAULT 'non_homologue' COMMENT 'homologue_mta = medicament au sens reglementaire : reference mais NON vendu ici',
  `numero_homologation` varchar(40) DEFAULT NULL,
  `homologation_fabricant` varchar(160) DEFAULT NULL,
  `homologation_expire_le` date DEFAULT NULL COMMENT 'Une homologation expire. Afficher « homologué » après expiration serait faux',
  `statut_moderation` enum('brouillon','en_attente','publie','rejete','retire') NOT NULL DEFAULT 'en_attente',
  `motif_moderation` text DEFAULT NULL,
  `modere_par_id` bigint(20) unsigned DEFAULT NULL,
  `modere_le` datetime DEFAULT NULL,
  `vues` int(10) unsigned NOT NULL DEFAULT 0,
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  `modifie_le` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `supprime_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_produits_reference` (`reference`),
  KEY `idx_produits_categorie` (`categorie_id`,`statut_moderation`,`actif`),
  KEY `idx_produits_boutique` (`boutique_id`,`actif`),
  KEY `idx_produits_prix` (`prix_ttc_cfa`),
  FULLTEXT KEY `ft_produits` (`nom`,`description`),
  FULLTEXT KEY `ft_produits_recherche` (`nom`,`description`),
  CONSTRAINT `fk_produits_boutique` FOREIGN KEY (`boutique_id`) REFERENCES `boutiques` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_produits_categorie` FOREIGN KEY (`categorie_id`) REFERENCES `categories` (`id`),
  CONSTRAINT `chk_homologation` CHECK (`statut_homologation` = 'non_homologue' or `numero_homologation` is not null and `homologation_expire_le` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `produits` DISABLE KEYS */;
/*!40000 ALTER TABLE `produits` ENABLE KEYS */;
DROP TABLE IF EXISTS `professionnels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `professionnels` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `profession` varchar(60) NOT NULL,
  `nom` varchar(140) NOT NULL,
  `pays_id` tinyint(3) unsigned NOT NULL,
  `ville` varchar(80) NOT NULL,
  `ordre_ou_association` varchar(160) NOT NULL,
  `numero_inscription` varchar(60) NOT NULL,
  `verifie_le` date DEFAULT NULL,
  `verifie_par_utilisateur_id` bigint(20) unsigned DEFAULT NULL,
  `verification_expire_le` date NOT NULL COMMENT 'Une vérification sans expiration devient un mensonge le jour où le professionnel est radié — et c''est la plateforme qui l''aura affirmé',
  `telephone` varchar(24) NOT NULL,
  `actes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`actes`)),
  `avertissement_sante` tinyint(1) NOT NULL DEFAULT 0,
  `publie` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pro_inscription` (`ordre_ou_association`,`numero_inscription`),
  KEY `ix_pro_profession` (`profession`,`publie`),
  KEY `fk_pro_pays` (`pays_id`),
  CONSTRAINT `fk_pro_pays` FOREIGN KEY (`pays_id`) REFERENCES `pays` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `professionnels` DISABLE KEYS */;
/*!40000 ALTER TABLE `professionnels` ENABLE KEYS */;
DROP TABLE IF EXISTS `promotions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `promotions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) DEFAULT NULL COMMENT 'NULL = promotion automatique, sans code à saisir',
  `libelle` varchar(120) NOT NULL,
  `pays_id` tinyint(3) unsigned DEFAULT NULL COMMENT 'NULL = tous les pays ouverts',
  `type` enum('pourcentage','montant_fixe','livraison_offerte') NOT NULL,
  `valeur` int(10) unsigned NOT NULL DEFAULT 0,
  `financeur` enum('plateforme','boutique','partage') NOT NULL DEFAULT 'plateforme',
  `part_boutique_pct` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Si financeur = partage',
  `boutique_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Promotion propre à une boutique',
  `categorie_id` int(10) unsigned DEFAULT NULL,
  `montant_minimum_cfa` int(10) unsigned NOT NULL DEFAULT 0,
  `plafond_remise_cfa` int(10) unsigned DEFAULT NULL,
  `usages_max` int(10) unsigned DEFAULT NULL,
  `usages_max_par_client` smallint(5) unsigned NOT NULL DEFAULT 1,
  `usages_actuels` int(10) unsigned NOT NULL DEFAULT 0,
  `debut_le` datetime NOT NULL,
  `fin_le` datetime NOT NULL,
  `accepte_par_boutique_le` datetime DEFAULT NULL COMMENT 'Obligatoire si la boutique finance',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_promotions_code` (`code`),
  KEY `idx_promotions_periode` (`active`,`debut_le`,`fin_le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `promotions` DISABLE KEYS */;
/*!40000 ALTER TABLE `promotions` ENABLE KEYS */;
DROP TABLE IF EXISTS `recherches_infructueuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `recherches_infructueuses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `terme_normalise` varchar(120) NOT NULL,
  `terme_original` varchar(160) NOT NULL,
  `occurrences` int(10) unsigned NOT NULL DEFAULT 1,
  `premiere_fois` datetime NOT NULL,
  `derniere_fois` datetime NOT NULL,
  `traitement` enum('a_traiter','synonyme_ajoute','produit_a_referencer','ignore') NOT NULL DEFAULT 'a_traiter',
  `note` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_recherche_terme` (`terme_normalise`),
  KEY `ix_recherche_file` (`traitement`,`occurrences`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `recherches_infructueuses` DISABLE KEYS */;
/*!40000 ALTER TABLE `recherches_infructueuses` ENABLE KEYS */;
DROP TABLE IF EXISTS `reconciliations_psp`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reconciliations_psp` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `prestataire_id` smallint(5) unsigned NOT NULL,
  `date_arrete` date NOT NULL,
  `nb_operations_psp` int(10) unsigned NOT NULL DEFAULT 0,
  `montant_psp_cfa` bigint(20) NOT NULL DEFAULT 0,
  `nb_operations_local` int(10) unsigned NOT NULL DEFAULT 0,
  `montant_local_cfa` bigint(20) NOT NULL DEFAULT 0,
  `ecart_montant_cfa` bigint(20) NOT NULL DEFAULT 0,
  `statut` enum('en_cours','conforme','ecart','regularise') NOT NULL DEFAULT 'en_cours',
  `detail_ecarts` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Références des opérations non appariées' CHECK (json_valid(`detail_ecarts`)),
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reconciliation` (`prestataire_id`,`date_arrete`),
  CONSTRAINT `fk_recon_psp` FOREIGN KEY (`prestataire_id`) REFERENCES `prestataires_paiement` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `reconciliations_psp` DISABLE KEYS */;
/*!40000 ALTER TABLE `reconciliations_psp` ENABLE KEYS */;
DROP TABLE IF EXISTS `registre_traitements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `registre_traitements` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `finalite` varchar(255) NOT NULL,
  `base_legale` enum('consentement','contrat','obligation_legale','interet_legitime') NOT NULL,
  `categories_donnees` text NOT NULL,
  `destinataires` text DEFAULT NULL COMMENT 'PSP, transporteur, hébergeur…',
  `duree_conservation_jours` int(10) unsigned DEFAULT NULL,
  `pays_hebergement` char(2) DEFAULT NULL,
  `declaration_ref` varchar(80) DEFAULT NULL COMMENT 'Numéro de récépissé de déclaration',
  `declare_le` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_traitements_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `registre_traitements` DISABLE KEYS */;
INSERT INTO `registre_traitements` (`id`, `code`, `finalite`, `base_legale`, `categories_donnees`, `destinataires`, `duree_conservation_jours`, `pays_hebergement`, `declaration_ref`, `declare_le`) VALUES (1,'gestion_comptes','Création et gestion des comptes clients et vendeurs','contrat','Nom, téléphone, e-mail, langue, pièce d\'identité (vendeurs)','Hébergeur',1095,NULL,NULL,NULL),
(2,'gestion_commandes','Traitement, livraison et suivi des commandes','contrat','Nom, téléphone, adresse de livraison, contenu de la commande','Transporteur, boutique concernée',3650,NULL,NULL,NULL),
(3,'paiements','Encaissement, reversement et lutte contre la fraude','obligation_legale','Numéro de téléphone marchand, montants, références de transaction','Prestataire de paiement agréé',3650,NULL,NULL,NULL),
(4,'prospection','Envoi d\'offres commerciales par SMS et e-mail','consentement','Nom, téléphone, e-mail, historique d\'achat','Fournisseur SMS',1095,NULL,NULL,NULL),
(5,'tracabilite_qr','Vérification d\'authenticité des produits étiquetés','interet_legitime','Adresse IP hachée, ville estimée, horodatage','Aucun',365,NULL,NULL,NULL);
/*!40000 ALTER TABLE `registre_traitements` ENABLE KEYS */;
DROP TABLE IF EXISTS `remises_transitaire`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `remises_transitaire` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `commande_id` bigint(20) unsigned NOT NULL,
  `sous_commande_id` bigint(20) unsigned DEFAULT NULL COMMENT 'NULL = toute la commande',
  `transitaire_nom` varchar(160) NOT NULL,
  `transitaire_contact` varchar(120) DEFAULT NULL,
  `transitaire_telephone` varchar(20) DEFAULT NULL,
  `adresse_remise` varchar(255) NOT NULL,
  `reference_client` varchar(80) DEFAULT NULL COMMENT 'Numéro de dossier donné par le client ou son transitaire',
  `instructions` text DEFAULT NULL,
  `statut` enum('a_remettre','remis','refuse_transitaire','annule') NOT NULL DEFAULT 'a_remettre',
  `remis_le` datetime DEFAULT NULL,
  `remis_par_id` bigint(20) unsigned DEFAULT NULL,
  `recu_par_nom` varchar(120) DEFAULT NULL,
  `recu_par_piece` varchar(60) DEFAULT NULL COMMENT 'Référence de la pièce d''identité présentée',
  `preuve_media_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Photo du bordereau signé',
  `nb_colis` smallint(5) unsigned DEFAULT NULL,
  `poids_total_g` int(10) unsigned DEFAULT NULL,
  `decharge_version` varchar(20) DEFAULT NULL,
  `decharge_acceptee_le` datetime DEFAULT NULL,
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_remises_commande` (`commande_id`,`statut`),
  KEY `fk_remises_sc` (`sous_commande_id`),
  CONSTRAINT `fk_remises_commande` FOREIGN KEY (`commande_id`) REFERENCES `commandes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_remises_sc` FOREIGN KEY (`sous_commande_id`) REFERENCES `sous_commandes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `remises_transitaire` DISABLE KEYS */;
/*!40000 ALTER TABLE `remises_transitaire` ENABLE KEYS */;
DROP TABLE IF EXISTS `retenues_source`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `retenues_source` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pays_id` tinyint(3) unsigned NOT NULL,
  `boutique_id` bigint(20) unsigned NOT NULL,
  `sous_commande_id` bigint(20) unsigned DEFAULT NULL,
  `reversement_id` bigint(20) unsigned DEFAULT NULL,
  `base_cfa` int(10) unsigned NOT NULL COMMENT 'Montant sur lequel la retenue est calculée',
  `taux_pct` decimal(5,2) NOT NULL,
  `montant_cfa` int(10) unsigned NOT NULL,
  `motif` enum('non_immatricule','immatricule','non_resident') NOT NULL,
  `periode` char(7) NOT NULL COMMENT 'AAAA-MM de déclaration',
  `statut` enum('a_reverser','declaree','reversee') NOT NULL DEFAULT 'a_reverser',
  `declaration_ref` varchar(80) DEFAULT NULL,
  `reversee_le` date DEFAULT NULL,
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_retenues_periode` (`pays_id`,`periode`,`statut`),
  KEY `idx_retenues_boutique` (`boutique_id`),
  CONSTRAINT `fk_ret_boutique` FOREIGN KEY (`boutique_id`) REFERENCES `boutiques` (`id`),
  CONSTRAINT `fk_ret_pays` FOREIGN KEY (`pays_id`) REFERENCES `pays` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `retenues_source` DISABLE KEYS */;
/*!40000 ALTER TABLE `retenues_source` ENABLE KEYS */;
DROP TABLE IF EXISTS `retours`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `retours` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sous_commande_id` bigint(20) unsigned NOT NULL,
  `reference` varchar(32) NOT NULL,
  `type` enum('retractation','echange','remboursement') NOT NULL,
  `motif` enum('ne_convient_pas','taille_incorrecte','erreur_commande','autre') NOT NULL,
  `commentaire` text DEFAULT NULL,
  `statut` enum('demande','accepte','refuse','en_transit','recu','rembourse','clos') NOT NULL DEFAULT 'demande',
  `motif_refus` text DEFAULT NULL,
  `frais_a_la_charge` enum('client','boutique','plateforme') NOT NULL DEFAULT 'client',
  `frais_retour_cfa` int(10) unsigned NOT NULL DEFAULT 0,
  `montant_rembourse_cfa` int(10) unsigned NOT NULL DEFAULT 0,
  `demande_le` datetime NOT NULL DEFAULT current_timestamp(),
  `traite_le` datetime DEFAULT NULL,
  `recu_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_retours_ref` (`reference`),
  KEY `idx_retours_statut` (`statut`),
  KEY `fk_retours_sc` (`sous_commande_id`),
  CONSTRAINT `fk_retours_sc` FOREIGN KEY (`sous_commande_id`) REFERENCES `sous_commandes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `retours` DISABLE KEYS */;
/*!40000 ALTER TABLE `retours` ENABLE KEYS */;
DROP TABLE IF EXISTS `reversement_lignes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reversement_lignes` (
  `reversement_id` bigint(20) unsigned NOT NULL,
  `sous_commande_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`reversement_id`,`sous_commande_id`),
  UNIQUE KEY `uk_une_vente_un_reversement` (`sous_commande_id`),
  CONSTRAINT `fk_rl_reversement` FOREIGN KEY (`reversement_id`) REFERENCES `reversements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rl_sc` FOREIGN KEY (`sous_commande_id`) REFERENCES `sous_commandes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `reversement_lignes` DISABLE KEYS */;
/*!40000 ALTER TABLE `reversement_lignes` ENABLE KEYS */;
DROP TABLE IF EXISTS `reversements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reversements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `boutique_id` bigint(20) unsigned NOT NULL,
  `reference` varchar(28) NOT NULL,
  `prestataire_id` smallint(5) unsigned DEFAULT NULL,
  `periode_debut` date NOT NULL,
  `periode_fin` date NOT NULL,
  `montant_brut_cfa` int(10) unsigned NOT NULL,
  `commission_cfa` int(10) unsigned NOT NULL,
  `retenue_source_cfa` int(10) unsigned NOT NULL DEFAULT 0,
  `frais_transfert_cfa` int(10) unsigned NOT NULL DEFAULT 0,
  `montant_net_cfa` int(10) unsigned NOT NULL,
  `statut` enum('a_payer','en_cours','paye','echoue','suspendu') NOT NULL DEFAULT 'a_payer',
  `reference_externe` varchar(100) DEFAULT NULL,
  `motif_echec` text DEFAULT NULL,
  `motif_suspension` varchar(255) DEFAULT NULL,
  `execute_le` datetime DEFAULT NULL,
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reversements_ref` (`reference`),
  KEY `idx_reversements` (`boutique_id`,`statut`),
  CONSTRAINT `fk_rev_boutique` FOREIGN KEY (`boutique_id`) REFERENCES `boutiques` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `reversements` DISABLE KEYS */;
/*!40000 ALTER TABLE `reversements` ENABLE KEYS */;
DROP TABLE IF EXISTS `scans_qr`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scans_qr` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code_qr_id` bigint(20) unsigned NOT NULL,
  `scanne_le` datetime NOT NULL DEFAULT current_timestamp(),
  `ip_hachee` char(64) DEFAULT NULL COMMENT 'Jamais en clair : hachage salé',
  `agent_hache` char(64) DEFAULT NULL,
  `ville_estimee` varchar(80) DEFAULT NULL,
  `pays_estime` char(2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_scans_code` (`code_qr_id`,`scanne_le`),
  CONSTRAINT `fk_scans_code` FOREIGN KEY (`code_qr_id`) REFERENCES `codes_qr` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `scans_qr` DISABLE KEYS */;
/*!40000 ALTER TABLE `scans_qr` ENABLE KEYS */;
DROP TABLE IF EXISTS `services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `services` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `boutique_id` bigint(20) unsigned NOT NULL,
  `famille_id` int(10) unsigned NOT NULL,
  `nom` varchar(180) NOT NULL,
  `description` text NOT NULL,
  `unite` varchar(40) NOT NULL COMMENT 'chantier, projet, participant, jour…',
  `mode_vente` enum('prix_fixe','devis') NOT NULL COMMENT 'Une formation à 75 000 F se met au panier ; une maison R+1 non. Imposer l''un des deux partout perd la moitié du catalogue',
  `prix_cfa` int(10) unsigned DEFAULT NULL COMMENT 'Mode prix_fixe uniquement',
  `fourchette_min_cfa` int(10) unsigned DEFAULT NULL COMMENT 'Mode devis : n''engage pas, évite les demandes hors budget',
  `fourchette_max_cfa` int(10) unsigned DEFAULT NULL,
  `delai_annonce` varchar(60) NOT NULL,
  `places` smallint(5) unsigned DEFAULT NULL COMMENT 'Formations : une session a une capacité, pas un stock',
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_service_boutique` (`boutique_id`,`actif`),
  KEY `fk_service_famille` (`famille_id`),
  FULLTEXT KEY `ft_services_recherche` (`nom`,`description`),
  CONSTRAINT `fk_service_boutique` FOREIGN KEY (`boutique_id`) REFERENCES `boutiques` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_service_famille` FOREIGN KEY (`famille_id`) REFERENCES `familles_service` (`id`),
  CONSTRAINT `chk_service_mode` CHECK (`mode_vente` = 'prix_fixe' and `prix_cfa` is not null or `mode_vente` = 'devis' and `fourchette_min_cfa` is not null and `fourchette_max_cfa` >= `fourchette_min_cfa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `services` DISABLE KEYS */;
/*!40000 ALTER TABLE `services` ENABLE KEYS */;
DROP TABLE IF EXISTS `sous_commandes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sous_commandes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `commande_id` bigint(20) unsigned NOT NULL,
  `boutique_id` bigint(20) unsigned NOT NULL,
  `reference` varchar(32) NOT NULL,
  `statut` enum('a_preparer','prete','expediee','livree','retournee','annulee','vendue_comptoir') NOT NULL DEFAULT 'a_preparer',
  `vente_comptoir` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Vente réalisée en présentiel, hors circuit de paiement de la plateforme',
  `etat_fonds` enum('attente_encaissement','sequestre','reverse','rembourse','impaye','hors_plateforme') NOT NULL DEFAULT 'sequestre',
  `montant_articles_ttc_cfa` int(10) unsigned NOT NULL DEFAULT 0,
  `montant_tva_cfa` int(10) unsigned NOT NULL DEFAULT 0,
  `frais_livraison_cfa` int(10) unsigned NOT NULL DEFAULT 0,
  `remise_cfa` int(10) unsigned NOT NULL DEFAULT 0,
  `taux_commission_pct` decimal(5,2) NOT NULL,
  `commission_cfa` int(10) unsigned NOT NULL DEFAULT 0,
  `taux_retenue_source_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `retenue_source_cfa` int(10) unsigned NOT NULL DEFAULT 0,
  `montant_net_cfa` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'articles − commission − retenue à la source',
  `expedie_le` datetime DEFAULT NULL,
  `livre_le` datetime DEFAULT NULL,
  `confirme_par_client_le` datetime DEFAULT NULL,
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  `modifie_le` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sous_commandes_ref` (`reference`),
  KEY `idx_sc_boutique` (`boutique_id`,`statut`),
  KEY `idx_sc_fonds` (`etat_fonds`),
  KEY `idx_sc_commande` (`commande_id`),
  CONSTRAINT `fk_sc_boutique` FOREIGN KEY (`boutique_id`) REFERENCES `boutiques` (`id`),
  CONSTRAINT `fk_sc_commande` FOREIGN KEY (`commande_id`) REFERENCES `commandes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `sous_commandes` DISABLE KEYS */;
/*!40000 ALTER TABLE `sous_commandes` ENABLE KEYS */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_sous_commande_regie BEFORE INSERT ON sous_commandes
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
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `taux_change`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `taux_change` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `devise` char(3) NOT NULL COMMENT 'EUR, USD, CNY, GBP…',
  `xof_pour_une_unite` decimal(14,6) NOT NULL COMMENT '1 EUR = 655,957 XOF',
  `source` varchar(60) NOT NULL COMMENT 'BCEAO (parité fixe), banque centrale, fournisseur',
  `marge_pct` decimal(5,2) NOT NULL DEFAULT 2.00,
  `date_debut` datetime NOT NULL,
  `date_fin` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_taux_change` (`devise`,`date_debut`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `taux_change` DISABLE KEYS */;
INSERT INTO `taux_change` (`id`, `devise`, `xof_pour_une_unite`, `source`, `marge_pct`, `date_debut`, `date_fin`) VALUES (1,'EUR',655.957000,'Parité fixe BCEAO',0.00,'2020-01-01 00:00:00',NULL),
(2,'XOF',1.000000,'Monnaie de compte',0.00,'2020-01-01 00:00:00',NULL);
/*!40000 ALTER TABLE `taux_change` ENABLE KEYS */;
DROP TABLE IF EXISTS `taux_taxe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `taux_taxe` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `pays_id` tinyint(3) unsigned NOT NULL,
  `code` varchar(20) NOT NULL COMMENT 'tva_normal, tva_reduit, tva_super_reduit',
  `libelle` varchar(80) NOT NULL,
  `taux_pct` decimal(5,2) NOT NULL COMMENT '18.00, 19.00, 10.00, 9.00…',
  `date_debut` date NOT NULL,
  `date_fin` date DEFAULT NULL COMMENT 'NULL = toujours en vigueur',
  PRIMARY KEY (`id`),
  KEY `idx_taux_pays` (`pays_id`,`code`,`date_debut`),
  CONSTRAINT `fk_taux_pays` FOREIGN KEY (`pays_id`) REFERENCES `pays` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `taux_taxe` DISABLE KEYS */;
INSERT INTO `taux_taxe` (`id`, `pays_id`, `code`, `libelle`, `taux_pct`, `date_debut`, `date_fin`) VALUES (1,1,'tva_normal','TVA taux normal',18.00,'2020-01-01',NULL),
(2,1,'tva_reduit','TVA hébergement/restauration',10.00,'2020-01-01',NULL),
(3,2,'tva_normal','TVA taux normal',18.00,'2020-01-01',NULL),
(4,2,'tva_reduit','TVA taux réduit',9.00,'2026-01-17',NULL),
(5,3,'tva_normal','TVA taux normal',18.00,'2020-01-01',NULL),
(6,3,'tva_reduit','TVA hôtellerie agréée',10.00,'2020-01-01',NULL),
(7,4,'tva_normal','TVA taux normal',18.00,'2020-01-01',NULL),
(8,4,'tva_reduit','TVA informatique et solaire',5.00,'2020-01-01',NULL),
(9,5,'tva_normal','TVA taux normal',19.00,'2020-01-01',NULL),
(10,6,'tva_normal','TVA taux normal',18.00,'2020-01-01',NULL),
(11,7,'tva_normal','TVA taux unique',18.00,'2019-01-01',NULL),
(12,8,'tva_normal','IVA taux normal',19.00,'2025-01-01',NULL),
(13,8,'tva_reduit','IVA taux réduit (Annexe I)',10.00,'2025-01-01',NULL);
/*!40000 ALTER TABLE `taux_taxe` ENABLE KEYS */;
DROP TABLE IF EXISTS `termes_interdits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `termes_interdits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `terme` varchar(60) NOT NULL,
  `gravite` enum('bloquant','a_verifier') NOT NULL DEFAULT 'a_verifier' COMMENT 'a_verifier : signalé au relecteur. bloquant : refusé dès la soumission, pour que le vendeur corrige pendant qu''il a sa fiche sous les yeux',
  `explication` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_terme_interdit` (`terme`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `termes_interdits` DISABLE KEYS */;
INSERT INTO `termes_interdits` (`id`, `terme`, `gravite`, `explication`) VALUES (1,'guerit','bloquant','Affirme un effet curatif : c\'est la définition du médicament'),
(2,'guerir','bloquant','Idem'),
(3,'remede','bloquant','Désigne explicitement un médicament'),
(4,'medicament','bloquant','Le mot suffit à faire basculer la fiche'),
(5,'anticancer','bloquant','Allégation grave, refus systématique'),
(6,'anti-cancer','bloquant','Idem'),
(7,'posologie','bloquant','Vocabulaire de prescription'),
(8,'ordonnance','bloquant','Vocabulaire de prescription'),
(9,'soigne','a_verifier','Souvent fautif, parfois innocent : « soigne la peau » en cosmétique'),
(10,'soigner','a_verifier','Idem'),
(11,'traite','a_verifier','Ambigu : « ne traite pas la peau » est licite'),
(12,'traiter','a_verifier','Idem'),
(13,'therapeutique','a_verifier','À vérifier en contexte'),
(14,'maladie','a_verifier','Selon la formulation'),
(15,'symptome','a_verifier','Selon la formulation'),
(16,'cure','a_verifier','Peut désigner une cure de boisson, licite');
/*!40000 ALTER TABLE `termes_interdits` ENABLE KEYS */;
DROP TABLE IF EXISTS `termes_recherche`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `termes_recherche` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `canon` varchar(60) NOT NULL COMMENT 'Le terme retenu, celui du catalogue',
  `variante` varchar(60) NOT NULL COMMENT 'Synonyme, nom local, traduction, orthographe',
  `nature` enum('synonyme','nom_local','traduction','orthographe','commercial') NOT NULL DEFAULT 'synonyme',
  `langue` varchar(20) DEFAULT NULL COMMENT 'moore, dioula, fulfulde, anglais…',
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_terme` (`canon`,`variante`),
  KEY `ix_terme_variante` (`variante`)
) ENGINE=InnoDB AUTO_INCREMENT=60 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `termes_recherche` DISABLE KEYS */;
INSERT INTO `termes_recherche` (`id`, `canon`, `variante`, `nature`, `langue`, `actif`, `created_at`, `updated_at`) VALUES (1,'karite','shea','traduction','anglais',1,NULL,NULL),
(2,'karite','sii','nom_local','moore',1,NULL,NULL),
(3,'karite','taama','nom_local','dioula',1,NULL,NULL),
(4,'karite','karite','orthographe',NULL,1,NULL,NULL),
(5,'bissap','oseille','synonyme',NULL,1,NULL,NULL),
(6,'bissap','oseille de guinee','synonyme',NULL,1,NULL,NULL),
(7,'bissap','hibiscus','synonyme',NULL,1,NULL,NULL),
(8,'bissap','da','nom_local','moore',1,NULL,NULL),
(9,'bissap','folere','nom_local','fulfulde',1,NULL,NULL),
(10,'bissap','roselle','traduction','anglais',1,NULL,NULL),
(11,'baobab','pain de singe','synonyme',NULL,1,NULL,NULL),
(12,'baobab','toega','nom_local','moore',1,NULL,NULL),
(13,'baobab','kuka','nom_local','haoussa',1,NULL,NULL),
(14,'baobab','bui','nom_local','dioula',1,NULL,NULL),
(15,'soumbala','nere','synonyme',NULL,1,NULL,NULL),
(16,'soumbala','netetou','nom_local','wolof',1,NULL,NULL),
(17,'soumbala','dawadawa','nom_local','haoussa',1,NULL,NULL),
(18,'soumbala','sumbala','orthographe',NULL,1,NULL,NULL),
(19,'savon noir','alata samina','nom_local','twi',1,NULL,NULL),
(20,'savon noir','savon traditionnel','synonyme',NULL,1,NULL,NULL),
(21,'pagne','wax','commercial',NULL,1,NULL,NULL),
(22,'pagne','faso dan fani','nom_local','dioula',1,NULL,NULL),
(23,'pagne','tissu imprime','synonyme',NULL,1,NULL,NULL),
(24,'bazin','bazin riche','commercial',NULL,1,NULL,NULL),
(25,'bazin','getzner','commercial',NULL,1,NULL,NULL),
(26,'boubou','grand boubou','synonyme',NULL,1,NULL,NULL),
(27,'boubou','kaftan','synonyme',NULL,1,NULL,NULL),
(28,'boubou','dashiki','synonyme',NULL,1,NULL,NULL),
(29,'miel','zom koom','nom_local','moore',1,NULL,NULL),
(30,'theiere','bouilloire','synonyme',NULL,1,NULL,NULL),
(31,'ceramique','poterie','synonyme',NULL,1,NULL,NULL),
(32,'ceramique','canari','nom_local',NULL,1,NULL,NULL),
(33,'ceramique','terre cuite','synonyme',NULL,1,NULL,NULL),
(34,'construction','villa','synonyme',NULL,1,NULL,NULL),
(35,'construction','batiment','synonyme',NULL,1,NULL,NULL),
(36,'construction','yiri','nom_local','moore',1,NULL,NULL),
(37,'construction','macon','synonyme',NULL,1,NULL,NULL),
(38,'construction','maconnerie','synonyme',NULL,1,NULL,NULL),
(39,'hangar','entrepot','synonyme',NULL,1,NULL,NULL),
(40,'hangar','magasin','synonyme',NULL,1,NULL,NULL),
(41,'hangar','charpente metallique','synonyme',NULL,1,NULL,NULL),
(42,'toiture','toit','synonyme',NULL,1,NULL,NULL),
(43,'toiture','tole','synonyme',NULL,1,NULL,NULL),
(44,'logiciel','application','synonyme',NULL,1,NULL,NULL),
(45,'logiciel','appli','synonyme',NULL,1,NULL,NULL),
(46,'logiciel','software','traduction','anglais',1,NULL,NULL),
(47,'logiciel','developpement','synonyme',NULL,1,NULL,NULL),
(48,'site web','site internet','synonyme',NULL,1,NULL,NULL),
(49,'site web','vitrine','synonyme',NULL,1,NULL,NULL),
(50,'formation','cours','synonyme',NULL,1,NULL,NULL),
(51,'formation','stage','synonyme',NULL,1,NULL,NULL),
(52,'formation','seminaire','synonyme',NULL,1,NULL,NULL),
(53,'bureautique','word','commercial',NULL,1,NULL,NULL),
(54,'bureautique','excel','commercial',NULL,1,NULL,NULL),
(55,'bureautique','microsoft office','commercial',NULL,1,NULL,NULL),
(56,'comptabilite','syscohada','commercial',NULL,1,NULL,NULL),
(57,'comptabilite','compta','orthographe',NULL,1,NULL,NULL),
(58,'notaire','acte notarie','synonyme',NULL,1,NULL,NULL),
(59,'notaire','etude notariale','synonyme',NULL,1,NULL,NULL);
/*!40000 ALTER TABLE `termes_recherche` ENABLE KEYS */;
DROP TABLE IF EXISTS `tickets_support`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tickets_support` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reference` varchar(24) NOT NULL,
  `pays_id` tinyint(3) unsigned NOT NULL,
  `utilisateur_id` bigint(20) unsigned DEFAULT NULL,
  `telephone` varchar(20) NOT NULL,
  `canal` enum('telephone','whatsapp','sms','web','agent') NOT NULL DEFAULT 'telephone' COMMENT 'Le téléphone est le canal principal sur ce marché, pas l''e-mail',
  `sujet` varchar(160) NOT NULL,
  `commande_id` bigint(20) unsigned DEFAULT NULL,
  `priorite` enum('basse','normale','haute','urgente') NOT NULL DEFAULT 'normale',
  `statut` enum('ouvert','en_cours','attente_client','resolu','clos') NOT NULL DEFAULT 'ouvert',
  `assigne_a_id` bigint(20) unsigned DEFAULT NULL,
  `echeance_reponse` datetime DEFAULT NULL,
  `premiere_reponse_le` datetime DEFAULT NULL,
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  `clos_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tickets_ref` (`reference`),
  KEY `idx_tickets_statut` (`statut`,`priorite`,`echeance_reponse`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `tickets_support` DISABLE KEYS */;
/*!40000 ALTER TABLE `tickets_support` ENABLE KEYS */;
DROP TABLE IF EXISTS `traductions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `traductions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `entite` varchar(40) NOT NULL COMMENT 'categorie, produit, gabarit_notification…',
  `entite_id` bigint(20) unsigned NOT NULL,
  `champ` varchar(40) NOT NULL COMMENT 'nom, description…',
  `langue` char(5) NOT NULL COMMENT 'fr, en, mos (mooré), dyu (dioula), bam (bambara)',
  `valeur` text NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_traductions` (`entite`,`entite_id`,`champ`,`langue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `traductions` DISABLE KEYS */;
/*!40000 ALTER TABLE `traductions` ENABLE KEYS */;
DROP TABLE IF EXISTS `transactions_paiement`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transactions_paiement` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `commande_id` bigint(20) unsigned NOT NULL,
  `prestataire_id` smallint(5) unsigned DEFAULT NULL,
  `operateur_paiement_id` smallint(5) unsigned DEFAULT NULL,
  `sens` enum('encaissement','remboursement') NOT NULL DEFAULT 'encaissement',
  `montant_cfa` int(10) unsigned NOT NULL,
  `frais_psp_cfa` int(10) unsigned NOT NULL DEFAULT 0,
  `reference_externe` varchar(100) DEFAULT NULL COMMENT 'Identifiant de transaction chez le PSP',
  `statut` enum('initiee','en_attente','reussie','echouee','expiree','annulee') NOT NULL DEFAULT 'initiee',
  `code_erreur` varchar(60) DEFAULT NULL,
  `message_erreur` varchar(255) DEFAULT NULL,
  `charge_utile_webhook` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`charge_utile_webhook`)),
  `initiee_le` datetime NOT NULL DEFAULT current_timestamp(),
  `finalisee_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tx_externe` (`prestataire_id`,`reference_externe`),
  KEY `idx_tx_commande` (`commande_id`,`statut`),
  KEY `idx_tx_statut` (`statut`,`initiee_le`),
  CONSTRAINT `fk_tx_commande` FOREIGN KEY (`commande_id`) REFERENCES `commandes` (`id`),
  CONSTRAINT `fk_tx_psp` FOREIGN KEY (`prestataire_id`) REFERENCES `prestataires_paiement` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `transactions_paiement` DISABLE KEYS */;
/*!40000 ALTER TABLE `transactions_paiement` ENABLE KEYS */;
DROP TABLE IF EXISTS `transporteurs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transporteurs` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `pays_id` tinyint(3) unsigned NOT NULL,
  `nom` varchar(80) NOT NULL,
  `type` enum('interne','partenaire','vendeur') NOT NULL DEFAULT 'partenaire' COMMENT 'vendeur = la boutique livre elle-même',
  `telephone` varchar(20) DEFAULT NULL,
  `encaisse_especes` tinyint(1) NOT NULL DEFAULT 0,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `fk_transporteurs_pays` (`pays_id`),
  CONSTRAINT `fk_transporteurs_pays` FOREIGN KEY (`pays_id`) REFERENCES `pays` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `transporteurs` DISABLE KEYS */;
/*!40000 ALTER TABLE `transporteurs` ENABLE KEYS */;
DROP TABLE IF EXISTS `utilisateurs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `utilisateurs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pays_id` tinyint(3) unsigned NOT NULL,
  `nom` varchar(120) NOT NULL,
  `telephone` varchar(20) NOT NULL COMMENT 'E.164 — identifiant de connexion',
  `email` varchar(180) DEFAULT NULL,
  `mot_de_passe` varchar(255) DEFAULT NULL,
  `langue` char(5) NOT NULL DEFAULT 'fr',
  `role` enum('client','vendeur','livreur','agent','admin') NOT NULL DEFAULT 'client',
  `kyc_niveau` enum('aucun','simplifie','complet') NOT NULL DEFAULT 'aucun',
  `kyc_valide_le` datetime DEFAULT NULL,
  `telephone_verifie_le` datetime DEFAULT NULL,
  `derniere_connexion` datetime DEFAULT NULL,
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  `modifie_le` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `anonymise_le` datetime DEFAULT NULL,
  `supprime_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_utilisateurs_tel` (`telephone`),
  UNIQUE KEY `uk_utilisateurs_email` (`email`),
  KEY `idx_utilisateurs_pays` (`pays_id`,`role`),
  CONSTRAINT `fk_utilisateurs_pays` FOREIGN KEY (`pays_id`) REFERENCES `pays` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `utilisateurs` DISABLE KEYS */;
/*!40000 ALTER TABLE `utilisateurs` ENABLE KEYS */;
DROP TABLE IF EXISTS `utilisations_promotion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `utilisations_promotion` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `promotion_id` int(10) unsigned NOT NULL,
  `commande_id` bigint(20) unsigned NOT NULL,
  `utilisateur_id` bigint(20) unsigned DEFAULT NULL,
  `remise_cfa` int(10) unsigned NOT NULL,
  `cree_le` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_utilisation` (`promotion_id`,`commande_id`),
  KEY `idx_utilisation_client` (`promotion_id`,`utilisateur_id`),
  KEY `fk_up_commande` (`commande_id`),
  CONSTRAINT `fk_up_commande` FOREIGN KEY (`commande_id`) REFERENCES `commandes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_up_promotion` FOREIGN KEY (`promotion_id`) REFERENCES `promotions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `utilisations_promotion` DISABLE KEYS */;
/*!40000 ALTER TABLE `utilisations_promotion` ENABLE KEYS */;
DROP TABLE IF EXISTS `v_lots_a_ecouler`;
/*!50001 DROP VIEW IF EXISTS `v_lots_a_ecouler`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_lots_a_ecouler` AS SELECT
 1 AS `boutique_id`,
  1 AS `produit_id`,
  1 AS `produit`,
  1 AS `variante_id`,
  1 AS `sku`,
  1 AS `declinaison`,
  1 AS `prix_normal_cfa`,
  1 AS `stock`,
  1 AS `numero_lot`,
  1 AS `date_expiration`,
  1 AS `jours_restants`,
  1 AS `remise_suggeree_pct` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `variante_attributs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `variante_attributs` (
  `variante_id` bigint(20) unsigned NOT NULL,
  `attribut_id` smallint(5) unsigned NOT NULL,
  `valeur` varchar(80) NOT NULL,
  PRIMARY KEY (`variante_id`,`attribut_id`),
  KEY `fk_va_attribut` (`attribut_id`),
  CONSTRAINT `fk_va_attribut` FOREIGN KEY (`attribut_id`) REFERENCES `attributs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_va_variante` FOREIGN KEY (`variante_id`) REFERENCES `variantes_produit` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `variante_attributs` DISABLE KEYS */;
/*!40000 ALTER TABLE `variante_attributs` ENABLE KEYS */;
DROP TABLE IF EXISTS `variantes_produit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `variantes_produit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `produit_id` bigint(20) unsigned NOT NULL,
  `sku` varchar(40) NOT NULL COMMENT 'Code article unique, imprimable en code-barres',
  `libelle` varchar(120) NOT NULL COMMENT '« Taille L — Bleu indigo », ou « Standard »',
  `prix_ttc_cfa` int(10) unsigned DEFAULT NULL COMMENT 'NULL = reprend le prix du produit',
  `stock` int(11) NOT NULL DEFAULT 0,
  `seuil_alerte` int(10) unsigned NOT NULL DEFAULT 3 COMMENT 'Alerte le vendeur avant la rupture',
  `poids_g` int(10) unsigned DEFAULT NULL,
  `defaut` tinyint(1) NOT NULL DEFAULT 0,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_variantes_sku` (`sku`),
  KEY `idx_variantes_produit` (`produit_id`,`actif`),
  CONSTRAINT `fk_variantes_produit` FOREIGN KEY (`produit_id`) REFERENCES `produits` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_variantes_prix` CHECK (`prix_ttc_cfa` is null or `prix_ttc_cfa` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `variantes_produit` DISABLE KEYS */;
/*!40000 ALTER TABLE `variantes_produit` ENABLE KEYS */;
DROP TABLE IF EXISTS `villes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `villes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `pays_id` tinyint(3) unsigned NOT NULL,
  `nom` varchar(80) NOT NULL,
  `slug` varchar(90) NOT NULL,
  `latitude` decimal(9,6) DEFAULT NULL,
  `longitude` decimal(9,6) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_villes` (`pays_id`,`slug`),
  CONSTRAINT `fk_villes_pays` FOREIGN KEY (`pays_id`) REFERENCES `pays` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `villes` DISABLE KEYS */;
INSERT INTO `villes` (`id`, `pays_id`, `nom`, `slug`, `latitude`, `longitude`, `active`) VALUES (1,1,'Ouagadougou','ouagadougou',NULL,NULL,1),
(2,1,'Bobo-Dioulasso','bobo-dioulasso',NULL,NULL,1),
(3,1,'Koudougou','koudougou',NULL,NULL,1),
(4,1,'Ouahigouya','ouahigouya',NULL,NULL,1),
(5,1,'Banfora','banfora',NULL,NULL,1),
(6,2,'Abidjan','abidjan',NULL,NULL,1),
(7,2,'Bouaké','bouake',NULL,NULL,1),
(8,2,'Yamoussoukro','yamoussoukro',NULL,NULL,1),
(9,2,'San-Pédro','san-pedro',NULL,NULL,1),
(10,3,'Dakar','dakar',NULL,NULL,1),
(11,3,'Thiès','thies',NULL,NULL,1),
(12,3,'Saint-Louis','saint-louis',NULL,NULL,1),
(13,5,'Niamey','niamey',NULL,NULL,1),
(14,5,'Zinder','zinder',NULL,NULL,1),
(15,5,'Maradi','maradi',NULL,NULL,1);
/*!40000 ALTER TABLE `villes` ENABLE KEYS */;
DROP TABLE IF EXISTS `zones_livraison`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `zones_livraison` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `pays_id` tinyint(3) unsigned NOT NULL,
  `ville_id` int(10) unsigned DEFAULT NULL,
  `quartier` varchar(80) DEFAULT NULL COMMENT 'NULL = tarif par défaut de la ville',
  `frais_base_cfa` int(10) unsigned NOT NULL,
  `frais_boutique_sup_cfa` int(10) unsigned NOT NULL DEFAULT 0,
  `frais_par_kg_cfa` int(10) unsigned NOT NULL DEFAULT 0,
  `delai_estime_jours` tinyint(3) unsigned NOT NULL DEFAULT 2,
  `paiement_livraison_autorise` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Le paiement en espèces se refuse dans les zones à risque',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_zones` (`pays_id`,`ville_id`,`quartier`),
  KEY `fk_zones_ville` (`ville_id`),
  CONSTRAINT `fk_zones_pays` FOREIGN KEY (`pays_id`) REFERENCES `pays` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_zones_ville` FOREIGN KEY (`ville_id`) REFERENCES `villes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `zones_livraison` DISABLE KEYS */;
INSERT INTO `zones_livraison` (`id`, `pays_id`, `ville_id`, `quartier`, `frais_base_cfa`, `frais_boutique_sup_cfa`, `frais_par_kg_cfa`, `delai_estime_jours`, `paiement_livraison_autorise`, `active`) VALUES (1,1,5,NULL,2500,1200,0,4,0,1),
(2,1,2,NULL,2500,1200,0,4,0,1),
(3,1,3,NULL,2500,1200,0,4,0,1),
(4,1,1,NULL,1500,900,0,2,1,1),
(5,1,4,NULL,2500,1200,0,4,0,1),
(6,2,6,NULL,1500,900,0,2,1,1),
(7,2,7,NULL,2500,1200,0,4,0,1),
(8,2,9,NULL,2500,1200,0,4,0,1),
(9,2,8,NULL,2500,1200,0,4,0,1),
(10,5,15,NULL,2500,1200,0,4,0,1),
(11,5,13,NULL,1500,900,0,2,1,1),
(12,5,14,NULL,2500,1200,0,4,0,1),
(13,3,10,NULL,1500,900,0,2,1,1),
(14,3,12,NULL,2500,1200,0,4,0,1),
(15,3,11,NULL,2500,1200,0,4,0,1);
/*!40000 ALTER TABLE `zones_livraison` ENABLE KEYS */;
/*!50001 DROP VIEW IF EXISTS `v_lots_a_ecouler`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_lots_a_ecouler` AS select `p`.`boutique_id` AS `boutique_id`,`p`.`id` AS `produit_id`,`p`.`nom` AS `produit`,`v`.`id` AS `variante_id`,`v`.`sku` AS `sku`,`v`.`libelle` AS `declinaison`,`v`.`prix_ttc_cfa` AS `prix_normal_cfa`,`v`.`stock` AS `stock`,`l`.`reference` AS `numero_lot`,`l`.`date_expiration` AS `date_expiration`,to_days(`l`.`date_expiration`) - to_days(curdate()) AS `jours_restants`,case when to_days(`l`.`date_expiration`) - to_days(curdate()) <= 7 then 50 when to_days(`l`.`date_expiration`) - to_days(curdate()) <= 15 then 40 when to_days(`l`.`date_expiration`) - to_days(curdate()) <= 30 then 30 else 20 end AS `remise_suggeree_pct` from ((`lots_qr` `l` join `variantes_produit` `v` on(`v`.`id` = `l`.`variante_id`)) join `produits` `p` on(`p`.`id` = `v`.`produit_id`)) where `l`.`date_expiration` is not null and `v`.`stock` > 0 and to_days(`l`.`date_expiration`) - to_days(curdate()) > 0 and to_days(`l`.`date_expiration`) - to_days(curdate()) <= coalesce((select cast(`parametres`.`valeur` as unsigned) from `parametres` where `parametres`.`cle` = 'liquidation_seuil_peremption_jours'),45) and !exists(select 1 from `liquidations` `q` where `q`.`variante_id` = `v`.`id` and `q`.`fin_le` >= current_timestamp() limit 1) order by to_days(`l`.`date_expiration`) - to_days(curdate()) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


SET FOREIGN_KEY_CHECKS = 1;
