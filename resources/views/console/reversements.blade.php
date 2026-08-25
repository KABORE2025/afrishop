@extends('layouts.app')

@section('titre', 'Reversements')

@section('navigation')
    @include('console._navigation')
@endsection

@section('contenu')
<div x-data="ecranReversementsAdmin" x-cloak>

    <h1 class="mb-1 text-2xl font-extrabold">Reversements</h1>

    {{--
      LECTURE SEULE, ET C'EST UNE DÉCISION.
      Marquer un virement « payé » depuis cette console reviendrait à
      déclarer à la main qu'une somme est partie. Cette confirmation doit
      venir du prestataire de paiement, par son API ou son webhook —
      sinon le grand livre affirme un versement que la banque n'a jamais
      exécuté, et l'écart n'apparaît qu'à l'arrêté de cantonnement,
      plusieurs jours plus tard.
    --}}
    <div class="note mb-4">
        <b>Écran de lecture.</b>
        Les virements sont préparés par <code>afrishop:preparer-reversements</code> et exécutés par
        le prestataire de paiement. Aucun statut ne se modifie ici : une somme n'est « payée »
        que lorsque le prestataire le confirme.
    </div>

    <div class="carte mb-4 flex flex-wrap items-end gap-3 p-4">
        <div>
            <label class="libelle" for="f-statut">Statut</label>
            <select id="f-statut" class="champ" x-model="statut" @change="charger(1)">
                <option value="">Tous</option>
                <option value="a_payer">À payer</option>
                <option value="en_cours">En cours</option>
                <option value="paye">Payés</option>
                <option value="echoue">Échoués</option>
                <option value="suspendu">Suspendus</option>
            </select>
        </div>
    </div>

    <template x-if="erreur">
        <div class="carte border-alerte/40 bg-red-50 p-4 text-sm text-alerte" x-text="erreur"></div>
    </template>

    <div x-show="chargement" class="py-12 text-center text-sm text-gris">Chargement…</div>

    <template x-if="!chargement && !erreur && elements.length === 0">
        <div class="carte p-10 text-center text-sm text-gris">Aucun reversement.</div>
    </template>

    <div class="carte overflow-x-auto" x-show="elements.length > 0">
        <table class="tableau">
            <thead>
                <tr>
                    <th>Référence</th>
                    <th>Période</th>
                    <th class="text-right">Brut</th>
                    <th class="text-right">Commission</th>
                    <th class="text-right">Retenue</th>
                    <th class="text-right">Net</th>
                    <th>État</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="r in elements" :key="r.id">
                    <tr>
                        <td class="whitespace-nowrap font-semibold" x-text="r.reference"></td>
                        <td class="whitespace-nowrap text-xs text-gris">
                            <span x-text="dateFr(r.periode.debut)"></span> →
                            <span x-text="dateFr(r.periode.fin)"></span>
                        </td>
                        <td class="text-right montant" x-text="fcfa(r.montants_cfa.brut)"></td>
                        <td class="text-right montant text-gris" x-text="fcfa(r.montants_cfa.commission)"></td>
                        {{-- Rappel permanent : cette colonne n'est pas un
                             revenu de la plateforme, c'est une dette
                             envers le Trésor. --}}
                        <td class="text-right montant text-gris" x-text="fcfa(r.montants_cfa.retenue_source)"></td>
                        <td class="text-right montant font-bold" x-text="fcfa(r.montants_cfa.net)"></td>
                        <td>
                            <span class="etat"
                                  :class="{
                                    'etat-reverse': r.statut === 'paye',
                                    'etat-sequestre': ['a_payer','en_cours'].includes(r.statut),
                                    'etat-impaye': r.statut === 'echoue',
                                    'etat-rembourse': r.statut === 'suspendu',
                                  }" x-text="libelleStatut(r.statut)"></span>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    {{-- Les échecs remontés en clair : un virement échoué est un vendeur
         qui n'a pas été payé et qui ne le sait peut-être pas encore. --}}
    <template x-for="r in elements.filter(x => x.motif_echec || x.motif_suspension)" :key="'m' + r.id">
        <div class="note mt-3">
            <b x-text="r.reference"></b>
            <span x-show="r.motif_echec" x-text="' — échec : ' + r.motif_echec"></span>
            <span x-show="r.motif_suspension" x-text="' — suspendu : ' + r.motif_suspension"></span>
            Les fonds restent en séquestre : à représenter au cycle suivant.
        </div>
    </template>
</div>
@endsection

@push('scripts')
<script type="module">
    const STATUTS = {
        a_payer: 'À payer', en_cours: 'En cours', paye: 'Payé',
        echoue: 'Échoué', suspendu: 'Suspendu',
    };

    document.addEventListener('alpine:init', () => {
        Alpine.data('ecranReversementsAdmin', () => ({
            elements: [], chargement: true, erreur: null, statut: 'a_payer',

            init() {
                if (!window.exigerConnexion()) return;
                this.charger(1);
            },

            async charger(page = 1) {
                this.chargement = true; this.erreur = null;
                const filtre = this.statut ? `&statut[]=${this.statut}` : '';
                try {
                    const r = await window.api.get(`/admin/reversements?page=${page}${filtre}`);
                    this.elements = r.data ?? [];
                } catch (e) { this.erreur = e.message; this.elements = []; }
                finally { this.chargement = false; }
            },

            libelleStatut(s) { return STATUTS[s] ?? s; },
        }));
    });
</script>
@endpush
