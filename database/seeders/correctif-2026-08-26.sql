-- =====================================================================
--  CORRECTIF DU 26/08/2026 — à passer UNE SEULE FOIS
-- =====================================================================
--  Ce fichier répare trois choses, dans cet ordre. Aucune d'elles n'est
--  destructive : rien n'est supprimé, rien n'est écrasé.
--
--    1. La table `migrations` est créée et remplie avec les migrations
--       DÉJÀ contenues dans le schéma installé. Sans elle, Laravel croit
--       qu'aucune migration n'a jamais tourné et tente de tout rejouer —
--       d'où l'erreur « Table 'pays' already exists ».
--
--    2. Les produits de démonstration sont publiés. Ils étaient créés
--       sans `statut_moderation`, donc à « en_attente » par défaut : la
--       vitrine les montrait, le panier les refusait.
--
--    3. Rien pour les médias : après ce fichier, `php artisan migrate`
--       appliquera tout seul la seule migration qui reste (les colonnes
--       vidéo de `medias`).
--
--  UTILISATION :
--    mysql -u root -p afrishope < database/seeders/correctif-2026-08-26.sql
--    php artisan migrate
--
--  (Remplacer `afrishope` par le nom réel de la base — voir DB_DATABASE
--   dans le fichier .env.)
-- =====================================================================


-- ---------------------------------------------------------------------
--  1. TABLE `migrations` — remettre Laravel d'accord avec la base
-- ---------------------------------------------------------------------
--  La base a été installée à partir de database/schema/
--  afrishop-installation-complete.sql, pas par `php artisan migrate`.
--  Les tables existent donc, mais rien ne le dit à Laravel : il n'y a
--  aucune trace de ce qui a été appliqué. La première commande
--  `migrate` repart alors de la migration numéro un et se heurte à une
--  table déjà présente.
--
--  On enregistre ici les migrations dont le contenu est DÉJÀ dans le
--  schéma installé. Elles ne seront plus rejouées, et `migrate` ne
--  s'occupera plus que des nouvelles.
--
--  ATTENTION : ne pas ajouter à cette liste une migration dont les
--  colonnes ne sont pas réellement en base. On lui ferait sauter son
--  tour, et le code appellerait des colonnes qui n'existent pas.

CREATE TABLE IF NOT EXISTS `migrations` (
  `id`        int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch`     int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Effacer puis réinsérer, plutôt qu'un `INSERT IGNORE` : le fichier
-- peut ainsi être repassé sans créer de doublons, et sans supposer
-- qu'une clé unique existe sur la colonne.
DELETE FROM `migrations` WHERE `migration` IN (
  '2026_08_02_000100_referentiels_uemoa',
  '2026_08_02_000200_identite_et_conformite',
  '2026_08_02_000300_boutiques_et_catalogue',
  '2026_08_02_000400_livraison_et_commandes',
  '2026_08_02_000500_argent_et_grand_livre',
  '2026_08_02_000600_fiscalite_et_facturation',
  '2026_08_02_000700_apres_vente_et_relation',
  '2026_08_02_000800_tracabilite_qr',
  '2026_08_02_000900_comptoir_transitaire_international',
  '2026_08_06_001000_liquidation_services_categories_reservees',
  '2026_08_06_001100_boutique_regie',
  '2026_08_23_093946_create_personal_access_tokens_table'
);

INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2026_08_02_000100_referentiels_uemoa', 1),
  ('2026_08_02_000200_identite_et_conformite', 1),
  ('2026_08_02_000300_boutiques_et_catalogue', 1),
  ('2026_08_02_000400_livraison_et_commandes', 1),
  ('2026_08_02_000500_argent_et_grand_livre', 1),
  ('2026_08_02_000600_fiscalite_et_facturation', 1),
  ('2026_08_02_000700_apres_vente_et_relation', 1),
  ('2026_08_02_000800_tracabilite_qr', 1),
  ('2026_08_02_000900_comptoir_transitaire_international', 1),
  ('2026_08_06_001000_liquidation_services_categories_reservees', 1),
  ('2026_08_06_001100_boutique_regie', 1),
  ('2026_08_23_093946_create_personal_access_tokens_table', 1);


-- ---------------------------------------------------------------------
--  2. PUBLIER LES PRODUITS DE DÉMONSTRATION
-- ---------------------------------------------------------------------
--  donnees-demonstration.sql insère les produits sans préciser
--  `statut_moderation`. La colonne vaut donc « en_attente », son défaut.
--
--  La modération est un vrai garde-fou, pas une formalité : une
--  plateforme qui vend de l'anti-contrefaçon ne peut pas laisser
--  publier n'importe quoi dans son propre catalogue. On ne le désactive
--  donc pas — on publie explicitement les six fiches de démonstration,
--  et la console permet désormais de faire la même chose pour les
--  suivantes.

UPDATE `produits`
   SET `statut_moderation` = 'publie',
       `motif_moderation`  = 'Jeu de démonstration — publié par le correctif du 26/08/2026.',
       `modere_le`         = NOW()
 WHERE `statut_moderation` = 'en_attente'
   AND `reference` IN ('P01','P02','P03','P04','P09','P10');

-- Vérification — doit renvoyer 6 lignes « publie ».
SELECT `reference`, `nom`, `statut_moderation`
  FROM `produits`
 ORDER BY `reference`;
