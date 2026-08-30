{{-- Confirmation de commande.
     Cette page ne montre RIEN de sensible — ni téléphone complet, ni
     adresse. Elle est accessible par la seule référence, pour qu'un
     client sans compte y revienne depuis son SMS ; une référence peut
     donc être devinée ou partagée. --}}
@extends('layout')
@section('titre', 'Commande '.$commande->reference.' — Afrishop')

@section('contenu')

@include('partiels.messages')

@php
  /*
    L'ÉTAT DU PAIEMENT DÉCIDE DE TOUT CE QUI SUIT.
    Cette page annonçait « commande enregistrée » et décrivait la suite
    comme si l'argent était encaissé — sans jamais regarder
    `statut_paiement`. Un client dont le prélèvement avait échoué
    repartait donc convaincu que son colis arrivait.
  */
  $paye     = $commande->statut_paiement === 'encaisse';
  $echoue   = in_array($commande->statut_paiement, ['echoue', 'rembourse'], true);
  $enCours  = ! $paye && ! $echoue;
@endphp

<div class="carte" style="padding:22px;margin:22px 0;border-left:4px solid
     {{ $paye ? 'var(--vert)' : ($echoue ? 'var(--rouge)' : '#a1690f') }}">
  <h1 style="font-size:22px;margin:0 0 6px">
    @if ($paye)      Commande confirmée
    @elseif ($echoue) Paiement non abouti
    @else            Paiement en attente
    @endif
  </h1>
  <p style="margin:0;color:var(--gris)">
    Référence <b style="color:var(--texte);font-size:16px">{{ $commande->reference }}</b> —
    notez-la pour suivre votre colis.
  </p>
</div>

{{-- Relance. Un paiement peut échouer pour mille raisons passagères :
     réseau coupé pendant la validation, solde reconstitué depuis. Sans
     ce bouton, il fallait refaire tout le panier — et la commande
     restait en base à immobiliser du stock. --}}
@if (! $paye)
  <div class="carte" style="padding:16px;margin-bottom:16px;border-left:4px solid var(--brun)">
    <p style="margin:0 0 10px">
      @if ($echoue)
        Le paiement n'a pas abouti et les articles ont été remis en stock.
        Vous pouvez relancer la demande.
      @else
        Validez la demande de paiement sur votre téléphone. Si vous n'avez rien reçu,
        relancez-la ci-dessous.
      @endif
    </p>
    <form method="post" action="{{ route('commande.payer', $commande->reference) }}">
      @csrf
      <button type="submit" class="chip"
              style="background:var(--brun);color:#fff;border-color:var(--brun);padding:10px 18px">
        {{ $echoue ? 'Réessayer le paiement' : 'Relancer la demande de paiement' }}
      </button>
    </form>
  </div>
@endif

{{--
  CE QUI SE PASSE MAINTENANT, EN TROIS PHRASES.
  Un client qui ne sait pas ce qui l'attend rappelle le support le
  lendemain. La différence entre les deux modes de paiement est dite
  ici parce que c'est le moment où elle devient concrète.
--}}
<div class="note">
  <b>Et maintenant ?</b>
  @if ($paye)
    Votre paiement est encaissé. La somme est <b>retenue par Afrishop</b> et ne sera
    versée à la boutique qu'après votre livraison. Un <b>code à 6 chiffres</b> vous
    sera envoyé par SMS à l'expédition : donnez-le au livreur à la remise du colis,
    c'est lui qui atteste que vous avez bien reçu la commande.
  @elseif ($echoue)
    Rien ne vous a été prélevé. Tant que le paiement n'aboutit pas, la boutique
    ne prépare pas le colis.
  @else
    La boutique ne préparera votre colis <b>qu'une fois le paiement encaissé</b>.
    Ensuite, la somme est retenue par Afrishop jusqu'à votre livraison.
  @endif
</div>

@php
  $totalArticles = $commande->sousCommandes->sum('montant_articles_ttc_cfa');
  $frais         = (int) $commande->total_frais_livraison_cfa;
@endphp

<h2 style="font-size:17px;margin:24px 0 6px">
  {{ $commande->sousCommandes->count() > 1
       ? $commande->sousCommandes->count().' colis' : 'Votre colis' }}
</h2>

{{-- Une sous-commande = un colis = une boutique. Le dire explicitement
     évite l'appel « je n'ai reçu qu'une partie de ma commande ». --}}
@if ($commande->sousCommandes->count() > 1)
  <p style="color:var(--gris);font-size:14px;margin:0 0 10px">
    Votre commande vient de plusieurs boutiques : les colis arriveront séparément,
    chacun avec son propre délai.
  </p>
@endif

@foreach ($commande->sousCommandes as $sc)
  <div class="carte" style="padding:14px;margin-bottom:10px">
    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px">
      <b>{{ $sc->boutique->emoji }} {{ $sc->boutique->nom }}</b>
      <span style="font-size:13px;color:var(--gris)">{{ $sc->reference }}</span>
    </div>

    <table style="margin:10px 0 0">
      @foreach ($sc->lignes as $l)
        <tr>
          <td>{{ $l->nom_produit }}
            <span style="display:block;font-size:12px;color:var(--gris)">{{ $l->libelle_variante }}</span>
          </td>
          <td style="white-space:nowrap">× {{ $l->quantite }}</td>
          <td style="white-space:nowrap;text-align:right">
            <b>{{ number_format($l->total_ttc_cfa, 0, ',', ' ') }} FCFA</b>
          </td>
        </tr>
      @endforeach
    </table>
  </div>
@endforeach

<div class="carte" style="padding:16px;margin-top:16px">
  <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:6px">
    <span>Articles</span><b>{{ number_format($totalArticles, 0, ',', ' ') }} FCFA</b>
  </div>
  <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:6px">
    <span>Livraison</span><b>{{ number_format($frais, 0, ',', ' ') }} FCFA</b>
  </div>
  <hr style="border:0;border-top:1px solid var(--bord);margin:10px 0">
  <div style="display:flex;justify-content:space-between;font-size:18px">
    <b>Total</b><b>{{ number_format($totalArticles + $frais, 0, ',', ' ') }} FCFA</b>
  </div>
</div>

<p style="margin:22px 0">
  <a href="{{ route('vitrine') }}" class="chip">← Continuer mes achats</a>
</p>

@endsection
