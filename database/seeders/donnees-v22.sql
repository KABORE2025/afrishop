-- =====================================================================
--  AFRISHOP — DONNÉES DE RÉFÉRENCE v2.2
-- =====================================================================

-- ---- Motifs de liquidation ------------------------------------------
INSERT INTO motifs_liquidation (code, libelle, aide_client, date_limite_obligatoire, ordre) VALUES
('peremption', 'Date limite proche',
 'Le lot approche sa date de consommation. Elle est affichée en clair, et la vente se ferme automatiquement à cette date.', 1, 1),
('surstock', 'Surstock',
 'Trop de stock, rotation lente. Le produit est neuf et parfaitement conforme.', 0, 2),
('fin_serie', 'Fin de série',
 'Dernières pièces, modèle arrêté. Aucun défaut.', 0, 3),
('defaut', 'Défaut mineur',
 'Petit défaut visible, décrit sur la fiche. Vendu en connaissance de cause.', 0, 4);

-- ---- Familles de service --------------------------------------------
INSERT INTO familles_service (code, libelle, devis_obligatoire, profession_reglementee) VALUES
('btp',       'BTP et construction',    1, 0),
('logiciel',  'Génie logiciel',         0, 0),
('formation', 'Formation',              0, 0),
('juridique', 'Juridique et notarial',  1, 1),
('autre',     'Autres prestations',     0, 0);

-- ---- Barème de commission dégressif ---------------------------------
-- 10 % jusqu'à 500 000, 5 % jusqu'à 5 millions, 1 % au-delà.
-- Contrôle : 25 000 000 F → 50 000 + 225 000 + 200 000 = 475 000 (1,9 %)
INSERT INTO bareme_commission (applique_a, plafond_cfa, taux_pct, ordre, en_vigueur_du) VALUES
('service',   500000, 10.00, 1, '2026-01-01'),
('service',  5000000,  5.00, 2, '2026-01-01'),
('service',     NULL,  1.00, 3, '2026-01-01');

-- ---- Catégorie réservée ---------------------------------------------
INSERT INTO categories (nom, slug, emoji, code_taxe, ordre, reservee, note_reserve) VALUES
('Produits naturels', 'produits-naturels', '🌿', 'tva_normal', 90, 1,
 'Plantes, écorces, poudres et préparations traditionnelles. Chaque fiche est relue avant publication : aucune allégation thérapeutique n''est acceptée.');

-- ---- Vocabulaire proscrit sur une fiche produit ---------------------
-- « bloquant »   : refusé dès la soumission, pour que le vendeur corrige
--                  pendant qu'il a sa fiche sous les yeux.
-- « a_verifier » : signalé au relecteur, qui tranche. « ne traite pas la
--                  peau » contient « traite » et reste innocent.
INSERT INTO termes_interdits (terme, gravite, explication) VALUES
('guerit',        'bloquant',   'Affirme un effet curatif : c''est la définition du médicament'),
('guerir',        'bloquant',   'Idem'),
('guérit',        'bloquant',   'Idem'),
('remede',        'bloquant',   'Désigne explicitement un médicament'),
('remède',        'bloquant',   'Idem'),
('medicament',    'bloquant',   'Le mot suffit à faire basculer la fiche'),
('médicament',    'bloquant',   'Idem'),
('anticancer',    'bloquant',   'Allégation grave, refus systématique'),
('anti-cancer',   'bloquant',   'Idem'),
('posologie',     'bloquant',   'Vocabulaire de prescription'),
('ordonnance',    'bloquant',   'Vocabulaire de prescription'),
('soigne',        'a_verifier', 'Souvent fautif, parfois innocent : « soigne la peau » en cosmétique'),
('soigner',       'a_verifier', 'Idem'),
('traite',        'a_verifier', 'Ambigu : « ne traite pas la peau » est licite'),
('traiter',       'a_verifier', 'Idem'),
('therapeutique', 'a_verifier', 'À vérifier en contexte'),
('thérapeutique', 'a_verifier', 'Idem'),
('maladie',       'a_verifier', 'Selon la formulation'),
('symptome',      'a_verifier', 'Selon la formulation'),
('symptôme',      'a_verifier', 'Selon la formulation'),
('cure',          'a_verifier', 'Peut désigner une cure de boisson, licite');

