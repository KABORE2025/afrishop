<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JournalAdministration;
use App\Models\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * =====================================================================
 *  COMPTES DU PERSONNEL — administrateurs et agents
 * =====================================================================
 *  Un administrateur peut en créer un autre, AVEC LES MÊMES DROITS que
 *  lui : il n'y a pas d'« administrateur principal » dans le code.
 *  Il peut aussi créer un AGENT (support) : aide aux clients et aux
 *  vendeurs, sans rien qui déplace de l'argent ni donne un accès
 *  (voir le partage des routes dans routes/api.php).
 *
 *  C'est le geste le plus sensible de la console : un compte admin voit
 *  toutes les commandes, les codes de remise, et tranche les litiges,
 *  c'est-à-dire déplace de l'argent. D'où :
 *    · un compte NEUF uniquement — jamais la promotion d'un compte
 *      existant (un client ou un vendeur ne devient pas admin par un
 *      numéro saisi) ;
 *    · un mot de passe temporaire montré UNE SEULE FOIS ;
 *    · une trace au journal d'administration : qui a créé qui, quand,
 *      depuis quelle adresse (hachée).
 * =====================================================================
 */
class AdministrateurController extends Controller
{
    public function index(): JsonResponse
    {
        $comptes = Utilisateur::whereIn('role', ['admin', 'agent'])
            ->orderBy('cree_le')
            ->get(['id', 'nom', 'telephone', 'role', 'cree_le', 'derniere_connexion'])
            ->map(fn (Utilisateur $u) => [
                'id'                 => $u->id,
                'nom'                => $u->nom,
                'telephone'          => $u->telephone,
                'role'               => $u->role,
                'cree_le'            => $u->cree_le?->toIso8601String(),
                'derniere_connexion' => $u->derniere_connexion?->toIso8601String(),
            ]);

        return response()->json(['data' => $comptes]);
    }

    public function creer(Request $r): JsonResponse
    {
        $donnees = $r->validate([
            'nom'       => ['required', 'string', 'min:2', 'max:120'],
            'telephone' => ['required', 'string', 'regex:/^\+?[0-9 ]{8,20}$/'],
            'role'      => ['nullable', 'in:admin,agent'],
        ], [
            'telephone.regex' => 'Téléphone invalide : chiffres uniquement, indicatif compris (ex. 22670001122).',
        ]);

        $telephone = preg_replace('/\D/', '', $donnees['telephone']);

        // Jamais de promotion : un numéro déjà utilisé est refusé, quel
        // que soit le rôle du compte qui le porte.
        if (Utilisateur::withTrashed()->where('telephone', $telephone)->exists()) {
            return response()->json([
                'message' => 'Ce téléphone appartient déjà à un compte Afrishop. Un administrateur se crée '
                    .'toujours avec un numéro qui n’a encore aucun compte.',
            ], 422);
        }

        $motDePasse = Str::password(12, symbols: false);
        $role = $donnees['role'] ?? 'admin';

        $admin = DB::transaction(function () use ($r, $donnees, $telephone, $motDePasse, $role) {
            $admin = Utilisateur::create([
                'pays_id'      => $r->user()->pays_id,
                'nom'          => $donnees['nom'],
                'telephone'    => $telephone,
                'mot_de_passe' => $motDePasse,
                'role'         => $role,
            ]);

            JournalAdministration::create([
                'agent_id'   => $r->user()->id,
                'action'     => $role === 'admin' ? 'administrateur_cree' : 'agent_cree',
                'cible_type' => 'utilisateur',
                'cible_id'   => $admin->id,
                'apres'      => ['nom' => $admin->nom, 'telephone' => $admin->telephone, 'role' => $role],
                'ip_hachee'  => hash('sha256', (string) $r->ip()),
            ]);

            return $admin;
        });

        return response()->json([
            'message' => ($role === 'admin' ? 'Compte administrateur' : 'Compte agent')." créé pour {$admin->nom}.",
            'compte'  => ['id' => $admin->id, 'nom' => $admin->nom, 'telephone' => $admin->telephone],
            'mot_de_passe_temporaire' => $motDePasse,
            'a_communiquer' => 'Transmettez ce mot de passe de vive voix : il ne sera plus jamais affiché.',
        ], 201);
    }
}
