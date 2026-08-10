-- =====================================================================
--  DONNÉES DE RÉFÉRENCE UEMOA
--  Valeurs vérifiées le 2 août 2026 auprès des sources officielles.
--  Les cellules NULL correspondent à des informations qui N'ONT PAS PU
--  être vérifiées de façon fiable : elles doivent être renseignées
--  après confirmation locale, pas devinées.
-- =====================================================================

INSERT INTO pays
 (code_iso2, nom, devise, indicatif_telephonique, langue_defaut,
  seuil_assujettissement_tva_cfa, retenue_source_non_immatricule_pct,
  retenue_source_immatricule_pct, retenue_source_seuil_cfa,
  facture_certifiee_obligatoire, plateforme_facturation,
  retractation_jours_ouvrables, retractation_jours_si_defaut_info,
  autorite_donnees, transfert_donnees_autorisation_requise, actif, ouvert_le) VALUES

-- Burkina Faso — pays de démarrage.
-- Retenue de 25 % sur les prestations versées à un résident sans IFU,
-- exonérée en dessous de 50 000 F. C'est le taux le plus lourd de la
-- zone et le principal argument pour immatriculer les vendeurs.
-- Facture électronique certifiée (FEC) obligatoire depuis la LF 2026.
('BF','Burkina Faso','XOF','+226','fr',  50000000, 25.00, 5.00, 50000, 1,'FEC',   NULL, NULL,'CIL',   1, 1,'2026-09-01'),

-- Côte d'Ivoire — assujettissement TVA seulement au-delà de 200 M.
-- AIRSI de 5 % sur les opérations avec le secteur informel.
('CI','Côte d''Ivoire','XOF','+225','fr',200000000,  5.00, 7.50,  NULL, 1,'FNE',   NULL, NULL,'ARTCI', 1, 0, NULL),

-- Sénégal — pas de facture certifiée en vigueur (projet en cours).
-- Rétractation : 7 jours ouvrables, portée à 3 mois si l'information
-- n'a pas été donnée au client (décret 2008-718, art. 12).
('SN','Sénégal','XOF','+221','fr',       50000000,  5.00, 5.00,  NULL, 0, NULL,      7,   90,'CDP',   1, 0, NULL),

-- Mali — retenue non confirmée : laissée NULL volontairement.
('ML','Mali','XOF','+223','fr',          50000000,  NULL, NULL,  NULL, 1,'Facture normalisée', NULL, NULL,'APDP', 1, 0, NULL),

-- Niger — TVA à 19 %, pas 18 %. Retenue non confirmée.
('NE','Niger','XOF','+227','fr',                 NULL, NULL, NULL, NULL, 1,'e-SECeF', NULL, NULL,'HAPDP', 1, 0, NULL),

-- Bénin — AIB : 5 % si le prestataire n'est pas immatriculé, 1 à 3 % sinon.
('BJ','Bénin','XOF','+229','fr',         50000000,  5.00, 3.00,  NULL, 1,'e-MECeF', NULL, NULL,'APDP',  1, 0, NULL),

-- Togo — TPU libératoire jusqu'à 60 M. Facture électronique B2B
-- seulement, cadre posé par la LF 2026, textes d'application à venir.
('TG','Togo','XOF','+228','fr',          60000000,  NULL, NULL,  NULL, 0,'FEC (B2B)', NULL, NULL,'IPDCP', 1, 0, NULL),

-- Guinée-Bissau — TVA (IVA) à 19 % depuis 2025. Obligations IVA à
-- partir de 10 M. Marché le plus petit de la zone.
('GW','Guinée-Bissau','XOF','+245','pt', 10000000,  NULL, NULL,  NULL, 1,'Facture normalisée', NULL, NULL, NULL, 1, 0, NULL);

