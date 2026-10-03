@extends('layouts.app')

@section('titre', 'Mot de passe oublié')

@section('contenu')
<div class="mx-auto max-w-md py-8" x-data="motDePasseOublie">
    <h1 class="mb-1 text-2xl font-extrabold">Mot de passe oublié</h1>

    {{-- Étape 1 : le numéro. La réponse est la même qu'il ait un compte ou
         non : la page ne doit pas révéler quels numéros sont inscrits. --}}
    <template x-if="etape === 1">
        <form class="carte mt-4 space-y-4 p-5" @submit.prevent="demander">
            <p class="text-sm text-gris">Saisissez le téléphone de votre compte : un code vous sera envoyé par SMS.</p>
            <div>
                <label class="libelle" for="tel">Téléphone</label>
                <input id="tel" type="tel" inputmode="tel" class="champ" x-model="d.telephone" required>
            </div>
            <div class="note" x-show="message" x-cloak x-text="message"></div>
            <button class="btn-primaire w-full" :disabled="enCours">Recevoir un code</button>
        </form>
    </template>

    <template x-if="etape === 2">
        <form class="carte mt-4 space-y-4 p-5" @submit.prevent="reinitialiser">
            <div class="note" x-text="info"></div>
            <div>
                <label class="libelle" for="code">Code reçu par SMS</label>
                <input id="code" inputmode="numeric" maxlength="6" class="champ montant text-center text-xl tracking-[0.4em]"
                       x-model="d.code" required placeholder="••••••">
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
            <button class="btn-primaire w-full" :disabled="enCours">Changer mon mot de passe</button>
            <button type="button" class="w-full text-sm text-gris hover:text-brun" @click="etape = 1; message = null">
                Pas reçu ? Redemander un code
            </button>
        </form>
    </template>

    <template x-if="etape === 3">
        <div class="carte mt-4 p-5">
            <p class="font-bold">Mot de passe changé.</p>
            <p class="mt-1 text-sm text-gris">Vos anciennes sessions sont fermées. Connectez-vous avec le nouveau.</p>
            <a :href="connexion" class="btn-primaire mt-4 inline-block">Se connecter</a>
        </div>
    </template>

    <p class="mt-4 text-center text-sm text-gris">
        Toujours bloqué ? Service client :
        <a href="{{ telephone_support(true) }}" class="font-semibold text-brun">{{ telephone_support() }}</a>
    </p>
</div>
@endsection

@push('scripts')
<script type="module">
document.addEventListener('alpine:init', () => Alpine.data('motDePasseOublie', () => ({
    etape: 1, info: '', message: null, enCours: false, connexion: '/vendeur/connexion',
    d: { telephone: '', code: '', mot_de_passe: '', mot_de_passe_confirmation: '' },
    async demander() {
        this.enCours = true; this.message = null;
        try { const r = await window.api.post('/auth/mot-de-passe-oublie', { telephone: this.d.telephone }); this.info = r.message; this.etape = 2; }
        catch (e) { this.message = e.message; }
        finally { this.enCours = false; }
    },
    async reinitialiser() {
        if (this.d.mot_de_passe !== this.d.mot_de_passe_confirmation) { this.message = 'Les deux mots de passe ne sont pas identiques.'; return; }
        this.enCours = true; this.message = null;
        try {
            const r = await window.api.post('/auth/reinitialiser', this.d);
            this.connexion = r.role === 'livreur' ? '/livreur/connexion' : '/vendeur/connexion';
            this.etape = 3;
        } catch (e) { this.message = e.message; }
        finally { this.enCours = false; }
    },
})));
</script>
@endpush
