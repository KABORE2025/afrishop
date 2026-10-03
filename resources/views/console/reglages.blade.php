@extends('layouts.app')

@section('titre', 'Réglages')

@section('navigation')
    @include('console._navigation')
@endsection

@section('contenu')
<div x-data="ecranReglages" x-cloak>
    <h1 class="mb-1 text-2xl font-extrabold">Réglages de la plateforme</h1>
    <p class="mb-4 text-sm text-gris">
        Chaque changement s'applique <b>tout de suite</b>, à toutes les boutiques, et il est inscrit au journal avec son motif.
        Les valeurs hors bornes sont refusées.
    </p>

    <template x-if="erreur"><div class="carte mb-4 border-alerte/40 bg-red-50 p-4 text-sm text-alerte" x-text="erreur"></div></template>

    <div class="space-y-3">
        <template x-for="p in liste" :key="p.cle">
            <form class="carte flex flex-wrap items-end gap-3 p-4" @submit.prevent="enregistrer(p)">
                <div class="min-w-0 flex-1">
                    <p class="font-semibold" x-text="p.libelle"></p>
                    <p class="tuile-note" x-text="'Entre ' + p.min + ' et ' + p.max + ' ' + p.unite + ' — valeur d’origine : ' + p.defaut"></p>
                </div>
                <div>
                    <label class="libelle" :for="'v-' + p.cle" x-text="p.unite"></label>
                    <input :id="'v-' + p.cle" type="number" class="champ w-36" :min="p.min" :max="p.max" x-model.number="p.nouvelle" required>
                </div>
                <div class="min-w-[14rem] flex-1">
                    <label class="libelle" :for="'m-' + p.cle">Motif du changement</label>
                    <input :id="'m-' + p.cle" class="champ" x-model="p.motif" placeholder="Au moins dix caractères">
                </div>
                <button class="btn-primaire" :disabled="p.nouvelle === p.valeur">Enregistrer</button>
            </form>
        </template>
    </div>

    <div class="note mt-4">
        La <b>fenêtre de protection</b> s'applique aussi aux colis déjà livrés : la raccourcir peut libérer
        immédiatement l'argent de commandes en cours de vérification par leurs clients.
    </div>
</div>
@endsection

@push('scripts')
<script type="module">
document.addEventListener('alpine:init', () => Alpine.data('ecranReglages', () => ({
    liste: [], erreur: null,
    init() { if (window.exigerConnexion('admin')) this.charger(); },
    async charger() {
        try { const r = await window.api.get('/admin/parametres'); this.liste = (r.data ?? []).map(p => ({ ...p, nouvelle: p.valeur, motif: '' })); }
        catch (e) { this.erreur = e.message; }
    },
    async enregistrer(p) {
        try { const r = await window.api.put(`/admin/parametres/${p.cle}`, { valeur: p.nouvelle, motif: p.motif }); alert(r.message); this.charger(); }
        catch (e) { alert(e.message); }
    },
})));
</script>
@endpush
