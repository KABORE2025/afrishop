-- =====================================================================
--  SCÉNARIO DE VALIDATION v2.2
-- =====================================================================
--  Ce fichier ne peuple pas une démo : il PROUVE que les règles tiennent.
--  Chaque bloc affiche un résultat qu'on peut lire et contredire.
-- =====================================================================

SET @b_koko  := (SELECT id FROM boutiques WHERE nom LIKE 'Atelier%' LIMIT 1);
-- La boutique varie selon le jeu chargé : on prend la première active
-- plutôt qu'un nom en dur, sinon le scénario casse dès qu'on change le seed.
SET @b_terr  := (SELECT id FROM boutiques WHERE id <> @b_koko ORDER BY id LIMIT 1);
SET @cat_nat := (SELECT id FROM categories WHERE reservee = 1 LIMIT 1);
-- v2.4 : le verrou « régie seule » de la v2.3 est retiré. Les catégories
-- réservées redeviennent ouvertes aux boutiques tierces, sur autorisation
-- fiche par fiche. Ce sont donc des boutiques ordinaires qui portent ces
-- produits — et c'est le champ `autorisations_publication.statut` qui
-- décide de ce que le client voit, pas l'identité du vendeur.
SET @pays_bf := (SELECT id FROM pays WHERE code_iso2 = 'BF' LIMIT 1);

-- ---------------------------------------------------------------------
-- 1. LIQUIDATION — le cas nominal
-- ---------------------------------------------------------------------
SET @var := (SELECT id FROM variantes_produit LIMIT 1);
SET @prix := (SELECT prix_ttc_cfa FROM variantes_produit WHERE id = @var);

INSERT INTO liquidations
  (variante_id, motif_id, prix_liquide_cfa, prix_reference_cfa,
   debut_le, fin_le, date_peremption, detail, created_at, updated_at)
VALUES
  (@var, (SELECT id FROM motifs_liquidation WHERE code='peremption'),
   ROUND(@prix * 0.7), @prix,
   NOW() - INTERVAL 5 DAY, NOW() + INTERVAL 20 DAY, CURDATE() + INTERVAL 30 DAY,
   'Lot récolte 2025, à consommer avant la date indiquée.', NOW(), NOW());

SELECT '1 · LIQUIDATION NOMINALE' AS controle;
SELECT v.sku,
       l.prix_reference_cfa AS prix_normal,
       l.prix_liquide_cfa   AS prix_liquide,
       CONCAT(ROUND((1 - l.prix_liquide_cfa / l.prix_reference_cfa) * 100), ' %') AS remise,
       m.libelle            AS motif,
       l.date_peremption
FROM liquidations l
JOIN variantes_produit v ON v.id = l.variante_id
JOIN motifs_liquidation m ON m.id = l.motif_id;

-- ---------------------------------------------------------------------
-- 2. SERVICES ET JALONS
-- ---------------------------------------------------------------------
INSERT INTO services
  (boutique_id, famille_id, nom, description, unite, mode_vente,
   fourchette_min_cfa, fourchette_max_cfa, delai_annonce, actif, created_at, updated_at)
VALUES
  (@b_koko, (SELECT id FROM familles_service WHERE code='btp'),
   'Construction R+1',
   'Bâtiment à un étage, structure poteaux-poutres, dalle pleine.',
   'chantier', 'devis', 12000000, 28000000, '8 à 14 mois', 1, NOW(), NOW());
SET @svc := LAST_INSERT_ID();

INSERT INTO jalons_type (service_id, ordre, libelle, pourcentage) VALUES
  (@svc, 1, 'Étude et fondations',        25),
  (@svc, 2, 'Élévation rez-de-chaussée',  25),
  (@svc, 3, 'Dalle et étage',             30),
  (@svc, 4, 'Finitions et réception',     20);

INSERT INTO demandes_devis
  (reference, service_id, boutique_id, client_nom, client_telephone, localisation,
   budget_envisage_cfa, besoin, echeance, statut, created_at, updated_at)
VALUES
  ('DEM-2026-0001', @svc, @b_koko, 'Boureima Sawadogo', '+22670442107', 'Saaba',
   22000000, 'Terrain 400 m² viabilisé. Quatre chambres à l''étage.', '1_3_mois',
   'chiffre', NOW(), NOW());
SET @dem := LAST_INSERT_ID();

-- Commission au barème : 500 000×10 % + 4 500 000×5 % + 20 000 000×1 %
--                      =      50 000  +      225 000 +      200 000 = 475 000
INSERT INTO devis
  (reference, demande_id, montant_cfa, commission_cfa, taux_moyen_pct,
   valable_jusqu_au, detail, statut, accepte_le, created_at, updated_at)
