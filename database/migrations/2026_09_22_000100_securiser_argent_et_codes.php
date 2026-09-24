<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * =====================================================================
 *  REVUE DU 22/09 — ARGENT ET CODES DE REMISE
 * =====================================================================
 *  1. Les sous-commandes créées AVANT ce correctif naissaient
 *     « sequestre » alors que rien n'était encore encaissé. On remet
 *     « attente_encaissement » sur celles dont la commande n'est pas
 *     payée : c'est ce qui empêche désormais de les expédier.
 *
 *  2. `retenues_source.statut` gagne « annulee » : une vente remboursée
 *     n'est plus une base imposable, la retenue ne doit plus être
 *     déclarée ni reversée au fisc.
 *
 *  3. Codes de livraison et de retrait, textes des SMS : chiffrés au
 *     repos (cast `encrypted`, clé APP_KEY). Une copie de la base — une
 *     sauvegarde égarée, un accès phpMyAdmin — ne donne plus les codes
 *     qui libèrent l'argent des boutiques. Les colonnes passent en TEXT
 *     parce qu'un code chiffré fait environ 200 caractères, pas 6.
 *
 *     ATTENTION : changer APP_KEY rend ces valeurs illisibles. APP_KEY
 *     se sauvegarde avec la base, jamais séparément.
 * =====================================================================
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Sous-commandes d'une commande non payée : pas de séquestre.
        //    Seulement les colis ENCORE EN BOUTIQUE : un colis déjà parti
        //    repasserait sinon « en attente », et la vérification des
        //    paiements l'annulerait et le remettrait en stock alors que le
        //    client l'a déjà. Ceux-là restent tels quels, pour examen humain.
        DB::table('sous_commandes')
            ->join('commandes', 'commandes.id', '=', 'sous_commandes.commande_id')
            ->where('sous_commandes.etat_fonds', 'sequestre')
            ->whereIn('sous_commandes.statut', ['a_preparer', 'prete'])
            ->where('commandes.statut_paiement', '!=', 'encaisse')
            ->update(['sous_commandes.etat_fonds' => 'attente_encaissement']);

        // 2. Retenue annulable.
        DB::statement("ALTER TABLE retenues_source MODIFY statut
            ENUM('a_reverser','declaree','reversee','annulee') NOT NULL DEFAULT 'a_reverser'");

        // 3. Chiffrement au repos.
        Schema::table('expeditions', fn (Blueprint $t) => $t->text('code_livraison')->nullable()->change());
        Schema::table('sous_commandes', fn (Blueprint $t) => $t->text('code_retrait')->nullable()->change());

        $this->chiffrer('expeditions', 'code_livraison');
        $this->chiffrer('sous_commandes', 'code_retrait');
        $this->chiffrer('notifications', 'corps_envoye');
    }

    public function down(): void
    {
        $this->dechiffrer('notifications', 'corps_envoye');
        $this->dechiffrer('sous_commandes', 'code_retrait');
        $this->dechiffrer('expeditions', 'code_livraison');

        Schema::table('sous_commandes', fn (Blueprint $t) => $t->char('code_retrait', 6)->nullable()->change());
        Schema::table('expeditions', fn (Blueprint $t) => $t->char('code_livraison', 6)->nullable()->change());

        DB::table('retenues_source')->where('statut', 'annulee')->update(['statut' => 'a_reverser']);
        DB::statement("ALTER TABLE retenues_source MODIFY statut
            ENUM('a_reverser','declaree','reversee') NOT NULL DEFAULT 'a_reverser'");
    }

    /** Chiffre les valeurs encore en clair ; rejouable sans double chiffrement. */
    private function chiffrer(string $table, string $colonne): void
    {
        DB::table($table)->whereNotNull($colonne)->orderBy('id')
            ->chunkById(500, function ($lignes) use ($table, $colonne) {
                foreach ($lignes as $ligne) {
                    if ($this->estChiffre($ligne->$colonne)) {
                        continue;
                    }
                    DB::table($table)->where('id', $ligne->id)
                        ->update([$colonne => Crypt::encryptString($ligne->$colonne)]);
                }
            });
    }

    private function dechiffrer(string $table, string $colonne): void
    {
        DB::table($table)->whereNotNull($colonne)->orderBy('id')
            ->chunkById(500, function ($lignes) use ($table, $colonne) {
                foreach ($lignes as $ligne) {
                    if (! $this->estChiffre($ligne->$colonne)) {
                        continue;
                    }
                    DB::table($table)->where('id', $ligne->id)
                        ->update([$colonne => Crypt::decryptString($ligne->$colonne)]);
                }
            });
    }

    private function estChiffre(string $valeur): bool
    {
        try {
            Crypt::decryptString($valeur);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
