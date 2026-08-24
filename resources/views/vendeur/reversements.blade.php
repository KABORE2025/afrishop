@extends('layouts.app')

@section('titre', 'Mes reversements')

@section('navigation')
    @include('vendeur._navigation')
@endsection

@section('contenu')
<div x-data="ecranReversements" x-cloak>

    <h1 class="mb-1 text-2xl font-extrabold">Mes reversements</h1>
    <p class="mb-4 text-sm text-gris">
        Un virement par semaine, regroupant les ventes dont les fonds ont été libérés du séquestre.
    </p>

    <template x-if="sansBoutique">
        <div class="note">
            <b>Votre candidature est en cours d'examen.</b>
            Vos reversements apparaîtront ici après votre première vente.
        </div>
    </template>

    <template x-if="erreur && !sansBoutique">
        <div class="carte border-alerte/40 bg-red-50 p-4 text-sm text-alerte" x-text="erreur"></div>
    </template>

    <div x-show="chargement" class="py-12 text-center text-sm text-gris">Chargement…</div>

    <template x-if="!chargement && !erreur && elements.length === 0">
        <div class="carte p-10 text-center text-sm text-gris">
            Aucun reversement pour l'instant.
        </div>
    </template>

    <div x-show="elements.length > 0">

        {{--
          LA RETENUE À LA SOURCE N'EST PAS UNE COMMISSION AFRISHOP.
          C'est de l'argent prélevé pour le compte de l'État. Présentée
          sans explication, elle passe pour une ponction de la plateforme
          — et à 25 % sans numéro fiscal, pour une ponction énorme.
          C'est aussi le meilleur endroit du produit pour expliquer ce
          que l'immatriculation rapporte concrètement.
        --}}
        <div class="note mb-4" x-show="aDeLaRetenue">
            <b>À propos de la retenue à la source</b>
            Cette ligne n'est pas prélevée par Afrishop : elle est versée à l'administration
            fiscale en votre nom. Son taux dépend de votre situation —
            <b>5 % avec un numéro fiscal (IFU), 25 % sans</b>.
            Sur une vente de 24 000 F, l'écart est d'environ 4 800 F. Se faire immatriculer
            rapporte donc bien plus qu'une négociation de commission.
        </div>

        <div class="carte overflow-x-auto">
            <table class="tableau">
                <thead>
                    <tr>
                        <th>Référence</th>
                        <th>Période</th>
                        <th class="text-right">Brut</th>
                        <th class="text-right">Commission</th>
                        <th class="text-right">Retenue</th>
                        <th class="text-right">Frais</th>
                        <th class="text-right">Net versé</th>
                        <th>État</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="r in elements" :key="r.id">
                        <tr>
                            <td class="whitespace-nowrap">
                                <span class="font-semibold" x-text="r.reference"></span>
                                <span class="block text-xs text-gris" x-show="r.execute_le"
                                      x-text="'Versé le ' + dateFr(r.execute_le)"></span>
                            </td>
                            <td class="whitespace-nowrap text-xs text-gris">
                                <span x-text="dateFr(r.periode.debut)"></span> →
                                <span x-text="dateFr(r.periode.fin)"></span>
                            </td>

                            {{--
                              LES CINQ MONTANTS SONT AFFICHÉS TELS QUE STOCKÉS.
                              Aucune soustraction n'est refaite ici : les taux
                              évoluent, et un recalcul avec ceux d'aujourd'hui
                              donnerait un chiffre différent de celui réellement
                              viré il y a trois mois. Un vendeur qui voit deux
                              montants pour le même virement cesse de faire
                              confiance à la plateforme.
                            --}}
                            <td class="text-right montant" x-text="fcfa(r.montants_cfa.brut)"></td>
                            <td class="text-right montant text-gris" x-text="'− ' + fcfa(r.montants_cfa.commission)"></td>
                            <td class="text-right montant text-gris" x-text="'− ' + fcfa(r.montants_cfa.retenue_source)"></td>
                            <td class="text-right montant text-gris" x-text="'− ' + fcfa(r.montants_cfa.frais_transfert)"></td>
                            <td class="text-right montant font-bold" x-text="fcfa(r.montants_cfa.net)"></td>

                            <td>
                                <span class="etat"
                                      :class="{
                                        'etat-reverse': r.statut === 'paye',
                                        'etat-sequestre': ['a_payer','en_cours'].includes(r.statut),
                                        'etat-impaye': r.statut === 'echoue',
                                        'etat-rembourse': r.statut === 'suspendu',
                                      }"
                                      x-text="libelleStatut(r.statut)"></span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        {{-- Explications des cas qui inquiètent. Un vendeur dont le
             virement a échoué croit à une disparition de son argent s'il
             ne lit nulle part le contraire. --}}
        <template x-for="r in elements.filter(x => x.motif_echec || x.motif_suspension)" :key="'m' + r.id">
            <div class="note mt-3">
                <b x-text="r.reference"></b>
                <span x-show="r.motif_echec">
                    Le virement a échoué : <span x-text="r.motif_echec"></span>
                    <b>Vos fonds ne sont pas perdus</b> — ils restent en séquestre et seront
                    représentés au cycle suivant. Vérifiez le numéro Mobile Money de votre boutique.
                </span>
                <span x-show="r.motif_suspension">
                    Virement suspendu : <span x-text="r.motif_suspension"></span>
                </span>
            </div>
        </template>
    </div>

    <div class="mt-4 flex items-center justify-between text-sm text-gris" x-show="pagination">
        <span>Page <span x-text="pagination?.current_page"></span> sur <span x-text="pagination?.last_page"></span></span>
        <span class="flex gap-2">
            <button class="btn-secondaire" :disabled="pagination?.current_page <= 1"
                    @click="charger(pagination.current_page - 1)">Précédente</button>
            <button class="btn-secondaire" :disabled="pagination?.current_page >= pagination?.last_page"
                    @click="charger(pagination.current_page + 1)">Suivante</button>
        </span>
    </div>
</div>
@endsection

@push('scripts')
<script type="module">
    const STATUTS = {
        a_payer: 'À payer', en_cours: 'En cours', paye: 'Versé',
        echoue: 'Échoué', suspendu: 'Suspendu',
    };

    document.addEventListener('alpine:init', () => {
        Alpine.data('ecranReversements', () => ({
            elements: [], pagination: null,
            chargement: true, erreur: null, sansBoutique: false,

            init() {
                if (!window.exigerConnexion()) return;
                this.charger(1);
            },

            async charger(page = 1) {
                this.chargement = true; this.erreur = null;
                try {
                    const r = await window.api.get(`/vendeur/reversements?page=${page}`);
                    this.elements = r.data ?? [];
                    this.pagination = r.meta ?? null;
                } catch (e) {
                    this.sansBoutique = e.sansBoutique === true;
                    this.erreur = e.message;
                    this.elements = [];
                } finally { this.chargement = false; }
            },

            /* L'explication sur la retenue n'apparaît que si le vendeur en
             * subit une. L'afficher à vide serait du bruit. */
            get aDeLaRetenue() {
                return this.elements.some(r => (r.montants_cfa?.retenue_source ?? 0) > 0);
            },

            libelleStatut(s) { return STATUTS[s] ?? s; },
        }));
    });
</script>
@endpush
