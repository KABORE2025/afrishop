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
                            {{-- Colis remis : l'argent attend la fin de la fenêtre
                                 de protection du client (72 h). Le dire évite
                                 l'appel « j'ai livré, pourquoi ne suis-je pas payé ? ». --}}
                            <span class="block text-xs text-gris" x-show="sc.argent.fin_protection_le && !sc.litige?.ouvert"
                                  x-text="'Payé après le ' + dateHeure(sc.argent.fin_protection_le)"></span>
                            <span class="block text-xs font-semibold text-alerte" x-show="sc.litige?.ouvert"
                                  x-text="'Litige ' + sc.litige?.reference + ' — fonds gelés'"></span>
                        </td>

                        <td class="text-right montant font-semibold"
                            x-text="fcfa(sc.montants_cfa.net_boutique)"></td>

                        <td class="text-right">
                            {{-- Rien ne part avant l'encaissement : le serveur le refuse
                                 aussi (VendeurController::refusSiNonPayee()). --}}
                            <span x-show="sc.argent.etat === 'attente_encaissement'" class="text-xs text-gris">
                                Paiement en attente — ne pas préparer
                            </span>

                            <button type="button" class="btn-secondaire"
                                    x-show="sc.argent.etat === 'sequestre' && sc.commande.mode_livraison !== 'retrait_boutique' && ['a_preparer','prete'].includes(sc.marchandise.statut)"
                                    @click="ouvrirExpedition(sc)">Expédier</button>

                            <button type="button" class="btn-secondaire"
                                    x-show="sc.argent.etat === 'sequestre' && sc.commande.mode_livraison === 'retrait_boutique' && sc.marchandise.statut === 'a_preparer'"
                                    @click="preparerRetrait(sc)">Préparer le retrait</button>

                            <button type="button" class="btn-secondaire"
                                    x-show="sc.commande.mode_livraison === 'retrait_boutique' && sc.marchandise.statut === 'prete'"
                                    @click="ouvrirRetrait(sc)">Confirmer le retrait</button>

                            <span x-show="sc.marchandise.statut === 'expediee' && sc.expedition?.statut !== 'retour_expediteur'" class="text-xs text-gris">
                                En cours de livraison
                            </span>

                            {{-- Échec définitif déclaré par le livreur : le client n'est
                                 remboursé et le stock rendu qu'à la réception du colis. --}}
                            <button type="button" class="btn-secondaire"
                                    x-show="sc.marchandise.statut === 'expediee' && sc.expedition?.statut === 'retour_expediteur'"
                                    @click="confirmerRetour(sc)">Colis revenu en boutique</button>

                            {{-- RETOUR demandé par le client : fonds gelés jusqu'à la fin. --}}
                            <template x-if="sc.retour && ['demande','accepte'].includes(sc.retour.statut)">
                                <div class="mb-1 text-left text-xs">
                                    <p class="font-semibold text-alerte" x-text="'Retour ' + sc.retour.reference + ' — ' + libelleMotifRetour(sc.retour.motif)"></p>
                                    <p class="text-gris" x-show="sc.retour.commentaire" x-text="sc.retour.commentaire"></p>
                                    <p class="text-gris" x-text="sc.retour.frais_a_la_charge === 'boutique' ? 'Renvoi à votre charge (erreur de commande).' : 'Renvoi à la charge du client.'"></p>
                                </div>
                            </template>
                            <button type="button" class="btn-primaire" x-show="sc.retour?.statut === 'demande'"
                                    @click="agirRetour(sc, 'accepter')">Accepter le retour</button>
                            <button type="button" class="btn-secondaire" x-show="sc.retour?.statut === 'demande'"
                                    @click="agirRetour(sc, 'refuser')">Refuser</button>
                            <button type="button" class="btn-primaire" x-show="sc.retour?.statut === 'accepte'"
                                    @click="agirRetour(sc, 'recevoir')">Article reçu — rembourser</button>

                            <button type="button" class="btn-danger"
                                    x-show="sc.litige?.ouvert && !sc.litige?.a_repondu"
                                    @click="ouvrirLitige(sc)">Répondre au litige</button>
                            <button type="button" class="btn-secondaire"
                                    x-show="sc.litige?.ouvert && sc.litige?.a_repondu"
                                    @click="ouvrirLitige(sc)">Litige en examen</button>
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
                <label class="libelle" for="e-livreur">Livreur affecté</label>
                <select id="e-livreur" class="champ" x-model="expedition.livreur_id" required>
                    <option value="">— Choisir le livreur —</option>
                    <template x-for="livreur in livreurs" :key="livreur.id">
                        <option :value="livreur.id" x-text="livreur.nom"></option>
                    </template>
                </select>
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

    {{-- ------------------------------------------------------------
         RETRAIT EN BOUTIQUE
         ------------------------------------------------------------ --}}
    <div x-show="retrait.sc" class="fixed inset-0 z-20 grid place-items-center bg-black/40 p-4" x-cloak>
        <div class="carte w-full max-w-md p-5">
            <h2 class="mb-1 text-lg font-bold">Confirmer le retrait</h2>
            <p class="mb-4 text-sm text-gris" x-text="retrait.sc?.reference"></p>

            <div class="note mb-3">
                Le client vous présente son <b>code à 6 chiffres</b> reçu par SMS.
                Ne remettez le colis qu'après avoir saisi et validé ce code.
            </div>

            <label class="libelle" for="r-code">Code du client</label>
            <input id="r-code" inputmode="numeric" maxlength="6"
                   class="champ montant text-center text-xl tracking-[0.4em]"
                   x-model="retrait.code" placeholder="••••••">

            <div class="note mt-3" x-show="retrait.message" x-text="retrait.message"></div>

            <div class="mt-4 flex justify-end gap-2">
                <button class="btn-secondaire" @click="retrait.sc = null">Annuler</button>
                <button class="btn-primaire" :disabled="retrait.enCours" @click="confirmerRetrait">
                    <span x-show="!retrait.enCours">Valider</span>
                    <span x-show="retrait.enCours">Envoi…</span>
                </button>
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------------
         LITIGE — la version de la boutique
         ------------------------------------------------------------ --}}
    <div x-show="litige.sc" class="fixed inset-0 z-20 overflow-y-auto bg-black/40 p-4" x-cloak>
        <div class="carte mx-auto my-6 w-full max-w-lg p-5">
            <h2 class="mb-1 text-lg font-bold">
                Litige <span x-text="litige.sc?.litige?.reference"></span>
            </h2>
            <p class="mb-4 text-sm text-gris">
                Commande <span x-text="litige.sc?.reference"></span> —
                <span x-text="libelleMotif(litige.sc?.litige?.motif)"></span>
            </p>

            <div class="mb-3 rounded-lg bg-fond p-3">
                <p class="tuile-libelle">Ce que dit le client</p>
                <p class="mt-1 text-sm" x-text="litige.sc?.litige?.description"></p>
                <div class="mt-2 flex flex-wrap gap-2" x-show="litige.sc?.litige?.photos?.length">
                    <template x-for="(p, i) in (litige.sc?.litige?.photos ?? [])" :key="i">
                        <a :href="p.grand" target="_blank" rel="noopener">
                            <img :src="p.vignette" alt="Photo jointe par le client"
                                 class="h-20 w-20 rounded-lg border border-bord object-cover">
                        </a>
                    </template>
                </div>
                <p class="tuile-note" x-show="!litige.sc?.litige?.photos?.length">Aucune photo fournie par le client.</p>
            </div>

            <template x-if="litige.sc?.litige?.a_repondu">
                <div class="rounded-lg bg-fond p-3">
                    <p class="tuile-libelle">Votre version</p>
                    <p class="mt-1 text-sm" x-text="litige.sc?.litige?.argument"></p>
                    <p class="tuile-note">Transmise à Afrishop. La décision vous sera communiquée.</p>
                </div>
            </template>

            <template x-if="!litige.sc?.litige?.a_repondu">
                <div>
                    <div class="note mb-3">
                        Les fonds de cette commande sont <b>gelés</b> jusqu'à la décision d'Afrishop.
                        Donnez votre version : ce que vous avez envoyé, dans quel état, et toute preuve
                        (photo avant envoi, échanges avec le client). <b>Une seule réponse</b>, non modifiable.
                    </div>
                    <label class="libelle" for="l-arg">Votre version</label>
                    <textarea id="l-arg" class="champ" rows="5" x-model="litige.argument"></textarea>
                    <p class="tuile-note">Au moins vingt caractères.</p>
                </div>
            </template>

            <div class="note mt-3" x-show="litige.message" x-text="litige.message"></div>

            <div class="mt-4 flex justify-end gap-2">
                <button class="btn-secondaire" @click="litige.sc = null">Fermer</button>
                <button class="btn-primaire" x-show="!litige.sc?.litige?.a_repondu"
                        :disabled="litige.enCours || litige.argument.trim().length < 20" @click="repondreLitige">
                    <span x-show="!litige.enCours">Envoyer ma version</span>
                    <span x-show="litige.enCours">Envoi…</span>
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

    const MOTIFS_LITIGE = {
        non_recu: 'Colis non reçu', endommage: 'Colis endommagé',
        non_conforme: 'Produit non conforme', incomplet: 'Commande incomplète',
        contrefacon: 'Soupçon de contrefaçon', autre: 'Autre motif',
    };

    document.addEventListener('alpine:init', () => {
        Alpine.data('ecranCommandes', () => ({
            elements: [], pagination: null,
            chargement: true, erreur: null, sansBoutique: false,
            livreurs: [],

            filtres: { statut: '', etat_fonds: '', recherche: '' },

            expedition: { sc: null, livreur_id: '', enCours: false, message: null },
            retrait: { sc: null, code: '', enCours: false, message: null },
            litige: { sc: null, argument: '', enCours: false, message: null },

            async init() {
                if (!window.exigerConnexion()) return;

                /* Filtre pré-rempli depuis l'URL : les tuiles du tableau
                 * de bord renvoient ici avec ?statut=a_preparer. */
                const p = new URLSearchParams(window.location.search);
                if (p.get('statut')) this.filtres.statut = p.get('statut');

                this.charger(1);
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

            async chargerLivreurs() {
                try {
                    const r = await window.api.get('/vendeur/livreurs');
                    this.livreurs = r.data ?? [];
                } catch {
                    this.livreurs = [];
                }
            },

            libelleStatut(s) { return STATUTS[s] ?? s; },
            libelleMotif(m) { return MOTIFS_LITIGE[m] ?? m; },
            libelleMotifRetour(m) {
                return { ne_convient_pas: 'ne convient pas', taille_incorrecte: 'taille incorrecte',
                         erreur_commande: 'mauvais article envoyé', autre: 'autre motif' }[m] ?? m;
            },

            async agirRetour(sc, action) {
                let corps = {};
                if (action === 'refuser') {
                    const motif = prompt('Motif du refus (au moins 10 caractères) — le client le lira :');
                    if (!motif) return;
                    corps = { motif };
                }
                if (action === 'recevoir' && !confirm('Vous avez l’article en main ? Le client sera remboursé et le stock rendu.')) return;
                try {
                    const r = await window.api.post(`/vendeur/retours/${sc.retour.id}/${action}`, corps);
                    alert(r.message);
                    this.charger(this.pagination?.current_page ?? 1);
                } catch (e) { alert(e.message); }
            },
            dateHeure(iso) {
                if (!iso) return '—';
                return new Date(iso).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
            },

            ouvrirLitige(sc) {
                this.litige = { sc, argument: '', enCours: false, message: null };
            },

            async repondreLitige() {
                if (this.litige.enCours) return;
                this.litige.enCours = true;
                this.litige.message = null;

                try {
                    await window.api.post(`/vendeur/litiges/${this.litige.sc.litige.id}/repondre`, {
                        argument: this.litige.argument,
                    });
                    this.litige.sc = null;
                    this.charger(this.pagination?.current_page ?? 1);
                } catch (e) {
                    this.litige.message = e.message;
                } finally {
                    this.litige.enCours = false;
                }
            },

            ouvrirExpedition(sc) {
                this.expedition = { sc, livreur_id: '', enCours: false, message: null };
            },

            async expedier() {
                if (this.expedition.enCours) return;   // double-clic = double expédition
                this.expedition.enCours = true;
                this.expedition.message = null;

                try {
                    await window.api.post(`/vendeur/commandes/${this.expedition.sc.id}/expedier`, {
                        livreur_id: Number(this.expedition.livreur_id),
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

            /* Pas de champ à saisir ici : le code est généré côté serveur
             * et part par SMS. Une confirmation suffit avant l'envoi. */
            async preparerRetrait(sc) {
                if (! confirm(`Envoyer le code de retrait par SMS au client pour ${sc.reference} ?`)) return;

                try {
                    await window.api.post(`/vendeur/commandes/${sc.id}/preparer-retrait`, {});
                    this.charger(this.pagination?.current_page ?? 1);
                } catch (e) {
                    alert(e.message);
                }
            },

            /* Le livreur a rapporté un colis non remis. Confirmer rembourse
             * le client et remet les articles en stock : ne cliquer
             * qu'avec le colis en main. */
            async confirmerRetour(sc) {
                if (! confirm(`Confirmez-vous avoir récupéré le colis ${sc.reference} ? `
                    + `Le client sera remboursé et les articles remis en stock.`)) return;

                try {
                    await window.api.post(`/vendeur/commandes/${sc.id}/confirmer-retour`, {});
                    this.charger(this.pagination?.current_page ?? 1);
                } catch (e) {
                    alert(e.message);
                }
            },

            ouvrirRetrait(sc) {
                this.retrait = { sc, code: '', enCours: false, message: null };
            },

            async confirmerRetrait() {
                if (this.retrait.enCours) return;
                this.retrait.enCours = true;
                this.retrait.message = null;

                try {
                    await window.api.post(`/vendeur/commandes/${this.retrait.sc.id}/confirmer-retrait`, {
                        code_retrait: this.retrait.code,
                    });
                    this.retrait.sc = null;
                    this.charger(this.pagination?.current_page ?? 1);
                } catch (e) {
                    this.retrait.message = e.message;
                } finally {
                    this.retrait.enCours = false;
                }
            },

        }));
    });
</script>
@endpush
