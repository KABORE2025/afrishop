@extends('layouts.app')

@section('titre', 'Personnel')

@section('navigation')
    @include('console._navigation')
@endsection

@section('contenu')
<div x-data="ecranAdministrateurs" x-cloak>

    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="mb-1 text-2xl font-extrabold">Personnel Afrishop</h1>
            <p class="text-sm text-gris">
                Un <b>administrateur</b> a les mêmes droits que vous. Un <b>agent</b> aide les clients et les
                vendeurs (commandes, codes, ouverture de litige) mais ne décide ni de l'argent ni des accès.
            </p>
        </div>
        <button class="btn-primaire" @click="ouvrir">Créer un compte</button>
    </div>

    <template x-if="erreur">
        <div class="carte mb-4 border-alerte/40 bg-red-50 p-4 text-sm text-alerte" x-text="erreur"></div>
    </template>

    <div x-show="chargement" class="py-12 text-center text-sm text-gris">Chargement…</div>

    <div class="carte overflow-x-auto" x-show="!chargement && comptes.length">
        <table class="tableau">
            <thead><tr><th>Nom</th><th>Téléphone</th><th>Rôle</th><th>Créé le</th><th>Dernière connexion</th></tr></thead>
            <tbody>
                <template x-for="c in comptes" :key="c.id">
                    <tr>
                        <td class="font-semibold" x-text="c.nom"></td>
                        <td x-text="c.telephone"></td>
                        <td x-text="c.role === 'admin' ? 'Administrateur' : 'Agent (support)'"></td>
                        <td class="text-xs" x-text="dateFr(c.cree_le)"></td>
                        <td class="text-xs" x-text="c.derniere_connexion ? dateFr(c.derniere_connexion) : 'Jamais'"></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    {{-- ------------------------------------------------------------
         CRÉATION
         ------------------------------------------------------------ --}}
    <div x-show="form.ouvert" class="fixed inset-0 z-20 overflow-y-auto bg-black/40 p-4" x-cloak>
        <div class="carte mx-auto my-6 w-full max-w-md p-5">

            <template x-if="!form.resultat">
                <form @submit.prevent="creer">
                    <h2 class="mb-2 text-lg font-bold">Créer un compte du personnel</h2>

                    <div class="mb-3 grid gap-2 sm:grid-cols-2">
                        <button type="button" class="btn-secondaire" :class="form.role === 'agent' && 'border-brun text-brun'"
                                @click="form.role = 'agent'">Agent (support)</button>
                        <button type="button" class="btn-secondaire" :class="form.role === 'admin' && 'border-brun text-brun'"
                                @click="form.role = 'admin'">Administrateur</button>
                    </div>

                    <div class="note mb-3" x-show="form.role === 'admin'">
                        Ce compte aura <b>exactement vos droits</b>, y compris celui de trancher les litiges
                        (donc de déplacer de l'argent) et de créer d'autres comptes du personnel.
                    </div>
                    <div class="note mb-3" x-show="form.role === 'agent'">
                        L'agent peut chercher une commande, renvoyer ou consulter un code, ouvrir un litige pour
                        un client, lire les candidatures et les produits. Il <b>ne peut pas</b> trancher un litige,
                        rembourser, accepter une boutique, modérer, ni créer de compte.
                    </div>
                    <p class="tuile-note mb-3">Le téléphone ne doit appartenir à <b>aucun compte</b> existant.</p>

                    <label class="libelle" for="a-nom">Nom complet</label>
                    <input id="a-nom" class="champ mb-3" x-model="form.nom" required minlength="2">

                    <label class="libelle" for="a-tel">Téléphone (identifiant de connexion)</label>
                    <input id="a-tel" class="champ mb-3" x-model="form.telephone" required inputmode="tel" placeholder="22670001122">

                    <label class="mb-3 flex items-start gap-2 text-sm">
                        <input type="checkbox" x-model="form.confirme" class="mt-1">
                        <span x-text="form.role === 'admin'
                            ? 'Je confirme que cette personne doit avoir accès à toute la console Afrishop.'
                            : 'Je confirme que cette personne fait partie du support Afrishop.'"></span>
                    </label>

                    <div class="note mb-3" x-show="form.message" x-text="form.message"></div>

                    <div class="flex justify-end gap-2">
                        <button type="button" class="btn-secondaire" @click="form.ouvert = false">Annuler</button>
                        <button class="btn-primaire" :disabled="form.enCours || !form.confirme">
                            <span x-show="!form.enCours">Créer le compte</span>
                            <span x-show="form.enCours">Création…</span>
                        </button>
                    </div>
                </form>
            </template>

            {{-- Mot de passe temporaire : affiché UNE fois, haché en base ensuite. --}}
            <template x-if="form.resultat">
                <div>
                    <h2 class="mb-2 text-lg font-bold" x-text="form.resultat.message"></h2>
                    <div class="carte border-brun-clair p-4">
                        <p class="tuile-libelle">À transmettre de vive voix — affiché une seule fois</p>
                        <p class="mt-1 text-sm">Téléphone : <b class="montant" x-text="form.resultat.compte.telephone"></b></p>
                        <p class="text-sm">Mot de passe : <b class="montant text-lg" x-text="form.resultat.mot_de_passe_temporaire"></b></p>
                        <p class="tuile-note text-alerte">Ne l'envoyez pas par SMS ni par messagerie : il ouvre toute la console.</p>
                    </div>
                    <div class="mt-4 flex justify-end">
                        <button class="btn-primaire" @click="fermer">J'ai noté, fermer</button>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script type="module">
    document.addEventListener('alpine:init', () => {
        Alpine.data('ecranAdministrateurs', () => ({
            comptes: [], chargement: true, erreur: null,
            form: { ouvert: false, role: 'agent', nom: '', telephone: '', confirme: false, enCours: false, message: null, resultat: null },

            init() {
                if (!window.exigerConnexion('admin')) return;
                this.charger();
            },

            async charger() {
                this.chargement = true; this.erreur = null;
                try { const r = await window.api.get('/admin/administrateurs'); this.comptes = r.data ?? []; }
                catch (e) { this.erreur = e.message; this.comptes = []; }
                finally { this.chargement = false; }
            },

            ouvrir() {
                this.form = { ouvert: true, role: 'agent', nom: '', telephone: '', confirme: false, enCours: false, message: null, resultat: null };
            },

            async creer() {
                if (this.form.enCours) return;   // un double-clic créerait deux comptes
                this.form.enCours = true; this.form.message = null;
                try {
                    this.form.resultat = await window.api.post('/admin/administrateurs', {
                        nom: this.form.nom, telephone: this.form.telephone, role: this.form.role,
                    });
                } catch (e) { this.form.message = e.message; }
                finally { this.form.enCours = false; }
            },

            fermer() {
                this.form.ouvert = false; this.form.resultat = null;
                this.charger();
            },
        }));
    });
</script>
@endpush
