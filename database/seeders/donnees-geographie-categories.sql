-- =====================================================================
--  AFRISHOP — VILLES, ZONES DE LIVRAISON ET CATÉGORIES
-- =====================================================================
--  POURQUOI CE FICHIER EXISTE
--
--  Ces trois référentiels vivaient jusqu'ici dans le jeu de DÉMONSTRATION.
--  Conséquence mesurée sur une installation neuve : 0 ville,
--  0 zone de livraison, 1 seule catégorie — et donc AUCUNE COMMANDE
--  POSSIBLE. Le formulaire de livraison en zone ouest-africaine exige de
--  choisir une ville, et un produit ne peut être classé nulle part.
--
--  Ce sont des données de référence, pas de la démonstration : elles
--  décrivent le monde réel, pas un scénario. Elles ont donc leur place
--  dans une installation vierge.
--
--  Les frais sont des ORDRES DE GRANDEUR à réviser avant exploitation :
--  ils viennent d'une estimation, pas d'un contrat transporteur signé.
--  Ils sont volontairement plus élevés hors des capitales, où la
--  livraison coûte réellement plus cher.
-- =====================================================================

-- ---------------------------------------------------------------------
-- CATÉGORIES ORDINAIRES
-- ---------------------------------------------------------------------
-- « Produits naturels » est déjà créée par donnees-v22.sql avec son
-- indicateur `reservee`. On ne la recrée pas ici : une catégorie en
-- double casserait le classement du catalogue en deux moitiés.
-- ---------------------------------------------------------------------
INSERT INTO categories (nom, slug, emoji, code_taxe, ordre, reservee, active) VALUES
('Beauté',    'beaute',    '💧', 'tva_normal', 10, 0, 1),
('Artisanat', 'artisanat', '🪡', 'tva_normal', 20, 0, 1),
('Textile',   'textile',   '🧵', 'tva_normal', 30, 0, 1),
('Maison',    'maison',    '🏠', 'tva_normal', 40, 0, 1),
('Épicerie',  'epicerie',  '🌾', 'tva_reduit', 50, 0, 1),
('Bijoux',    'bijoux',    '📿', 'tva_normal', 60, 0, 1),
('Services',  'services',  '🔧', 'tva_normal', 70, 0, 1);

-- ---------------------------------------------------------------------
-- VILLES DES QUATRE PAYS OÙ LES BOUTIQUES PEUVENT S'INSCRIRE
-- ---------------------------------------------------------------------
-- Limité aux villes réellement desservies. Une ville proposée au client
-- mais que personne ne livre produit une commande impossible à honorer
-- — et un litige garanti.
-- ---------------------------------------------------------------------
INSERT INTO villes (pays_id, nom, slug, active) VALUES
((SELECT id FROM pays WHERE code_iso2='BF'),'Ouagadougou',   'ouagadougou',    1),
((SELECT id FROM pays WHERE code_iso2='BF'),'Bobo-Dioulasso','bobo-dioulasso', 1),
((SELECT id FROM pays WHERE code_iso2='BF'),'Koudougou',     'koudougou',      1),
((SELECT id FROM pays WHERE code_iso2='BF'),'Ouahigouya',    'ouahigouya',     1),
((SELECT id FROM pays WHERE code_iso2='BF'),'Banfora',       'banfora',        1),
((SELECT id FROM pays WHERE code_iso2='CI'),'Abidjan',       'abidjan',        1),
((SELECT id FROM pays WHERE code_iso2='CI'),'Bouaké',        'bouake',         1),
((SELECT id FROM pays WHERE code_iso2='CI'),'Yamoussoukro',  'yamoussoukro',   1),
((SELECT id FROM pays WHERE code_iso2='CI'),'San-Pédro',     'san-pedro',      1),
((SELECT id FROM pays WHERE code_iso2='SN'),'Dakar',         'dakar',          1),
((SELECT id FROM pays WHERE code_iso2='SN'),'Thiès',         'thies',          1),
((SELECT id FROM pays WHERE code_iso2='SN'),'Saint-Louis',   'saint-louis',    1),
((SELECT id FROM pays WHERE code_iso2='NE'),'Niamey',        'niamey',         1),
((SELECT id FROM pays WHERE code_iso2='NE'),'Zinder',        'zinder',         1),
((SELECT id FROM pays WHERE code_iso2='NE'),'Maradi',        'maradi',         1);

-- ---------------------------------------------------------------------
-- ZONES DE LIVRAISON
-- ---------------------------------------------------------------------
-- Une zone par ville, sans découpage par quartier au démarrage : mieux
-- vaut un tarif unique honnête qu'un découpage fin et faux.
--
-- `frais_boutique_sup_cfa` est le supplément par boutique additionnelle
-- dans la même commande. Il existe parce qu'une commande éclatée sur
-- trois boutiques, c'est trois retraits et trois trajets — le facturer
-- une seule fois ferait vendre la livraison à perte.
--
-- `paiement_livraison_autorise` est à 0 hors des capitales : le paiement
-- à la livraison suppose un livreur qui manipule des espèces et rentre
-- les déposer. Ce circuit n'existe pas partout, et l'ouvrir là où il
-- n'existe pas revient à promettre ce qu'on ne peut pas tenir.
-- ---------------------------------------------------------------------
INSERT INTO zones_livraison
 (pays_id, ville_id, quartier, frais_base_cfa, frais_boutique_sup_cfa,
  frais_par_kg_cfa, delai_estime_jours, paiement_livraison_autorise, active)
SELECT v.pays_id, v.id, NULL,
       CASE v.slug
         WHEN 'ouagadougou' THEN 1500 WHEN 'abidjan' THEN 1500
         WHEN 'dakar'       THEN 1500 WHEN 'niamey'  THEN 1500
         ELSE 2500 END,
       CASE v.slug
         WHEN 'ouagadougou' THEN 900 WHEN 'abidjan' THEN 900
         WHEN 'dakar'       THEN 900 WHEN 'niamey'  THEN 900
         ELSE 1200 END,
       0,
       CASE v.slug
         WHEN 'ouagadougou' THEN 2 WHEN 'abidjan' THEN 2
         WHEN 'dakar'       THEN 2 WHEN 'niamey'  THEN 2
         ELSE 4 END,
       CASE v.slug
         WHEN 'ouagadougou' THEN 1 WHEN 'abidjan' THEN 1
         WHEN 'dakar'       THEN 1 WHEN 'niamey'  THEN 1
         ELSE 0 END,
       1
FROM villes v
WHERE v.pays_id IN (SELECT id FROM pays WHERE code_iso2 IN ('BF','CI','SN','NE'));
