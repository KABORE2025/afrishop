@extends('layouts.app')

@section('titre', 'Boutiques')

@section('navigation')
    @include('console._navigation')
@endsection

@section('contenu')
<div x-data="ecranBoutiques" x-cloak>
    <h1 class="mb-1 text-2xl font-extrabold">Boutiques</h1>
    <p class="mb-4 text-sm text-gris">
        Une boutique ne vend et n'est payée qu'avec un compte de paiement <b>vérifié</b>. Les comptes à vérifier sont en tête.
    </p>

    <div class="carte mb-4 flex flex-wrap items-end gap-3 p-4">
        <div>
            <label class="libelle" for="f">Afficher</label>
            <select id="f" class="champ" x-model="filtre" @change="charger(1)">
                <option value="tous">Toutes</option>
                <option value="a_verifier">Compte de paiement à vérifier</option>
                <option value="suspendues">Suspendues</option>
            </select>
        </div>
        <form class="min-w-0 flex-1" @submit.prevent="charger(1)">
            <label class="libelle" for="q">Nom, code ou téléphone</label>
            <input id="q" class="champ w-full" x-model="q">
        </form>
    </div>

    <template x-if="erreur"><div class="carte mb-4 border-alerte/40 bg-red-50 p-4 text-sm text-alerte" x-text="erreur"></div></template>
    <div x-show="chargement" class="py-12 text-center text-sm text-gris">Chargement…</div>

    <div class="space-y-3">
        <template x-for="b in liste" :key="b.id">
            <div class="carte p-4">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="font-bold"><span x-text="b.nom"></span> <span class="text-xs text-gris" x-text="b.code + ' — ' + (b.ville ?? 'ville non renseignée')"></span></p>
                        <p class="text-xs text-gris" x-show="b.gerant" x-text="'Gérant : ' + b.gerant?.nom + ' — ' + b.gerant?.telephone"></p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span class="statut" x-text="b.statut"></span>
                        <span class="etat" :class="b.peut_vendre ? 'etat-reverse' : 'etat-impaye'" x-text="b.peut_vendre ? 'En vente' : 'Ne vend pas'"></span>
                    </div>
                </div>

                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg bg-fond p-3 text-sm">
                        <p class="tuile-libelle">Compte de paiement</p>
                        <template x-if="b.paiement.numero">
                            <p class="mt-1"><b x-text="b.paiement.operateur"></b> — <span class="montant" x-text="b.paiement.numero"></span><br>
                               Titulaire : <span x-text="b.paiement.titulaire"></span></p>
                        </template>
                        <p class="mt-1 text-gris" x-show="!b.paiement.numero">Pas encore renseigné par la boutique.</p>
                        <p class="tuile-note" x-show="b.paiement.verifie_le" x-text="'Vérifié le ' + dateFr(b.paiement.verifie_le)"></p>
                        <button class="btn-primaire mt-2" x-show="b.paiement.numero && !b.paiement.verifie_le" @click="ouvrir('paiement', b)">Vérifier ce compte</button>
                    </div>
                    <div class="rounded-lg bg-fond p-3 text-sm">
                        <p class="tuile-libelle">Identification du gérant (KYC)</p>
                        <p class="mt-1 font-semibold" x-text="libelleKyc(b.gerant?.kyc)"></p>
                        <p class="tuile-note" x-show="b.gerant?.kyc === 'aucun'">Versements plafonnés à 200 000 F par mois.</p>
                        <button class="btn-secondaire mt-2" @click="ouvrir('kyc', b)">Modifier</button>
                    </div>
                </div>

                <div class="mt-3 flex gap-2">
                    <button class="btn-danger" x-show="b.statut === 'actif'" @click="ouvrir('suspendre', b)">Suspendre</button>
                    <button class="btn-primaire" x-show="b.statut === 'suspendu'" @click="ouvrir('reactiver', b)">Réactiver</button>
                </div>
            </div>
        </template>
    </div>

    <div class="mt-3 flex items-center justify-end gap-2 text-sm" x-show="meta.dernier > 1">
        <button class="btn-secondaire" :disabled="meta.page <= 1" @click="charger(meta.page - 1)">←</button>
        <span x-text="meta.page + ' / ' + meta.dernier"></span>
        <button class="btn-secondaire" :disabled="meta.page >= meta.dernier" @click="charger(meta.page + 1)">→</button>
    </div>

    {{-- ACTION --}}
    <div x-show="act.type" class="fixed inset-0 z-20 overflow-y-auto bg-black/40 p-4">
        <div class="carte mx-auto my-6 w-full max-w-md p-5">
            <h2 class="mb-1 text-lg font-bold" x-text="{ paiement: 'Vérifier le compte de paiement', kyc: 'Identification du gérant', suspendre: 'Suspendre la boutique', reactiver: 'Réactiver la boutique' }[act.type]"></h2>
            <p class="mb-3 text-sm text-gris" x-text="act.b?.nom"></p>

            <template x-if="act.type === 'paiement'">
                <div>
                    <div class="note mb-3">
                        Envoyez un petit montant (ex. 100 F) au <b class="montant" x-text="act.b.paiement.numero"></b>
                        (<span x-text="act.b.paiement.operateur"></span>), puis appelez le gérant : il doit confirmer l'avoir reçu
                        <b>et</b> que le nom affiché par l'opérateur est bien « <span x-text="act.b.paiement.titulaire"></span> ».
                    </div>
                    <label class="flex items-start gap-2 text-sm"><input type="checkbox" x-model="act.confirmation" class="mt-1">
                        <span>Le titulaire a confirmé avoir reçu le virement de test.</span></label>
                </div>
            </template>

            <template x-if="act.type === 'kyc'">
                <div>
                    <label class="libelle" for="niv">Niveau</label>
                    <select id="niv" class="champ mb-3" x-model="act.niveau">
                        <option value="aucun">Non identifié (versements plafonnés)</option>
                        <option value="simplifie">Identification simplifiée (pièce d'identité vue)</option>
                        <option value="complet">Identification complète (pièce + justificatif d'activité)</option>
                    </select>
                </div>
            </template>

            <template x-if="act.type === 'suspendre'">
                <div class="note mb-3">Ses produits disparaissent de la vitrine tout de suite. Les commandes déjà passées suivent leur cours.</div>
            </template>

            <template x-if="act.type !== 'paiement'">
                <div>
                    <label class="libelle" for="mot">Motif — journalisé</label>
                    <textarea id="mot" class="champ" rows="3" x-model="act.motif"></textarea>
                    <p class="tuile-note">Au moins dix caractères.</p>
                </div>
            </template>

            <div class="note mt-3" x-show="act.message" x-text="act.message"></div>
            <div class="mt-3 flex justify-end gap-2">
                <button class="btn-secondaire" @click="act.type = null">Annuler</button>
                <button :class="act.type === 'suspendre' ? 'btn-danger' : 'btn-primaire'" :disabled="act.enCours" @click="executer">Confirmer</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script type="module">
document.addEventListener('alpine:init', () => Alpine.data('ecranBoutiques', () => ({
    liste: [], meta: { page: 1, dernier: 1 }, filtre: 'tous', q: '', chargement: true, erreur: null,
    act: { type: null },
    init() { if (window.exigerConnexion('admin')) this.charger(1); },
    async charger(page = 1) {
        this.chargement = true; this.erreur = null;
        try {
            const r = await window.api.get(`/admin/boutiques?page=${page}&filtre=${this.filtre}&q=${encodeURIComponent(this.q)}`);
            this.liste = r.data ?? []; this.meta = r.meta;
        } catch (e) { this.erreur = e.message; }
        finally { this.chargement = false; }
    },
    libelleKyc(k) { return { aucun: 'Non identifié', simplifie: 'Identification simplifiée', complet: 'Identification complète' }[k] ?? '—'; },
    ouvrir(type, b) { this.act = { type, b, motif: '', niveau: b.gerant?.kyc ?? 'aucun', confirmation: false, enCours: false, message: null }; },
    async executer() {
        if (this.act.enCours) return;
        this.act.enCours = true; this.act.message = null;
        const id = this.act.b.id;
        try {
            const r = this.act.type === 'paiement'
                ? await window.api.post(`/admin/boutiques/${id}/verifier-paiement`, { numero: this.act.b.paiement.numero, confirmation: this.act.confirmation })
                : this.act.type === 'kyc'
                    ? await window.api.post(`/admin/boutiques/${id}/identification`, { niveau: this.act.niveau, motif: this.act.motif })
                    : await window.api.post(`/admin/boutiques/${id}/${this.act.type}`, { motif: this.act.motif });
            this.act.type = null; alert(r.message); this.charger(this.meta.page);
        } catch (e) { this.act.message = e.message; }
        finally { this.act.enCours = false; }
    },
})));
</script>
@endpush
