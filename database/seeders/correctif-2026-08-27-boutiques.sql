-- =====================================================================
--  CORRECTIF DU 27/08/2026 — comptes de reversement des boutiques
-- =====================================================================
--  POURQUOI : `Boutique::peutVendre()` exige trois choses, et pas
--  seulement `statut = 'actif'` :
--
--     1. statut actif ;
--     2. un numéro de reversement RENSEIGNÉ ET VÉRIFIÉ ;
--     3. la boutique n'est pas en congés.
--
--  `donnees-demonstration.sql` crée les trois boutiques sans toucher
--  aux colonnes `paiement_*`. Aucune ne pouvait donc recevoir de
--  commande — d'où « La boutique « Atelier Kôkô » ne peut pas recevoir
--  de commande actuellement. » à la validation du panier.
--
--  Ce n'est pas un bogue : c'est la garantie qu'on n'encaisse pas un
--  argent qu'on ne saura pas reverser. On ne désactive donc pas la
--  règle — on donne aux boutiques de démonstration le compte vérifié
--  qu'elles auraient dans la vraie vie.
--
--  UTILISATION :
--    php artisan afrishop:sql database/seeders/correctif-2026-08-27-boutiques.sql
-- =====================================================================


-- Orange Money Burkina et Côte d'Ivoire, depuis le référentiel installé.
SET @opBf := (SELECT id FROM operateurs_paiement
               WHERE code = 'orange_money'
                 AND pays_id = (SELECT id FROM pays WHERE code_iso2 = 'BF')
               LIMIT 1);

SET @opCi := (SELECT id FROM operateurs_paiement
               WHERE code = 'orange_money'
                 AND pays_id = (SELECT id FROM pays WHERE code_iso2 = 'CI')
               LIMIT 1);

-- Repli : si aucun opérateur n'est déclaré pour le pays, on prend le
-- premier actif. Mieux vaut une démonstration qui tourne qu'un
-- correctif qui échoue en silence sur une clé nulle.
SET @opBf := COALESCE(@opBf, (SELECT id FROM operateurs_paiement WHERE actif = 1 ORDER BY id LIMIT 1));
SET @opCi := COALESCE(@opCi, @opBf);


-- ---------------------------------------------------------------------
--  Le numéro de reversement, vérifié
-- ---------------------------------------------------------------------
--  `paiement_verifie_le` n'est PAS décoratif : c'est la trace qu'un
--  micro-virement ou un appel API a confirmé que ce numéro existe et
--  appartient bien au titulaire annoncé. En production, cette date est
--  posée par la vérification, jamais à la main.

UPDATE `boutiques`
   SET `operateur_paiement_id` = @opBf,
       `paiement_numero`       = '+22670112233',
       `paiement_titulaire`    = 'Karité du Sahel',
       `paiement_verifie_le`   = NOW()
 WHERE `code` = 'BF-V001';

UPDATE `boutiques`
   SET `operateur_paiement_id` = @opBf,
       `paiement_numero`       = '+22676458912',
       `paiement_titulaire`    = 'Atelier Kôkô',
       `paiement_verifie_le`   = NOW()
 WHERE `code` = 'BF-V002';

UPDATE `boutiques`
   SET `operateur_paiement_id` = @opCi,
       `paiement_numero`       = '+22505446788',
       `paiement_titulaire`    = 'Nature & Baobab',
       `paiement_verifie_le`   = NOW()
 WHERE `code` = 'CI-V002';


-- Vérification — les trois doivent afficher « peut vendre ».
SELECT `code`, `nom`, `statut`, `paiement_numero`,
       CASE WHEN `statut` = 'actif'
             AND `paiement_numero` IS NOT NULL
             AND `paiement_verifie_le` IS NOT NULL
             AND (`fermee_du` IS NULL OR `fermee_du` > CURDATE()
                  OR (`fermee_au` IS NOT NULL AND `fermee_au` < CURDATE()))
            THEN 'peut vendre' ELSE 'BLOQUEE' END AS `etat`
  FROM `boutiques`
 ORDER BY `code`;
