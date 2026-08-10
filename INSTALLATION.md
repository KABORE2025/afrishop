# Afrishop — Installation

## Réponse courte

**Non, ne jouez pas les fichiers `-delta` un par un.**
Sur une base neuve, un seul fichier suffit :

```bash
mysql -u root -p -e "CREATE DATABASE afrishop CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p afrishop < database/schema/afrishop-installation-complete.sql
```

C'est tout. 73 tables, 80 clés étrangères, données de référence chargées.

## Pourquoi le dernier delta seul ne marche pas

Les fichiers `-delta` sont **incrémentaux, pas cumulatifs** : chacun ne contient
que ce qui a changé à cette étape. Vérifié en chargeant `afrishop-v2.5-delta.sql`
seul sur une base vide : **0 table créée**, et une erreur — il tente de modifier
une table `jalons` qui n'existe pas encore.

| Fichier | Ce qu'il contient |
|---|---|
| `afrishop-v2.sql` | 54 CREATE TABLE — le socle |
| `afrishop-v2.1-delta.sql` | 4 CREATE, 6 ALTER — comptoir, transitaire, international |
| `afrishop-v2.2-delta.sql` | 14 CREATE, 3 ALTER — liquidation, services, catégories réservées |
| `afrishop-v2.3-delta.sql` | 1 ALTER — boutique régie |
| `afrishop-v2.4-delta.sql` | 2 ALTER — annuaire, recherches |
| `afrishop-v2.5-delta.sql` | 2 ALTER — plafond de séquestre par jalon |
| **`afrishop-installation-complete.sql`** | **tout ce qui précède, déjà appliqué** |

## Les deux situations

**Base neuve** → `afrishop-installation-complete.sql`, une commande, terminé.
Ne jouez PAS les deltas en plus : ils échoueraient en tentant de recréer
l'existant.

**Base déjà installée et remplie** → jouez uniquement les deltas postérieurs à
votre version, dans l'ordre croissant. C'est à ça qu'ils servent : faire évoluer
sans détruire. Sauvegardez avant.

En cas de doute sur la version en place :

```sql
SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='afrishop';
-- 54 → v2.0   58 → v2.1   72 → v2.2   73 → v2.3 et au-delà
SHOW COLUMNS FROM jalons LIKE 'etat_fonds';
-- contient 'hors_plateforme' → v2.5 déjà appliquée
```

## Troisième voie : les migrations Laravel

Le dossier `database/migrations/` fait la même chose, avec l'ordre géré tout
seul et la possibilité de revenir en arrière :

```bash
php artisan migrate
```

**Choisissez une seule voie.** Mélanger le SQL direct et `artisan migrate` sur la
même base produit une table `migrations` qui ment sur ce qui est réellement
appliqué — et la prochaine mise à jour casse.

## Données de démonstration — facultatif

`afrishop-installation-complete.sql` ne contient AUCUNE boutique, aucun produit,
aucune commande. La base est vierge et prête pour vos vraies données.

Pour une base peuplée à des fins de démonstration, ajoutez ensuite, dans cet
ordre :

```bash
mysql -u root -p afrishop < database/seeders/test-scenario.sql
mysql -u root -p afrishop < database/seeders/test-scenario-v21.sql
mysql -u root -p afrishop < database/seeders/test-scenario-v22.sql
```

L'ordre compte : les fichiers postérieurs référencent les identifiants créés par
les précédents.

## Vérification après installation

```sql
SELECT COUNT(*) AS tables_attendues_73 FROM information_schema.tables
  WHERE table_schema='afrishop';
SELECT COUNT(*) AS cles_attendues_80 FROM information_schema.table_constraints
  WHERE table_schema='afrishop' AND constraint_type='FOREIGN KEY';
SELECT (SELECT COUNT(*) FROM pays) pays, (SELECT COUNT(*) FROM villes) villes,
       (SELECT COUNT(*) FROM zones_livraison) zones,
       (SELECT COUNT(*) FROM categories) categories;
-- attendu : 23 pays, 15 villes, 15 zones, 8 categories
```

Si `villes` ou `zones_livraison` renvoie 0, **aucune commande ne pourra être
passée** : le formulaire de livraison en zone ouest-africaine exige de choisir
une ville. C'était le cas des versions antérieures à ce fichier, corrigé par
`seeders/donnees-geographie-categories.sql`.
