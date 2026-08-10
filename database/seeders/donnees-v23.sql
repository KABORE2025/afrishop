-- =====================================================================
--  AFRISHOP v2.3 — LA BOUTIQUE RÉGIE : OSSATURE CONSERVÉE, NON UTILISÉE
-- =====================================================================
--  La v2.3 avait fait d'Afrishop le vendeur exclusif des catégories
--  réservées. La v2.4 est revenue sur ce choix : le rayon redevient
--  ouvert aux boutiques tierces, sur autorisation fiche par fiche.
--
--  La colonne `est_regie` et ses neutralisations — commission et retenue
--  ramenées à zéro par déclencheur — RESTENT en place. Elles ne gênent
--  personne tant qu'aucune boutique n'est marquée, et elles protègent
--  immédiatement le jour où Afrishop ouvrira sa propre boutique.
--
--  AUCUNE BOUTIQUE N'EST MARQUÉE RÉGIE AUJOURD'HUI. C'est volontaire :
--  une ossature inutilisée coûte moins cher qu'une politique qu'on
--  applique à moitié.
-- =====================================================================
SELECT 'Regie : ossature en place, aucune boutique marquee' AS etat,
       (SELECT COUNT(*) FROM boutiques WHERE est_regie = 1) AS boutiques_regie;