-- ---------------------------------------------------------------------
-- Taux de TVA, historisés. Un taux n'est jamais une colonne du pays :
-- une facture de 2026 doit rester recalculable au taux de 2026.
-- ---------------------------------------------------------------------
INSERT INTO taux_taxe (pays_id, code, libelle, taux_pct, date_debut) VALUES
((SELECT id FROM pays WHERE code_iso2='BF'),'tva_normal','TVA taux normal',            18.00,'2020-01-01'),
((SELECT id FROM pays WHERE code_iso2='BF'),'tva_reduit','TVA hébergement/restauration',10.00,'2020-01-01'),
((SELECT id FROM pays WHERE code_iso2='CI'),'tva_normal','TVA taux normal',            18.00,'2020-01-01'),
((SELECT id FROM pays WHERE code_iso2='CI'),'tva_reduit','TVA taux réduit',             9.00,'2026-01-17'),
((SELECT id FROM pays WHERE code_iso2='SN'),'tva_normal','TVA taux normal',            18.00,'2020-01-01'),
((SELECT id FROM pays WHERE code_iso2='SN'),'tva_reduit','TVA hôtellerie agréée',      10.00,'2020-01-01'),
((SELECT id FROM pays WHERE code_iso2='ML'),'tva_normal','TVA taux normal',            18.00,'2020-01-01'),
((SELECT id FROM pays WHERE code_iso2='ML'),'tva_reduit','TVA informatique et solaire', 5.00,'2020-01-01'),
((SELECT id FROM pays WHERE code_iso2='NE'),'tva_normal','TVA taux normal',            19.00,'2020-01-01'),
((SELECT id FROM pays WHERE code_iso2='BJ'),'tva_normal','TVA taux normal',            18.00,'2020-01-01'),
((SELECT id FROM pays WHERE code_iso2='TG'),'tva_normal','TVA taux unique',            18.00,'2019-01-01'),
((SELECT id FROM pays WHERE code_iso2='GW'),'tva_normal','IVA taux normal',            19.00,'2025-01-01'),
((SELECT id FROM pays WHERE code_iso2='GW'),'tva_reduit','IVA taux réduit (Annexe I)', 10.00,'2025-01-01');

-- ---------------------------------------------------------------------
-- Opérateurs de paiement, par pays.
-- Wave n'est présent que dans 5 pays sur 8 : coder la liste en dur
-- dans l'application serait faux dès le deuxième pays ouvert.
-- ---------------------------------------------------------------------
INSERT INTO operateurs_paiement (pays_id, code, nom, type, ordre) VALUES
((SELECT id FROM pays WHERE code_iso2='BF'),'orange_money','Orange Money','mobile_money',1),
((SELECT id FROM pays WHERE code_iso2='BF'),'wave','Wave','mobile_money',2),
((SELECT id FROM pays WHERE code_iso2='BF'),'moov_flooz','Moov Money (Flooz)','mobile_money',3),
((SELECT id FROM pays WHERE code_iso2='BF'),'coris_money','Coris Money','mobile_money',4),
((SELECT id FROM pays WHERE code_iso2='BF'),'especes','Paiement à la livraison','especes',9),
((SELECT id FROM pays WHERE code_iso2='CI'),'orange_money','Orange Money','mobile_money',1),
((SELECT id FROM pays WHERE code_iso2='CI'),'wave','Wave','mobile_money',2),
((SELECT id FROM pays WHERE code_iso2='CI'),'mtn_momo','MTN MoMo','mobile_money',3),
((SELECT id FROM pays WHERE code_iso2='CI'),'moov_flooz','Moov Money','mobile_money',4),
((SELECT id FROM pays WHERE code_iso2='CI'),'especes','Paiement à la livraison','especes',9),
((SELECT id FROM pays WHERE code_iso2='SN'),'wave','Wave','mobile_money',1),
((SELECT id FROM pays WHERE code_iso2='SN'),'orange_money','Orange Money','mobile_money',2),
((SELECT id FROM pays WHERE code_iso2='SN'),'free_money','Free Money','mobile_money',3),
((SELECT id FROM pays WHERE code_iso2='SN'),'especes','Paiement à la livraison','especes',9),
-- Togo : Wave absent. Ne pas le proposer serait moins grave que de
-- le proposer et d'échouer au paiement.
((SELECT id FROM pays WHERE code_iso2='TG'),'mixx_yas','Mixx by Yas','mobile_money',1),
((SELECT id FROM pays WHERE code_iso2='TG'),'moov_flooz','Moov Money (Flooz)','mobile_money',2),
((SELECT id FROM pays WHERE code_iso2='TG'),'especes','Paiement à la livraison','especes',9),
((SELECT id FROM pays WHERE code_iso2='BJ'),'mtn_momo','MTN MoMo','mobile_money',1),
((SELECT id FROM pays WHERE code_iso2='BJ'),'moov_flooz','Moov Money (Flooz)','mobile_money',2),
((SELECT id FROM pays WHERE code_iso2='BJ'),'celtiis_cash','Celtiis Cash','mobile_money',3),
((SELECT id FROM pays WHERE code_iso2='BJ'),'especes','Paiement à la livraison','especes',9),
((SELECT id FROM pays WHERE code_iso2='ML'),'orange_money','Orange Money','mobile_money',1),
((SELECT id FROM pays WHERE code_iso2='ML'),'wave','Wave','mobile_money',2),
((SELECT id FROM pays WHERE code_iso2='ML'),'moov_flooz','Moov Money','mobile_money',3),
((SELECT id FROM pays WHERE code_iso2='ML'),'especes','Paiement à la livraison','especes',9),
((SELECT id FROM pays WHERE code_iso2='NE'),'airtel_money','Airtel Money','mobile_money',1),
((SELECT id FROM pays WHERE code_iso2='NE'),'moov_flooz','Moov Money (Flooz)','mobile_money',2),
((SELECT id FROM pays WHERE code_iso2='NE'),'wave','Wave','mobile_money',3),
((SELECT id FROM pays WHERE code_iso2='NE'),'especes','Paiement à la livraison','especes',9),
((SELECT id FROM pays WHERE code_iso2='GW'),'orange_money','Orange Money','mobile_money',1),
((SELECT id FROM pays WHERE code_iso2='GW'),'especes','Paiement à la livraison','especes',9);

