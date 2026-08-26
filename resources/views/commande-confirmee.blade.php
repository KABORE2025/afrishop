{{-- Confirmation de commande.
     Cette page ne montre RIEN de sensible — ni téléphone complet, ni
     adresse. Elle est accessible par la seule référence, pour qu'un
     client sans compte y revienne depuis son SMS ; une référence peut
     donc être devinée ou partagée. --}}
@extends('layout')
@section('titre', 'Commande '.$commande->reference.' — Afrishop')

@section('contenu')

<div class="carte" style="padding:22px;margin:22px 0;border-left:4px solid var(--vert)">
  <h1 style="font-size:22px;margin:0 0 6px">Commande enregistrée</h1>
  <p style="margin:0;color:var(--gris)">
    Référence <b style="color:var(--texte);font-size:16px">{{ $commande->reference }}</b> —
    notez-la pour suivre votre colis.
  </p>
</div>

{{--
  CE QUI SE PASSE MAINTENANT, EN TROIS PHRASES.
  Un client qui ne sait pas ce qui l'attend rappelle le support le
  lendemain. La différence entre les deux modes de paiement est dite
  ici parce que c'est le moment où elle devient concrète.
--}}
<div class="note">
  <b>Et maintenant ?</b>
  @if ($commande->mode_paiement === 'especes_livraison')
    Vous paierez le livreur en espèces à la remise du colis. Un
    <b>code à 6 chiffres</b> vous sera envoyé par SMS : donnez-le au livreur,
    c'est lui qui atteste que vous avez bien reçu la commande.
  @else
    Le paiement va être demandé sur votre téléphone. Une fois encaissé, la somme est
    <b>retenue par Afrishop</b> et n'est versée à la boutique qu'après votre livraison.
    Un <b>code à 6 chiffres</b> vous sera envoyé par SMS à l'expédition : donnez-le au
    livreur à la remise du colis.
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
