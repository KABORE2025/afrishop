@extends('layouts.app')

@section('titre', 'Candidatures')

@section('navigation')
    @include('console._navigation')
@endsection

@section('contenu')
<div x-data="ecranCandidatures" x-cloak>

    <h1 class="mb-1 text-2xl font-extrabold">Candidatures</h1>
    <p class="mb-4 text-sm text-gris">
        Accepter crée la boutique et rattache son gérant. Refuser exige un motif.
    </p>

    <div class="carte mb-4 flex flex-wrap items-end gap-3 p-4">
        <div>
            <label class="libelle" for="f-statut">Statut</label>
            <select id="f-statut" class="champ" x-model="statut" @change="charger(1)">
                <option value="en_attente">En attente</option>
                <option value="en_verification">En vérification</option>
                <option value="acceptee">Acceptées</option>
                <option value="refusee">Refusées</option>
            </select>
        </div>
    </div>

    <template x-if="erreur">
        <div class="carte border-alerte/40 bg-red-50 p-4 text-sm text-alerte" x-text="erreur"></div>
    </template>

    <div x-show="chargement" class="py-12 text-center text-sm text-gris">Chargement…</div>

    <template x-if="!chargement && !erreur && elements.length === 0">
        <div class="carte p-10 text-center text-sm text-gris">Rien à traiter ici.</div>
    </template>

    <div class="space-y-3">
        <template x-for="c in elements" :key="c.id">
            <div class="carte p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-bold" x-text="c.nom_boutique"></p>
                        <p class="text-xs text-gris">
                            <span x-text="c.responsable"></span> — <span x-text="c.telephone"></span>
                        </p>
                    </div>

                    {{-- L'ancienneté est mise en avant, pas la date de
                         dépôt. Une candidature oubliée est un vendeur qui
                         part ailleurs, et personne ne remarque une date. --}}
                    <span class="etat"
                          :class="c.anciennete_jours > 7 ? 'etat-impaye' : 'etat-sequestre'"
                          x-show="['en_attente','en_verification'].includes(c.statut)"
                          x-text="'En attente depuis ' + c.anciennete_jours + ' j'"></span>

                    <span class="etat etat-reverse" x-show="c.statut === 'acceptee'">Acceptée</span>
                    <span class="etat etat-rembourse" x-show="c.statut === 'refusee'">Refusée</span>
                </div>

                <p class="mt-2 text-sm" x-text="c.description"></p>

                <p class="tuile-note text-alerte" x-show="c.motif_refus"
                   x-text="'Motif du refus : ' + c.motif_refus"></p>

                <div class="mt-3 flex gap-2" x-show="['en_attente','en_verification'].includes(c.statut)">
                    <button class="btn-primaire" @click="ouvrirAcceptation(c)">Accepter</button>
                    <button class="btn-secondaire" @click="ouvrirRefus(c)">Refuser</button>
                </div>
            </div>
        </template>
    </div>

    {{-- ------------------------------------------------------------
         ACCEPTATION
         ------------------------------------------------------------ --}}
    <div x-show="acc.c" class="fixed inset-0 z-20 overflow-y-auto bg-black/40 p-4" x-cloak>
        <div class="carte mx-auto my-6 w-full max-w-lg p-5">
            <h2 class="mb-1 text-lg font-bold">Accepter la candidature</h2>
            <p class="mb-4 text-sm text-gris" x-text="acc.c?.nom_boutique"></p>

            <template x-if="!acc.resultat">
                <div>
                    <div class="mb-3">
                        <label class="libelle" for="a-taux">Taux de commission (%)</label>
                        <input id="a-taux" type="number" min="0" max="20" class="champ montant"
                               x-model="acc.taux_commission" placeholder="10">
                    </div>

                    <div class="mb-3">
                        <label class="libelle" for="a-niveau">Niveau de confiance</label>
                        <select id="a-niveau" class="champ" x-model="acc.niveau">
                            <option value="nouveau">Nouveau — séquestre le plus long</option>
                            <option value="verifie">Vérifié — libération à J+1 après livraison</option>
                            <option value="interne">Interne — libération à l'expédition</option>
                        </select>
                        {{-- Le niveau n'est pas une étiquette de politesse :
                             il fixe la durée du séquestre, donc la protection
                             de l'acheteur. --}}
                        <p class="tuile-note text-attente">
                            « Vérifié » raccourcit le séquestre : à n'accorder qu'à une boutique
                            dont on a vérifié quelque chose. Par défaut, laisser « nouveau ».
                        </p>
                    </div>

                    <div class="note mb-3" x-show="acc.message" x-text="acc.message"></div>

                    <div class="flex justify-end gap-2">
                        <button class="btn-secondaire" @click="acc.c = null">Annuler</button>
                        <button class="btn-primaire" :disabled="acc.enCours" @click="accepter">
                            <span x-show="!acc.enCours">Créer la boutique</span>
                            <span x-show="acc.enCours">Création…</span>
                        </button>
                    </div>
                </div>
            </template>

            {{-- Le mot de passe temporaire n'est affiché QU'UNE FOIS :
                 il est haché en base et ne sera plus lisible. L'écran
                 doit donc empêcher de fermer sans l'avoir vu. --}}
            <template x-if="acc.resultat">
                <div>
                    <div class="note mb-3">
                        <b x-text="acc.resultat.message"></b>
                    </div>

                    <template x-if="acc.resultat.gerant.mot_de_passe_temporaire">
                        <div class="carte border-brun-clair p-4">
                            <p class="tuile-libelle">À transmettre au gérant — affiché une seule fois</p>
                            <p class="mt-1 text-sm">
                                Téléphone :
                                <b class="montant" x-text="acc.resultat.gerant.telephone"></b>
                            </p>
                            <p class="text-sm">
                                Mot de passe :
                                <b class="montant text-lg" x-text="acc.resultat.gerant.mot_de_passe_temporaire"></b>
                            </p>
                            <p class="tuile-note text-alerte">
                                Ce mot de passe est haché en base : il ne pourra plus être affiché.
                            </p>
                        </div>
                    </template>

                    <p class="tuile-note" x-show="!acc.resultat.gerant.mot_de_passe_temporaire"
                       x-text="acc.resultat.a_communiquer"></p>

                    <div class="mt-4 flex justify-end">
                        <button class="btn-primaire" @click="fermerAcceptation">J'ai noté, fermer</button>
                    </div>
                </div>
            </template>
        </div>
    </div>

    {{-- ------------------------------------------------------------
         REFUS
         ------------------------------------------------------------ --}}
    <div x-show="ref.c" class="fixed inset-0 z-20 grid place-items-center bg-black/40 p-4" x-cloak>
        <div class="carte w-full max-w-md p-5">
            <h2 class="mb-1 text-lg font-bold">Refuser la candidature</h2>
            <p class="mb-4 text-sm text-gris" x-text="ref.c?.nom_boutique"></p>

            <label class="libelle" for="r-motif">Motif — communiqué au candidat</label>
            <textarea id="r-motif" class="champ" rows="4" x-model="ref.motif"
                      placeholder="Expliquez ce qui manque ou ce qui pose problème."></textarea>
            {{-- Obligatoire côté serveur aussi (min:10). Un refus muet
                 est indéfendable le jour où on le conteste. --}}
            <p class="tuile-note">Au moins dix caractères. Un refus sans motif est incontestable.</p>

            <div class="note mt-3" x-show="ref.message" x-text="ref.message"></div>

            <div class="mt-4 flex justify-end gap-2">
                <button class="btn-secondaire" @click="ref.c = null">Annuler</button>
                <button class="btn-danger" :disabled="ref.enCours" @click="refuser">Confirmer le refus</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script type="module">
    document.addEventListener('alpine:init', () => {
        Alpine.data('ecranCandidatures', () => ({
            elements: [], chargement: true, erreur: null, statut: 'en_attente',
            acc: { c: null, taux_commission: '', niveau: 'nouveau', enCours: false, message: null, resultat: null },
            ref: { c: null, motif: '', enCours: false, message: null },

            init() {
                if (!window.exigerConnexion()) return;
                this.charger(1);
            },

            async charger(page = 1) {
                this.chargement = true; this.erreur = null;
                try {
                    const r = await window.api.get(`/admin/candidatures?page=${page}&statut[]=${this.statut}`);
                    this.elements = r.data ?? [];
                } catch (e) { this.erreur = e.message; this.elements = []; }
                finally { this.chargement = false; }
            },

            ouvrirAcceptation(c) {
                this.acc = { c, taux_commission: '', niveau: 'nouveau', enCours: false, message: null, resultat: null };
            },

            async accepter() {
                if (this.acc.enCours) return;
                this.acc.enCours = true; this.acc.message = null;

                const corps = { niveau: this.acc.niveau };
                if (this.acc.taux_commission !== '') corps.taux_commission = Number(this.acc.taux_commission);

                try {
                    this.acc.resultat = await window.api.post(
                        `/admin/candidatures/${this.acc.c.id}/accepter`, corps);
                } catch (e) {
                    this.acc.message = e.message;
                } finally { this.acc.enCours = false; }
            },

            /* La fermeture ne se fait qu'au clic explicite : refermer
             * automatiquement ferait disparaître le mot de passe
             * temporaire avant que l'opérateur l'ait noté. */
            fermerAcceptation() {
                this.acc.c = null;
                this.acc.resultat = null;
                this.charger(1);
            },

            ouvrirRefus(c) { this.ref = { c, motif: '', enCours: false, message: null }; },

            async refuser() {
                if (this.ref.enCours) return;
                this.ref.enCours = true; this.ref.message = null;
                try {
                    await window.api.post(`/admin/candidatures/${this.ref.c.id}/refuser`, { motif: this.ref.motif });
                    this.ref.c = null;
                    this.charger(1);
                } catch (e) { this.ref.message = e.message; }
                finally { this.ref.enCours = false; }
            },
        }));
    });
</script>
@endpush
