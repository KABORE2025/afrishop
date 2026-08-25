@extends('layouts.app')

@section('titre', 'Connexion')

@section('contenu')
<div class="mx-auto max-w-md py-8" x-data="connexion">

    {{-- Titre neutre : administrateurs et vendeurs se connectent par la
         même porte. C'est le RÔLE du compte qui décide où l'on arrive,
         pas l'URL par laquelle on est entré. --}}
    <h1 class="mb-1 text-2xl font-extrabold">Connexion</h1>
    <p class="mb-6 text-sm text-gris">Espace vendeur et console Afrishop.</p>

    <form class="carte space-y-4 p-5" @submit.prevent="entrer">

        <div>
            <label class="libelle" for="tel">Téléphone</label>
            <input id="tel" type="tel" inputmode="tel" autocomplete="username"
                   class="champ" x-model="donnees.telephone" required>
            <span class="erreur-champ" x-show="erreur('telephone')" x-text="erreur('telephone')"></span>
        </div>

        <div>
            <label class="libelle" for="mdp">Mot de passe</label>
            <input id="mdp" type="password" autocomplete="current-password"
                   class="champ" x-model="donnees.mot_de_passe" required>
            <span class="erreur-champ" x-show="erreur('mot_de_passe')" x-text="erreur('mot_de_passe')"></span>
        </div>

        {{-- Message d'erreur global. Un identifiant refusé ne dit jamais
             LEQUEL des deux est faux : préciser « ce numéro n'existe pas »
             permettrait de découvrir quels numéros ont un compte. --}}
        <div class="note" x-show="message" x-cloak>
            <span x-text="message"></span>
        </div>

        <button type="submit" class="btn-primaire w-full" :disabled="enCours">
            <span x-show="!enCours">Se connecter</span>
            <span x-show="enCours" x-cloak>Connexion…</span>
        </button>
    </form>

    <p class="mt-4 text-center text-sm text-gris">
        Pas encore vendeur ?
        <a href="{{ route('vitrine') }}" class="font-semibold text-brun">Déposer une candidature</a>
    </p>
</div>
@endsection

@push('scripts')
<script type="module">
    document.addEventListener('alpine:init', () => {
        Alpine.data('connexion', () => ({
            donnees: { telephone: '', mot_de_passe: '' },
            erreurs: {}, message: null, enCours: false,

            async entrer() {
                if (this.enCours) return;
                this.enCours = true;
                this.erreurs = {};
                this.message = null;

                try {
                    const r = await window.api.post('/auth/connexion', this.donnees);

                    /* AuthController::connecter() renvoie { utilisateur, jeton }.
                     * Le jeton Sanctum est conservé dans le navigateur et
                     * renvoyé en en-tête `Authorization: Bearer` à chaque
                     * appel suivant — c'est lui, et lui seul, qui prouve
                     * au serveur qui vous êtes. */
                    if (!r.jeton) {
                        this.message = "Connexion acceptée mais aucun jeton reçu. Contactez le support.";
                        return;
                    }

                    localStorage.setItem('afrishop.jeton', r.jeton);

                    /*
                     * DESTINATION SELON LE RÔLE, pas selon l'URL.
                     * Renvoyer tout le monde vers /vendeur envoyait un
                     * administrateur sur une API vendeur à laquelle son
                     * rôle ne donne pas accès — et le message d'erreur
                     * ne disait pas pourquoi.
                     *
                     * `?retour=` (posé par la garde `exigerConnexion`)
                     * reste prioritaire : quelqu'un qui visait une page
                     * précise doit y revenir après s'être connecté.
                     */
                    const params = new URLSearchParams(window.location.search);
                    const retour = params.get('retour');

                    const role = r.utilisateur?.role;
                    const parDefaut = ['admin', 'agent'].includes(role) ? '/console'
                                    : role === 'vendeur'               ? '/vendeur'
                                    : '/';

                    window.location.href = retour || parDefaut;
                } catch (e) {
                    this.erreurs = e.erreurs ?? {};
                    this.message = e.message;
                } finally {
                    this.enCours = false;
                }
            },

            erreur(champ) { return this.erreurs[champ]?.[0] ?? null; },
        }));
    });
</script>
@endpush
