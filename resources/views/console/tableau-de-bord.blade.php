@extends('layouts.app')

@section('titre', 'Console')

@section('navigation')
    @include('console._navigation')
@endsection

@section('contenu')
<div x-data="consoleAccueil" x-cloak>

    <h1 class="mb-1 text-2xl font-extrabold">Console Afrishop</h1>
    <p class="mb-5 text-sm text-gris">Ce qui attend une décision.</p>

    <template x-if="erreur">
        <div class="carte border-alerte/40 bg-red-50 p-4 text-sm text-alerte" x-text="erreur"></div>
    </template>

    <div x-show="chargement" class="py-12 text-center text-sm text-gris">Chargement…</div>

    <template x-if="d">
        <div>
            {{--
              LE CHIFFRE MIS EN AVANT EST L'ÉCART DE CANTONNEMENT.
              Pas le GMV. Un écart entre le solde en banque et le solde
              du grand livre veut dire que l'argent des vendeurs et
              celui de la plateforme se sont mélangés quelque part. Non
              expliqué, c'est le seul indicateur qui puisse mettre
              Afrishop en défaut face à un contrôle — donc c'est lui qui
              ouvre l'écran, tous les jours.
            --}}
            <div class="carte mb-5 p-5"
                 :class="ecartAnormal && 'border-alerte'">
                <p class="tuile-libelle">Cantonnement — écart du dernier arrêté</p>

                <template x-if="d.cantonnement">
                    <div>
                        <p class="chiffre-cle mt-2" :class="ecartAnormal && 'text-alerte'"
                           x-text="fcfa(d.cantonnement.ecart_cfa)"></p>
                        <p class="tuile-note">
                            Arrêté du <span x-text="dateFr(d.cantonnement.date_arrete)"></span> —
                            banque <span class="montant" x-text="fcfa(d.cantonnement.solde_banque_cfa)"></span>,
                            grand livre <span class="montant" x-text="fcfa(d.cantonnement.solde_grand_livre_cfa)"></span>.
                        </p>
                        <p class="tuile-note text-alerte" x-show="ecartAnormal">
                            Écart à expliquer. Tant qu'il ne l'est pas, on ne peut pas affirmer
                            que les fonds des boutiques sont intacts.
                        </p>
                        <p class="tuile-note" x-show="d.cantonnement.commentaire"
                           x-text="d.cantonnement.commentaire"></p>
                    </div>
                </template>

                {{-- Aucun arrêté : ce n'est pas « rien à signaler », c'est
                     « personne ne vérifie ». La distinction compte. --}}
                <template x-if="!d.cantonnement">
                    <div>
                        <p class="chiffre-cle mt-2 text-gris">—</p>
                        <p class="tuile-note text-attente">
                            Aucun arrêté enregistré. La commande
                            <code>php artisan afrishop:arreter-cantonnement</code> n'a jamais été lancée :
                            personne ne vérifie aujourd'hui que les fonds des boutiques sont intacts.
                        </p>
                    </div>
                </template>
            </div>

            <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <a href="/console/candidatures" class="tuile hover:border-brun-clair">
                    <p class="tuile-libelle">Candidatures</p>
                    <p class="tuile-valeur" x-text="d.a_traiter.candidatures"></p>
                    {{-- L'ancienneté du plus vieux dossier, pas la date.
                         Dix candidatures d'hier ne posent pas de problème ;
                         une qui attend depuis trois semaines, si. --}}
                    <p class="tuile-note" :class="d.plus_ancien_jours.candidature > 7 && 'text-alerte'"
                       x-show="d.plus_ancien_jours.candidature !== null">
                        La plus ancienne attend depuis
                        <span x-text="d.plus_ancien_jours.candidature"></span> jour(s).
                    </p>
                </a>

                <a href="/console/litiges" class="tuile hover:border-brun-clair"
                   :class="d.a_traiter.litiges > 0 && 'border-alerte/50'">
                    <p class="tuile-libelle">Litiges ouverts</p>
                    <p class="tuile-valeur" :class="d.a_traiter.litiges > 0 && 'text-alerte'"
                       x-text="d.a_traiter.litiges"></p>
                    <p class="tuile-note" x-show="d.plus_ancien_jours.litige !== null">
                        Le plus ancien : <span x-text="d.plus_ancien_jours.litige"></span> jour(s).
                        Les fonds restent gelés des deux côtés.
                    </p>
                </a>

                {{-- Cette tuile était un simple compteur : elle annonçait
                     du travail sans donner l'écran pour le faire. --}}
                <a href="/console/produits" class="tuile hover:border-brun-clair"
                   :class="d.a_traiter.produits_a_moderer > 0 && 'border-attente/50'">
                    <p class="tuile-libelle">Produits à modérer</p>
                    <p class="tuile-valeur" x-text="d.a_traiter.produits_a_moderer"></p>
                    <p class="tuile-note">Invisibles en vitrine tant qu'ils ne sont pas validés.</p>
                </a>

                <a href="/console/reversements" class="tuile hover:border-brun-clair">
                    <p class="tuile-libelle">Virements à exécuter</p>
                    <p class="tuile-valeur" x-text="d.a_traiter.reversements_a_payer"></p>
                    <p class="tuile-note text-alerte" x-show="d.a_traiter.reversements_echoues > 0">
                        <span x-text="d.a_traiter.reversements_echoues"></span> en échec — vendeurs non payés.
                    </p>
                </a>
            </div>

            {{-- L'argent, présenté comme une DETTE et non comme un
                 revenu. Ce qui dort chez le prestataire appartient aux
                 boutiques : le lire comme une trésorerie conduit à
                 dépenser ce qu'on ne possède pas. --}}
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="tuile">
                    <p class="tuile-libelle">Dû aux boutiques</p>
                    <p class="tuile-valeur" x-text="fcfa(d.argent_cfa.du_aux_boutiques)"></p>
                    <p class="tuile-note">Dette, pas trésorerie.</p>
                </div>
                <div class="tuile">
                    <p class="tuile-libelle">En séquestre</p>
                    <p class="tuile-valeur" x-text="fcfa(d.argent_cfa.en_sequestre)"></p>
                    <p class="tuile-note">Encaissé, pas encore libérable.</p>
                </div>
                <div class="tuile">
                    <p class="tuile-libelle">À virer</p>
                    <p class="tuile-valeur" x-text="fcfa(d.argent_cfa.a_virer)"></p>
                    <p class="tuile-note">Reversements préparés, en attente d'exécution.</p>
                </div>
            </div>
        </div>
    </template>
</div>
@endsection

@push('scripts')
<script type="module">
    document.addEventListener('alpine:init', () => {
        Alpine.data('consoleAccueil', () => ({
            d: null, chargement: true, erreur: null,

            async init() {
                if (!window.exigerConnexion()) return;
                try { this.d = await window.api.get('/admin/tableau-de-bord'); }
                catch (e) { this.erreur = e.message; }
                finally { this.chargement = false; }
            },

            get ecartAnormal() {
                return this.d?.cantonnement && this.d.cantonnement.statut !== 'conforme';
            },
        }));
    });
</script>
@endpush
