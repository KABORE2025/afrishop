-- =====================================================================
--  SCÉNARIO DE VÉRIFICATION
--  Une commande réelle de bout en bout, pour prouver que le modèle
--  tient : éclatement, TVA, commission, retenue à la source, grand
--  livre, cantonnement.
-- =====================================================================

-- Une ville, une zone, deux vendeurs burkinabè : l'un immatriculé,
-- l'autre pas. C'est toute la différence.
INSERT INTO villes (pays_id, nom, slug) VALUES
((SELECT id FROM pays WHERE code_iso2='BF'),'Ouagadougou','ouagadougou');
INSERT INTO zones_livraison (pays_id, ville_id, quartier, frais_base_cfa, frais_boutique_sup_cfa)
VALUES ((SELECT id FROM pays WHERE code_iso2='BF'),1,NULL,1500,900);

INSERT INTO utilisateurs (pays_id, nom, telephone, role, kyc_niveau) VALUES
((SELECT id FROM pays WHERE code_iso2='BF'),'Aminata Sawadogo','+22670112233','vendeur','complet'),
((SELECT id FROM pays WHERE code_iso2='BF'),'Issa Ouattara','+22676458912','vendeur','simplifie'),
((SELECT id FROM pays WHERE code_iso2='BF'),'Salif Kaboré','+22670459988','client','aucun');

INSERT INTO boutiques (utilisateur_id, pays_id, ville_id, code, nom, slug, telephone,
    taux_commission, statut, numero_fiscal, regime_fiscal, assujetti_tva) VALUES
-- Boutique A : immatriculée (IFU), régime forfaitaire, non assujettie
-- à la TVA. Retenue à la source réduite : 5 %.
(1,(SELECT id FROM pays WHERE code_iso2='BF'),1,'BF-V001','Karité du Sahel','karite-du-sahel','+22670112233',
 10.00,'actif','IFU00123456789','forfaitaire',0),
-- Boutique B : PAS d'IFU. Retenue à la source de 25 %.
(2,(SELECT id FROM pays WHERE code_iso2='BF'),1,'BF-V002','Atelier Kôkô','atelier-koko','+22676458912',
 12.00,'actif',NULL,'non_immatricule',0);

INSERT INTO categories (nom, slug, code_taxe) VALUES ('Beauté','beaute','tva_normal'),('Artisanat','artisanat','tva_normal');

-- ON DÉSIGNE LES CATÉGORIES PAR LEUR SLUG, JAMAIS PAR LEUR IDENTIFIANT.
-- Ce fichier écrivait « categorie_id = 1 » en dur. Cela a tenu tant que
-- ce script était le seul à créer des catégories ; le jour où un autre
-- jeu de données en a inséré une avant lui, l'identifiant 1 a désigné
-- une catégorie RÉSERVÉE, et le beurre de karité s'est retrouvé dans le
-- rayon des produits naturels. Un identifiant de substitution n'a de
-- sens que dans l'ordre où on l'a créé ; un slug en a un partout.
INSERT INTO produits (boutique_id, categorie_id, reference, nom, slug, prix_ttc_cfa, statut_moderation, tracable) VALUES
(1,(SELECT id FROM categories WHERE slug='beaute'),'BF-P0001','Beurre de karité brut','beurre-karite-brut',3500,'publie',1),
(2,(SELECT id FROM categories WHERE slug='artisanat'),'BF-P0002','Sac en cuir tanné','sac-cuir-tanne',24000,'publie',0);
INSERT INTO variantes_produit (produit_id, sku, libelle, prix_ttc_cfa, stock, defaut) VALUES
(1,'BF-P0001-500','Pot 500 g',3500,60,1),
(2,'BF-P0002-STD','Standard',24000,4,1);

-- ---- La commande : deux boutiques, paiement à la livraison ----
INSERT INTO commandes (reference, pays_id, client_nom, client_telephone, ville_id, quartier, repere,
    mode_livraison, mode_paiement, statut_paiement, statut,
    total_articles_ttc_cfa, total_frais_livraison_cfa, total_a_payer_cfa, cgv_version, cgv_acceptees_le, confirmee_le)