INSERT INTO prestataires_paiement (code, nom, supporte_payout, supporte_split, actif) VALUES
-- supporte_split = 0 partout : au 2 août 2026, aucun agrégateur UEMOA
-- ne proposait de véritable sous-compte marchand avec séquestre.
-- C'est ce constat qui impose le grand livre interne.
('cinetpay','CinetPay',1,0,1),
('paydunya','PayDunya',1,0,1),
('fedapay','FedaPay',1,0,0),
('semoa','Semoa',1,0,0);

-- ---------------------------------------------------------------------
-- Paramètres métier, par pays quand c'est pertinent.
-- ---------------------------------------------------------------------
INSERT INTO parametres (pays_id, cle, valeur, type, libelle) VALUES
(NULL,'delai_confirmation_auto_jours','3','entier','Libération automatique des fonds après livraison'),
(NULL,'commission_defaut_pct','10','decimal','Commission d''une nouvelle boutique'),
(NULL,'reversement_minimum_cfa','5000','entier','En dessous, on attend le cycle suivant'),
(NULL,'reputation_seuil_commandes','3','entier','Avant, affichage « Nouvelle boutique »'),
(NULL,'plafond_wallet_identifie_cfa','2000000','entier','Plafond BCEAO de solde — instruction 008-05-2015'),
(NULL,'plafond_wallet_non_identifie_mensuel_cfa','200000','entier','Plafond BCEAO mensuel sans identification'),
(NULL,'panier_max_mobile_money_cfa','1500000','entier','Au-delà, bascule vers espèces ou virement'),
(NULL,'qr_longueur_jeton','12','entier','Entropie de l''anti-contrefaçon'),
(NULL,'qr_seuil_scans_suspects','50','entier','Déclenche le verdict « étiquette à vérifier »'),
(NULL,'delai_reponse_droits_jours','60','entier','Délai légal de réponse à une demande d''accès'),
((SELECT id FROM pays WHERE code_iso2='BF'),'commission_defaut_pct','10','decimal','Commission Burkina'),
((SELECT id FROM pays WHERE code_iso2='CI'),'commission_defaut_pct','12','decimal','Commission Côte d''Ivoire');

-- Registre des traitements — base des déclarations CIL / CDP / ARTCI.
INSERT INTO registre_traitements
 (code, finalite, base_legale, categories_donnees, destinataires, duree_conservation_jours, pays_hebergement) VALUES
('gestion_comptes','Création et gestion des comptes clients et vendeurs','contrat',
 'Nom, téléphone, e-mail, langue, pièce d''identité (vendeurs)','Hébergeur', 1095, NULL),
('gestion_commandes','Traitement, livraison et suivi des commandes','contrat',
 'Nom, téléphone, adresse de livraison, contenu de la commande','Transporteur, boutique concernée', 3650, NULL),
('paiements','Encaissement, reversement et lutte contre la fraude','obligation_legale',
 'Numéro de téléphone marchand, montants, références de transaction','Prestataire de paiement agréé', 3650, NULL),
('prospection','Envoi d''offres commerciales par SMS et e-mail','consentement',
 'Nom, téléphone, e-mail, historique d''achat','Fournisseur SMS', 1095, NULL),
('tracabilite_qr','Vérification d''authenticité des produits étiquetés','interet_legitime',
 'Adresse IP hachée, ville estimée, horodatage','Aucun', 365, NULL);
