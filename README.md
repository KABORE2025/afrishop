# Afrishop — API (v2.1, multi-pays et international)

Backend Laravel 13 de la place de marché Afrishop.

La version 2 corrige les manques du registre et étend le modèle aux huit
pays de l'UEMOA. La version 2.1 y ajoute la **vente au comptoir**,
l'**expédition déléguée à un transitaire** et l'ouverture aux **clients
internationaux**.

**58 entités**, validées sur MariaDB 10.11.

## Prérequis

| Composant | Version | Remarque |
|---|---|---|
| PHP | 8.3+ | requis par Laravel 13 |
| MySQL | 8.0+ | ou MariaDB 10.6+ |
| Composer | 2.7+ | |

## Installation

```bash
composer install
cp .env.example .env && php artisan key:generate

# utf8mb4 obligatoire : utf8mb3 casserait les emojis produits
mysql -u root -e "CREATE DATABASE afrishop
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

php artisan migrate
mysql -u root afrishop < database/seeders/donnees-reference.sql
mysql -u root afrishop < database/seeders/donnees-international.sql
```

Générer le sel de hachage des scans QR **une seule fois**, et ne plus y toucher :

```bash
php -r "echo bin2hex(random_bytes(32));"   # → AFRISHOP_SEL_SCAN
```

Le changer après la mise en production rendrait tous les hachages
existants incomparables et ferait perdre l'historique de détection de fraude.

## Tâches planifiées

```cron
* * * * * cd /var/www/afrishop && php artisan schedule:run >> /dev/null 2>&1
```

| Tâche | Fréquence | Si elle s'arrête |
|---|---|---|
| `afrishop:liberer-fonds` | quotidienne | **les vendeurs ne sont plus payés**, sans alerte |
| `afrishop:arreter-cantonnement` | quotidienne | perte de la preuve de conformité BCEAO |
| `afrishop:reconcilier-psp` | quotidienne | un écart se découvre des mois plus tard |
| `afrishop:preparer-reversements` | hebdomadaire | les vendeurs attendent une semaine de plus |

## Organisation du code

```
app/
├── Enums/          états métier et transitions autorisées
├── Models/         Eloquent — une classe par table
├── Services/       LA LOGIQUE MÉTIER. Commencer ici.
│   ├── PanierService          panier → commande éclatée, TVA, commission, retenue
│   ├── TaxeService            taux de TVA par pays, date et catégorie
│   ├── RetenueSourceService   retenue à la source et gain d'immatriculation
│   ├── SequestreService       blocage et libération des fonds
│   ├── EspecesService         paiement à la livraison et cash livreur
│   ├── GrandLivreService      écritures, contrepassation, soldes
│   ├── CantonnementService    arrêté quotidien et écarts
│   ├── ReversementService     regroupement, suspension, versement
│   ├── FactureService         numérotation continue, certification par pays
│   ├── NotificationService    gabarits, coût, files
│   ├── LotQrService           génération et vérification des étiquettes QR
│   ├── VenteComptoirService   vente présentielle et journal de stock
│   ├── ExpeditionInternationaleService  remise à transitaire, devis
│   └── ChangeService          affichage multi-devise, sans risque de change
├── Support/aides.php  lecture des paramètres métier par pays
└── Http/           contrôleurs fins : HTTP ⇄ services
```

## Les quatre chemins de vente

| Chemin | Argent | Grand livre | Commission | Notre responsabilité |
|---|---|---|---|---|
| Vente en ligne | encaissé → séquestré → reversé | oui | normale | jusqu'à la porte |
| Paiement à la livraison | perçu à la porte → séquestré | oui | normale | jusqu'à la porte |
| **Vente au comptoir** | hors plateforme | **non** | **0 % par défaut** | aucune |
| **Export par transitaire** | réglé localement | selon le règlement | normale | jusqu'à la remise |

## Deux règles à ne jamais contourner

1. **`mouvements_compte` est en écriture seule.** Jamais d'`UPDATE`, jamais
   de `DELETE`. Une erreur se corrige par une écriture inverse
   (`GrandLivreService::contrepasser`). Un grand livre modifiable ne
   prouve rien.
2. **Un vendeur ne voit que sa boutique**, et le filtrage est fait par le
   serveur à partir du jeton — jamais à partir d'un identifiant envoyé
   par le client.

3. **Une remise à transitaire sans preuve n'en est pas une.**
   `ExpeditionInternationaleService::remettre()` exige le nom et la pièce
   d'identité de la personne qui réceptionne. Sans eux, la responsabilité
   de la plateforme ne prend pas fin — et un colis perdu six semaines plus
   tard redevient notre problème.

## Dette technique assumée

Le franc CFA est la **monnaie de compte** : les 53 colonnes en `_cfa` ne
sont pas renommées. Une opération en devise conserve sa devise et son taux
d'affichage à côté du montant réglé. Ce choix devra être rouvert le jour
où une boutique sera **réellement établie hors zone franc** — une vraie
boutique en Chine payée en yuans, et non un commerçant chinois installé
localement et réglé en francs CFA.

## Documents de référence

- `docs/Afrishop-conception-totale.pdf` — le dossier complet (44 pages)
- `docs/Afrishop-addendum-v2.1.pdf` — comptoir, transitaire, international (11 pages)
- `docs/registre-des-manques.md` — les 29 oublis de la v1 et leur correction
- `database/schema/afrishop-v2.sql` — schéma commenté colonne par colonne
- `database/schema/afrishop-v2.1-delta.sql` — l'extension v2.1
- `database/seeders/donnees-reference.sql` — données UEMOA vérifiées
- `database/seeders/test-scenario.sql` — scénario d'équilibre comptable
- `docs/diagrammes/` — les douze diagrammes Merise et UML
