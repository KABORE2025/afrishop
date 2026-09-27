@extends('layouts.app')

@section('titre', 'Mes livreurs')

@section('navigation')
    @include('vendeur._navigation')
@endsection

@section('contenu')
<div x-data="ecranLivreurs" x-cloak>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold">Mes livreurs</h1>
            <p class="text-sm text-gris">Seuls les livreurs de la même ville que votre boutique peuvent être rattachés.</p>
        </div>
        <button class="btn-primaire" @click="ouvrir('choix')">Ajouter un livreur</button>
    </div>

    <div x-show="erreur" class="carte mb-4 border-alerte/40 bg-red-50 p-4 text-sm text-alerte" x-text="erreur"></div>
    <div x-show="message" class="note mb-4" x-text="message"></div>
    <div x-show="chargement" class="py-12 text-center text-sm text-gris">Chargement…</div>

    <div x-show="!chargement && agents.length === 0" class="note">Aucun livreur n’est encore rattaché à votre boutique.</div>
    <div x-show="!chargement && agents.length" class="carte overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead><tr class="border-b"><th class="p-3">ID</th><th class="p-3">Nom</th><th class="p-3">Téléphone</th><th class="p-3">Statut</th></tr></thead>
            <tbody>
                <template x-for="agent in agents" :key="agent.id">
                    <tr class="border-b last:border-0"><td class="p-3" x-text="agent.id"></td><td class="p-3" x-text="agent.nom"></td><td class="p-3" x-text="agent.telephone"></td><td class="p-3" x-text="libelleStatut(agent.statut)"></td></tr>
                </template>
            </tbody>
        </table>
    </div>

    <div x-show="modal" class="fixed inset-0 z-20 grid place-items-center bg-black/40 p-4" x-cloak>
        <div class="carte w-full max-w-md p-5">
            <template x-if="mode === 'choix'">
                <div>
                    <h2 class="mb-4 text-lg font-bold">Ajouter un livreur</h2>
                    <button class="btn-primaire mb-3 w-full" @click="mode = 'existant'">Inviter un livreur déjà inscrit</button>
                    <button class="btn-secondaire w-full" @click="mode = 'nouveau'">Créer un nouveau livreur</button>
                </div>
            </template>

            <template x-if="mode === 'existant'">
                <form @submit.prevent="rattacher">
                    <h2 class="mb-2 text-lg font-bold">Inviter un livreur déjà inscrit</h2>
                    <p class="mb-4 text-sm text-gris">
                        Demandez-lui son identifiant : il l’a en haut de son espace livreur. Sa ville doit être celle
                        de votre boutique. Il recevra un SMS et devra <b>accepter</b> votre invitation avant de
                        pouvoir recevoir vos colis.
                    </p>
                    <label class="libelle" for="livreur-id">ID du livreur</label>
                    <input id="livreur-id" class="champ mb-4" type="number" min="1" required x-model="existant.livreur_id">
                    <div class="flex justify-end gap-2"><button type="button" class="btn-secondaire" @click="modal = false">Annuler</button><button class="btn-primaire" :disabled="enCours">Envoyer l’invitation</button></div>
                </form>
            </template>

            <template x-if="mode === 'nouveau'">
                <form @submit.prevent="inviter">
                    <h2 class="mb-2 text-lg font-bold">Créer un livreur</h2>
                    <p class="mb-4 text-sm text-gris">Le livreur sera créé dans la ville de votre boutique et recevra un code d’activation par SMS.</p>
                    <label class="libelle" for="livreur-nom">Nom complet</label>
                    <input id="livreur-nom" class="champ mb-3" required maxlength="120" x-model="nouveau.nom">
                    <label class="libelle" for="livreur-tel">Téléphone</label>
                    <input id="livreur-tel" class="champ mb-3" required maxlength="20" x-model="nouveau.telephone">
                    <label class="libelle" for="livreur-cnib">CNIB</label>
                    <input id="livreur-cnib" class="champ mb-4" required minlength="5" maxlength="80" x-model="nouveau.cnib">
                    <div class="flex justify-end gap-2"><button type="button" class="btn-secondaire" @click="modal = false">Annuler</button><button class="btn-primaire" :disabled="enCours">Créer et inviter</button></div>
                </form>
            </template>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script type="module">
document.addEventListener('alpine:init', () => {
    Alpine.data('ecranLivreurs', () => ({
        agents: [], chargement: true, erreur: null, message: null, modal: false, mode: 'choix', enCours: false,
        existant: { livreur_id: '' }, nouveau: { nom: '', telephone: '', cnib: '' },
        async init() { if (window.exigerConnexion()) await this.charger(); },
        async charger() {
            this.chargement = true; this.erreur = null;
            try { const r = await window.api.get('/vendeur/agents-remise'); this.agents = r.data ?? []; }
            catch (e) { this.erreur = e.message; this.agents = []; }
            finally { this.chargement = false; }
        },
        ouvrir(mode) { this.mode = mode; this.modal = true; this.message = null; },
        libelleStatut(s) {
            return { actif: 'Actif', en_attente: 'Invitation en attente', refuse: 'A refusé', suspendu: 'Suspendu' }[s] ?? s;
        },
        async rattacher() {
            this.enCours = true;
            try { const r = await window.api.post('/vendeur/agents-remise/rattachements', { livreur_id: Number(this.existant.livreur_id) }); this.message = r.message; this.modal = false; await this.charger(); }
            catch (e) { this.erreur = e.message; }
            finally { this.enCours = false; }
        },
        async inviter() {
            this.enCours = true;
            try { const r = await window.api.post('/vendeur/agents-remise/invitations', this.nouveau); this.message = r.message; this.modal = false; this.nouveau = { nom: '', telephone: '', cnib: '' }; await this.charger(); }
            catch (e) { this.erreur = e.message; }
            finally { this.enCours = false; }
        },
    }));
});
</script>
@endpush
