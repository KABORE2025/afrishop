<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 *  EXÉCUTION D'UN FICHIER SQL PAR LARAVEL
 * =====================================================================
 *  Pourquoi cette commande existe : sous Windows, `mysql < fichier.sql`
 *  ne fonctionne pas. PowerShell réserve l'opérateur `<` et refuse la
 *  ligne AVANT de lancer quoi que ce soit. S'ajoutent deux pièges :
 *  le client `mysql` n'est pas toujours dans le PATH, et un tube
 *  PowerShell réencode le fichier — les accents des commentaires SQL
 *  ressortent alors en caractères parasites.
 *
 *  Cette commande contourne les trois d'un coup : elle passe par la
 *  connexion PDO de Laravel, donc par les identifiants du `.env`. Si
 *  l'application se connecte à la base, cette commande aussi.
 *
 *  LIMITE ASSUMÉE : le découpage des instructions se fait sur le
 *  point-virgule en fin de ligne. Cela suffit pour des fichiers de
 *  correctif et de données, pas pour un fichier contenant des
 *  procédures stockées ou des `DELIMITER`. Pour un import de schéma
 *  complet, utiliser le vrai client `mysql` ou phpMyAdmin.
 *
 *  UTILISATION :
 *    php artisan afrishop:sql database/seeders/correctif-2026-08-26.sql
 * =====================================================================
 */
class ExecuterSql extends Command
{
    protected $signature = 'afrishop:sql
                            {fichier : Chemin du fichier .sql, relatif à la racine du projet}
                            {--transaction : Tout annuler si une instruction échoue}';

    protected $description = "Exécute un fichier SQL via la connexion de l'application";

    public function handle(): int
    {
        $chemin = base_path($this->argument('fichier'));

        if (! is_file($chemin)) {
            $this->error("Fichier introuvable : {$chemin}");

            return self::FAILURE;
        }

        $instructions = $this->decouper((string) file_get_contents($chemin));

        if ($instructions === []) {
            $this->warn('Aucune instruction à exécuter.');

            return self::SUCCESS;
        }

        $this->info(count($instructions) . ' instruction(s) à exécuter sur la base «'
            . DB::connection()->getDatabaseName() . '».');

        /*
         * La transaction n'est PAS le comportement par défaut, et c'est
         * délibéré. MySQL valide implicitement toute transaction en
         * cours dès qu'il rencontre un CREATE TABLE ou un ALTER TABLE :
         * promettre une annulation complète sur un fichier qui en
         * contient serait un mensonge. On l'offre en option pour les
         * fichiers purement UPDATE/INSERT.
         */
        $enTransaction = (bool) $this->option('transaction');

        if ($enTransaction) {
            DB::beginTransaction();
        }

        foreach ($instructions as $i => $sql) {
            $apercu = mb_substr(preg_replace('/\s+/', ' ', $sql), 0, 70);

            try {
                // `select` plutôt que `statement` pour rendre les lignes
                // des SELECT de vérification que contiennent ces
                // fichiers ; `statement` les exécuterait en silence.
                $resultat = str_starts_with(mb_strtoupper(ltrim($sql)), 'SELECT')
                    ? DB::select($sql)
                    : DB::statement($sql);

                if (is_array($resultat) && $resultat !== []) {
                    $this->line('');
                    $this->table(array_keys((array) $resultat[0]),
                        array_map(fn ($l) => (array) $l, $resultat));
                } else {
                    $this->line(sprintf('  <fg=green>✓</> %2d. %s', $i + 1, $apercu));
                }
            } catch (\Throwable $e) {
                if ($enTransaction) {
                    DB::rollBack();
                    $this->error('Annulé : aucune modification n\'a été conservée.');
                }

                $this->error(sprintf('  ✗ %2d. %s', $i + 1, $apercu));
                $this->error('     ' . $e->getMessage());

                return self::FAILURE;
            }
        }

        if ($enTransaction) {
            DB::commit();
        }

        $this->newLine();
        $this->info('Terminé.');

        return self::SUCCESS;
    }

    /**
     * Découpe le fichier en instructions.
     *
     * Les commentaires `--` sont retirés ligne à ligne AVANT le
     * découpage : un point-virgule à l'intérieur d'un commentaire
     * couperait sinon une instruction en deux.
     *
     * @return list<string>
     */
    private function decouper(string $contenu): array
    {
        $lignes = [];

        foreach (preg_split('/\R/', $contenu) as $ligne) {
            $nette = trim($ligne);

            if ($nette === '' || str_starts_with($nette, '--') || str_starts_with($nette, '#')) {
                continue;
            }

            $lignes[] = $ligne;
        }

        $instructions = [];

        foreach (explode(";\n", implode("\n", $lignes) . "\n") as $bloc) {
            $bloc = trim($bloc);

            if ($bloc !== '') {
                $instructions[] = $bloc;
            }
        }

        return $instructions;
    }
}