VALUES
  ('DEV-2026-0001', @dem, 25000000, 475000, 1.90,
   CURDATE() + INTERVAL 30 DAY, 'Chiffrage après visite du 2 août.', 'accepte', NOW(), NOW(), NOW());
SET @dev := LAST_INSERT_ID();

INSERT INTO jalons (devis_id, ordre, libelle, pourcentage, montant_cfa, statut, etat_fonds, created_at, updated_at)
SELECT @dev, jt.ordre, jt.libelle, jt.pourcentage,
       FLOOR(25000000 * jt.pourcentage / 100),
       CASE jt.ordre WHEN 1 THEN 'valide' WHEN 2 THEN 'en_cours' ELSE 'a_venir' END,
       CASE jt.ordre WHEN 1 THEN 'reverse' WHEN 2 THEN 'sequestre' ELSE 'non_appele' END,
       NOW(), NOW()
FROM jalons_type jt WHERE jt.service_id = @svc ORDER BY jt.ordre;

SELECT '2 · JALONS D''UN CHANTIER À 25 MILLIONS' AS controle;
SELECT ordre, libelle, CONCAT(pourcentage,' %') AS part,
       FORMAT(montant_cfa, 0) AS montant, statut, etat_fonds
FROM jalons WHERE devis_id = @dev ORDER BY ordre;

SELECT '2b · CE QUE LA PLATEFORME DÉTIENT RÉELLEMENT' AS controle;
SELECT FORMAT(25000000, 0)                                    AS montant_du_contrat,
       FORMAT(SUM(CASE WHEN etat_fonds='sequestre' THEN montant_cfa ELSE 0 END), 0)
                                                              AS reellement_sequestre,
       CONCAT(ROUND(SUM(CASE WHEN etat_fonds='sequestre' THEN montant_cfa ELSE 0 END)
              / 25000000 * 100), ' %')                        AS part_exposee
FROM jalons WHERE devis_id = @dev;

SELECT '2c · SOMME DES JALONS = MONTANT DU DEVIS ?' AS controle;
SELECT FORMAT(d.montant_cfa, 0)      AS devis,
       FORMAT(SUM(j.montant_cfa), 0) AS somme_jalons,
       SUM(j.pourcentage)            AS somme_pct,
       IF(SUM(j.montant_cfa) = d.montant_cfa, 'ÉQUILIBRÉ', 'ÉCART') AS verdict
FROM devis d JOIN jalons j ON j.devis_id = d.id
WHERE d.id = @dev GROUP BY d.id, d.montant_cfa;

-- ---------------------------------------------------------------------
-- 3. CATÉGORIE RÉSERVÉE — le silence vaut refus
-- ---------------------------------------------------------------------
INSERT INTO produits (boutique_id, categorie_id, reference, nom, slug, description,
                      prix_ttc_cfa, actif, tracable, statut_moderation)
VALUES
 (@b_terr, @cat_nat, 'PRD-NAT-0001', 'Feuilles de baobab séchées', 'feuilles-baobab-sechees',
  'Feuilles de baobab récoltées et séchées à l''ombre, réduites en poudre. Usage culinaire traditionnel : épaississant des sauces.',
  1500, 1, 1, 'publie'),
 (@b_terr, @cat_nat, 'PRD-NAT-0002', 'Poudre de moringa', 'poudre-moringa',
  'Feuilles de moringa oleifera séchées et broyées. Se consomme dans les boissons ou les plats.',
  2800, 1, 1, 'publie'),
 (@b_koko, @cat_nat, 'PRD-NAT-0003', 'Écorce de kinkéliba', 'ecorce-kinkeliba',
  'Tisane traditionnelle qui soigne les troubles digestifs et traite les problèmes de foie. Remède efficace contre la fatigue.',
  1200, 1, 0, 'publie');

SET @p_ok  := (SELECT id FROM produits WHERE slug='feuilles-baobab-sechees');
SET @p_att := (SELECT id FROM produits WHERE slug='poudre-moringa');
SET @p_ko  := (SELECT id FROM produits WHERE slug='ecorce-kinkeliba');

INSERT INTO autorisations_publication (produit_id, statut, motif, demande_le, decide_le, created_at, updated_at) VALUES
 (@p_ok,  'accorde', 'Conforme — usage culinaire, aucune allégation.',
  NOW() - INTERVAL 21 DAY, NOW() - INTERVAL 19 DAY, NOW(), NOW()),
 (@p_att, 'demande', NULL, NOW() - INTERVAL 2 DAY, NULL, NOW(), NOW()),
 (@p_ko,  'refuse',  '« soigne », « traite », « remède efficace » : à retirer intégralement. Décrivez la plante et sa préparation, pas ses effets sur la santé.',
  NOW() - INTERVAL 9 DAY, NOW() - INTERVAL 8 DAY, NOW(), NOW());

