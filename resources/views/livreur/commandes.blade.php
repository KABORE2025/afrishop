@extends('layouts.app')

@section('titre', 'Mes livraisons')

@section('contenu')
<div x-data="ecranLivreur" x-cloak>
    <h1 class="mb-1 text-2xl font-extrabold">Mes livraisons</h1>
    <p class="mb-5 text-sm text-gris">Demandez le code seulement après vérification et remise du colis.</p>

    {{-- ------------------------------------------------------------
         TABLEAU DE BORD — compteurs d'activité, aucun montant : le
         livreur n'est aujourd'hui rémunéré par personne pour aucune
         livraison, afficher un « gains » à zéro serait trompeur.
         ------------------------------------------------------------ --}}
    <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4" x-show="bord">
        <div class="tuile">
            <p class="tuile-libelle">En cours</p>
            <p class="tuile-valeur" x-text="bord?.compteurs?.en_cours ?? 0"></p>
        </div>
        <div class="tuile">
            <p class="tuile-libelle">Livrées aujourd'hui</p>
            <p class="tuile-valeur" x-text="bord?.compteurs?.livrees_aujourdhui ?? 0"></p>
        </div>
        <div class="tuile">
            <p class="tuile-libelle">Livrées cette semaine</p>
            <p class="tuile-valeur" x-text="bord?.compteurs?.livrees_semaine ?? 0"></p>
        </div>
        <div class="tuile" :class="bord?.compteurs?.echecs_semaine > 0 && 'border-alerte/50'">
            <p class="tuile-libelle">Échecs cette semaine</p>
            <p class="tuile-valeur" x-text="bord?.compteurs?.echecs_semaine ?? 0"></p>
        </div>
    </div>

    {{-- Onglets : travail du jour / historique. --}}
    <div class="mb-4 flex gap-2">
        <button type="button" @click="changerOnglet('encours')"
                :class="onglet === 'encours' ? 'btn-primaire' : 'btn-secondaire'">À livrer</button>
        <button type="button" @click="changerOnglet('historique')"
                :class="onglet === 'historique' ? 'btn-primaire' : 'btn-secondaire'">Historique</button>
    </div>

    <div class="note mb-4" x-show="erreur" x-text="erreur"></div>

    <div class="carte overflow-x-auto" x-show="!chargement && commandes.length">
        <table class="tableau">
            <thead><tr>
                <th>Référence</th>
                <th>Client / adresse</th>
                <th x-show="onglet === 'historique'">Statut</th>
                <th></th>
            </tr></thead>
            <tbody>
                <template x-for="commande in commandes" :key="commande.id">
                    <tr>
                        <td>
                            <span x-text="commande.reference"></span>
                            <span class="block text-xs text-alerte" x-show="commande.echecs_signales > 0"
                                  x-text="commande.echecs_signales + ' échec(s) signalé(s)'"></span>
                        </td>
                        <td>
                            <b x-text="commande.client.nom"></b>
                            <span class="block text-xs text-gris" x-text="commande.client.telephone"></span>
                            <span class="block text-xs text-gris"
                                  x-text="commande.client.quartier + (commande.client.repere ? ' — ' + commande.client.repere : '')"></span>
                        </td>
                        <td x-show="onglet === 'historique'">
                            <span class="statut" x-text="libelleStatutExpedition(commande.expedition.statut)"></span>
                        </td>
                        <td class="text-right">
                            <template x-if="onglet === 'encours' && commande.expedition.statut === 'retour_expediteur'">
                                <span class="text-xs text-alerte">À rapporter à la boutique</span>
                            </template>
                            <template x-if="onglet === 'encours' && commande.expedition.statut !== 'retour_expediteur'">
                                <div class="flex justify-end gap-2">
                                    <button class="btn-secondaire" @click="ouvrirEchec(commande)">Échec</button>
                                    <button class="btn-primaire" @click="ouvrir(commande)">Confirmer la remise</button>
                                </div>
                            </template>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
    <p x-show="!chargement && !commandes.length" class="carte p-8 text-center text-sm text-gris">
        <span x-text="onglet === 'encours' ? 'Aucune livraison à confirmer.' : 'Aucune livraison dans votre historique.'"></span>
    </p>

    {{-- ------------------------------------------------------------
         CONFIRMER LA REMISE
         ------------------------------------------------------------ --}}
    <div x-show="livraison" class="fixed inset-0 z-20 grid place-items-center bg-black/40 p-4" x-cloak>
        <div class="carte w-full max-w-md p-5">
            <h2 class="mb-1 text-lg font-bold">Confirmer la remise</h2>
            <p class="mb-4 text-sm text-gris" x-text="livraison?.reference"></p>
            <div class="note mb-3">Le client vérifie le colis puis vous dicte son code SMS. Ne lui demandez pas ce code avant la remise.</div>
            <label class="libelle" for="code">Code du client</label>
            <input id="code" inputmode="numeric" maxlength="6" class="champ montant text-center text-xl tracking-[0.4em]" x-model="code" placeholder="••••••">
            <p class="mt-3 text-sm text-alerte" x-show="message" x-text="message"></p>
            <div class="mt-4 flex justify-end gap-2">
                <button class="btn-secondaire" @click="livraison = null">Annuler</button>
                <button class="btn-primaire" :disabled="enCours" @click="valider">Valider</button>
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------------
         SIGNALER UN ÉCHEC
         ------------------------------------------------------------ --}}
    <div x-show="echec" class="fixed inset-0 z-20 grid place-items-center bg-black/40 p-4" x-cloak>
        <div class="carte w-full max-w-md p-5">
            <h2 class="mb-1 text-lg font-bold">Signaler un échec</h2>
            <p class="mb-4 text-sm text-gris" x-text="echec?.reference"></p>

            <label class="libelle" for="motif">Raison</label>
            <select id="motif" class="champ mb-3" x-model="motifEchec">
                <option value="absent">Client absent</option>
                <option value="adresse_introuvable">Adresse introuvable</option>
                <option value="colis_refuse">Colis refusé</option>
                <option value="autre">Autre</option>
            </select>

            <label class="libelle" for="commentaire">Commentaire (facultatif)</label>
            <textarea id="commentaire" class="champ" rows="2" maxlength="500" x-model="commentaireEchec"></textarea>

            <div class="note mt-3">
                En dessous du plafond de tentatives, cette livraison reste affectée à vous : vous pourrez retenter.
                Au-delà, le colis est marqué retourné et le client automatiquement remboursé.
            </div>

            <p class="mt-3 text-sm text-alerte" x-show="messageEchec" x-text="messageEchec"></p>

            <div class="mt-4 flex justify-end gap-2">
                <button class="btn-secondaire" @click="echec = null">Annuler</button>
                <button class="btn-primaire" :disabled="enCoursEchec" @click="validerEchec">Signaler</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script type="module">
