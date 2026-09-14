@extends('layouts.app')

@section('titre', 'Mes livraisons')

@section('contenu')
<div x-data="ecranLivreur" x-cloak>
    <h1 class="mb-1 text-2xl font-extrabold">Mes livraisons</h1>
    <p class="mb-5 text-sm text-gris">Demandez le code seulement après vérification et remise du colis.</p>
    <div class="note mb-4" x-show="erreur" x-text="erreur"></div>
    <div class="carte overflow-x-auto" x-show="!chargement && commandes.length">
        <table class="tableau"><thead><tr><th>Référence</th><th>Client / adresse</th><th>À encaisser</th><th></th></tr></thead>
        <tbody><template x-for="commande in commandes" :key="commande.id"><tr>
            <td x-text="commande.reference"></td>
            <td><b x-text="commande.client.nom"></b><span class="block text-xs text-gris" x-text="commande.client.telephone"></span><span class="block text-xs text-gris" x-text="commande.client.quartier + (commande.client.repere ? ' — ' + commande.client.repere : '')"></span></td>
            <td x-text="commande.montant_a_encaisser_cfa === null ? 'Déjà payé' : fcfa(commande.montant_a_encaisser_cfa)"></td>
            <td><button class="btn-primaire" @click="ouvrir(commande)">Confirmer la remise</button></td>
        </tr></template></tbody></table>
    </div>
    <p x-show="!chargement && !commandes.length" class="carte p-8 text-center text-sm text-gris">Aucune livraison à confirmer.</p>
    <div x-show="livraison" class="fixed inset-0 z-20 grid place-items-center bg-black/40 p-4" x-cloak><div class="carte w-full max-w-md p-5">
        <h2 class="mb-1 text-lg font-bold">Confirmer la remise</h2><p class="mb-4 text-sm text-gris" x-text="livraison?.reference"></p>
        <div class="note mb-3">Le client vérifie le colis puis vous dicte son code SMS. Ne lui demandez pas ce code avant la remise.</div>
        <label class="libelle" for="code">Code du client</label><input id="code" inputmode="numeric" maxlength="6" class="champ montant text-center text-xl tracking-[0.4em]" x-model="code" placeholder="••••••">
        <div class="mt-3" x-show="livraison?.montant_a_encaisser_cfa !== null"><label class="libelle" for="montant">Montant encaissé (FCFA)</label><input id="montant" type="number" min="0" class="champ" x-model="montant"></div>
        <p class="mt-3 text-sm text-alerte" x-show="message" x-text="message"></p>
        <div class="mt-4 flex justify-end gap-2"><button class="btn-secondaire" @click="livraison = null">Annuler</button><button class="btn-primaire" :disabled="enCours" @click="valider">Valider</button></div>
    </div></div>
</div>
@endsection

@push('scripts')
<script type="module">
document.addEventListener('alpine:init', () => Alpine.data('ecranLivreur', () => ({
    commandes: [], chargement: true, erreur: null, livraison: null, code: '', montant: '', message: null, enCours: false,
    async init() { if (!window.exigerConnexion('livreur')) return; await this.charger(); },
    async charger() { this.chargement = true; try { const r = await window.api.get('/livreur/commandes'); this.commandes = r.data ?? []; } catch (e) { this.erreur = e.message; } finally { this.chargement = false; } },
    ouvrir(commande) { this.livraison = commande; this.code = ''; this.montant = commande.montant_a_encaisser_cfa ?? ''; this.message = null; },
    async valider() { this.enCours = true; this.message = null; try { const corps = { code_livraison: this.code }; if (this.livraison.montant_a_encaisser_cfa !== null) corps.montant_percu_cfa = Number(this.montant); await window.api.post(`/livreur/commandes/${this.livraison.id}/livrer`, corps); this.livraison = null; await this.charger(); } catch (e) { this.message = e.message; } finally { this.enCours = false; } },
})));
</script>
@endpush
