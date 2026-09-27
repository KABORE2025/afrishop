<?php

namespace App\Http\Controllers;

use App\Models\Candidature;
use App\Models\Categorie;
use App\Models\Ville;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * =====================================================================
 *  « OUVRIR MA BOUTIQUE » — candidature vendeur, rendu serveur
 * =====================================================================
 *  L'API /api/candidatures existait, mais aucun écran ne l'appelait :
 *  un commerçant ne pouvait pas demander à vendre sur Afrishop.
 *
 *  La demande est traitée à la main dans la console (Candidatures).
 *  Accepter crée la boutique et le compte du gérant ; un numéro déjà
 *  porté par un livreur, un vendeur ou le personnel est refusé à cette
 *  étape (AdminController::accepterCandidature()).
 * =====================================================================
 */
class CandidatureWebController extends Controller
{
    public function formulaire(): View
    {
        return view('devenir-vendeur', [
            'villes'     => $this->villes(),
            'categories' => Categorie::whereNull('parent_id')->where('active', true)->orderBy('nom')->get(['id', 'nom']),
        ]);
    }

    public function enregistrer(Request $r): RedirectResponse
    {
        $donnees = $r->validate([
            'nom_boutique' => ['required', 'string', 'min:2', 'max:120'],
            'responsable'  => ['required', 'string', 'min:2', 'max:120'],
            'telephone'    => ['required', 'string', 'regex:/^\+?[0-9 ]{8,20}$/'],
            'ville_id'     => ['required', 'integer', Rule::in($this->villes()->collapse()->pluck('id')->all())],
            'categorie_id' => ['nullable', 'integer', 'exists:categories,id'],
            'description'  => ['required', 'string', 'min:30', 'max:2000'],
        ], [
            'telephone.regex' => 'Téléphone invalide : chiffres uniquement (ex. 70 00 11 22).',
            'ville_id.in'     => 'Choisissez une ville dans la liste.',
            'description.min' => 'Décrivez votre activité en quelques phrases (30 caractères au moins) : ce que vous vendez, depuis quand, où.',
        ], [
            'nom_boutique' => 'nom de la boutique', 'responsable' => 'nom du responsable',
            'ville_id' => 'ville', 'categorie_id' => 'catégorie',
        ]);

        $telephone = preg_replace('/\D/', '', $donnees['telephone']);

        // Une demande à la fois par numéro : sans cela, un commerçant
        // impatient remplit la file de la console de doublons.
        $enCours = Candidature::where('telephone', $telephone)
            ->whereIn('statut', ['en_attente', 'en_verification'])->exists();
        if ($enCours) {
            return back()->withInput()->with('erreur',
                ' — une demande est déjà en cours d’examen pour ce numéro. Nous vous recontacterons.');
        }

        $ville = Ville::findOrFail($donnees['ville_id']);

        Candidature::create([
            'pays_id'      => $ville->pays_id,
            'nom_boutique' => $donnees['nom_boutique'],
            'responsable'  => $donnees['responsable'],
            'telephone'    => $telephone,
            'ville_id'     => $ville->id,
            'categorie_id' => $donnees['categorie_id'] ?? null,
            'description'  => $donnees['description'],
            'statut'       => 'en_attente',
        ]);

        return redirect()->route('devenir-vendeur')->with('succes',
            'Merci ! Votre demande est enregistrée. Afrishop l’examine et vous rappelle au '
            .$donnees['telephone'].' sous quelques jours ouvrés.');
    }

    /**
     * Villes actives des pays OUVERTS AUX BOUTIQUES, groupées par pays.
     * Pas « ouvert_a_la_vente » : la France ou les États-Unis sont
     * ouverts aux acheteurs, pas à l'installation de boutiques.
     */
    private function villes()
    {
        return Ville::query()
            ->where('villes.active', true)
            ->join('pays', 'pays.id', '=', 'villes.pays_id')
            ->where('pays.ouvert_aux_boutiques', true)
            ->orderBy('pays.nom')->orderBy('villes.nom')
            ->get(['villes.id', 'villes.nom', 'villes.pays_id', 'pays.nom as pays_nom'])
            ->groupBy('pays_nom');
    }
}