-- ---- Dictionnaire de recherche --------------------------------------
-- Amorçage seulement. Il est fait pour grossir, alimenté par la table
-- `recherches_infructueuses` — c'est-à-dire par les clients eux-mêmes.
INSERT INTO termes_recherche (canon, variante, nature, langue) VALUES
('karite','shea','traduction','anglais'),
('karite','sii','nom_local','moore'),
('karite','taama','nom_local','dioula'),
('karite','karite','orthographe',NULL),
('bissap','oseille','synonyme',NULL),
('bissap','oseille de guinee','synonyme',NULL),
('bissap','hibiscus','synonyme',NULL),
('bissap','da','nom_local','moore'),
('bissap','folere','nom_local','fulfulde'),
('bissap','roselle','traduction','anglais'),
('baobab','pain de singe','synonyme',NULL),
('baobab','toega','nom_local','moore'),
('baobab','kuka','nom_local','haoussa'),
('baobab','bui','nom_local','dioula'),
('soumbala','nere','synonyme',NULL),
('soumbala','netetou','nom_local','wolof'),
('soumbala','dawadawa','nom_local','haoussa'),
('soumbala','sumbala','orthographe',NULL),
('savon noir','alata samina','nom_local','twi'),
('savon noir','savon traditionnel','synonyme',NULL),
('pagne','wax','commercial',NULL),
('pagne','faso dan fani','nom_local','dioula'),
('pagne','tissu imprime','synonyme',NULL),
('bazin','bazin riche','commercial',NULL),
('bazin','getzner','commercial',NULL),
('boubou','grand boubou','synonyme',NULL),
('boubou','kaftan','synonyme',NULL),
('boubou','dashiki','synonyme',NULL),
('miel','zom koom','nom_local','moore'),
('theiere','bouilloire','synonyme',NULL),
('ceramique','poterie','synonyme',NULL),
('ceramique','canari','nom_local',NULL),
('ceramique','terre cuite','synonyme',NULL),
-- « maison » est VOLONTAIREMENT ABSENT de cette famille : c'est aussi le
-- nom de la catégorie des ustensiles. L'y mettre ferait remonter des
-- théières à quelqu'un qui cherche un maçon. Un synonyme n'est pas
-- seulement un mot proche : c'est un mot dont on a vérifié qu'il ne veut
-- pas dire autre chose ailleurs.
('construction','villa','synonyme',NULL),
('construction','batiment','synonyme',NULL),
('construction','yiri','nom_local','moore'),
('construction','macon','synonyme',NULL),
('construction','maconnerie','synonyme',NULL),
('hangar','entrepot','synonyme',NULL),
('hangar','magasin','synonyme',NULL),
('hangar','charpente metallique','synonyme',NULL),
('toiture','toit','synonyme',NULL),
('toiture','tole','synonyme',NULL),
('logiciel','application','synonyme',NULL),
('logiciel','appli','synonyme',NULL),
('logiciel','software','traduction','anglais'),
('logiciel','developpement','synonyme',NULL),
('site web','site internet','synonyme',NULL),
('site web','vitrine','synonyme',NULL),
('formation','cours','synonyme',NULL),
('formation','stage','synonyme',NULL),
('formation','seminaire','synonyme',NULL),
('bureautique','word','commercial',NULL),
('bureautique','excel','commercial',NULL),
('bureautique','microsoft office','commercial',NULL),
('comptabilite','syscohada','commercial',NULL),
('comptabilite','compta','orthographe',NULL),
('notaire','acte notarie','synonyme',NULL),
('notaire','etude notariale','synonyme',NULL);

-- ---------------------------------------------------------------------
-- PLAFOND DE SÉQUESTRE PAR JALON
-- ---------------------------------------------------------------------
-- C'est le seul paramètre du système dont la valeur est JURIDIQUE et non
-- commerciale. Au-dessus, la plateforme ne détient plus les fonds : le
-- client règle le prestataire en direct et Afrishop facture sa commission
-- à part. Le mettre en paramètre plutôt qu'en constante permet de le
-- caler sur le régime d'agrément obtenu, sans redéploiement.
-- ---------------------------------------------------------------------
INSERT INTO parametres (pays_id, cle, valeur, type, libelle) VALUES
(NULL,'services.plafond_sequestre_jalon_cfa','2000000','entier',
 'Au-dela, le jalon est regle en direct au prestataire'),
(NULL,'services.commission_facturee_a_part','1','booleen',
 'Commission sur jalon hors plateforme : facturee, pas prelevee'),
(NULL,'liquidation.seuil_alerte_peremption_jours','45','entier',
 'Declenche la proposition de liquidation au vendeur'),
(NULL,'liquidation.duree_max_jours','90','entier',
 'Au-dela, le prix barre ne veut plus rien dire');
