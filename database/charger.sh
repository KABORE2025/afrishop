#!/usr/bin/env bash
# =====================================================================
#  CHARGEMENT COMPLET D'UNE BASE AFRISHOP
# =====================================================================
#  L'ORDRE COMPTE, et il n'est pas devinable — d'où ce script.
#
#  Deux dépendances non évidentes :
#
#   · les deltas s'appliquent DANS L'ORDRE et ne sont pas idempotents :
#     v2.4 retire un déclencheur posé par v2.3 et ajoute des colonnes.
#     Rejouer un delta sur une base déjà migrée échoue — c'est voulu,
#     un delta qui se rejoue en silence masque un état incertain.
#
#   · les scénarios de test s'appuient sur les référentiels : les lancer
#     avant produit des clés étrangères orphelines.
#
#  Usage : ./charger.sh [nom_de_base]
# =====================================================================
set -u
BASE="${1:-afrishop}"
ICI="$(cd "$(dirname "$0")" && pwd)"

mysql -e "DROP DATABASE IF EXISTS \`$BASE\`;
          CREATE DATABASE \`$BASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || exit 1

FICHIERS=(
  schema/afrishop-v2.sql
  schema/afrishop-v2.1-delta.sql
  schema/afrishop-v2.2-delta.sql
  schema/afrishop-v2.3-delta.sql
  schema/afrishop-v2.4-delta.sql
  seeders/donnees-reference.sql
  seeders/donnees-international.sql
  seeders/donnees-v22.sql
  seeders/test-scenario.sql
  seeders/test-scenario-v21.sql
  seeders/donnees-v23.sql        # la régie, AVANT les produits réservés
  seeders/test-scenario-v22.sql
)

echec=0
for f in "${FICHIERS[@]}"; do
  printf '%-38s ' "$(basename "$f")"
  if mysql "$BASE" < "$ICI/$f" >/dev/null 2>/tmp/afri_err; then
    echo 'OK'
  else
    echo 'ÉCHEC'
    grep -o 'ERROR.*' /tmp/afri_err | head -2
    echec=1
  fi
done

echo
mysql "$BASE" -N -e "SELECT CONCAT(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$BASE'), ' tables · ',
  (SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE constraint_schema='$BASE' AND constraint_type='FOREIGN KEY'), ' clés étrangères · ',
  (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='$BASE'), ' déclencheurs')"
exit $echec
