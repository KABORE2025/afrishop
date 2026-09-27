@extends('layouts.app')

@section('titre', 'Litiges')

@section('navigation')
    @include('console._navigation')
@endsection

@section('contenu')
<div x-data="ecranLitiges" x-cloak>

    <h1 class="mb-1 text-2xl font-extrabold">Litiges</h1>
    <p class="mb-4 text-sm text-gris">
        Tant qu'un litige est ouvert, les fonds sont gelés : ni le client ni la boutique n'ont leur argent.
    </p>

    <div class="carte mb-4 flex flex-wrap items-end gap-3 p-4">
        <div>
            <label class="libelle" for="f-statut">Statut</label>
            <select id="f-statut" class="champ" x-model="statut" @change="charger(1)">
                <option value="ouvert">Ouverts</option>
                <option value="en_examen">En examen</option>
                <option value="resolu_client">Résolus — client</option>
                <option value="resolu_boutique">Résolus — boutique</option>
            </select>
        </div>
    </div>

    <template x-if="erreur">
        <div class="carte border-alerte/40 bg-red-50 p-4 text-sm text-alerte" x-text="erreur"></div>
    </template>

    <div x-show="chargement" class="py-12 text-center text-sm text-gris">Chargement…</div>

    <template x-if="!chargement && !erreur && elements.length === 0">
        <div class="carte p-10 text-center text-sm text-gris">Aucun litige dans cet état.</div>
    </template>

    <div class="space-y-3">
        <template x-for="l in elements" :key="l.id">
            <div class="carte p-4">

                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-bold">
                            <span x-text="l.reference"></span>
                            <span class="text-gris" x-text="' — ' + libelleMotif(l.motif)"></span>
                        </p>
                        <p class="text-xs text-gris" x-show="l.sous_commande">
                            Commande <span x-text="l.sous_commande?.reference"></span> —
                            marchandise <span x-text="l.sous_commande?.statut"></span>
                        </p>
                    </div>

                    {{-- Le montant en jeu sert à hiérarchiser : un litige à
                         180 000 F et un à 2 500 F ne méritent pas la même
                         minute d'attention. --}}
                    <p class="montant text-lg font-bold" x-show="l.sous_commande"
                       x-text="fcfa(l.sous_commande?.montant_en_jeu_cfa)"></p>
                </div>

                {{--
                  LES DEUX VERSIONS CÔTE À CÔTE.
                  La réputation est un actif pour un vendeur : arbitrer
                  sans avoir lu sa version laisse un client de mauvaise
                  foi abîmer durablement une boutique honnête.
                --}}
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg bg-fond p-3">
                        <p class="tuile-libelle">Version du client</p>
                        <p class="mt-1 text-sm" x-text="l.client.description"></p>
                        {{-- Clic = photo en grand dans un nouvel onglet. Les
                             liens expirent en 30 min : recharger la page
                             si une photo ne s'ouvre plus. --}}
                        <div class="mt-2 flex flex-wrap gap-2" x-show="l.client.photos?.length">
                            <template x-for="(p, i) in l.client.photos" :key="i">
                                <a :href="p.grand" target="_blank" rel="noopener">
                                    <img :src="p.vignette" alt="Photo jointe par le client"
                                         class="h-20 w-20 rounded-lg border border-bord object-cover">
                                </a>
                            </template>
                        </div>
                        <p class="tuile-note" x-show="!l.client.photos?.length">Aucune photo fournie.</p>
                        <p class="tuile-note" x-text="'Ouvert le ' + dateFr(l.client.ouvert_le)"></p>
                    </div>

                    <div class="rounded-lg p-3" :class="l.boutique.a_repondu ? 'bg-fond' : 'bg-amber-50'">
                        <p class="tuile-libelle">Version de la boutique</p>
                        <p class="mt-1 text-sm" x-show="l.boutique.a_repondu" x-text="l.boutique.argument"></p>
                        {{-- Arbitrer sans réponse de la boutique reste
                             possible, mais l'écran doit le dire. --}}
                        <p class="mt-1 text-sm text-attente" x-show="!l.boutique.a_repondu">
                            La boutique n'a pas encore répondu. Arbitrer maintenant, c'est trancher
                            sans l'avoir entendue.
                        </p>
                    </div>
                </div>

                <p class="tuile-note" x-show="l.resolution" x-text="'Résolution : ' + l.resolution"></p>

                <div class="mt-3" x-show="['ouvert','en_examen'].includes(l.statut) && window.estAdminPlein()">
                    <button class="btn-primaire" @click="ouvrirArbitrage(l)">Arbitrer</button>
                </div>
                <div class="mt-3" x-show="['ouvert','en_examen'].includes(l.statut) && !window.estAdminPlein()">
                    <p class="tuile-note">Décision réservée à un administrateur.</p>
                </div>
            </div>
        </template>
    </div>

    {{-- ------------------------------------------------------------
         ARBITRAGE — le seul écran de la console qui déplace de l'argent
         ------------------------------------------------------------ --}}
    <div x-show="arb.l" class="fixed inset-0 z-20 overflow-y-auto bg-black/40 p-4" x-cloak>
        <div class="carte mx-auto my-6 w-full max-w-lg p-5">
            <h2 class="mb-1 text-lg font-bold">Arbitrer</h2>
            <p class="mb-4 text-sm text-gris" x-text="arb.l?.reference"></p>

            <div class="mb-3 grid gap-2 sm:grid-cols-2">
                <button class="btn-secondaire" :class="arb.sens === 'client' && 'border-brun text-brun'"
                        @click="arb.sens = 'client'">En faveur du client</button>
                <button class="btn-secondaire" :class="arb.sens === 'boutique' && 'border-brun text-brun'"
                        @click="arb.sens = 'boutique'">En faveur de la boutique</button>
            </div>

            {{-- Dire EXACTEMENT ce que le clic va provoquer sur l'argent.
                 Un arbitrage n'est pas un changement de statut : c'est un
                 remboursement ou une libération de fonds, et il ne se
                 défait pas d'un clic. --}}
            <div class="note mb-3" x-show="arb.sens === 'client'">
                <b>Le client sera remboursé.</b>
                Les fonds séquestrés lui reviennent, l'écriture correspondante est passée au
                grand livre, et la boutique ne percevra rien sur cette vente.
            </div>
            <div class="note mb-3" x-show="arb.sens === 'boutique'">
                <b>Les fonds seront libérés vers la boutique.</b>
                Ils entreront dans son prochain reversement. Le client ne sera pas remboursé.
            </div>

            <div class="mb-3">
                <label class="libelle" for="a-res">Motivation — visible des deux parties</label>
                <textarea id="a-res" class="champ" rows="4" x-model="arb.resolution"
                          placeholder="Ce qui a été constaté, et pourquoi la décision va dans ce sens."></textarea>
                <p class="tuile-note">
                    Au moins dix caractères. C'est la pièce à produire si l'une des deux parties revient dessus.
                </p>
            </div>

            <div class="note mb-3" x-show="arb.message" x-text="arb.message"></div>

            <div class="flex justify-end gap-2">
                <button class="btn-secondaire" @click="arb.l = null">Annuler</button>
                <button class="btn-primaire" :disabled="arb.enCours || !arb.sens" @click="arbitrer">
                    <span x-show="!arb.enCours">Confirmer l'arbitrage</span>
                    <span x-show="arb.enCours">Traitement…</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script type="module">
    const MOTIFS = {
        non_recu: 'Colis non reçu', endommage: 'Colis endommagé',
        non_conforme: 'Produit non conforme', incomplet: 'Commande incomplète',
        contrefacon: 'Soupçon de contrefaçon', autre: 'Autre motif',
    };

    document.addEventListener('alpine:init', () => {
        Alpine.data('ecranLitiges', () => ({
            elements: [], chargement: true, erreur: null, statut: 'ouvert',
            arb: { l: null, sens: '', resolution: '', enCours: false, message: null },

            init() {
                if (!window.exigerConnexion()) return;
                this.charger(1);
            },

            async charger(page = 1) {
                this.chargement = true; this.erreur = null;
                try {
                    const r = await window.api.get(`/admin/litiges?page=${page}&statut[]=${this.statut}`);
                    this.elements = r.data ?? [];
                } catch (e) { this.erreur = e.message; this.elements = []; }
                finally { this.chargement = false; }
            },

            libelleMotif(m) { return MOTIFS[m] ?? m; },

            ouvrirArbitrage(l) {
                this.arb = { l, sens: '', resolution: '', enCours: false, message: null };
            },

            async arbitrer() {
                if (this.arb.enCours) return;   // un double-clic rembourserait deux fois
                this.arb.enCours = true; this.arb.message = null;

                try {
                    await window.api.post(`/admin/litiges/${this.arb.l.id}/arbitrer`, {
                        sens: this.arb.sens, resolution: this.arb.resolution,
                    });
                    this.arb.l = null;
                    this.charger(1);
                } catch (e) {
                    this.arb.message = e.message;
                } finally { this.arb.enCours = false; }
            },
        }));
    });
</script>
@endpush
