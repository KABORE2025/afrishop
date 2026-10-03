@extends('layouts.app')

@section('titre', 'Connexion livreur')

@section('contenu')
<div class="mx-auto max-w-md py-8" x-data="connexionLivreur">
    <h1 class="mb-1 text-2xl font-extrabold">Espace livreur</h1>
    <p class="mb-6 text-sm text-gris">Connectez-vous pour confirmer les remises de colis.</p>
    <form class="carte space-y-4 p-5" @submit.prevent="entrer">
        <div><label class="libelle" for="tel">Téléphone</label><input id="tel" type="tel" class="champ" x-model="donnees.telephone" required></div>
        <div><label class="libelle" for="mdp">Mot de passe</label><input id="mdp" type="password" class="champ" x-model="donnees.mot_de_passe" required></div>
        <div class="note" x-show="message" x-cloak x-text="message"></div>
        <button class="btn-primaire w-full" :disabled="enCours">Se connecter</button>
    </form>
    <p class="mt-4 text-center text-sm">
        <a href="/mot-de-passe-oublie" class="font-semibold text-brun hover:underline">Mot de passe oublié ?</a>
    </p>
    <p class="mt-2 text-center text-sm text-gris">
        Nouveau livreur, avec un code reçu par SMS ?
        <a href="/livreur/activer" class="font-semibold text-brun hover:underline">Activer mon compte</a>
    </p>
</div>
@endsection

@push('scripts')
<script type="module">
document.addEventListener('alpine:init', () => Alpine.data('connexionLivreur', () => ({
    donnees: { telephone: '', mot_de_passe: '' }, message: null, enCours: false,
    async entrer() {
        this.enCours = true; this.message = null;
        try {
            const r = await window.api.post('/auth/connexion', this.donnees);
            if (!r.jeton || r.utilisateur?.role !== 'livreur') { this.message = "Ce compte n'est pas un compte livreur."; return; }
            localStorage.setItem('afrishop.jeton', r.jeton);
            localStorage.setItem('afrishop.role', 'livreur');
            const retour = new URLSearchParams(window.location.search).get('retour');
            window.location.href = retour || '/livreur';
        } catch (e) { this.message = e.message; } finally { this.enCours = false; }
    },
})));
</script>
@endpush
