@extends('layouts.app')

@section('titre', 'Étiquettes QR')

@section('navigation')
    @include('vendeur._navigation')
@endsection

@section('contenu')
<div x-data="ecranEtiquettesVendeur" x-cloak>
    <h1 class="mb-1 text-2xl font-extrabold">Étiquettes anti-contrefaçon</h1>
    <p class="mb-4 text-sm text-gris">
        Chaque étiquette porte un QR unique : le client le scanne et voit que le produit vient bien de chez vous.
        Vous <b>demandez</b> un lot ; Afrishop le génère et vous remet les étiquettes imprimées.
    </p>

    <template x-if="erreur"><div class="carte mb-4 border-alerte/40 bg-red-50 p-4 text-sm text-alerte" x-text="erreur"></div></template>

    <form class="carte mb-4 space-y-3 p-4" @submit.prevent="demander">
        <h2 class="font-bold">Demander des étiquettes</h2>
        <p class="note" x-show="!produits.length">
            Aucun produit « traçable ». Cochez « traçable » sur la fiche d'un produit (cosmétique, alimentaire, médicament…)
            dans l'écran Produits pour pouvoir demander des étiquettes.
        </p>
        <div class="grid gap-3 sm:grid-cols-2" x-show="produits.length">
            <div class="sm:col-span-2">
                <label class="libelle" for="prod">Produit</label>
                <select id="prod" class="champ" x-model="f.produit_id" required>
                    <option value="">— Choisir —</option>
                    <template x-for="p in produits" :key="p.id"><option :value="p.id" x-text="p.nom"></option></template>
                </select>
            </div>
            <div><label class="libelle" for="fab">Date de fabrication</label><input id="fab" type="date" class="champ" x-model="f.date_fabrication" required></div>
            <div><label class="libelle" for="exp">Date d'expiration</label><input id="exp" type="date" class="champ" x-model="f.date_expiration" required></div>
            <div><label class="libelle" for="fabr">Fabricant</label><input id="fabr" class="champ" x-model="f.fabricant" required maxlength="160"></div>
            <div><label class="libelle" for="qte">Nombre d'étiquettes</label><input id="qte" type="number" min="1" class="champ" x-model="f.quantite" required></div>
            <div class="sm:col-span-2"><label class="libelle" for="desc">Précisions (facultatif)</label><input id="desc" class="champ" x-model="f.description" maxlength="1000"></div>
        </div>
        <div class="note" x-show="message" x-text="message"></div>
        <button class="btn-primaire" x-show="produits.length" :disabled="enCours">Envoyer la demande</button>
    </form>

    <div class="carte overflow-x-auto" x-show="lots.length">
        <table class="tableau">
            <thead><tr><th>Lot</th><th>Produit</th><th>Étiquettes</th><th>Fabrication → expiration</th><th>État</th><th>Scannées</th></tr></thead>
            <tbody>
                <template x-for="l in lots" :key="l.id">
                    <tr>
                        <td class="font-semibold" x-text="l.reference"></td>
                        <td x-text="l.produit"></td>
                        <td x-text="l.quantite"></td>
                        <td class="text-xs" x-text="dateFr(l.date_fabrication) + ' → ' + dateFr(l.date_expiration)"></td>
                        <td>
                            <span class="etat" :class="{'etat-impaye': ['refuse','rappele'].includes(l.statut), 'etat-reverse': ['imprime','en_circulation'].includes(l.statut), 'etat-sequestre': ['demande','genere'].includes(l.statut)}" x-text="l.statut_libelle"></span>
                            <p class="tuile-note text-alerte" x-show="l.motif_refus" x-text="l.motif_refus"></p>
                        </td>
                        <td x-text="l.scannes"></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('scripts')
<script type="module">
document.addEventListener('alpine:init', () => Alpine.data('ecranEtiquettesVendeur', () => ({
    lots: [], produits: [], erreur: null, message: null, enCours: false,
    f: { produit_id: '', date_fabrication: '', date_expiration: '', fabricant: '', quantite: 100, description: '' },
    init() { if (window.exigerConnexion('vendeur')) this.charger(); },
    async charger() {
        try { const r = await window.api.get('/vendeur/lots-qr'); this.lots = r.data ?? []; this.produits = r.produits_tracables ?? []; }
        catch (e) { this.erreur = e.message; }
    },
    async demander() {
        this.enCours = true; this.message = null;
        try {
            const r = await window.api.post('/vendeur/lots-qr/demandes', { ...this.f, produit_id: Number(this.f.produit_id), quantite: Number(this.f.quantite) });
            this.message = r.message; await this.charger();
        } catch (e) { this.message = e.message; }
        finally { this.enCours = false; }
    },
})));
</script>
@endpush
