-- =====================================================================
--  SCÉNARIOS DE VÉRIFICATION v2.1
-- =====================================================================

-- Une boutique tenue par un commerçant chinois installé à Ouagadougou :
-- exploitation locale, origine étrangère, réglée comme une boutique
-- locale. C'est le cas décrit pour le démarrage.
INSERT INTO utilisateurs (pays_id, nom, telephone, role, kyc_niveau) VALUES
((SELECT id FROM pays WHERE code_iso2='BF'),'Chen Wei','+22665889977','vendeur','complet');
INSERT INTO boutiques (utilisateur_id, pays_id, pays_origine_id, ville_id, code, nom, slug,
    telephone, taux_commission, taux_commission_comptoir, statut, type_boutique,
    vend_en_ligne, vend_au_comptoir, numero_fiscal, regime_fiscal,
    paiement_numero, paiement_verifie_le)
VALUES ((SELECT id FROM utilisateurs WHERE telephone='+22665889977'),
        (SELECT id FROM pays WHERE code_iso2='BF'),
        (SELECT id FROM pays WHERE code_iso2='CN'),
        1,'BF-V003','Bazar du Faso','bazar-du-faso','+22665889977',
        10.00, 0.00,'actif','importateur',1,1,'IFU00987654321','forfaitaire',
        '+22665889977', NOW());
INSERT INTO produits (boutique_id, categorie_id, reference, nom, slug, prix_ttc_cfa,
    statut_moderation, tracable)
VALUES ((SELECT id FROM boutiques WHERE code='BF-V003'),(SELECT id FROM categories WHERE slug='artisanat'),'BF-P0003',
        'Théière en fonte','theiere-fonte',18000,'publie',0);
INSERT INTO variantes_produit (produit_id, sku, libelle, prix_ttc_cfa, stock, defaut)
VALUES ((SELECT id FROM produits WHERE reference='BF-P0003'),'BF-P0003-STD','Standard',18000,12,1);


-- =====================================================================
--  SCÉNARIO A — VENTE AU COMPTOIR
--  Un client entre, choisit, paie 18 000 F en liquide, repart.
--  L'argent n'a jamais transité par la plateforme.
-- =====================================================================
INSERT INTO commandes (reference, pays_id, client_nom, client_telephone,
    mode_livraison, mode_paiement, statut_paiement, statut, canal,
    total_articles_ttc_cfa, total_a_payer_cfa, confirmee_le)
VALUES ('BF-CMD-2026-000010',(SELECT id FROM pays WHERE code_iso2='BF'),
        'Client comptoir','+22600000000','emporte','especes_comptoir','encaisse',
        'livree','comptoir',18000,18000,NOW());

INSERT INTO sous_commandes (commande_id, boutique_id, reference, statut, vente_comptoir,
    etat_fonds, montant_articles_ttc_cfa, taux_commission_pct, commission_cfa,
    taux_retenue_source_pct, retenue_source_cfa, montant_net_cfa, livre_le)
VALUES ((SELECT id FROM commandes WHERE reference='BF-CMD-2026-000010'),
        (SELECT id FROM boutiques WHERE code='BF-V003'),
        'BF-CMD-2026-000010-BF-V003','vendue_comptoir',1,
        'hors_plateforme',18000, 0.00, 0, 0.00, 0, 18000, NOW());

INSERT INTO lignes_commande (sous_commande_id, variante_id, sku, nom_produit,
    libelle_variante, prix_unitaire_ttc_cfa, quantite, total_ttc_cfa)
VALUES ((SELECT id FROM sous_commandes WHERE reference='BF-CMD-2026-000010-BF-V003'),
        (SELECT id FROM variantes_produit WHERE sku='BF-P0003-STD'),
        'BF-P0003-STD','Théière en fonte','Standard',18000,1,18000);

-- Le stock bouge, et le journal dit pourquoi.
UPDATE variantes_produit SET stock = stock - 1 WHERE sku='BF-P0003-STD';
INSERT INTO mouvements_stock (variante_id, type, quantite, stock_avant, stock_apres,
    piece_type, piece_id, auteur_id)
VALUES ((SELECT id FROM variantes_produit WHERE sku='BF-P0003-STD'),'vente_comptoir',-1,12,11,
        'sous_commande',(SELECT id FROM sous_commandes WHERE reference='BF-CMD-2026-000010-BF-V003'),
        (SELECT id FROM utilisateurs WHERE telephone='+22665889977'));


-- =====================================================================
--  SCÉNARIO B — COMMANDE DEPUIS PARIS, REMISE À TRANSITAIRE
--  Awa vit à Paris. Elle commande du karité, le fait régler par sa
--  sœur à Ouagadougou, et demande qu'on remette le colis à son
--  transitaire habituel. Afrishop ne touche ni au transport ni à la
--  douane.
-- =====================================================================
INSERT INTO commandes (reference, pays_id, pays_livraison_id, client_nom, client_telephone,
    paye_par_nom, paye_par_telephone, paye_par_lien,
    adresse_ligne1, code_postal, ville_texte,
    destinataire_nom, destinataire_telephone,
    mode_livraison, mode_paiement, statut_paiement, statut, canal,
    devise_affichage, taux_affichage,
    total_articles_ttc_cfa, total_a_payer_cfa, cgv_version, cgv_acceptees_le, confirmee_le)
VALUES ('FR-CMD-2026-000001',
        (SELECT id FROM pays WHERE code_iso2='FR'),
        (SELECT id FROM pays WHERE code_iso2='BF'),
        'Awa Traoré','+33612345678',
        'Fatimata Traoré','+22670223344','soeur',
        '12 rue de Belleville','75020','Paris',
        'Fatimata Traoré','+22670223344',
        'remise_transitaire','especes_comptoir','encaisse','confirmee','web',
        'EUR', 655.957000,
        35000, 35000, 'v1.0', NOW(), NOW());

INSERT INTO sous_commandes (commande_id, boutique_id, reference, statut, etat_fonds,
    montant_articles_ttc_cfa, taux_commission_pct, commission_cfa,
    taux_retenue_source_pct, retenue_source_cfa, montant_net_cfa)
VALUES ((SELECT id FROM commandes WHERE reference='FR-CMD-2026-000001'),
        (SELECT id FROM boutiques WHERE code='BF-V001'),
        'FR-CMD-2026-000001-BF-V001','prete','hors_plateforme',
        35000, 10.00, 3500, 5.00, 1750, 29750);

-- La remise au transitaire désigné par la cliente, contre preuve.
INSERT INTO remises_transitaire (commande_id, transitaire_nom, transitaire_contact,
    transitaire_telephone, adresse_remise, reference_client, instructions,
    statut, remis_le, recu_par_nom, recu_par_piece, nb_colis, poids_total_g,
    decharge_version, decharge_acceptee_le)
VALUES ((SELECT id FROM commandes WHERE reference='FR-CMD-2026-000001'),
        'Sahel Cargo International','M. Ibrahim Sawadogo','+22670998877',
        'Zone industrielle de Kossodo, entrepôt 12, Ouagadougou',
        'SCI-2026-4417','Groupage maritime vers Le Havre. Ne pas ouvrir les colis.',
        'remis', NOW(),'Ibrahim Sawadogo','CNIB B1234567', 2, 3400,
        'v1.0', NOW());
