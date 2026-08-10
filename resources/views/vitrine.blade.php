{{-- Vitrine : catalogue filtrable. Aucun JavaScript — les filtres sont
     de simples liens qui rechargent la page avec un paramètre. C'est
     plus lent d'un aller-retour, et infiniment plus robuste. --}}
@extends('layout')
@section('titre', 'Afrishop — produits d\'Afrique de l\'Ouest')

@section('contenu')

<div class="hero">
  <h1>Les meilleurs produits d'Afrique de l'Ouest,<br>dans un seul panier</h1>
  <p>Commandez chez plusieurs boutiques en une fois. Payez par Mobile Money,
     à la livraison, ou retirez sur place.</p>
</div>

<div class="filtres">
  <a href="{{ route('vitrine') }}" class="chip {{ $categorieActive ? '' : 'on' }}">Toutes</a>
  @foreach ($categories as $c)
    <a href="{{ route('vitrine', ['categorie' => $c->slug]) }}"
       class="chip {{ $categorieActive === $c->slug ? 'on' : '' }}">
      {{ $c->emoji }} {{ $c->nom }}
    </a>
  @endforeach
</div>

@if ($produits->isEmpty())
  <div class="vide">
    <p><strong>Aucun produit dans cette base.</strong></p>
    <p>Chargez le jeu de démonstration :<br>
       <code>mysql -u root afrishope &lt; database/seeders/donnees-demonstration.sql</code></p>
  </div>
@else
  <div class="grille">
    @foreach ($produits as $p)
      @php
        // Le prix qui compte est celui de la variante la moins chère,
        // jamais celui du produit : c'est la variante qui porte le prix
        // ET le stock.
        $dispo    = $p->variantes->sum('stock');
        $prixMini = $p->variantes->min('prix_ttc_cfa') ?? $p->prix_ttc_cfa;
      @endphp
      <a href="{{ route('produit', $p->slug) }}" class="carte">
        <div class="vis">{{ $p->categorie->emoji ?? '📦' }}</div>
        <div class="corps">
          @if ($dispo <= 0)
            <span class="etiq rupture">Rupture</span>
          @elseif ($p->tracable)
            <span class="etiq">Traçable QR</span>
          @endif
          <span class="vendeur">{{ $p->boutique->emoji }} {{ $p->boutique->nom }}</span>
          <span class="titre">{{ $p->nom }}</span>
          <span class="decl">
            {{ $p->variantes->count() > 1
                 ? $p->variantes->count().' déclinaisons'
                 : ($p->variantes->first()->libelle ?? '') }}
          </span>
          <span class="prix">
            {{ $p->variantes->count() > 1 ? 'dès ' : '' }}{{ number_format($prixMini, 0, ',', ' ') }} FCFA
          </span>
        </div>
      </a>
    @endforeach
  </div>

  {{-- Pagination écrite à la main : `$produits->links()` rend les
       gabarits Tailwind de Laravel, et sans Tailwind le résultat est
       cassé. Deux liens suffisent, et ils marchent sans CSS. --}}
  @if ($produits->hasPages())
    <div style="display:flex;gap:10px;align-items:center;margin-top:22px">
      @if ($produits->onFirstPage())
        <span class="chip" style="opacity:.4">← Précédent</span>
      @else
        <a class="chip" href="{{ $produits->previousPageUrl() }}">← Précédent</a>
      @endif
      <span style="font-size:13.5px;color:var(--gris)">
        page {{ $produits->currentPage() }} sur {{ $produits->lastPage() }}
      </span>
      @if ($produits->hasMorePages())
        <a class="chip" href="{{ $produits->nextPageUrl() }}">Suivant →</a>
      @else
        <span class="chip" style="opacity:.4">Suivant →</span>
      @endif
    </div>
  @endif
@endif

@endsection
