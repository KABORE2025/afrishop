@extends('layouts.app')

@section('titre', 'Mes commandes')

@section('navigation')
    @include('vendeur._navigation')
@endsection

@section('contenu')
<div x-data="ecranCommandes" x-cloak>

    <h1 class="mb-4 text-2xl font-extrabold">Mes commandes</h1>

    {{-- Filtres, sur une seule ligne au-dessus de la liste. --}}
    <div class="carte mb-4 flex flex-wrap items-end gap-3 p-4">
        <div>
            <label class="libelle" for="f-statut">Marchandise</label>
            <select id="f-statut" class="champ" x-model="filtres.statut" @change="charger(1)">
                <option value="">Toutes</option>
                <option value="a_preparer">À préparer</option>
                <option value="prete">Prête</option>
                <option value="expediee">Expédiée</option>
                <option value="livree">Livrée</option>
                <option value="retournee">Retournée</option>
                <option value="annulee">Annulée</option>
            </select>
        </div>

        <div>
            <label class="libelle" for="f-fonds">Argent</label>
            <select id="f-fonds" class="champ" x-model="filtres.etat_fonds" @change="charger(1)">
                <option value="">Tous</option>
                <option value="attente_encaissement">En attente d'encaissement</option>
                <option value="sequestre">En séquestre</option>
                <option value="reverse">Reversé</option>
                <option value="rembourse">Remboursé</option>
                <option value="impaye">Impayé</option>
            </select>
        </div>

        <div class="flex-1 min-w-[180px]">
            <label class="libelle" for="f-ref">Référence</label>
            <input id="f-ref" type="search" class="champ" placeholder="AFS-2026-…"
                   x-model.debounce.400ms="filtres.recherche" @input.debounce.400ms="charger(1)">
        </div>
    </div>

    <template x-if="sansBoutique">
        <div class="note">
            <b>Votre candidature est en cours d'examen.</b>
            Vous verrez vos commandes ici dès que votre boutique sera validée.
        </div>
    </template>

    <template x-if="erreur && !sansBoutique">
        <div class="carte border-alerte/40 bg-red-50 p-4 text-sm text-alerte" x-text="erreur"></div>
    </template>

    <div x-show="chargement" class="py-12 text-center text-sm text-gris">Chargement…</div>

    <template x-if="!chargement && !erreur && elements.length === 0">
        <div class="carte p-10 text-center text-sm text-gris">
            Aucune commande ne correspond à ces filtres.
        </div>
    </template>

    {{--
      LES DEUX AXES SONT DEUX COLONNES DISTINCTES.
      Jamais une seule colonne « statut ». Une commande LIVRÉE dont les
      fonds sont EN SÉQUESTRE est le cas normal pendant tout le délai de
      réclamation : les fusionner ferait croire à une anomalie, ou pire,
      ferait croire le vendeur payé.
    --}}
    <div class="carte overflow-x-auto" x-show="elements.length > 0">
        <table class="tableau">
            <thead>
                <tr>
                    <th>Référence</th>
                    <th>Marchandise</th>
                    <th>Argent</th>
                    <th class="text-right">Net à recevoir</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="sc in elements" :key="sc.id">
                    <tr>
                        {{-- `whitespace-nowrap` : une référence coupée en
                             trois lignes est illisible, et c'est la
                             colonne qu'on lit en premier. Le tableau
                             défile plutôt que de la casser. --}}
                        <td class="whitespace-nowrap">
                            <span class="font-semibold" x-text="sc.reference"></span>
                            <span class="block text-xs text-gris" x-text="dateFr(sc.cree_le)"></span>
                        </td>

                        <td>
                            <span class="statut" :class="'statut-' + sc.marchandise.statut"
                                  x-text="libelleStatut(sc.marchandise.statut)"></span>
                        </td>

                        <td>
                            {{-- Le libellé vient de l'API (EtatFonds::libelle()) :
                                 une seule formulation pour toute la plateforme. --}}
                            <span class="etat" :class="'etat-' + sc.argent.etat"
                                  x-text="sc.argent.libelle"></span>
                        </td>

                        <td class="text-right montant font-semibold"
                            x-text="fcfa(sc.montants_cfa.net_boutique)"></td>

                        <td class="text-right">
                            <button type="button" class="btn-secondaire"
                                    x-show="['a_preparer','prete'].includes(sc.marchandise.statut)"
                                    @click="ouvrirExpedition(sc)">Expédier</button>

                            <span x-show="sc.marchandise.statut === 'expediee'" class="text-xs text-gris">
                                En cours de livraison
                            </span>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    {{-- Pagination --}}
    <div class="mt-4 flex items-center justify-between text-sm text-gris" x-show="pagination">
        <span>
            Page <span x-text="pagination?.current_page"></span> sur <span x-text="pagination?.last_page"></span>
            — <span x-text="pagination?.total"></span> commande(s)
        </span>
        <span class="flex gap-2">
            <button class="btn-secondaire" :disabled="pagination?.current_page <= 1"
                    @click="charger(pagination.current_page - 1)">Précédente</button>
            <button class="btn-secondaire" :disabled="pagination?.current_page >= pagination?.last_page"
                    @click="charger(pagination.current_page + 1)">Suivante</button>
        </span>
    </div>

    {{-- ------------------------------------------------------------
         EXPÉDITION
         ------------------------------------------------------------ --}}
    <div x-show="expedition.sc" class="fixed inset-0 z-20 grid place-items-center bg-black/40 p-4" x-cloak>
        <div class="carte w-full max-w-md p-5">
            <h2 class="mb-1 text-lg font-bold">Expédier</h2>
            <p class="mb-4 text-sm text-gris" x-text="expedition.sc?.reference"></p>

            <div class="mb-3">
                <label class="libelle" for="e-transp">Transporteur</label>
                <select id="e-transp" class="champ" x-model="expedition.transporteur_id">
                    <option value="">— Non précisé —</option>
                    <template x-for="t in transporteurs" :key="t.id">
                        <option :value="t.id" x-text="t.nom + (t.encaisse_especes ? ' (encaisse)' : '')"></option>
                    </template>
                </select>
            </div>

            <div class="mb-3">
                <label class="libelle" for="e-livreur">Livreur affecté</label>
                <select id="e-livreur" class="champ" x-model="expedition.livreur_id" required>
                    <option value="">— Choisir le livreur —</option>
                    <template x-for="livreur in livreurs" :key="livreur.id">
                        <option :value="livreur.id" x-text="livreur.nom"></option>
                    </template>
                </select>
            </div>

            <div class="mb-3">
                <label class="libelle" for="e-suivi">Code de suivi</label>
                <input id="e-suivi" type="text" class="champ" x-model="expedition.code_suivi" maxlength="60">
            </div>

            <div class="note mb-4">
                Un <b>code de livraison à 6 chiffres</b> est envoyé au client par SMS.
                Le livreur devra le lui demander à la remise du colis. Ce code ne vous
                est pas communiqué : c'est lui qui prouve que la livraison a bien eu lieu.
            </div>

            <div class="note mb-3" x-show="expedition.message" x-text="expedition.message"></div>

            <div class="flex justify-end gap-2">
                <button class="btn-secondaire" @click="expedition.sc = null">Annuler</button>
                <button class="btn-primaire" :disabled="expedition.enCours" @click="expedier">
                    <span x-show="!expedition.enCours">Confirmer l'expédition</span>
                    <span x-show="expedition.enCours">Envoi…</span>
                </button>
            </div>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script type="module">
    const STATUTS = {
        a_preparer: 'À préparer', prete: 'Prête', expediee: 'Expédiée',
        livree: 'Livrée', retournee: 'Retournée', annulee: 'Annulée',
    };

    document.addEventListener('alpine:init', () => {
        Alpine.data('ecranCommandes', () => ({
            elements: [], pagination: null,
            chargement: true, erreur: null, sansBoutique: false,
            transporteurs: [],
            livreurs: [],

            filtres: { statut: '', etat_fonds: '', recherche: '' },

            expedition: { sc: null, transporteur_id: '', code_suivi: '', enCours: false, message: null },

            async init() {
                if (!window.exigerConnexion()) return;

                /* Filtre pré-rempli depuis l'URL : les tuiles du tableau
                 * de bord renvoient ici avec ?statut=a_preparer. */
                const p = new URLSearchParams(window.location.search);
                if (p.get('statut')) this.filtres.statut = p.get('statut');

                this.charger(1);
                this.chargerTransporteurs();
                this.chargerLivreurs();
            },

            async charger(page = 1) {
                this.chargement = true;
                this.erreur = null;

                const params = new URLSearchParams({ page });
                for (const [c, v] of Object.entries(this.filtres)) if (v) params.append(c, v);

                try {
                    const r = await window.api.get(`/vendeur/commandes?${params}`);
                    this.elements = r.data ?? [];
                    this.pagination = r.meta ?? null;
                } catch (e) {
                    this.sansBoutique = e.sansBoutique === true;
                    this.erreur = e.message;
                    this.elements = [];
                } finally {
                    this.chargement = false;
                }
            },

            async chargerTransporteurs() {
                try {
                    const r = await window.api.get('/transporteurs');
                    this.transporteurs = r.data ?? r;
                } catch {
                    /* Le transporteur est facultatif à l'expédition :
                     * une liste vide ne doit pas bloquer l'écran. */
                }
            },

            async chargerLivreurs() {
                try {
                    const r = await window.api.get('/vendeur/livreurs');
                    this.livreurs = r.data ?? [];
                } catch {
                    this.livreurs = [];
                }
            },

            libelleStatut(s) { return STATUTS[s] ?? s; },

            ouvrirExpedition(sc) {
                this.expedition = { sc, transporteur_id: '', livreur_id: '', code_suivi: '', enCours: false, message: null };
            },

            async expedier() {
                if (this.expedition.enCours) return;   // double-clic = double expédition
                this.expedition.enCours = true;
                this.expedition.message = null;

                try {
                    await window.api.post(`/vendeur/commandes/${this.expedition.sc.id}/expedier`, {
                        transporteur_id: this.expedition.transporteur_id || null,
                        livreur_id: Number(this.expedition.livreur_id),
                        code_suivi: this.expedition.code_suivi || null,
                    });
                    this.expedition.sc = null;
                    this.charger(this.pagination?.current_page ?? 1);
                } catch (e) {
                    /* Message serveur affiché tel quel : il est déjà rédigé
                     * en français et explique la règle métier. */
                    this.expedition.message = e.message;
                } finally {
                    this.expedition.enCours = false;
                }
            },

        }));
    });
</script>
@endpush
