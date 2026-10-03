@extends('layouts.app')

@section('titre', 'Étiquettes QR')

@section('navigation')
    @include('console._navigation')
@endsection

@section('contenu')
<div x-data="ecranEtiquettes" x-cloak>
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="mb-1 text-2xl font-extrabold">Étiquettes QR anti-contrefaçon</h1>
            <p class="text-sm text-gris">Les demandes des boutiques sont en tête. Seule la console génère des étiquettes.</p>
        </div>
        <button class="btn-primaire" @click="nouveau = !nouveau">Créer un lot</button>
    </div>

    <template x-if="erreur"><div class="carte mb-4 border-alerte/40 bg-red-50 p-4 text-sm text-alerte" x-text="erreur"></div></template>

    <form class="carte mb-4 space-y-3 p-4" x-show="nouveau" @submit.prevent="creer">
        <div class="grid gap-3 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="libelle" for="p">Produit traçable</label>
                <select id="p" class="champ" x-model="f.produit_id" required>
                    <option value="">— Choisir —</option>
                    <template x-for="p in produits" :key="p.id"><option :value="p.id" x-text="p.nom + ' — ' + (p.boutique ?? '')"></option></template>
                </select>
            </div>
            <div><label class="libelle" for="df">Date de fabrication</label><input id="df" type="date" class="champ" x-model="f.date_fabrication" required></div>
            <div><label class="libelle" for="de">Date d'expiration</label><input id="de" type="date" class="champ" x-model="f.date_expiration" required></div>
            <div><label class="libelle" for="fa">Fabricant</label><input id="fa" class="champ" x-model="f.fabricant" required></div>
            <div><label class="libelle" for="q">Nombre d'étiquettes</label><input id="q" type="number" min="1" class="champ" x-model="f.quantite" required></div>
        </div>
        <div class="note" x-show="msg" x-text="msg"></div>
        <button class="btn-primaire" :disabled="enCours">Générer les étiquettes</button>
    </form>

    <div class="space-y-3">
        <template x-for="l in lots" :key="l.id">
            <div class="carte p-4" :class="l.statut === 'demande' && 'border-brun-clair'">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="font-bold"><span x-text="l.reference"></span> — <span x-text="l.produit"></span></p>
                        <p class="text-xs text-gris" x-text="l.boutique + ' — ' + l.quantite + ' étiquettes — ' + l.fabricant + ' — ' + dateFr(l.date_fabrication) + ' → ' + dateFr(l.date_expiration)"></p>
                    </div>
                    <span class="etat" :class="{'etat-impaye': ['refuse','rappele'].includes(l.statut), 'etat-reverse': ['imprime','en_circulation'].includes(l.statut), 'etat-sequestre': ['demande','genere'].includes(l.statut)}" x-text="l.statut_libelle"></span>
                </div>
                <p class="tuile-note" x-show="!['demande','refuse'].includes(l.statut)" x-text="l.scannes + ' étiquette(s) déjà scannée(s) par des clients'"></p>
                <p class="tuile-note text-alerte" x-show="l.motif_refus" x-text="'Refus : ' + l.motif_refus"></p>

                <div class="mt-3 flex flex-wrap gap-2">
                    <button class="btn-primaire" x-show="l.statut === 'demande'" @click="agir(l, 'generer')">Accepter et générer</button>
                    <button class="btn-secondaire" x-show="l.statut === 'demande'" @click="agir(l, 'refuser')">Refuser</button>
                    <button class="btn-primaire" x-show="['genere','imprime','en_circulation'].includes(l.statut)" @click="imprimer(l)">Imprimer la planche</button>
                    <button class="btn-secondaire" x-show="['genere','imprime','en_circulation'].includes(l.statut)" @click="stats(l)">Statistiques</button>
                    <button class="btn-danger" x-show="['genere','imprime','en_circulation'].includes(l.statut)" @click="agir(l, 'rappel')">Rappeler le lot</button>
                </div>

                <div class="note mt-3" x-show="detail[l.id]">
                    <template x-if="detail[l.id]">
                        <div>
                            <p><b x-text="detail[l.id].en_circulation"></b> en circulation, <b x-text="detail[l.id].jamais_scannes"></b> jamais scannées.
                               Expire dans <span x-text="detail[l.id].expire_dans"></span>.</p>
                            <p x-show="detail[l.id].suspects?.length" class="text-alerte">
                                Étiquettes suspectes (scannées trop souvent, possible copie) :
                                <span x-text="(detail[l.id].suspects ?? []).map(s => s.code_lisible + ' (' + s.nb_scans + ')').join(', ')"></span>
                            </p>
                        </div>
                    </template>
                </div>
            </div>
        </template>
    </div>

    <div class="mt-3 flex items-center justify-end gap-2 text-sm" x-show="meta.dernier > 1">
        <button class="btn-secondaire" :disabled="meta.page <= 1" @click="charger(meta.page - 1)">←</button>
        <span x-text="meta.page + ' / ' + meta.dernier"></span>
        <button class="btn-secondaire" :disabled="meta.page >= meta.dernier" @click="charger(meta.page + 1)">→</button>
    </div>
</div>
@endsection

@push('scripts')
<script type="module">
document.addEventListener('alpine:init', () => Alpine.data('ecranEtiquettes', () => ({
    lots: [], produits: [], meta: { page: 1, dernier: 1 }, erreur: null, nouveau: false, msg: null, enCours: false, detail: {},
    f: { produit_id: '', date_fabrication: '', date_expiration: '', fabricant: '', quantite: 100 },
    init() { if (window.exigerConnexion('admin')) this.charger(1); },
    async charger(page = 1) {
        try { const r = await window.api.get(`/admin/lots-qr?page=${page}`); this.lots = r.data ?? []; this.meta = r.meta; this.produits = r.produits_tracables ?? []; }
        catch (e) { this.erreur = e.message; }
    },
    async creer() {
        this.enCours = true; this.msg = null;
        try {
            const r = await window.api.post('/admin/lots-qr', { ...this.f, produit_id: Number(this.f.produit_id), quantite: Number(this.f.quantite) });
            this.nouveau = false; await this.charger(1);
            if (confirm(`Lot ${r.reference} créé (${r.premier_code} → ${r.dernier_code}). Ouvrir la planche à imprimer ?`)) window.open(r.url_planche, '_blank');
        } catch (e) { this.msg = e.message; }
        finally { this.enCours = false; }
    },
    async imprimer(l) {
        try { const r = await window.api.get(`/admin/lots-qr/${l.id}/lien-planche`); window.open(r.url, '_blank'); }
        catch (e) { alert(e.message); }
    },
    async stats(l) {
        try { this.detail = { ...this.detail, [l.id]: await window.api.get(`/admin/lots-qr/${l.id}/statistiques`) }; }
        catch (e) { alert(e.message); }
    },
    async agir(l, action) {
        let corps = {};
        if (action !== 'generer') {
            const motif = prompt(action === 'rappel'
                ? 'Motif du rappel (contrefaçon, défaut, péremption…) — tout scan affichera une alerte au client :'
                : 'Motif du refus — la boutique le lira :');
            if (!motif) return;
            corps = { motif };
        }
        try {
            const r = await window.api.post(`/admin/lots-qr/${l.id}/${action}`, corps);
            alert(r.message); await this.charger(this.meta.page);
            if (action === 'generer' && r.url_planche && confirm('Ouvrir la planche à imprimer ?')) window.open(r.url_planche, '_blank');
        } catch (e) { alert(e.message); }
    },
})));
</script>
@endpush
