@extends('layouts.app')

@section('titre', 'Activer mon compte livreur')

@section('contenu')
{{-- Le livreur créé par une boutique reçoit un code par SMS ; sans cette
     page, il ne pouvait ni choisir de mot de passe ni se connecter. --}}
<div class="mx-auto max-w-md py-8" x-data="activationLivreur">
    <h1 class="mb-1 text-2xl font-extrabold">Activer mon compte livreur</h1>
    <p class="mb-6 text-sm text-gris">
        Une boutique vous a invité : vous avez reçu un code à 6 chiffres par SMS. Choisissez ici votre mot de passe.
    </p>

    <template x-if="!termine">
        <form class="carte space-y-4 p-5" @submit.prevent="activer">
            <div>
                <label class="libelle" for="cnib">Numéro de CNIB</label>
                <input id="cnib" class="champ" x-model="d.cnib" required autocapitalize="characters">
                <p class="tuile-note">Celui donné à la boutique qui vous a invité.</p>
            </div>
            <div>
                <label class="libelle" for="tel">Téléphone (celui qui a reçu le SMS)</label>
                <input id="tel" type="tel" inputmode="tel" class="champ" x-model="d.telephone" required>
            </div>
            <div>
                <label class="libelle" for="code">Code reçu par SMS</label>
                <input id="code" inputmode="numeric" maxlength="6" class="champ montant text-center text-xl tracking-[0.4em]"
                       x-model="d.code" required placeholder="••••••">
            </div>
            <div>
                <label class="libelle" for="mdp">Mot de passe (8 caractères au moins)</label>
                <input id="mdp" type="password" autocomplete="new-password" class="champ" x-model="d.mot_de_passe" required minlength="8">
            </div>
            <div>
                <label class="libelle" for="mdp2">Mot de passe, encore une fois</label>
                <input id="mdp2" type="password" autocomplete="new-password" class="champ" x-model="d.mot_de_passe_confirmation" required minlength="8">
            </div>
            <div class="note" x-show="message" x-cloak x-text="message"></div>
            <button class="btn-primaire w-full" :disabled="enCours">
                <span x-show="!enCours">Activer mon compte</span><span x-show="enCours">Activation…</span>
            </button>
        </form>
    </template>

    <template x-if="termine">
        <div class="carte p-5">
            <p class="font-bold">Compte activé.</p>
            <p class="mt-1 text-sm text-gris">Connectez-vous avec votre téléphone et le mot de passe choisi.</p>
            <a href="/livreur/connexion" class="btn-primaire mt-4 inline-block">Se connecter</a>
        </div>
    </template>
</div>
@endsection

@push('scripts')
<script type="module">
document.addEventListener('alpine:init', () => Alpine.data('activationLivreur', () => ({
    d: { cnib: '', telephone: '', code: '', mot_de_passe: '', mot_de_passe_confirmation: '' },
    message: null, enCours: false, termine: false,
    async activer() {
        if (this.d.mot_de_passe !== this.d.mot_de_passe_confirmation) { this.message = 'Les deux mots de passe ne sont pas identiques.'; return; }
        this.enCours = true; this.message = null;
        try { await window.api.post('/agents-remise/activer', this.d); this.termine = true; }
        catch (e) { this.message = e.message; }
        finally { this.enCours = false; }
    },
})));
</script>
@endpush
