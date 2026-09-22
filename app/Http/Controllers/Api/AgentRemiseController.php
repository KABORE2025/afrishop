<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentRemiseBoutique;
use App\Models\AgentRemiseTelephone;
use App\Models\Boutique;
use App\Models\Notification;
use App\Models\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Invitations d'agents : identité CNIB, puis affiliation à une ou plusieurs boutiques. */
class AgentRemiseController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $boutique = $this->boutique($r);
        $agents = $boutique->agentsRemise()->with('livreur:id,nom,telephone,ville_id')->get()
            ->sortBy(fn (AgentRemiseBoutique $a) => $a->livreur->nom)
            ->map(fn (AgentRemiseBoutique $a) => [
                'id' => $a->livreur_id, 'nom' => $a->livreur->nom,
                'telephone' => $a->livreur->telephone, 'ville_id' => $a->livreur->ville_id,
                'statut' => $a->statut,
                'autorise_livraison' => $a->autorise_livraison,
                'autorise_retrait_boutique' => $a->autorise_retrait_boutique,
            ])->values();

        return response()->json(['data' => $agents]);
    }

    /** Rattache immédiatement à la boutique un livreur déjà activé dans la même ville. */
    public function rattacher(Request $r): JsonResponse
    {
        $boutique = $this->boutiqueAvecVille($r);
        $data = $r->validate(['livreur_id' => ['required', 'integer', 'exists:utilisateurs,id']]);

        $affiliation = DB::transaction(function () use ($boutique, $data, $r) {
            $livreur = Utilisateur::whereKey($data['livreur_id'])->lockForUpdate()->firstOrFail();

            if ($livreur->role !== 'livreur') {
                abort(422, 'Cet identifiant ne correspond pas à un livreur.');
            }
            if ($livreur->ville_id !== $boutique->ville_id) {
                abort(422, 'Ce livreur travaille dans une autre ville et ne peut pas être rattaché à cette boutique.');
            }

            $affiliation = AgentRemiseBoutique::firstOrNew([
                'livreur_id' => $livreur->id, 'boutique_id' => $boutique->id,
            ]);
            $affiliation->fill([
                'statut' => 'actif', 'autorise_livraison' => true,
                'autorise_retrait_boutique' => true,
                'invite_par_utilisateur_id' => $r->user()->id,
                'jeton_invitation_hash' => null, 'invitation_expire_le' => null,
                'accepte_le' => $affiliation->accepte_le ?? now(),
            ]);
            $affiliation->save();

            return $affiliation;
        });

        return response()->json([
            'message' => 'Livreur rattaché à la boutique.', 'agent_id' => $affiliation->livreur_id,
        ], 201);
    }

    public function inviter(Request $r): JsonResponse
    {
        $boutique = $this->boutiqueAvecVille($r);
        $data = $r->validate([
            'nom' => ['required', 'string', 'max:120'], 'cnib' => ['required', 'string', 'min:5', 'max:80'],
            'telephone' => ['required', 'string', 'max:20'],
            'autorise_livraison' => ['boolean'], 'autorise_retrait_boutique' => ['boolean'],
        ]);
        $cnib = $this->normaliserCnib($data['cnib']);
        $hash = hash_hmac('sha256', $cnib, (string) config('app.key'));
        $code = (string) random_int(100000, 999999);

        $affiliation = DB::transaction(function () use ($boutique, $data, $cnib, $hash, $code, $r) {
            if (Utilisateur::where('cnib_hash', $hash)->exists()) {
                abort(422, 'Ce livreur existe déjà. Rattachez-le avec son identifiant.');
            }
            if (Utilisateur::where('telephone', $data['telephone'])->exists()) {
                abort(422, 'Ce téléphone appartient déjà à un autre compte.');
            }
            $livreur = Utilisateur::create([
                'pays_id' => $boutique->pays_id, 'ville_id' => $boutique->ville_id,
                'nom' => $data['nom'], 'telephone' => $data['telephone'], 'role' => 'livreur',
                'cnib_hash' => $hash, 'cnib_chiffree' => Crypt::encryptString($cnib),
            ]);
            AgentRemiseTelephone::create(['livreur_id' => $livreur->id, 'telephone' => $data['telephone'], 'est_principal' => true]);

            $affiliation = AgentRemiseBoutique::firstOrNew(['livreur_id' => $livreur->id, 'boutique_id' => $boutique->id]);
            $affiliation->fill([
                'statut' => 'en_attente', 'autorise_livraison' => $data['autorise_livraison'] ?? true,
                'autorise_retrait_boutique' => $data['autorise_retrait_boutique'] ?? true,
                'invite_par_utilisateur_id' => $r->user()->id, 'jeton_invitation_hash' => hash('sha256', $code),
                'invitation_expire_le' => now()->addDays(7), 'accepte_le' => null,
            ]);
            $affiliation->save();
            Notification::create([
                'telephone' => $data['telephone'], 'canal' => 'sms',
                'corps_envoye' => "Afrishop : {$boutique->nom} vous invite comme agent de remise. Code d'activation : {$code}. Valable 7 jours.",
                'statut' => 'en_file', 'nb_segments' => 1,
            ]);
            return $affiliation;
        });

        return response()->json(['message' => 'Invitation envoyée à l’agent.', 'agent_id' => $affiliation->livreur_id], 201);
    }

    public function activer(Request $r): JsonResponse
    {
        $data = $r->validate([
            'cnib' => ['required', 'string', 'min:5', 'max:80'], 'telephone' => ['required', 'string', 'max:20'],
            'code' => ['required', 'digits:6'], 'mot_de_passe' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $hash = hash_hmac('sha256', $this->normaliserCnib($data['cnib']), (string) config('app.key'));

        DB::transaction(function () use ($data, $hash) {
            $livreur = Utilisateur::where('cnib_hash', $hash)->lockForUpdate()->first();
            if (! $livreur) abort(422, 'Invitation introuvable.');
            $affiliation = AgentRemiseBoutique::where('livreur_id', $livreur->id)->where('statut', 'en_attente')
                ->where('invitation_expire_le', '>=', now())->where('jeton_invitation_hash', hash('sha256', $data['code']))
                ->lockForUpdate()->first();
            if (! $affiliation) abort(422, 'Code d’invitation invalide ou expiré.');
            $telephone = AgentRemiseTelephone::where('livreur_id', $livreur->id)->where('telephone', $data['telephone'])->first();
            if (! $telephone) abort(422, 'Ce téléphone ne correspond pas à l’invitation.');
            $livreur->update(['mot_de_passe' => $data['mot_de_passe'], 'telephone_verifie_le' => now()]);
            $telephone->update(['verifie_le' => now()]);
            $affiliation->update(['statut' => 'actif', 'accepte_le' => now(), 'jeton_invitation_hash' => null]);
        });
        return response()->json(['message' => 'Compte agent activé. Vous pouvez maintenant vous connecter.']);
    }

    private function boutique(Request $r): Boutique
    {
        $boutique = $r->user()?->boutique;
        abort_unless($boutique, 403, 'Aucune boutique rattachée à ce compte.');
        return $boutique;
    }

    private function boutiqueAvecVille(Request $r): Boutique
    {
        $boutique = $this->boutique($r);
        abort_if($boutique->ville_id === null, 422, 'Renseignez d’abord la ville de votre boutique avant d’ajouter un livreur.');

        return $boutique;
    }

    private function normaliserCnib(string $cnib): string
    {
        return Str::upper((string) preg_replace('/[^A-Za-z0-9]/', '', $cnib));
    }
}
