@extends('layouts.app')

@section('titre', 'Tableau de bord')

@section('navigation')
    @include('vendeur._navigation')
@endsection

@section('contenu')
<div x-data="tableauDeBord" x-cloak>

    {{-- Candidature pas encore validée : un état normal du parcours,
         pas un refus. Surtout pas de rouge ni de « accès refusé ». --}}
    <template x-if="sansBoutique">
        <div class="note">
            <b>Votre candidature est en cours d'examen.</b>
            Votre compte existe, mais aucune boutique n'y est encore rattachée.
            Vous recevrez un SMS dès qu'elle sera validée.
        </div>
    </template>

    <template x-if="erreur && !sansBoutique">
        <div class="carte border-alerte/40 bg-red-50 p-4 text-sm text-alerte" x-text="erreur"></div>
    </template>

    <div x-show="chargement" class="py-12 text-center text-sm text-gris">Chargement…</div>

    <template x-if="d && !sansBoutique">
        <div>
            {{--
              LE CHIFFRE MIS EN AVANT — UN SEUL PAR ÉCRAN.
              C'est « à recevoir » et non le chiffre d'affaires, parce que
              c'est la question qu'un vendeur se pose réellement : est-ce
              que je vais être payé, et de combien. Le chiffre d'affaires
              flatte ; celui-ci sert.
            --}}
            <div class="carte mb-5 p-5">
                <p class="tuile-libelle">À recevoir au prochain virement</p>
                <p class="chiffre-cle mt-2" x-text="fcfa(d.montants_cfa.a_recevoir)"></p>
                <p class="tuile-note">
                    Fonds libérés du séquestre, en attente du virement hebdomadaire.
                </p>
            </div>

            {{-- Les trois autres montants. Volontairement SÉPARÉS et
                 jamais additionnés : un vendeur qui les confond se croit
                 plus riche qu'il ne l'est. --}}
            <div class="mb-5 grid gap-3 sm:grid-cols-3">
                <div class="tuile">
                    <p class="tuile-libelle">Encaissé</p>
                    <p class="tuile-valeur" x-text="fcfa(d.montants_cfa.encaisse)"></p>
                    <p class="tuile-note">Ce que les clients ont payé.</p>
                </div>
                <div class="tuile">
                    <p class="tuile-libelle">En séquestre</p>
                    <p class="tuile-valeur" x-text="fcfa(d.montants_cfa.en_sequestre)"></p>
                    <p class="tuile-note">À vous, mais retenu jusqu'à la fin du délai de réclamation.</p>
                </div>
                <div class="tuile">
                    <p class="tuile-libelle">Panier moyen</p>
                    <p class="tuile-valeur" x-text="fcfa(d.montants_cfa.panier_moyen)"></p>
                    <p class="tuile-note">Sur les ventes encaissées.</p>
                </div>
            </div>

            <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <a href="/vendeur/commandes?statut=a_preparer" class="tuile hover:border-brun-clair">
                    <p class="tuile-libelle">À préparer</p>
                    <p class="tuile-valeur" x-text="d.compteurs.commandes_a_traiter"></p>
                </a>
                <a href="/vendeur/commandes?statut=expediee" class="tuile hover:border-brun-clair">
                    <p class="tuile-libelle">En cours de livraison</p>
                    <p class="tuile-valeur" x-text="d.compteurs.commandes_expediees"></p>
                </a>
                <div class="tuile" :class="d.compteurs.litiges_ouverts > 0 && 'border-alerte/50'">
                    <p class="tuile-libelle">Litiges ouverts</p>
                    <p class="tuile-valeur"
                       :class="d.compteurs.litiges_ouverts > 0 && 'text-alerte'"
                       x-text="d.compteurs.litiges_ouverts"></p>
                    <p class="tuile-note" x-show="d.compteurs.litiges_ouverts > 0">
                        Les fonds concernés restent gelés jusqu'à l'arbitrage.
                    </p>
                </div>
                <div class="tuile">
                    <p class="tuile-libelle">Produits publiés</p>
                    <p class="tuile-valeur" x-text="d.compteurs.produits_publies"></p>
                    <p class="tuile-note" x-show="d.compteurs.produits_en_attente > 0">
                        <span x-text="d.compteurs.produits_en_attente"></span> en attente de validation.
                    </p>
                </div>
            </div>

            {{-- Alerte de stock : ce qui fait perdre une vente sans que
                 personne ne s'en aperçoive. --}}
            <template x-if="d.stock.ruptures > 0 || d.stock.critiques > 0">
                <div class="note mb-5">
                    <b>Stock à surveiller</b>
                    <span x-show="d.stock.ruptures > 0">
                        <span x-text="d.stock.ruptures"></span> variante(s) en rupture — invendables en l'état.
                    </span>
                    <span x-show="d.stock.critiques > 0">
                        <span x-text="d.stock.critiques"></span> sous le seuil d'alerte.
                    </span>
                </div>
            </template>

            <template x-if="d.dernier_reversement">
                <div class="carte p-4">
                    <p class="tuile-libelle">Dernier virement</p>
                    <p class="mt-1 text-sm">
                        <span class="font-semibold montant" x-text="fcfa(d.dernier_reversement.montant_net_cfa)"></span>
                        — <span x-text="d.dernier_reversement.reference"></span>
                        — <span x-text="d.dernier_reversement.statut"></span>
                        <span x-show="d.dernier_reversement.execute_le"
                              x-text="' le ' + dateFr(d.dernier_reversement.execute_le)"></span>
                    </p>
                    {{-- Un virement échoué laisse les fonds en séquestre :
                         ils ne sont pas perdus. Le dire, sinon le vendeur
                         croit à une disparition. --}}
                    <p class="tuile-note" x-show="d.dernier_reversement.statut === 'echoue'">
                        Le virement a échoué : vos fonds sont toujours là et seront
                        représentés au cycle suivant. Vérifiez votre numéro Mobile Money.
                    </p>
                </div>
            </template>
        </div>
    </template>
</div>
@endsection

@push('scripts')
<script type="module">
    document.addEventListener('alpine:init', () => {
        Alpine.data('tableauDeBord', () => ({
            d: null, chargement: true, erreur: null, sansBoutique: false,

            async init() {
                if (!window.exigerConnexion()) return;

                try {
                    this.d = await window.api.get('/vendeur/tableau-de-bord');
                } catch (e) {
                    this.sansBoutique = e.sansBoutique === true;
                    this.erreur = e.message;
                } finally {
                    this.chargement = false;
                }
            },
        }));
    });
</script>
@endpush
