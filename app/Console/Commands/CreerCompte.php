<?php

namespace App\Console\Commands;

use App\Models\Utilisateur;
use App\Models\Ville;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
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
 *      php artisan afrishop:compte +22670000000 --role=livreur --nom="Nom Livreur" --ville=1
 * =====================================================================
 */
class CreerCompte extends Command
{
    protected $signature = 'afrishop:compte
        {telephone : Numéro au format international, ex. +22670112233}
        {--nom= : Nom, requis seulement à la création}
        {--role=vendeur : client | vendeur | livreur | agent | admin}
        {--pays=1 : Identifiant du pays, requis seulement à la création}
        {--ville= : Identifiant de la ville, obligatoire pour un livreur}
        {--mot-de-passe= : Mot de passe ; généré automatiquement si absent}';

    protected $description = 'Crée un compte ou lui attribue un mot de passe';

    public function handle(): int
    {
        $telephone = $this->argument('telephone');
        $role = $this->option('role');

        if (! in_array($role, ['client', 'vendeur', 'livreur', 'agent', 'admin'], true)) {
            $this->error("Rôle inconnu : {$role}");

            return self::FAILURE;
        }

        $ville = null;
        if ($this->option('ville') !== null) {
            $ville = Ville::find($this->option('ville'));
            if (! $ville) {
                $this->error('Ville introuvable : '.$this->option('ville'));

                return self::FAILURE;
            }
        }
        if ($role === 'livreur' && ! $ville) {
            $this->error('Un livreur doit avoir une ville : ajoutez --ville=<id>.');

            return self::FAILURE;
        }
        if ($role === 'livreur' && ! Schema::hasColumn('utilisateurs', 'ville_id')) {
            $this->error('La migration des villes de livreurs n’est pas appliquée. Exécutez d’abord : php artisan migrate');

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
            if ($ville && $ville->pays_id !== $utilisateur->pays_id) {
                $this->error('La ville choisie appartient à un autre pays.');

                return self::FAILURE;
            }
            $miseAJour = ['role' => $role, 'ville_id' => $ville?->id];
            // Réexécuter la commande pour renseigner une ville ne doit pas
            // invalider silencieusement le mot de passe du livreur.
            if ($this->option('mot-de-passe')) {
                $miseAJour['mot_de_passe'] = $motDePasse;
            }
            // Requête directe : la ville est une donnée d'affectation
            // opérationnelle et doit être persistée même si le modèle a
            // des événements ou des attributs modifiés en mémoire.
            Utilisateur::whereKey($utilisateur->id)->update($miseAJour);
            $utilisateur->refresh();
            $this->info("Compte mis à jour : {$utilisateur->nom} ({$telephone}) — rôle {$role}.");
        } else {
            if (! $this->option('nom')) {
                $this->error("Ce numéro n'existe pas : --nom est requis pour créer le compte.");

                return self::FAILURE;
            }
            if ($ville && $ville->pays_id !== (int) $this->option('pays')) {
                $this->error('La ville choisie appartient à un autre pays.');

                return self::FAILURE;
            }

            $utilisateur = Utilisateur::create([
                'pays_id' => (int) $this->option('pays'),
                'ville_id' => $ville?->id,
                'nom' => $this->option('nom'),
                'telephone' => $telephone,
                'mot_de_passe' => $motDePasse,
                'role' => $role,
            ]);
            $this->info("Compte créé : {$utilisateur->nom} ({$telephone}) — rôle {$role}.");
        }

        $this->newLine();
        $this->line('  Téléphone    : '.$telephone);
        if (! $utilisateur->wasRecentlyCreated && ! $this->option('mot-de-passe')) {
            $this->line('  Mot de passe : inchangé');
        } else {
            $this->line('  Mot de passe : '.$motDePasse);
            $this->comment('Notez-le maintenant : il est haché en base et ne sera plus affichable.');
        }
        $this->newLine();

        if (! $utilisateur->boutique && $role === 'vendeur') {
            $this->newLine();
            $this->warn("Ce compte n'a aucune boutique rattachée : l'espace vendeur affichera "
                .'« votre candidature est en cours d\'examen » tant qu\'une boutique ne lui est pas liée.');
        }

        return self::SUCCESS;
    }
}
