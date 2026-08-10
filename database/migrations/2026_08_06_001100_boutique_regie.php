<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 11/11 — La boutique régie : Afrishop vendeur de lui-même
 *
 * Afrishop tient un rayon en propre — les produits naturels — avec son
 * stock, ses commandes et sa caisse, comme n'importe quelle boutique.
 * Un seul champ l'en distingue : `est_regie`.
 *
 * CE CHOIX A UN PRIX, ET LE SCHÉMA LE PAIE EXPLICITEMENT.
 *
 * Afrishop cesse d'être un simple intermédiaire : sur ce rayon, il
 * devient VENDEUR. La plateforme édite le classement ET y figure. Six
 * neutralisations en découlent, dont quatre sont posées ici, en base,
 * là où elles tiennent même si l'interface est contournée :
 *
 *   1. COMMISSION NULLE      — se facturer une commission à soi-même est
 *                              une écriture circulaire qui gonfle le
 *                              chiffre d'affaires sans qu'un franc entre.
 *   2. AUCUNE RETENUE        — la retenue à la source est un prélèvement
 *                              opéré par un TIERS payeur. Quand le payeur
 *                              et le vendeur sont la même personne
 *                              morale, il n'y a pas de tiers.
 *   3. HORS GRAND LIVRE      — « ce qu'Afrishop doit à Afrishop » n'est
 *                              pas une dette, et l'inscrire fausserait le
 *                              total de ce qu'il doit réellement aux
 *                              vendeurs — seul usage de ce total.
 *   4. VERROU DE CATÉGORIE   — une catégorie réservée n'accepte QUE la
 *                              régie. Contrainte de base, pas de code.
 *
 * Les deux autres — exclusion du comparateur et du tri, signalement du
 * conflit d'arbitrage — relèvent de la présentation et vivent dans
 * l'application.
 *
 * CE QUE LE SCHÉMA NE PEUT PAS RÉGLER : devenir vendeur change le statut
 * fiscal d'Afrishop sur ce rayon. Ce n'est plus une commission
 * d'intermédiation, c'est une MARGE COMMERCIALE. Régime de TVA, base
 * imposable et obligations déclaratives diffèrent. [FISCALISTE]
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutiques', function (Blueprint $t) {
            $t->boolean('est_regie')->default(false)->after('statut')
              ->comment('Boutique tenue par Afrishop lui-même. Commission et retenue nulles, '
                      . 'hors grand livre, exclue du comparateur et du tri');
        });

        // Un index partiel serait plus élégant, mais MariaDB ne les
        // connaît pas. L'index simple suffit : la table des boutiques
        // reste petite, et la régie s'y cherche à chaque affichage.
        DB::statement('CREATE INDEX ix_boutique_regie ON boutiques (est_regie)');

        /* VERROU DE CATÉGORIE, EN BASE.
           Une interface se contourne : appel d'API direct, import en
           masse, script de reprise. Ce trigger tient quel que soit le
           chemin. Il porte sur l'INSERT et sur l'UPDATE — sans le second,
           il suffirait de créer le produit dans une catégorie libre puis
           de le déplacer. */
        foreach (['INSERT', 'UPDATE'] as $evt) {
            $nom = 'trg_produit_categorie_reservee_' . strtolower($evt);
            DB::unprepared(<<<SQL
CREATE TRIGGER {$nom} BEFORE {$evt} ON produits
FOR EACH ROW
BEGIN
    DECLARE v_reservee TINYINT DEFAULT 0;
    DECLARE v_regie    TINYINT DEFAULT 0;

    SELECT reservee INTO v_reservee FROM categories WHERE id = NEW.categorie_id;

    IF v_reservee = 1 THEN
        SELECT est_regie INTO v_regie FROM boutiques WHERE id = NEW.boutique_id;
        IF v_regie = 0 THEN
            SIGNAL SQLSTATE '45000'
              SET MESSAGE_TEXT = 'Categorie reservee : seule la regie Afrishop peut y publier';
        END IF;
    END IF;
END
SQL);
        }

        /* COMMISSION ET RETENUE NULLES, GARANTIES EN BASE.
           La règle vit aussi dans l'application, et c'est très bien : ici
           elle est le dernier filet. Un import de reprise ou un script
           d'ajustement qui écrirait une commission sur une vente de la
           régie créerait une dette d'Afrishop envers lui-même — invisible
           jusqu'au jour du rapprochement bancaire. */
        DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_sous_commande_regie BEFORE INSERT ON sous_commandes
FOR EACH ROW
BEGIN
    DECLARE v_regie TINYINT DEFAULT 0;
    SELECT est_regie INTO v_regie FROM boutiques WHERE id = NEW.boutique_id;
    IF v_regie = 1 THEN
        SET NEW.commission_cfa = 0;
        SET NEW.taux_commission_pct = 0;
        SET NEW.retenue_source_cfa = 0;
        SET NEW.taux_retenue_source_pct = 0;
    END IF;
END
SQL);

        /* PAS D'INDEX UNIQUE PARTIEL POUR « UNE SEULE RÉGIE PAR PAYS ».
           MariaDB ne connaît pas `CREATE UNIQUE INDEX … WHERE`, et un
           index unique sur (pays_id, est_regie) sans condition
           interdirait d'avoir deux boutiques ORDINAIRES dans le même
           pays — exactement l'inverse du but recherché.
           La règle est donc portée par l'application, et c'est assumé :
           mieux vaut une règle applicative honnête qu'une contrainte de
           base qui contraint la mauvaise chose. */
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_produit_categorie_reservee_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_produit_categorie_reservee_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_sous_commande_regie');
        DB::statement('DROP INDEX IF EXISTS ix_boutique_regie ON boutiques');
        Schema::table('boutiques', fn (Blueprint $t) => $t->dropColumn('est_regie'));
    }
};
