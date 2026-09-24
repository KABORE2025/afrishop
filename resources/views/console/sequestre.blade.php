@extends('layouts.app')

@section('titre', 'Séquestre')

@section('navigation')
    @include('console._navigation')
@endsection

@section('contenu')
<div x-data="ecranSequestre" x-cloak>

    <h1 class="mb-1 text-2xl font-extrabold">Séquestre</h1>

    {{--
      PAS DE BOUTON « LIBÉRER », ET C'EST UNE DÉCISION.
      Les fonds partent par trois chemins seulement, tous dans
      SequestreService : confirmation du client, fin de la fenêtre de
      protection (tâche horaire), arbitrage d'un litige. Un bouton qui
      forcerait la libération contournerait les garde-fous qui protègent
      l'acheteur — et un seul compte admin compromis suffirait à vider
      le séquestre. Pour débloquer un litige : l'écran Litiges.
    --}}
    <div class="note mb-4">
        <b>Écran de lecture.</b>
        Après la remise du colis, les fonds restent retenus pendant la fenêtre de protection du client
        (72 h). Ils partent dès que le client confirme que tout est conforme, ou automatiquement à la fin
        du délai s'il n'a signalé aucun problème. Un signalement les gèle jusqu'à l'arbitrage.
        Un litige se débloque depuis l'écran <a href="/console/litiges" class="text-brun hover:underline">Litiges</a>.
    </div>

    <template x-if="erreur">
        <div class="carte mb-4 border-alerte/40 bg-red-50 p-4 text-sm text-alerte" x-text="erreur"></div>
    </template>

    {{-- Les quatre étapes, cliquables. --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <template x-for="e in ETAPES" :key="e.cle">
            <button class="tuile text-left" :class="etape === e.cle && 'border-brun'" @click="etape = e.cle; charger(1)">
                <p class="tuile-libelle" x-text="e.libelle"></p>
                <p class="tuile-valeur montant" x-text="fcfa(totaux[e.cle]?.montant_cfa)"></p>
                <p class="tuile-note" x-text="(totaux[e.cle]?.nombre ?? 0) + ' commande(s) — ' + e.note"></p>
            </button>
        </template>
    </div>

    <div x-show="chargement" class="py-12 text-center text-sm text-gris">Chargement…</div>

    <template x-if="!chargement && !erreur && elements.length === 0">
        <div class="carte p-10 text-center text-sm text-gris">Rien à cette étape.</div>
    </template>

    <div class="carte overflow-x-auto" x-show="!chargement && elements.length > 0">
        <table class="tableau">
            <thead>
                <tr>
                    <th>Référence</th>
                    <th>Boutique</th>
                    <th>Marchandise</th>
                    <th>Ce qui retient les fonds</th>
                    <th>Libération prévue</th>
                    <th class="text-right">Net boutique</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="sc in elements" :key="sc.id">
                    <tr>
                        <td class="whitespace-nowrap font-semibold">
                            <a :href="'/console/commandes?id=' + sc.id" class="text-brun hover:underline" x-text="sc.reference"></a>
                            <p class="text-xs font-normal text-gris" x-text="sc.client_nom"></p>
                        </td>
                        <td x-text="sc.boutique"></td>
                        <td><span class="statut" x-text="sc.statut"></span></td>
                        <td class="text-sm" :class="sc.fonds.liberable && 'text-succes'" x-text="sc.fonds.raison"></td>
                        <td class="whitespace-nowrap text-xs" x-text="sc.fonds.liberation_prevue_le ? dateHeure(sc.fonds.liberation_prevue_le) : '—'"></td>
                        <td class="text-right montant" x-text="fcfa(sc.montant_net_cfa)"></td>
                    </tr>
                </template>
            </tbody>
        </table>
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
    document.addEventListener('alpine:init', () => {
        Alpine.data('ecranSequestre', () => ({
            ETAPES: [
                { cle: 'bloques',   libelle: 'Gelés',                note: 'litige ou retour en cours' },
                { cle: 'delai',     libelle: 'Livrés, en délai',     note: 'fenêtre de protection du client' },
                { cle: 'livraison', libelle: 'En cours de livraison', note: 'payés, pas encore remis' },
                { cle: 'attente',   libelle: 'Paiement non confirmé', note: 'rien d’encaissé : ne pas expédier' },
            ],
            etape: 'bloques', totaux: {}, elements: [], meta: { page: 1, dernier: 1 },
            chargement: true, erreur: null,

            init() {
                if (!window.exigerConnexion()) return;
                this.charger(1);
            },

            async charger(page = 1) {
                this.chargement = true; this.erreur = null;
                try {
                    const r = await window.api.get(`/admin/sequestre?etape=${this.etape}&page=${page}`);
                    this.totaux = r.totaux ?? {};
                    this.elements = r.data ?? [];
                    this.meta = r.meta ?? { page: 1, dernier: 1 };
                } catch (e) { this.erreur = e.message; this.elements = []; }
                finally { this.chargement = false; }
            },

            dateHeure(iso) {
                if (!iso) return '—';
                return new Date(iso).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
            },
        }));
    });
</script>
@endpush