SELECT '3 · CE QUE LE CLIENT VOIT EN CATÉGORIE RÉSERVÉE' AS controle;
SELECT p.nom,
       b.nom AS vendue_par,
       COALESCE(a.statut, '(aucune demande)') AS autorisation,
       IF(a.statut = 'accorde', 'VISIBLE', 'invisible') AS en_vitrine
FROM produits p
JOIN boutiques b ON b.id = p.boutique_id
LEFT JOIN autorisations_publication a ON a.produit_id = p.id
WHERE p.categorie_id = @cat_nat ORDER BY p.id;

SELECT '3c · CE QUI DÉCIDE DE LA VISIBILITÉ' AS controle;
SELECT 'l''autorisation, pas l''identité du vendeur' AS regle,
       COUNT(DISTINCT p.boutique_id) AS boutiques_tierces_sur_le_rayon
FROM produits p WHERE p.categorie_id = @cat_nat;

SELECT '3b · TERMES INTERDITS PRÉSENTS DANS LA FICHE REFUSÉE' AS controle;
SELECT t.terme, t.gravite
FROM termes_interdits t, produits p
WHERE p.id = @p_ko
  AND LOWER(CONCAT(p.nom,' ',p.description)) LIKE CONCAT('%', LOWER(t.terme), '%')
ORDER BY t.gravite, t.terme;

-- ---------------------------------------------------------------------
-- 4. ANNUAIRE
-- ---------------------------------------------------------------------
INSERT INTO professionnels
 (profession, nom, pays_id, ville, ordre_ou_association, numero_inscription,
  verifie_le, verification_expire_le, telephone, actes, avertissement_sante, publie, created_at, updated_at)
VALUES
 ('Notaire', 'Maître Salamata Ouédraogo', @pays_bf, 'Ouagadougou',
  'Chambre des notaires du Burkina Faso', 'NOT-BF-0142',
  '2026-03-11', '2027-03-11', '+22625304412',
  '["Vente immobilière","Succession","Constitution de société"]', 0, 1, NOW(), NOW()),
 ('Tradipraticien', 'Naaba Tigré Sawadogo', @pays_bf, 'Ouagadougou',
  'Association des tradipraticiens de santé du Burkina', 'TPS-BF-0455',
  '2026-04-18', '2027-04-18', '+22676224109',
  '["Consultation","Préparations à base de plantes"]', 1, 1, NOW(), NOW());

SELECT '4 · ANNUAIRE — AUCUN LIEN VERS LES COMMANDES' AS controle;
SELECT p.profession, p.nom, p.numero_inscription,
       IF(p.verification_expire_le > CURDATE(), 'vérification valide', 'À REVÉRIFIER') AS verification,
       IF(p.avertissement_sante, 'avertissement affiché', '—') AS sante
FROM professionnels p WHERE p.publie = 1;

SELECT '4b · UNE CLÉ ÉTRANGÈRE RELIE-T-ELLE professionnels AUX COMMANDES ?' AS controle;
SELECT IFNULL(GROUP_CONCAT(referenced_table_name), 'AUCUNE — conforme : la plateforme n''encaisse pas d''honoraires') AS resultat
FROM information_schema.key_column_usage
WHERE table_schema = DATABASE() AND table_name = 'professionnels'
  AND referenced_table_name IN ('commandes','sous_commandes','lignes_commande');

-- ---------------------------------------------------------------------
-- 5. RECHERCHE — familles de synonymes
-- ---------------------------------------------------------------------
SELECT '5 · FAMILLE DU TERME « da » (bissap en mooré)' AS controle;
SELECT GROUP_CONCAT(DISTINCT variante ORDER BY variante SEPARATOR ' · ') AS reconnu_aussi_sous
FROM termes_recherche
WHERE canon = (SELECT canon FROM termes_recherche WHERE variante = 'da' LIMIT 1);

SELECT '5b · « maison » EST-IL DANS LA FAMILLE CONSTRUCTION ?' AS controle;
SELECT IFNULL((SELECT variante FROM termes_recherche WHERE canon='construction' AND variante='maison'),
              'NON — volontaire : « Maison » est aussi une catégorie du catalogue') AS resultat;

INSERT INTO recherches_infructueuses
 (terme_normalise, terme_original, occurrences, premiere_fois, derniere_fois, traitement)
VALUES
 ('gnamakoudji', 'gnamakoudji', 7, NOW() - INTERVAL 12 DAY, NOW() - INTERVAL 1 DAY, 'a_traiter'),
 ('aspirine',    'aspirine',    2, NOW() - INTERVAL 3 DAY,  NOW(),                  'a_traiter');

SELECT '5c · FILE DE TRAVAIL DU RESPONSABLE CATALOGUE' AS controle;
SELECT terme_normalise, occurrences, traitement FROM recherches_infructueuses ORDER BY occurrences DESC;
