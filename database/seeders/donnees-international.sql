-- =====================================================================
--  RÉFÉRENTIEL INTERNATIONAL
--  Les pays de l'UEMOA sont ouverts à la vente ET aux boutiques.
--  Les autres sont, pour l'instant, ouverts à la vente seulement :
--  on livre chez eux ou on remet à leur transitaire, mais aucune
--  boutique n'y est encore établie.
-- =====================================================================

UPDATE pays SET zone='uemoa', ouvert_a_la_vente=1, ouvert_aux_boutiques=1,
                format_adresse='ouest_africain';

INSERT INTO pays (code_iso2, nom, devise, zone, indicatif_telephonique, langue_defaut,
                  format_adresse, actif, ouvert_a_la_vente, ouvert_aux_boutiques,
                  autorite_donnees, transfert_donnees_autorisation_requise) VALUES
-- Europe — la diaspora la plus nombreuse. Le RGPD s'y applique dès
-- qu'on traite les données d'un résident : à instruire avant ouverture.
('FR','France','EUR','europe','+33','fr','postal',1,1,0,'CNIL',0),
('BE','Belgique','EUR','europe','+32','fr','postal',1,1,0,'APD',0),
('IT','Italie','EUR','europe','+39','it','postal',1,1,0,'Garante',0),
('ES','Espagne','EUR','europe','+34','es','postal',1,1,0,'AEPD',0),
('DE','Allemagne','EUR','europe','+49','de','postal',1,1,0,'BfDI',0),
('GB','Royaume-Uni','GBP','europe','+44','en','postal',1,1,0,'ICO',0),
('CH','Suisse','CHF','europe','+41','fr','postal',1,1,0,'PFPDT',0),
-- Amériques
('US','États-Unis','USD','ameriques','+1','en','postal',1,1,0,NULL,0),
('CA','Canada','CAD','ameriques','+1','fr','postal',1,1,0,'CPVP',0),
-- Asie — la Chine est ouverte aux BOUTIQUES car des commerçants
-- chinois installés localement y sont rattachés par leur origine.
('CN','Chine','CNY','asie','+86','zh','libre',1,1,1,NULL,0),
('AE','Émirats arabes unis','AED','asie','+971','ar','libre',1,1,0,NULL,0),
('TR','Turquie','TRY','asie','+90','tr','postal',1,1,0,'KVKK',0),
-- Afrique hors UEMOA
('GH','Ghana','GHS','cedeao','+233','en','libre',1,1,0,'DPC',0),
('NG','Nigéria','NGN','cedeao','+234','en','libre',1,1,0,'NDPC',0),
('MA','Maroc','MAD','afrique','+212','fr','postal',1,1,0,'CNDP',0);

-- ---------------------------------------------------------------------
--  Taux de change — POUR L'AFFICHAGE UNIQUEMENT.
--  Le règlement reste en francs CFA : la plateforme ne prend aucun
--  risque de change. La marge de 2 % protège d'une variation entre
--  l'affichage et le paiement ; l'euro n'en a pas besoin, sa parité
--  avec le franc CFA est fixe.
-- ---------------------------------------------------------------------
INSERT INTO taux_change (devise, xof_pour_une_unite, source, marge_pct, date_debut) VALUES
('EUR', 655.957000, 'Parité fixe BCEAO', 0.00, '2020-01-01 00:00:00'),
('XOF',   1.000000, 'Monnaie de compte', 0.00, '2020-01-01 00:00:00');
-- Les autres devises (USD, GBP, CNY, CAD…) sont volontairement absentes :
-- elles doivent être alimentées par une source de taux réelle avant
-- d'être affichées. Afficher un prix converti au doigt mouillé est
-- pire que de ne rien afficher.
