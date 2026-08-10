{{-- Fiche produit. Le comparateur en bas est ce qui distingue une place
     de marché d'une boutique : il montre au client qu'un autre vendeur
     propose le même article, et à quel prix. --}}
@extends('layout')
@section('titre', $produit->nom.' — Afrishop')

@section('contenu')

<p style="margin:16px 0"><a href="{{ route('vitrine') }}" style="color:var(--gris)">← Retour au catalogue</a></p>

<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.2fr);gap:24px;align-items:start">

  <div class="carte" style="border-radius:14px">
    <div class="vis" style="font-size:110px">{{ $produit->categorie->emoji ?? '📦' }}</div>
  </div>

  <div>
    <span class="vendeur">{{ $produit->boutique->emoji }} {{ $produit->boutique->nom }}</span>
    <h1 style="margin:6px 0 10px;font-size:24px">{{ $produit->nom }}</h1>
    <p style="color:var(--gris)">{{ $produit->description }}</p>

    <table>
      <tr><th>Déclinaison</th><th>Prix</th><th>Stock</th></tr>
      @foreach ($produit->variantes as $v)
        <tr>
          <td>{{ $v->libelle }}</td>
          <td><b>{{ number_format($v->prix_ttc_cfa, 0, ',', ' ') }} FCFA</b></td>
          <td>
            @if ($v->stock > 0)
              {{ $v->stock }} en stock
            @else
              <span style="color:var(--rouge);font-weight:700">épuisé</span>
            @endif
          </td>
        </tr>
      @endforeach
    </table>

    @if ($produit->tracable)
      <div class="note">
        <b>Produit traçable</b>
        Chaque exemplaire porte une étiquette QR unique. La scanner mène à une
        page qui indique le lot, la date de fabrication et la date limite.
      </div>
    @endif
  </div>
</div>

@if ($offres->isNotEmpty())
  <h2 style="font-size:18px;margin:28px 0 6px">Le même produit ailleurs</h2>
  <p style="color:var(--gris);font-size:14px;margin:0 0 10px">
    D'autres boutiques vendent un article portant le même nom. Les prix et les
    stocks sont les leurs.
  </p>
  <table>
    <tr><th>Boutique</th><th>À partir de</th><th>Stock total</th><th></th></tr>
    @foreach ($offres as $o)
      <tr>
        <td>{{ $o->boutique->emoji }} {{ $o->boutique->nom }}</td>
        <td><b>{{ number_format($o->variantes->min('prix_ttc_cfa') ?? 0, 0, ',', ' ') }} FCFA</b></td>
        <td>{{ $o->variantes->sum('stock') }}</td>
        <td><a href="{{ route('produit', $o->slug) }}" style="color:var(--brun);font-weight:700">Voir</a></td>
      </tr>
    @endforeach
  </table>
@endif

@endsection
