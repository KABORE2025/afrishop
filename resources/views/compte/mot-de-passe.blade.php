@extends('layouts.app')

@section('titre', 'Changer mon mot de passe')

@section('contenu')
<div class="mx-auto max-w-md py-8" x-data="changerMotDePasse">
    <h1 class="mb-1 text-2xl font-extrabold">Changer mon mot de passe</h1>
    <p class="mb-6 text-sm text-gris">
        À faire dès la première connexion si vous avez reçu un mot de passe temporaire.
    </p>

    <form class="carte space-y-4 p-5" @submit.prevent="enregistrer" x-show="!fait">
        <div>
            <label class="libelle" for="actuel">Mot de passe actuel</label>
            <input id="actuel" type="password" autocomplete="current-password" class="champ" x-model="d.mot_de_passe_actuel" required>
        </div>
        <div>
            <label class="libelle" for="mdp">Nouveau mot de passe (8 caractères au moins)</label>
            <input id="mdp" type="password" autocomplete="new-password" class="champ" x-model="d.mot_de_passe" required minlength="8">
        </div>
        <div>
            <label class="libelle" for="mdp2">Nouveau mot de passe, encore une fois</label>
            <input id="mdp2" type="password" autocomplete="new-password" class="champ" x-model="d.mot_de_passe_confirmation" required minlength="8">
        </div>
        <div class="note" x-show="message" x-cloak x-text="message"></div>
        <button class="btn-primaire w-full" :disabled="enCours">Enregistrer</button>
    </form>

    <div class="carte p-5" x-show="fait" x-cloak>
        <p class="font-bold">Mot de passe modifié.</p>
        <p class="mt-1 text-sm text-gris">Vos autres sessions (autres téléphones, autres ordinateurs) ont été fermées.</p>
    </div>
</div>
@endsection

@push('scripts')
<script type="module">
document.addEventListener('alpine:init', () => Alpine.data('changerMotDePasse', () => ({
    d: { mot_de_passe_actuel: '', mot_de_passe: '', mot_de_passe_confirmation: '' },
    message: null, enCours: false, fait: false,
    init() { window.exigerConnexion(); },
    async enregistrer() {
        if (this.d.mot_de_passe !== this.d.mot_de_passe_confirmation) { this.message = 'Les deux mots de passe ne sont pas identiques.'; return; }
        this.enCours = true; this.message = null;
        try { await window.api.post('/auth/mot-de-passe', this.d); this.fait = true; }
        catch (e) { this.message = e.message; }
        finally { this.enCours = false; }
    },
})));
</script>
@endpush
