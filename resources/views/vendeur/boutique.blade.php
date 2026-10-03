@extends('layouts.app')

@section('titre', 'Ma boutique')

@section('navigation')
    @include('vendeur._navigation')
@endsection

@section('contenu')
<div x-data="ecranMaBoutique" x-cloak>
    <h1 class="mb-1 text-2xl font-extrabold">Ma boutique</h1>

    <template x-if="erreur"><div class="carte mb-4 border-alerte/40 bg-red-50 p-4 text-sm text-alerte" x-text="erreur"></div></template>
    <div x-show="chargement" class="py-12 text-center text-sm text-gris">Chargement…</div>

    <template x-if="b">
        <div class="space-y-4">
            {{-- ÉTAT : la première chose à savoir, c'est si la boutique vend. --}}
            <div class="carte p-4" :class="b.peut_vendre ? 'border-succes/40' : 'border-alerte/40 bg-red-50'">
                <p class="font-bold" x-text="b.peut_vendre ? 'Votre boutique est en vente sur Afrishop.' : 'Votre boutique ne vend pas actuellement.'"></p>
                <p class="mt-1 text-sm" x-show="!b.peut_vendre" x-text="b.raison_indisponibilite"></p>
                <p class="mt-1 text-sm text-gris" x-show="!b.peut_vendre && !p.verifie_le">
                    Il faut un compte de paiement <b>vérifié par Afrishop</b> : renseignez-le ci-dessous, puis Afrishop vous
                    fait un petit virement de test et vous appelle pour confirmer.
                </p>
            </div>

            {{-- COMPTE DE PAIEMENT --}}
            <form class="carte space-y-3 p-4" @submit.prevent="enregistrerPaiement">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-bold">Compte où recevoir vos ventes</h2>
                    <span class="etat" :class="p.verifie_le ? 'etat-reverse' : (p.numero ? 'etat-sequestre' : 'etat-impaye')"
                          x-text="p.verifie_le ? 'Vérifié' : (p.numero ? 'En attente de vérification' : 'Non renseigné')"></span>
                </div>
                <div class="note">
                    Changer ce compte <b>suspend vos ventes et vos versements</b> jusqu'à ce qu'Afrishop vérifie le nouveau
                    numéro. C'est ce qui empêche quelqu'un qui volerait votre téléphone de détourner votre argent.
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label class="libelle" for="op">Opérateur</label>
                        <select id="op" class="champ" x-model="fp.operateur_paiement_id" required>
                            <option value="">— Choisir —</option>
                            <template x-for="o in operateurs" :key="o.id"><option :value="o.id" x-text="o.nom"></option></template>
                        </select>
                    </div>
                    <div>
                        <label class="libelle" for="num">Numéro Mobile Money</label>
                        <input id="num" class="champ" inputmode="tel" x-model="fp.numero" required>
                    </div>
                    <div>
                        <label class="libelle" for="tit">Nom du titulaire</label>
                        <input id="tit" class="champ" x-model="fp.titulaire" required>
                    </div>
                </div>
                <p class="tuile-note">Identification du gérant : <b x-text="libelleKyc(kyc)"></b>.
                    <span x-show="kyc === 'aucun'">Sans identification, vos versements sont plafonnés à 200 000 F par mois : Afrishop vous demandera votre pièce d'identité.</span>
                </p>
                <div class="note" x-show="msgPaiement" x-text="msgPaiement"></div>
                <button class="btn-primaire" :disabled="enCours">Enregistrer le compte de paiement</button>
            </form>

            {{-- FICHE --}}
            <form class="carte space-y-3 p-4" @submit.prevent="enregistrerFiche">
                <h2 class="font-bold">Fiche de la boutique</h2>
                <p class="text-sm text-gris">
                    <b x-text="b.nom"></b> (<span x-text="b.code"></span>) — pour changer le nom, appelez le service client
                    <a href="{{ telephone_support(true) }}" class="font-semibold text-brun">{{ telephone_support() }}</a>.
                </p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="libelle" for="tel">Téléphone de la boutique</label>
                        <input id="tel" class="champ" inputmode="tel" x-model="ff.telephone" required>
                    </div>
                    <div>
                        <label class="libelle" for="ville">Ville</label>
                        <select id="ville" class="champ" x-model="ff.ville_id" required>
                            <option value="">— Choisir —</option>
                            <template x-for="v in villes" :key="v.id"><option :value="v.id" x-text="v.nom"></option></template>
                        </select>
                        <p class="tuile-note">Vos livreurs doivent être dans cette ville.</p>
                    </div>
                </div>
                <div>
                    <label class="libelle" for="desc">Présentation</label>
                    <textarea id="desc" class="champ" rows="4" x-model="ff.description" maxlength="2000"></textarea>
                </div>

                <details class="rounded-lg bg-fond p-3" :open="!!ff.fermee_du">
                    <summary class="cursor-pointer text-sm font-semibold">Fermeture temporaire (congés, rupture…)</summary>
                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        <div><label class="libelle" for="du">Fermée du</label><input id="du" type="date" class="champ" x-model="ff.fermee_du"></div>
                        <div><label class="libelle" for="au">au (inclus)</label><input id="au" type="date" class="champ" x-model="ff.fermee_au"></div>
                        <div><label class="libelle" for="msg">Message aux clients</label><input id="msg" class="champ" x-model="ff.message_fermeture" maxlength="255"></div>
                    </div>
                    <p class="tuile-note">Pendant la fermeture, vos produits ne sont plus proposés. Videz la date pour rouvrir.</p>
                </details>

                <div class="note" x-show="msgFiche" x-text="msgFiche"></div>
                <button class="btn-primaire" :disabled="enCours">Enregistrer la fiche</button>
            </form>

            {{-- LOGO --}}
            <div class="carte flex flex-wrap items-center gap-4 p-4">
                <div class="grid h-20 w-20 place-items-center overflow-hidden rounded-lg border border-bord bg-fond text-xs text-gris">
                    <img x-show="b.logo_url" :src="b.logo_url" alt="Logo" class="h-full w-full object-cover">
                    <span x-show="!b.logo_url">Pas de logo</span>
                </div>
                <div>
                    <label class="libelle" for="logo">Logo (JPG, PNG, 4 Mo au plus)</label>
                    <input id="logo" type="file" accept="image/*" @change="envoyerLogo($event)">
                    <p class="tuile-note" x-show="msgLogo" x-text="msgLogo"></p>
                </div>
            </div>
        </div>
    </template>
</div>
@endsection

@push('scripts')
<script type="module">
document.addEventListener('alpine:init', () => Alpine.data('ecranMaBoutique', () => ({
    b: null, p: {}, kyc: 'aucun', villes: [], operateurs: [], chargement: true, erreur: null, enCours: false,
    fp: {}, ff: {}, msgPaiement: null, msgFiche: null, msgLogo: null,
    init() { if (window.exigerConnexion('vendeur')) this.charger(); },
    async charger() {
        this.chargement = true;
        try {
            const r = await window.api.get('/vendeur/boutique');
            this.b = r.boutique; this.p = r.paiement; this.kyc = r.identification;
            this.villes = r.villes; this.operateurs = r.operateurs;
            this.fp = { operateur_paiement_id: r.paiement.operateur_paiement_id ?? '', numero: r.paiement.numero ?? '', titulaire: r.paiement.titulaire ?? '' };
            this.ff = { telephone: r.boutique.telephone, ville_id: r.boutique.ville_id ?? '', description: r.boutique.description ?? '',
                        fermee_du: r.boutique.fermee_du ?? '', fermee_au: r.boutique.fermee_au ?? '', message_fermeture: r.boutique.message_fermeture ?? '' };
        } catch (e) { this.erreur = e.message; }
        finally { this.chargement = false; }
    },
    libelleKyc(k) { return { aucun: 'non identifié', simplifie: 'identification simplifiée', complet: 'identification complète' }[k] ?? k; },
    async enregistrerPaiement() {
        if (this.p.verifie_le && !confirm('Changer de compte suspendra vos ventes jusqu’à la vérification du nouveau. Continuer ?')) return;
        this.enCours = true; this.msgPaiement = null;
        try { const r = await window.api.put('/vendeur/boutique/paiement', { ...this.fp, operateur_paiement_id: Number(this.fp.operateur_paiement_id) }); await this.charger(); this.msgPaiement = r.message; }
        catch (e) { this.msgPaiement = e.message; }
        finally { this.enCours = false; }
    },
    async enregistrerFiche() {
        this.enCours = true; this.msgFiche = null;
        try {
            const d = { ...this.ff, ville_id: Number(this.ff.ville_id) };
            for (const k of ['fermee_du', 'fermee_au', 'message_fermeture']) if (!d[k]) d[k] = null;
            const r = await window.api.put('/vendeur/boutique', d); await this.charger(); this.msgFiche = r.message;
        } catch (e) { this.msgFiche = e.message; }
        finally { this.enCours = false; }
    },
    async envoyerLogo(ev) {
        const f = ev.target.files?.[0]; if (!f) return;
        const fd = new FormData(); fd.append('logo', f);
        this.msgLogo = 'Envoi…';
        try { const r = await window.api.fichier('/vendeur/boutique/logo', fd); this.b.logo_url = r.logo_url; this.msgLogo = r.message; }
        catch (e) { this.msgLogo = e.message; }
    },
})));
</script>
@endpush
