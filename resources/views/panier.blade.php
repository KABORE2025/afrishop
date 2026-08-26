{{-- Panier. Aucun JavaScript : chaque bouton est un formulaire.
     C'est un aller-retour de plus, et ça marche en 2G sur un téléphone
     qui n'a rien téléchargé. --}}
@extends('layout')
@section('titre', 'Mon panier — Afrishop')

@section('contenu')

<h1 style="font-size:24px;margin:18px 0 4px">Mon panier</h1>

@include('partiels.messages')

{{-- Alertes de mise à jour : article retiré, quantité rabotée. Un
     panier qui change tout seul sans un mot fait croire à un bogue. --}}
@foreach ($alertes as $a)
  <div class="note"><b>Panier mis à jour</b>{{ $a }}</div>
@endforeach

@if ($lignes->isEmpty())
  <div class="vide">
    <p><strong>Votre panier est vide.</strong></p>
    <p><a href="{{ route('vitrine') }}" style="color:var(--brun);font-weight:700">Voir le catalogue</a></p>
  </div>
@else

  {{--
    GROUPÉ PAR BOUTIQUE, ET CE N'EST PAS COSMÉTIQUE.
    Un panier multi-boutiques produit plusieurs colis, donc plusieurs
    frais de livraison. Le client doit le comprendre AVANT de payer,
    pas le découvrir au total.
  --}}
  @foreach ($par_boutique as $lignesBoutique)
    @php $b = $lignesBoutique->first()['boutique']; @endphp

    <h2 style="font-size:16px;margin:20px 0 6px">
      {{ $b->emoji }} {{ $b->nom }}
    </h2>

    <table>
      <tr><th>Article</th><th>Prix</th><th>Quantité</th><th>Total</th><th></th></tr>
      @foreach ($lignesBoutique as $l)
        <tr>
          <td>
            <a href="{{ route('produit', $l['produit']->slug) }}" style="font-weight:700">
              {{ $l['produit']->nom }}
            </a>
            <span style="display:block;font-size:12.5px;color:var(--gris)">{{ $l['variante']->libelle }}</span>
          </td>
          <td>{{ number_format($l['prix_cfa'], 0, ',', ' ') }} FCFA</td>
          <td>
            {{-- Champ + bouton plutôt qu'un sélecteur : sur un petit
                 écran, un menu déroulant de 99 valeurs est pénible. --}}
            <form method="post" action="{{ route('panier.modifier') }}" style="display:flex;gap:6px">
              @csrf
              <input type="hidden" name="variante_id" value="{{ $l['variante']->id }}">
              <input type="number" name="quantite" value="{{ $l['quantite'] }}"
                     min="0" max="{{ min(99, $l['variante']->stock) }}"
                     style="width:64px;padding:5px;border:1px solid var(--bord);border-radius:6px">
              <button class="chip" type="submit">OK</button>
            </form>
            @if ($l['variante']->stock <= 5)
              <span style="font-size:12px;color:var(--rouge)">
                plus que {{ $l['variante']->stock }}
              </span>
            @endif
          </td>
          <td><b>{{ number_format($l['total_cfa'], 0, ',', ' ') }} FCFA</b></td>
          <td>
            <form method="post" action="{{ route('panier.modifier') }}">
              @csrf
              <input type="hidden" name="variante_id" value="{{ $l['variante']->id }}">
              <input type="hidden" name="quantite" value="0">
              <button type="submit" class="chip" style="color:var(--rouge)">Retirer</button>
            </form>
          </td>
        </tr>
      @endforeach
    </table>
  @endforeach

  @if ($par_boutique->count() > 1)
    <div class="note">
      <b>{{ $par_boutique->count() }} boutiques dans ce panier</b>
      Vous recevrez {{ $par_boutique->count() }} colis distincts. Les frais de livraison
      sont réduits sur les colis suivants, mais ils s'additionnent : le détail
      apparaît à l'étape suivante.
    </div>
  @endif

  <div class="carte" style="margin-top:20px;padding:16px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
      <div>
        <div style="font-size:13px;color:var(--gris)">Total des articles</div>
        <div style="font-size:22px;font-weight:800">
          {{ number_format($total_articles_cfa, 0, ',', ' ') }} FCFA
        </div>
        <div style="font-size:12.5px;color:var(--gris)">
          Hors livraison — calculée à l'étape suivante selon votre ville.
        </div>
      </div>

      <div style="display:flex;gap:10px;align-items:center">
        <form method="post" action="{{ route('panier.vider') }}">
          @csrf
          <button type="submit" class="chip">Vider le panier</button>
        </form>
        <a href="{{ route('commander') }}" class="chip"
           style="background:var(--brun);color:#fff;border-color:var(--brun);padding:10px 18px;font-size:15px">
          Commander →
        </a>
      </div>
    </div>
  </div>
@endif

@endsection
