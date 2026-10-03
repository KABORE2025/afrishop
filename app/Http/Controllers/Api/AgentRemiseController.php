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

    /**
     * Proposer à un livreur EXISTANT de livrer pour la boutique.
     *
     * Ce n'est plus un rattachement immédiat : le livreur doit ACCEPTER
     * dans son espace (invitationsLivreur / repondreInvitation). Avant,
     * n'importe quel vendeur de la ville pouvait s'attacher un livreur
     * sans son accord, lui affecter des courses et lui faire voir
     * l'adresse et le téléphone de ses clients.
     *
     * Un seul message d'erreur, quel que soit le cas (identifiant
     * inconnu, pas livreur, autre ville) : des messages distincts
     * permettaient de deviner, en essayant des numéros, qui est livreur.
     */
    public function rattacher(Request $r): JsonResponse
    {
        $boutique = $this->boutiqueAvecVille($r);
        $data = $r->validate(['livreur_id' => ['required', 'integer', 'min:1']]);

        $resultat = DB::transaction(function () use ($boutique, $data, $r) {
            $livreur = Utilisateur::whereKey($data['livreur_id'])->lockForUpdate()->first();

            if (! $livreur || $livreur->role !== 'livreur' || $livreur->ville_id !== $boutique->ville_id) {
                abort(422, 'Aucun livreur de votre ville ne porte cet identifiant. Vérifiez-le auprès du livreur : '
                    .'il le trouve en haut de son espace livreur.');
            }

            // Compte pas encore activé (créé par inviter(), sans mot de
            // passe) : il ne peut ni se connecter ni accepter une
            // invitation, et réécrire son affiliation effaçait le code
            // d'activation reçu par SMS — compte bloqué pour toujours.
            if ($livreur->mot_de_passe === null) {
                abort(422, 'Ce livreur n’a pas encore activé son compte. Il doit d’abord l’activer avec '
                    .'le code reçu par SMS ; vous pourrez l’inviter ensuite.');
            }

            $affiliation = AgentRemiseBoutique::firstOrNew([
                'livreur_id' => $livreur->id, 'boutique_id' => $boutique->id,
            ]);

            if ($affiliation->exists && $affiliation->statut === 'actif') {
                return 'deja';
            }
            if ($affiliation->exists && $affiliation->statut === 'suspendu') {
                abort(422, 'Ce livreur est suspendu pour votre boutique : appelez le service client Afrishop au '.telephone_support().'.');
            }

            $affiliation->fill([
                'statut' => 'en_attente', 'autorise_livraison' => true,
                'autorise_retrait_boutique' => true,
                'invite_par_utilisateur_id' => $r->user()->id,
                // Pas de code : le livreur a déjà un compte, il accepte
                // connecté, dans son espace. Le code reste réservé à
                // l'activation d'un NOUVEAU livreur (activer()).
                'jeton_invitation_hash' => null,
                'invitation_expire_le' => now()->addDays(7),
                'accepte_le' => null,
            ]);
            $affiliation->save();

            Notification::create([
                'destinataire_id' => $livreur->id, 'telephone' => $livreur->telephone, 'canal' => 'sms',
                'corps_envoye' => "Afrishop : {$boutique->nom} vous propose de livrer pour elle. "
                    .'Acceptez ou refusez dans votre espace livreur (valable 7 jours).',
                'statut' => 'en_file', 'nb_segments' => 1,
            ]);

            return 'invite';
        });

        if ($resultat === 'deja') {
            return response()->json(['message' => 'Ce livreur livre déjà pour votre boutique.']);
        }

        return response()->json([
            'message' => 'Invitation envoyée. Le livreur doit l’accepter dans son espace avant de pouvoir recevoir vos colis.',
        ], 202);
    }

    /** Côté livreur : les boutiques qui lui proposent de livrer pour elles. */
    public function invitationsLivreur(Request $r): JsonResponse
    {
        $invitations = AgentRemiseBoutique::where('livreur_id', $r->user()->id)
            ->where('statut', 'en_attente')
            // Les invitations avec code concernent l'activation d'un
            // nouveau compte : elles se valident par activer(), pas ici.
            ->whereNull('jeton_invitation_hash')
            ->where('invitation_expire_le', '>=', now())
            ->with('boutique:id,nom,ville_id,telephone')
            ->get()
            ->map(fn (AgentRemiseBoutique $a) => [
                'boutique_id' => $a->boutique_id,
                'boutique'    => $a->boutique?->nom,
                'telephone'   => $a->boutique?->telephone,
                'expire_le'   => $a->invitation_expire_le?->toIso8601String(),
            ])->values();

        return response()->json(['data' => $invitations, 'mon_identifiant' => $r->user()->id]);
    }

    /** Côté livreur : accepter ou refuser. Rien n'est actif sans ce geste. */
    public function repondreInvitation(Request $r, Boutique $boutique): JsonResponse
    {
        $data = $r->validate(['reponse' => ['required', 'in:accepter,refuser']]);

        $affiliation = AgentRemiseBoutique::where('livreur_id', $r->user()->id)
            ->where('boutique_id', $boutique->id)->where('statut', 'en_attente')
            ->whereNull('jeton_invitation_hash')->where('invitation_expire_le', '>=', now())
            ->first();

        abort_unless($affiliation, 404, 'Cette invitation n’existe plus ou a expiré.');

        $accepte = $data['reponse'] === 'accepter';
        $affiliation->update([
            'statut'               => $accepte ? 'actif' : 'refuse',
            'accepte_le'           => $accepte ? now() : null,
            'invitation_expire_le' => null,
        ]);

        return response()->json([
            'message' => $accepte
                ? "Vous livrez maintenant pour {$boutique->nom}."
                : "Invitation de {$boutique->nom} refusée.",
        ]);
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
                'corps_envoye' => "Afrishop : {$boutique->nom} vous invite comme livreur. Code d'activation : {$code}, valable 7 jours. "
                    .'Activez votre compte sur '.route('livreur.activer'),
                'statut' => 'en_file', 'nb_segments' => 2,
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
