<?php

namespace App\Console\Commands;

use App\Models\Utilisateur;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * =====================================================================
 *  afrishop:compte — créer un compte ou lui donner un mot de passe
 * =====================================================================
 *  POURQUOI CETTE COMMANDE EXISTE
 *  Les jeux de données de démonstration créent des utilisateurs SANS
 *  mot de passe :
 *
 *      INSERT INTO utilisateurs (pays_id, nom, telephone, role) VALUES …
 *
 *  La colonne `mot_de_passe` reste donc NULL, et AuthController refuse
 *  explicitement ce cas (`$utilisateur->mot_de_passe === null`). Aucun
 *  compte de démonstration ne peut se connecter — ce n'est pas un
 *  réglage à trouver, c'est une impasse.
 *
 *  Ce refus est d'ailleurs LE BON COMPORTEMENT : un compte sans mot de
 *  passe ne doit jamais être connectable, sinon un jeu de démonstration
 *  chargé par erreur en production ouvrirait des comptes vendeurs à qui
 *  connaît un numéro de téléphone.
 *
 *  La commande sert aussi en exploitation réelle : créer le premier
 *  administrateur d'une installation neuve, ou réinitialiser le mot de
 *  passe d'un vendeur qui a perdu le sien.
 *
 *  EXEMPLES
 *      php artisan afrishop:compte +22670112233
 *      php artisan afrishop:compte +22670000001 --role=admin --nom="Admin Afrishop"
 *      php artisan afrishop:compte +22670112233 --mot-de-passe=monsecret
 * =====================================================================
 */
class CreerCompte extends Command
{
    protected $signature = 'afrishop:compte
        {telephone : Numéro au format international, ex. +22670112233}
        {--nom= : Nom, requis seulement à la création}
        {--role=vendeur : client | vendeur | agent | admin}
        {--pays=1 : Identifiant du pays, requis seulement à la création}
        {--mot-de-passe= : Mot de passe ; généré automatiquement si absent}';

    protected $description = 'Crée un compte ou lui attribue un mot de passe';

    public function handle(): int
    {
        $telephone = $this->argument('telephone');
        $role      = $this->option('role');

        if (! in_array($role, ['client', 'vendeur', 'agent', 'admin'], true)) {
            $this->error("Rôle inconnu : {$role}");
            return self::FAILURE;
        }

        /*
         * Mot de passe généré par défaut. 12 caractères aléatoires
         * valent mieux qu'un « motdepasse » suggéré par la commande :
         * un mot de passe par défaut finit toujours par survivre
         * jusqu'en production.
         */
        $motDePasse = $this->option('mot-de-passe') ?: Str::random(12);

        $utilisateur = Utilisateur::where('telephone', $telephone)->first();

        if ($utilisateur) {
            $utilisateur->update([
                'mot_de_passe' => $motDePasse,   // haché par le cast « hashed »
                'role'         => $role,
            ]);
            $this->info("Compte mis à jour : {$utilisateur->nom} ({$telephone}) — rôle {$role}.");
        } else {
            if (! $this->option('nom')) {
                $this->error("Ce numéro n'existe pas : --nom est requis pour créer le compte.");
                return self::FAILURE;
            }

            $utilisateur = Utilisateur::create([
                'pays_id'      => (int) $this->option('pays'),
                'nom'          => $this->option('nom'),
                'telephone'    => $telephone,
                'mot_de_passe' => $motDePasse,
                'role'         => $role,
            ]);
            $this->info("Compte créé : {$utilisateur->nom} ({$telephone}) — rôle {$role}.");
        }

        $this->newLine();
        $this->line('  Téléphone    : ' . $telephone);
        $this->line('  Mot de passe : ' . $motDePasse);
        $this->newLine();

        // Affiché une seule fois, volontairement : le mot de passe est
        // haché en base et ne pourra plus être relu.
        $this->comment('Notez-le maintenant : il est haché en base et ne sera plus affichable.');

        if (! $utilisateur->boutique && $role === 'vendeur') {
            $this->newLine();
            $this->warn("Ce compte n'a aucune boutique rattachée : l'espace vendeur affichera "
                . '« votre candidature est en cours d\'examen » tant qu\'une boutique ne lui est pas liée.');
        }

        return self::SUCCESS;
    }
}
