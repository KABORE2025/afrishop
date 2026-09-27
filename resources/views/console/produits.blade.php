{{--
  =====================================================================
   MODÉRATION DU CATALOGUE
  =====================================================================
   L'écran qui manquait. Le tableau de bord comptait déjà les « produits
   à modérer », mais aucune page ne permettait de trancher : une fiche
   créée par un vendeur naissait « en_attente » et y restait pour
   toujours. Le catalogue ne pouvait donc s'enrichir d'aucune fiche
   nouvelle.

   PARTI PRIS D'AFFICHAGE : la photo d'abord, en grand. Neuf refus sur
   dix se décident sur l'image — floue, volée à un site marchand, sans
   rapport avec le libellé. Une liste en tableau, où la photo serait une
   vignette de 40 px, ferait publier à l'aveugle.
  =====================================================================
--}}
@extends('layouts.app')

@section('titre', 'Modération du catalogue')

@section('navigation')
    @include('console._navigation')
@endsection

@section('contenu')
<div x-data="ecranModeration" x-cloak>

    <h1 class="mb-1 text-2xl font-extrabold">Modération du catalogue</h1>
    <p class="mb-4 text-sm text-gris">
        Une fiche n'est visible en vitrine — et achetable — qu'une fois publiée ici.
    </p>

    <div class="carte mb-4 flex flex-wrap items-end gap-3 p-4">
        <div>
            <label class="libelle" for="f-statut">Statut</label>
            <select id="f-statut" class="champ" x-model="statut" @change="charger(1)">
                <option value="en_attente">À traiter</option>
                <option value="publie">Publiées</option>
                <option value="rejete">Refusées</option>
                <option value="retire">Retirées</option>
                <option value="brouillon">Brouillons</option>
            </select>
        </div>

        <div class="grow">
            <label class="libelle" for="f-recherche">Rechercher</label>
            <input id="f-recherche" type="search" class="champ" x-model="recherche"
                   @keydown.enter="charger(1)" placeholder="Nom du produit">
        </div>
    </div>

    <template x-if="erreur">
        <div class="carte border-alerte/40 bg-red-50 p-4 text-sm text-alerte" x-text="erreur"></div>
    </template>

    {{--
      CONFIRMATION APRÈS DÉCISION.
      Sans elle, publier une fiche la faisait simplement disparaître de
      la liste : rien ne distinguait « c'est fait » de « ça a planté ».
      Le message reprend le nom du produit — sur une file de vingt
      fiches, « Fiche publiée » tout court ne dit pas laquelle.
    --}}
    <div x-show="succes" x-transition class="carte mb-4 border-succes/40 bg-green-50 p-4 text-sm"
         x-cloak>
        <b class="text-succes" x-text="succes"></b>
        <button class="ml-2 underline" @click="succes = null">fermer</button>
    </div>

    <div x-show="chargement" class="py-12 text-center text-sm text-gris">Chargement…</div>

    <template x-if="!chargement && !erreur && elements.length === 0">
        <div class="carte p-10 text-center text-sm text-gris">Rien à traiter ici.</div>
    </template>

    <div class="space-y-3">
        <template x-for="p in elements" :key="p.id">
            <div class="carte p-4">

                <div class="flex flex-col gap-4 sm:flex-row">

                    {{-- ------------------------------------------------
                         LA PHOTO, EN GRAND ET EN PREMIER
                         ------------------------------------------------ --}}
                    <div class="shrink-0">
                        <template x-if="p.medias && p.medias.length">
                            <img :src="p.medias[0].urls.vignette || p.medias[0].urls.poster"
                                 :alt="p.medias[0].texte_alternatif || p.nom"
                                 loading="lazy"
                                 class="h-40 w-40 rounded-carte border border-bord object-cover">
                        </template>

                        {{-- Une fiche sans photo n'est pas un détail : elle
                             ne se vendra pas, et c'est un motif de renvoi
                             au vendeur plutôt qu'un refus sec. --}}
                        <template x-if="!p.medias || !p.medias.length">
                            <div class="grid h-40 w-40 place-items-center rounded-carte border border-dashed border-bord text-center text-xs text-gris">
                                Aucune photo
                            </div>
                        </template>

                        <p class="mt-1 text-center text-xs text-gris"
                           x-show="p.medias && p.medias.length > 1"
                           x-text="'+ ' + (p.medias.length - 1) + ' autre(s)'"></p>
                    </div>

                    {{-- ------------------------------------------------
                         LA FICHE
                         ------------------------------------------------ --}}
                    <div class="min-w-0 grow">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-bold" x-text="p.nom"></p>
                                <p class="text-xs text-gris">
                                    <span x-text="p.reference"></span>
                                    <template x-if="p.boutique">
                                        <span>
                                            — <span x-text="p.boutique.emoji"></span>
                                            <span x-text="p.boutique.nom"></span>
                                        </span>
                                    </template>
                                    <template x-if="p.categorie">
                                        <span> — <span x-text="p.categorie.nom"></span></span>
                                    </template>
                                </p>
                            </div>

                            <span class="etat"
                                  :class="{
                                      'etat-attente_encaissement': p.moderation.statut === 'en_attente',
                                      'etat-reverse':              p.moderation.statut === 'publie',
                                      'etat-impaye':               p.moderation.statut === 'rejete',
                                      'etat-rembourse':            p.moderation.statut === 'retire' || p.moderation.statut === 'brouillon'
                                  }"
                                  x-text="libelleStatut(p.moderation.statut)"></span>
                        </div>

                        <p class="mt-2 text-sm" x-text="p.description || 'Aucune description.'"></p>

                        <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm">
                            <span>
                                <span class="text-gris">Prix&nbsp;:</span>
                                <b class="montant" x-text="formaterCfa(p.prix_ttc_cfa)"></b>
                            </span>
                            <span x-show="p.stock_total !== undefined">
                                <span class="text-gris">Stock&nbsp;:</span>
                                <b class="montant" x-text="p.stock_total"></b>
                            </span>
                            <span x-show="p.poids_g">
                                <span class="text-gris">Poids&nbsp;:</span>
                                <b class="montant" x-text="p.poids_g + ' g'"></b>
                            </span>
                            {{-- Le poids n'est pas cosmétique : c'est lui qui
                                 sert au calcul des frais de livraison. Une
                                 fiche sans poids fera facturer un forfait. --}}
                            <span class="text-attente" x-show="!p.poids_g">Poids non renseigné</span>
                            <span class="text-attente" x-show="p.tracable">Étiquettes QR demandées</span>
                        </div>

                        <p class="tuile-note text-alerte" x-show="p.moderation.motif"
                           x-text="'Motif : ' + p.moderation.motif"></p>

                        {{-- ------------------------------------------------
                             LES DÉCISIONS
                             ------------------------------------------------ --}}
                        <p class="mt-3" x-show="!window.estAdminPlein()"><p class="tuile-note">Décision réservée à un administrateur.</p></p>
                        <div class="mt-3 flex flex-wrap gap-2" x-show="window.estAdminPlein()">
                            <button class="btn-primaire"
                                    x-show="p.moderation.statut !== 'publie'"
                                    :disabled="enCours === p.id"
                                    @click="decider(p, 'publie')">Publier</button>

                            <button class="btn-secondaire"
                                    x-show="p.moderation.statut !== 'rejete'"
                                    @click="ouvrirMotif(p, 'rejete')">Refuser</button>

                            <button class="btn-danger"
                                    x-show="p.moderation.statut === 'publie'"
                                    @click="ouvrirMotif(p, 'retire')">Retirer de la vitrine</button>

                            <a class="btn-secondaire" :href="'/p/' + p.slug" target="_blank"
                               x-show="p.moderation.statut === 'publie'">Voir en vitrine</a>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    {{-- ------------------------------------------------------------
         MOTIF — obligatoire pour un refus ou un retrait
         ------------------------------------------------------------ --}}
    <div x-show="mot.p" class="fixed inset-0 z-20 grid place-items-center bg-black/40 p-4" x-cloak>
        <div class="carte w-full max-w-md p-5">
            <h2 class="mb-1 text-lg font-bold"
                x-text="mot.decision === 'rejete' ? 'Refuser la fiche' : 'Retirer la fiche'"></h2>
            <p class="mb-4 text-sm text-gris" x-text="mot.p?.nom"></p>

            <label class="libelle" for="m-motif">Motif — lu par la boutique</label>
            <textarea id="m-motif" class="champ" rows="4" x-model="mot.motif"
                      placeholder="Ex. : la photo ne montre pas le produit décrit."></textarea>

            {{-- Même règle que le refus d'une candidature, et pour la
                 même raison : sans motif, le vendeur resoumet la fiche
                 à l'identique et la file grossit. --}}
            <p class="tuile-note">
                Au moins dix caractères. Un refus muet fait resoumettre la même fiche.
            </p>

            <div class="note mt-3" x-show="mot.message" x-text="mot.message"></div>

            <div class="mt-4 flex justify-end gap-2">
                <button class="btn-secondaire" @click="mot.p = null">Annuler</button>
                <button class="btn-danger" :disabled="mot.enCours" @click="confirmerMotif">Confirmer</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script type="module">
    document.addEventListener('alpine:init', () => {
        Alpine.data('ecranModeration', () => ({
            elements: [], chargement: true, erreur: null, succes: null,
            statut: 'en_attente', recherche: '', enCours: null,
            mot: { p: null, decision: null, motif: '', enCours: false, message: null },

            init() {
                /* `exigerConnexion('admin')` redirige un vendeur vers son
                 * propre espace : la console voit les coordonnées et les
                 * montants de toutes les boutiques. */
                if (!window.exigerConnexion('admin')) return;
                this.charger(1);
            },

            formaterCfa(n) {
                return new Intl.NumberFormat('fr-FR').format(n ?? 0) + ' FCFA';
            },

            /* Le badge dit « En attente », pas « en_attente ». Une console
             * qui affiche des identifiants techniques finit par être lue
             * de travers par l'opérateur qui la lit vite. */
            libelleStatut(s) {
                return {
                    brouillon:  'Brouillon',
                    en_attente: 'À traiter',
                    publie:     'Publiée',
                    rejete:     'Refusée',
                    retire:     'Retirée',
                }[s] ?? s;
            },

            async charger(page = 1) {
                this.chargement = true; this.erreur = null;
                try {
                    const q = new URLSearchParams({ page });
                    q.append('statut[]', this.statut);
                    if (this.recherche) q.append('recherche', this.recherche);

                    const r = await window.api.get(`/admin/produits?${q}`);
                    this.elements = r.data ?? [];
                } catch (e) { this.erreur = e.message; this.elements = []; }
                finally { this.chargement = false; }
            },

            /* Publication : décision favorable, pas de motif exigé. */
            async decider(p, decision, motif = null) {
                if (this.enCours) return;
                this.enCours = p.id;
                this.erreur = null; this.succes = null;
                try {
                    const r = await window.api.post(`/admin/produits/${p.id}/moderer`,
                        motif ? { decision, motif } : { decision });

                    /* Le nom du produit dans le message : sur une file
                     * de vingt fiches, « Fiche publiée » ne dit pas
                     * laquelle vient de partir. */
                    this.succes = `« ${p.nom} » — ${r.message}`;
                    await this.charger(1);
                } catch (e) { this.erreur = e.message; }
                finally { this.enCours = null; }
            },

            ouvrirMotif(p, decision) {
                this.mot = { p, decision, motif: '', enCours: false, message: null };
            },

            async confirmerMotif() {
                if (this.mot.enCours) return;

                /* Contrôlé ici ET côté serveur. Celui d'ici évite un
                 * aller-retour ; celui du serveur est celui qui fait
                 * autorité, parce que cet écran n'est pas la seule
                 * porte d'entrée de l'API. */
                if (this.mot.motif.trim().length < 10) {
                    this.mot.message = 'Le motif doit faire au moins dix caractères.';
                    return;
                }

                this.mot.enCours = true; this.mot.message = null;
                try {
                    const nom = this.mot.p.nom;
                    const r = await window.api.post(`/admin/produits/${this.mot.p.id}/moderer`, {
                        decision: this.mot.decision,
                        motif:    this.mot.motif,
                    });
                    this.mot.p = null;
                    this.succes = `« ${nom} » — ${r.message}`;
                    await this.charger(1);
                } catch (e) { this.mot.message = e.message; }
                finally { this.mot.enCours = false; }
            },
        }));
    });
</script>
@endpush
