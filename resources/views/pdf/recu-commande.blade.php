{{--
  REÇU DE COMMANDE — rendu en PDF par dompdf.

  Ce n'est PAS une facture : au Burkina (FEC), en Côte d'Ivoire (FNE),
  au Bénin (e-MECeF) et au Niger (e-SECeF), une facture n'a de valeur
  que certifiée par le dispositif de l'État, au nom du VENDEUR (voir
  FactureService). Le document le dit en tête, lisiblement.

  Contraintes dompdf : CSS 2.1 seulement (pas de flex ni de grid), et
  la police DejaVu Sans pour les accents et le symbole « ₣ » éventuel.
--}}
@php
  $fcfa = fn ($m) => number_format((int) $m, 0, ',', ' ') . ' FCFA';
  $paiement = $commande->transactions
      ->where('sens', 'encaissement')->where('statut', 'reussie')->sortByDesc('id')->first();
  $modesPaiement = [
      'mobile_money' => 'Mobile Money', 'carte' => 'Carte bancaire',
      'virement' => 'Virement', 'especes_livraison' => 'Espèces à la livraison',
  ];
  $modesLivraison = [
      'domicile' => 'Livraison à domicile', 'point_relais' => 'Point relais',
      'retrait_boutique' => 'Retrait en boutique',
  ];
  // Téléphone partiellement masqué : le reçu peut être imprimé, transféré, égaré.
  $tel = preg_replace('/\D/', '', (string) $commande->client_telephone);
  $telMasque = strlen($tel) > 4 ? str_repeat('•', strlen($tel) - 4) . substr($tel, -4) : $tel;

  $totalArticles = $commande->sousCommandes->sum('montant_articles_ttc_cfa');
  $totalFrais    = $commande->sousCommandes->sum('frais_livraison_cfa');
  $totalRemise   = $commande->sousCommandes->sum('remise_cfa');
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Reçu {{ $commande->reference }}</title>
<style>
  @page { margin: 28px 34px; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1a1a1a; }
  h1 { font-size: 18px; margin: 0; }
  .muted { color: #6b6257; }
  .avertissement { border: 1px solid #a1690f; background: #fdf6e7; color: #7a4f0b; padding: 6px 10px; margin: 10px 0 14px; font-size: 10px; }
  table { width: 100%; border-collapse: collapse; }
  .infos td { vertical-align: top; padding: 2px 0; }
  .lignes th { text-align: left; border-bottom: 1px solid #1a1a1a; padding: 5px 4px; font-size: 10px; }
  .lignes td { border-bottom: 1px solid #e6dcd0; padding: 5px 4px; }
  .droite { text-align: right; }
  .boutique { background: #f6f2ec; padding: 6px 8px; margin-top: 14px; font-weight: bold; }
  .totaux td { padding: 3px 4px; }
  .total td { font-size: 14px; font-weight: bold; border-top: 2px solid #1a1a1a; padding-top: 6px; }
  .pied { margin-top: 22px; font-size: 9px; color: #6b6257; border-top: 1px solid #e6dcd0; padding-top: 8px; }
</style>
</head>
<body>

<table>
  <tr>
    <td><h1>Afrishop</h1><span class="muted">Place de marché multi-boutiques</span></td>
    <td class="droite">
      <b style="font-size:14px">REÇU DE COMMANDE</b><br>
      N° {{ $commande->reference }}<br>
      <span class="muted">Émis le {{ now()->format('d/m/Y à H:i') }}</span>
    </td>
  </tr>
</table>

<div class="avertissement">
  <b>Ce document est un reçu, il ne vaut pas facture.</b>
  La facture est émise par chaque boutique, qui vend les produits en son nom.
</div>

<table class="infos">
  <tr>
    <td style="width:50%">
      <b>Client</b><br>
      {{ $commande->client_nom }}<br>
      Tél. {{ $telMasque }}
    </td>
    <td>
      <b>Commande</b><br>
      Passée le {{ $commande->cree_le?->format('d/m/Y à H:i') }}<br>
      {{ $modesLivraison[$commande->mode_livraison] ?? $commande->mode_livraison }}<br>
      Paiement : {{ $modesPaiement[$commande->mode_paiement] ?? $commande->mode_paiement }}
      @if ($paiement)
        <br>Encaissé le {{ \Illuminate\Support\Carbon::parse($paiement->finalisee_le ?? $paiement->initiee_le)->format('d/m/Y à H:i') }}
        @if ($paiement->reference_externe)<br><span class="muted">Réf. paiement {{ $paiement->reference_externe }}</span>@endif
      @endif
    </td>
  </tr>
</table>

@foreach ($commande->sousCommandes as $sc)
  <div class="boutique">{{ $sc->boutique->nom }} <span class="muted" style="font-weight:normal">— colis {{ $sc->reference }}</span></div>
  <table class="lignes">
    <tr>
      <th>Produit</th>
      <th class="droite" style="width:14%">Prix unitaire</th>
      <th class="droite" style="width:8%">Qté</th>
      <th class="droite" style="width:16%">Total TTC</th>
    </tr>
    @foreach ($sc->lignes as $l)
      <tr>
        <td>{{ $l->nom_produit }}@if ($l->libelle_variante)<br><span class="muted">{{ $l->libelle_variante }}</span>@endif</td>
        <td class="droite">{{ $fcfa($l->prix_unitaire_ttc_cfa) }}</td>
        <td class="droite">{{ $l->quantite }}</td>
        <td class="droite">{{ $fcfa($l->total_ttc_cfa) }}</td>
      </tr>
    @endforeach
    @if ((int) $sc->frais_livraison_cfa > 0)
      <tr><td colspan="3">Livraison</td><td class="droite">{{ $fcfa($sc->frais_livraison_cfa) }}</td></tr>
    @endif
    @if ((int) $sc->remise_cfa > 0)
      <tr><td colspan="3">Remise</td><td class="droite">− {{ $fcfa($sc->remise_cfa) }}</td></tr>
    @endif
  </table>
@endforeach

<table class="totaux" style="margin-top:14px;width:45%;margin-left:55%">
  <tr><td>Articles TTC</td><td class="droite">{{ $fcfa($totalArticles) }}</td></tr>
  <tr><td>Livraison</td><td class="droite">{{ $fcfa($totalFrais) }}</td></tr>
  @if ($totalRemise > 0)
    <tr><td>Remises</td><td class="droite">− {{ $fcfa($totalRemise) }}</td></tr>
  @endif
  <tr class="total"><td>Total payé</td><td class="droite">{{ $fcfa($commande->total_a_payer_cfa) }}</td></tr>
</table>

<div class="pied">
  Le montant payé est retenu par Afrishop et n'est versé à chaque boutique qu'après la livraison de son colis
  et un délai de vérification de {{ (int) parametre('delai_confirmation_auto_jours', 3) * 24 }} heures,
  pendant lequel vous pouvez signaler un problème depuis la page de votre commande.<br>
  Suivi : {{ route('suivi') }}
</div>

</body>
</html>
