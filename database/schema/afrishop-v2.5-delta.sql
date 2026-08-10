-- =====================================================================
--  AFRISHOP — DELTA v2.5
--  PLAFOND DE SÉQUESTRE PAR JALON
-- =====================================================================
--  Un chantier découpé en tranches réduit l'exposition de la plateforme,
--  mais ne l'annule pas : sur un R+1 de 18,5 millions, une tranche de
--  30 % pèse encore 5,5 millions. Détenir cette somme pour le compte
--  d'un tiers relève d'un métier réglementé.
--
--  Au-delà du plafond `services.plafond_sequestre_jalon_cfa`, le jalon
--  est réglé DIRECTEMENT entre le client et le prestataire. La
--  plateforme héberge le devis, les jalons et la réception ; elle ne
--  tient pas la caisse, et facture sa commission séparément.
--
--  Il fallait donc que `etat_fonds` sache dire « cet argent n'est jamais
--  passé par nous ». La valeur existe déjà pour la vente au comptoir :
--  on la réutilise plutôt que d'en inventer une seconde, parce que deux
--  vocabulaires pour une même réalité finissent toujours par diverger.
-- =====================================================================

ALTER TABLE jalons
  MODIFY COLUMN etat_fonds
    ENUM('non_appele','attente_encaissement','sequestre','reverse',
         'rembourse','hors_plateforme')
    NOT NULL DEFAULT 'non_appele'
    COMMENT 'hors_plateforme = tranche au-dessus du plafond, reglee en direct';

-- ---------------------------------------------------------------------
-- Le grand livre doit distinguer une commission PRÉLEVÉE d'une
-- commission À FACTURER. Sur un jalon réglé en direct, Afrishop n'a rien
-- prélevé : il a une créance sur le prestataire. Les confondre ferait
-- croire que l'argent est déjà rentré.
-- ---------------------------------------------------------------------
ALTER TABLE mouvements_compte
  MODIFY COLUMN type
    ENUM('vente','vente_service','commission','commission_a_facturer',
         'retenue_source','frais_livraison','remboursement','versement',
         'ajustement')
    NOT NULL
    COMMENT 'commission_a_facturer = due mais non prelevee (jalon hors plateforme)';
