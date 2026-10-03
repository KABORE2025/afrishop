@extends('layouts.app')

@section('titre', 'Avis clients')

@section('navigation')
    @include('console._navigation')
@endsection

@section('contenu')
<div x-data="ecranAvis" x-cloak>
    <h1 class="mb-1 text-2xl font-extrabold">Avis clients</h1>
    <p class="mb-4 text-sm text-gris">
        Seul un client dont le colis a été remis peut laisser un avis ; il est publié tout de suite.
        Retirez un avis s'il est injurieux, hors sujet ou contient des coordonnées — pas parce qu'il est négatif.
    </p>

    <div class="carte mb-4 p-4">
        <label class="libelle" for="s">Afficher</label>
        <select id="s" class="champ" x-model="statut" @change="charger(1)">
            <option value="publie">Publiés</option>
            <option value="rejete">Retirés</option>
        </select>
    </div>

    <template x-if="erreur"><div class="carte mb-4 border-alerte/40 bg-red-50 p-4 text-sm text-alerte" x-text="erreur"></div></template>

    <div class="space-y-3">
        <template x-for="a in liste" :key="a.id">
            <div class="carte p-4">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <p class="font-bold">
                        <span class="text-brun" x-text="'★'.repeat(a.note) + '☆'.repeat(5 - a.note)"></span>
                        <a :href="'/p/' + a.slug" target="_blank" class="ml-2 hover:underline" x-text="a.produit"></a>
                    </p>
                    <span class="text-xs text-gris" x-text="a.auteur + ' — ' + a.boutique + ' — ' + dateFr(a.le)"></span>
                </div>
                <p class="mt-2 text-sm" x-text="a.commentaire || '(sans commentaire)'"></p>
                <p class="tuile-note text-alerte" x-show="a.motif_rejet" x-text="'Retiré : ' + a.motif_rejet"></p>
                <button class="btn-danger mt-3" x-show="statut === 'publie'" @click="retirer(a)">Retirer</button>
            </div>
        </template>
    </div>
    <p class="py-10 text-center text-sm text-gris" x-show="!liste.length && !erreur">Aucun avis.</p>

    <div class="mt-3 flex items-center justify-end gap-2 text-sm" x-show="meta.dernier > 1">
        <button class="btn-secondaire" :disabled="meta.page <= 1" @click="charger(meta.page - 1)">←</button>
        <span x-text="meta.page + ' / ' + meta.dernier"></span>
        <button class="btn-secondaire" :disabled="meta.page >= meta.dernier" @click="charger(meta.page + 1)">→</button>
    </div>
</div>
@endsection

@push('scripts')
<script type="module">
document.addEventListener('alpine:init', () => Alpine.data('ecranAvis', () => ({
    liste: [], statut: 'publie', meta: { page: 1, dernier: 1 }, erreur: null,
    init() { if (window.exigerConnexion('admin')) this.charger(1); },
    async charger(page = 1) {
        try { const r = await window.api.get(`/admin/avis?page=${page}&statut=${this.statut}`); this.liste = r.data ?? []; this.meta = r.meta; }
        catch (e) { this.erreur = e.message; }
    },
    async retirer(a) {
        const motif = prompt('Motif du retrait (au moins 10 caractères) :');
        if (!motif) return;
        try { const r = await window.api.post(`/admin/avis/${a.id}/retirer`, { motif }); alert(r.message); this.charger(this.meta.page); }
        catch (e) { alert(e.message); }
    },
})));
</script>
@endpush