VALUES ('BF-CMD-2026-000001',(SELECT id FROM pays WHERE code_iso2='BF'),'Salif Kaboré','+22670459988',1,
    'Tanghin','En face de la pharmacie du Progrès','domicile','especes_livraison','attente','confirmee',
    31000, 2400, 33400, 'v1.0', NOW(), NOW());

-- Sous-commande A — boutique immatriculée, commission 10 %, RAS 5 %
INSERT INTO sous_commandes (commande_id, boutique_id, reference, statut, etat_fonds,
    montant_articles_ttc_cfa, frais_livraison_cfa, taux_commission_pct, commission_cfa,
    taux_retenue_source_pct, retenue_source_cfa, montant_net_cfa)
VALUES (1,1,'BF-CMD-2026-000001-V001','a_preparer','attente_encaissement',
    7000, 1500, 10.00, 700, 5.00, 350, 5950);

-- Sous-commande B — boutique SANS IFU, commission 12 %, RAS 25 %
INSERT INTO sous_commandes (commande_id, boutique_id, reference, statut, etat_fonds,
    montant_articles_ttc_cfa, frais_livraison_cfa, taux_commission_pct, commission_cfa,
    taux_retenue_source_pct, retenue_source_cfa, montant_net_cfa)
VALUES (1,2,'BF-CMD-2026-000001-V002','a_preparer','attente_encaissement',
    24000, 900, 12.00, 2880, 25.00, 6000, 15120);

INSERT INTO lignes_commande (sous_commande_id, variante_id, sku, nom_produit, libelle_variante,
    prix_unitaire_ttc_cfa, taux_tva_pct, quantite, total_ttc_cfa, total_tva_cfa) VALUES
(1,1,'BF-P0001-500','Beurre de karité brut','Pot 500 g',3500,0.00,2,7000,0),
(2,2,'BF-P0002-STD','Sac en cuir tanné','Standard',24000,0.00,1,24000,0);

-- ---- Livraison et encaissement en espèces ----
INSERT INTO transporteurs (pays_id, nom, type, encaisse_especes) VALUES
((SELECT id FROM pays WHERE code_iso2='BF'),'Coursiers Ouaga','partenaire',1);
INSERT INTO expeditions (sous_commande_id, transporteur_id, code_livraison, statut, expedie_le, livre_le, code_valide_le)
VALUES (1,1,'482910','livree',NOW(),NOW(),NOW()),
       (2,1,'773051','livree',NOW(),NOW(),NOW());
INSERT INTO encaissements_especes (expedition_id, transporteur_id, montant_du_cfa, montant_percu_cfa, statut, remis_le)
VALUES (1,1,8500,8500,'remis',NOW()),
       (2,1,24900,24900,'remis',NOW());

UPDATE sous_commandes SET statut='livree', etat_fonds='sequestre', livre_le=NOW();
UPDATE commandes SET statut='livree', statut_paiement='encaisse';

-- ---- GRAND LIVRE : une écriture par flux, jamais de solde stocké ----
INSERT INTO mouvements_compte (boutique_id, type, sens, montant_cfa, piece_type, piece_id, libelle) VALUES
(1,'vente','credit',7000,'sous_commande',1,'Vente BF-CMD-2026-000001-V001'),
(1,'commission','debit',700,'sous_commande',1,'Commission Afrishop 10 %'),
(1,'retenue_source','debit',350,'sous_commande',1,'Retenue à la source 5 % (IFU fourni)'),
(2,'vente','credit',24000,'sous_commande',2,'Vente BF-CMD-2026-000001-V002'),
(2,'commission','debit',2880,'sous_commande',2,'Commission Afrishop 12 %'),
(2,'retenue_source','debit',6000,'sous_commande',2,'Retenue à la source 25 % (aucun IFU)'),
(NULL,'commission','credit',3580,'commande',1,'Commissions encaissées');

INSERT INTO retenues_source (pays_id, boutique_id, sous_commande_id, base_cfa, taux_pct, montant_cfa, motif, periode) VALUES
((SELECT id FROM pays WHERE code_iso2='BF'),1,1,7000,5.00,350,'immatricule','2026-08'),
((SELECT id FROM pays WHERE code_iso2='BF'),2,2,24000,25.00,6000,'non_immatricule','2026-08');
