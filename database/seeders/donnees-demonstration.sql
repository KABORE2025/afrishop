-- =====================================================================
--  AFRISHOP — DONNÉES DE DÉMONSTRATION
-- =====================================================================
--  À jouer APRÈS `afrishop-installation-complete.sql`, et uniquement si
--  vous voulez une vitrine peuplée. Rien ici n'est réel : ni ces
--  boutiques, ni ces artisans, ni ces prix.
--
--      mysql -u root afrishope < database/seeders/donnees-demonstration.sql
--
--  POUR TOUT EFFACER et retrouver une base vierge :
--      DELETE FROM variantes_produit; DELETE FROM produits;
--      DELETE FROM boutiques; DELETE FROM utilisateurs;
--
--  CE JEU EST CALIBRÉ, PAS DÉCORATIF. Il contient volontairement :
--    · deux boutiques vendant LE MÊME produit à des prix différents
--      (« Beurre de karité brut »), sans quoi le comparateur n'a rien à
--      comparer et on ne voit pas à quoi il sert ;
--    · une boutique avec numéro fiscal et une sans, pour que l'écart de
--      retenue à la source (5 % contre 25 %) se voie sur de vrais
--      montants ;
--    · un produit en rupture, pour vérifier qu'il ne s'achète pas ;
--    · un produit traçable QR et un qui ne l'est pas.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Comptes vendeurs. Le téléphone sert d'identifiant : c'est ce que les
-- vendeurs possèdent tous, contrairement à une adresse électronique.
-- ---------------------------------------------------------------------
SET @bf := (SELECT id FROM pays WHERE code_iso2 = 'BF');
SET @ci := (SELECT id FROM pays WHERE code_iso2 = 'CI');
SET @ouaga := (SELECT id FROM villes WHERE slug = 'ouagadougou');
SET @bobo  := (SELECT id FROM villes WHERE slug = 'bobo-dioulasso');
SET @abj   := (SELECT id FROM villes WHERE slug = 'abidjan');

INSERT INTO utilisateurs (pays_id, nom, telephone, role) VALUES
 (@bf, 'Aminata Sawadogo', '+22670112233', 'vendeur'),
 (@bf, 'Issa Ouattara',    '+22676458912', 'vendeur'),
 (@ci, 'Clarisse Amani',   '+22505446788', 'vendeur');

SET @u1 := (SELECT id FROM utilisateurs WHERE telephone = '+22670112233');
SET @u2 := (SELECT id FROM utilisateurs WHERE telephone = '+22676458912');
SET @u3 := (SELECT id FROM utilisateurs WHERE telephone = '+22505446788');

-- ---------------------------------------------------------------------
-- Boutiques. Noter `numero_fiscal` : renseigné pour deux d'entre elles,
-- NULL pour Atelier Kôkô. C'est ce qui fait passer sa retenue à la
-- source de 5 % à 25 % — l'écart le plus coûteux du système pour un
-- artisan, et celui qu'il faut lui montrer chiffré.
-- ---------------------------------------------------------------------
INSERT INTO boutiques
 (utilisateur_id, pays_id, ville_id, code, nom, slug, emoji, description,
  telephone, taux_commission, taux_commission_comptoir, statut, niveau,
  type_boutique, vend_en_ligne, vend_au_comptoir, numero_fiscal) VALUES
 (@u1, @bf, @ouaga, 'BF-V001', 'Karité du Sahel', 'karite-du-sahel', '🧴',
  'Coopérative de 40 femmes. Karité, savons et huiles pressées à froid.',
  '+226 70 11 22 33', 10, 0, 'actif', 'verifie', 'cooperative', 1, 1, 'IFU00123456789'),
 (@u2, @bf, @bobo,  'BF-V002', 'Atelier Kôkô', 'atelier-koko', '👜',
  'Maroquinerie et sculpture sur bois. Pièces uniques.',
  '+226 76 45 89 12', 12, 0, 'actif', 'nouveau', 'artisan', 1, 1, NULL),
 (@u3, @ci, @abj,   'CI-V002', 'Nature & Baobab', 'nature-baobab', '🌿',
  'Cosmétiques naturels : karité, baobab, huiles pressées à froid.',
  '+225 05 44 67 88', 10, 0, 'actif', 'verifie', 'artisan', 1, 1, 'CI-NCC-8823');