document.addEventListener('alpine:init', () => Alpine.data('ecranLivreur', () => ({
    commandes: [], bord: null, onglet: 'encours', chargement: true, erreur: null,
    livraison: null, code: '', message: null, enCours: false,
    echec: null, motifEchec: 'absent', commentaireEchec: '', messageEchec: null, enCoursEchec: false,

    async init() {
        if (!window.exigerConnexion('livreur')) return;
        await Promise.all([this.charger(), this.chargerBord()]);
    },

    async chargerBord() {
        try { this.bord = await window.api.get('/livreur/tableau-de-bord'); } catch { this.bord = null; }
    },

    async changerOnglet(onglet) {
        if (this.onglet === onglet) return;
        this.onglet = onglet;
        await this.charger();
    },

    async charger() {
        this.chargement = true;
        try {
            const params = this.onglet === 'historique'
                ? '?statut[]=livree&statut[]=retournee&statut[]=annulee'
                : '';
            const r = await window.api.get(`/livreur/commandes${params}`);
            this.commandes = r.data ?? [];
        } catch (e) {
            this.erreur = e.message;
        } finally {
            this.chargement = false;
        }
    },

    libelleStatutExpedition(s) {
        return { en_cours: 'En cours', livree: 'Livrée', echouee: 'Échec', retour_expediteur: 'Retournée' }[s] ?? s;
    },

    ouvrir(commande) { this.livraison = commande; this.code = ''; this.message = null; },

    async valider() {
        this.enCours = true; this.message = null;
        try {
            await window.api.post(`/livreur/commandes/${this.livraison.id}/livrer`, { code_livraison: this.code });
            this.livraison = null;
            await Promise.all([this.charger(), this.chargerBord()]);
        } catch (e) {
            this.message = e.message;
        } finally {
            this.enCours = false;
        }
    },

    ouvrirEchec(commande) {
        this.echec = commande; this.motifEchec = 'absent'; this.commentaireEchec = ''; this.messageEchec = null;
    },

    async validerEchec() {
        this.enCoursEchec = true; this.messageEchec = null;
        try {
            const r = await window.api.post(`/livreur/commandes/${this.echec.id}/echec`, {
                motif: this.motifEchec,
                commentaire: this.commentaireEchec || null,
            });
            this.echec = null;
            window.alert(r.message);
            await Promise.all([this.charger(), this.chargerBord()]);
        } catch (e) {
            this.messageEchec = e.message;
        } finally {
            this.enCoursEchec = false;
        }
    },
})));
</script>
@endpush
