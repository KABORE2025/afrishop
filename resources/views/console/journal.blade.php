@extends('layouts.app')

@section('titre', 'Journal')

@section('navigation')
    @include('console._navigation')
@endsection

@section('contenu')
<div x-data="ecranJournal" x-cloak>
    <h1 class="mb-1 text-2xl font-extrabold">Journal d'administration</h1>
    <p class="mb-4 text-sm text-gris">
        Qui a fait quoi, quand et pourquoi : codes consultés, comptes créés, boutiques vérifiées ou suspendues,
        réglages modifiés. Rien ne peut y être effacé depuis la console.
    </p>

    <div class="carte mb-4 p-4">
        <label class="libelle" for="a">Type d'action</label>
        <select id="a" class="champ" x-model="action" @change="charger(1)">
            <option value="">Toutes</option>
            <template x-for="a in actions" :key="a"><option :value="a" x-text="libelle(a)"></option></template>
        </select>
    </div>

    <template x-if="erreur"><div class="carte mb-4 border-alerte/40 bg-red-50 p-4 text-sm text-alerte" x-text="erreur"></div></template>

    <div class="carte overflow-x-auto" x-show="lignes.length">
        <table class="tableau">
            <thead><tr><th>Quand</th><th>Qui</th><th>Action</th><th>Sur</th><th>Détail</th></tr></thead>
            <tbody>
                <template x-for="l in lignes" :key="l.id">
                    <tr>
                        <td class="whitespace-nowrap text-xs" x-text="dateHeure(l.le)"></td>
                        <td x-text="l.agent ?? '—'"></td>
                        <td class="font-semibold" x-text="libelle(l.action)"></td>
                        <td class="text-xs" x-text="l.cible"></td>
                        <td class="text-xs">
                            <span x-show="l.motif" x-text="'Motif : ' + l.motif"></span>
                            <span class="block text-gris" x-show="l.avant || l.apres" x-text="resume(l)"></span>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
    <p class="py-10 text-center text-sm text-gris" x-show="!lignes.length && !erreur">Aucune entrée.</p>

    <div class="mt-3 flex items-center justify-end gap-2 text-sm" x-show="meta.dernier > 1">
        <button class="btn-secondaire" :disabled="meta.page <= 1" @click="charger(meta.page - 1)">←</button>
        <span x-text="meta.page + ' / ' + meta.dernier"></span>
        <button class="btn-secondaire" :disabled="meta.page >= meta.dernier" @click="charger(meta.page + 1)">→</button>
    </div>
</div>
@endsection

@push('scripts')
<script type="module">
const LIBELLES = {
    code_consulte: 'Code de remise consulté', code_renvoye: 'Code de remise renvoyé',
    administrateur_cree: 'Administrateur créé', agent_cree: 'Agent créé',
    paiement_verifie: 'Compte de paiement vérifié', kyc_modifie: 'Identification modifiée',
    boutique_suspendue: 'Boutique suspendue', boutique_reactivee: 'Boutique réactivée',
    parametre_modifie: 'Réglage modifié', avis_retire: 'Avis retiré',
};
document.addEventListener('alpine:init', () => Alpine.data('ecranJournal', () => ({
    lignes: [], actions: [], action: '', meta: { page: 1, dernier: 1 }, erreur: null,
    init() { if (window.exigerConnexion('admin')) this.charger(1); },
    async charger(page = 1) {
        try {
            const r = await window.api.get(`/admin/journal?page=${page}&action=${this.action}`);
            this.lignes = r.data ?? []; this.actions = r.actions ?? []; this.meta = r.meta;
        } catch (e) { this.erreur = e.message; }
    },
    libelle(a) { return LIBELLES[a] ?? a; },
    resume(l) {
        const f = (o) => o ? Object.entries(o).map(([k, v]) => `${k} = ${v}`).join(', ') : '—';
        return `${f(l.avant)} → ${f(l.apres)}`;
    },
    dateHeure(iso) { return iso ? new Date(iso).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'; },
})));
</script>
@endpush