SET @b1 := (SELECT id FROM boutiques WHERE code = 'BF-V001');
SET @b2 := (SELECT id FROM boutiques WHERE code = 'BF-V002');
SET @b3 := (SELECT id FROM boutiques WHERE code = 'CI-V002');

SET @cBeaute    := (SELECT id FROM categories WHERE slug = 'beaute');
SET @cArtisanat := (SELECT id FROM categories WHERE slug = 'artisanat');
SET @cEpicerie  := (SELECT id FROM categories WHERE slug = 'epicerie');

-- ---------------------------------------------------------------------
-- Produits. `prix_ttc_cfa` sur le produit est un prix d'appel : le prix
-- qui fait foi est celui de la VARIANTE. Deux boutiques vendent le même
-- « Beurre de karité brut » — c'est la paire que le comparateur doit
-- savoir rapprocher.
-- ---------------------------------------------------------------------
INSERT INTO produits
 (boutique_id, categorie_id, reference, nom, slug, description,
  prix_ttc_cfa, poids_g, actif, tracable) VALUES
 (@b1, @cBeaute, 'P01', 'Beurre de karité brut', 'beurre-karite-brut-sahel',
  'Non raffiné, pressé à froid par la coopérative. Se conserve deux ans à l''abri de la chaleur.',
  2200, 250, 1, 1),
 (@b1, @cBeaute, 'P02', 'Savon noir africain', 'savon-noir-africain',
  'Savon traditionnel à base de beurre de karité et de cendres de cabosses de cacao.',
  1800, 300, 1, 1),
 (@b2, @cArtisanat, 'P03', 'Sac en cuir tanné', 'sac-cuir-tanne',
  'Cuir tanné et cousu à la main à Bobo-Dioulasso. Chaque pièce est unique.',
  24000, 900, 1, 0),
 (@b2, @cArtisanat, 'P04', 'Masque en bois sculpté', 'masque-bois-sculpte',
  'Sculpté dans un seul bloc de bois de vène. Pièce unique, finition huilée.',
  18500, 1400, 1, 0),
 (@b3, @cBeaute, 'P09', 'Beurre de karité brut', 'beurre-karite-brut-baobab',
  'Karité de Côte d''Ivoire, filtré deux fois. Texture plus fine, odeur plus discrète.',
  2600, 250, 1, 1),
 (@b3, @cEpicerie, 'P10', 'Huile de baobab', 'huile-de-baobab',
  'Pressée à froid, riche en oméga. Flacon ambré pour la protéger de la lumière.',
  4500, 120, 1, 1);

-- ---------------------------------------------------------------------
-- Variantes. LE STOCK EST ICI, jamais sur le produit. Le pot de 250 g
-- de « Savon noir » est volontairement à 0 : il doit apparaître en
-- rupture et refuser la mise au panier.
-- ---------------------------------------------------------------------
INSERT INTO variantes_produit (produit_id, sku, libelle, prix_ttc_cfa, stock, defaut, actif)
SELECT p.id, v.sku, v.libelle, v.prix, v.stock, v.def, 1
FROM produits p JOIN (
  SELECT 'P01' r, 'P01-250'  sku, 'Pot 250 g'  libelle, 2200 prix, 41 stock, 1 def UNION ALL
  SELECT 'P01', 'P01-500',  'Pot 500 g',  3500, 57, 0 UNION ALL
  SELECT 'P01', 'P01-1000', 'Pot 1 kg',   6200, 12, 0 UNION ALL
  SELECT 'P02', 'P02-STD',  'Pain 300 g', 1800,  0, 1 UNION ALL
  SELECT 'P03', 'P03-M',    'Modèle M',  24000,  4, 1 UNION ALL
  SELECT 'P03', 'P03-L',    'Modèle L',  28000,  2, 0 UNION ALL
  SELECT 'P04', 'P04-STD',  'Pièce unique', 18500, 3, 1 UNION ALL
  SELECT 'P09', 'P09-500',  'Pot 500 g',  2600, 35, 1 UNION ALL
  SELECT 'P10', 'P10-100',  'Flacon 100 ml', 4500, 27, 1
) v ON v.r = p.reference;

SELECT CONCAT(
  (SELECT COUNT(*) FROM boutiques), ' boutiques, ',
  (SELECT COUNT(*) FROM produits),  ' produits, ',
  (SELECT COUNT(*) FROM variantes_produit), ' variantes'
) AS charge;
