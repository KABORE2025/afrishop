{{-- Fiche produit. Le comparateur en bas est ce qui distingue une place
     de marché d'une boutique : il montre au client qu'un autre vendeur
     propose le même article, et à quel prix. --}}
@extends('layout')
@section('titre', $produit->nom.' — Afrishop')

@section('contenu')

<p style="margin:16px 0"><a href="{{ route('vitrine') }}" style="color:var(--gris)">← Retour au catalogue</a></p>

<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.2fr);gap:24px;align-items:start">

  <div>
    @php $medias = $produit->medias; $principal = $medias->first(); @endphp

    <div class="carte" style="border-radius:14px">
      <div class="vis" style="font-size:110px">
        @if ($principal && ($principal->chemin_grand || $principal->chemin_original))
          <img src="{{ Storage::disk('public')->url($principal->chemin_grand ?: $principal->chemin_original) }}"
               alt="{{ $principal->texte_alternatif ?: $produit->nom }}" width="1200" height="1200">
        @else
          {{ $produit->categorie->emoji ?? '📦' }}
        @endif
      </div>
    </div>

    {{-- Les autres médias, en vignettes. Sans JavaScript, chacune est
         un lien vers l'image en grand : plus fruste qu'un carrousel, et
         ça marche partout. --}}
    @if ($medias->count() > 1)
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
        @foreach ($medias as $m)
          <a href="{{ Storage::disk('public')->url($m->chemin_grand ?: $m->chemin_original) }}"
             style="position:relative;display:block">
            <img src="{{ Storage::disk('public')->url($m->chemin_miniature ?: $m->chemin_original) }}"
                 alt="{{ $m->texte_alternatif ?: $produit->nom }}" loading="lazy"
                 style="width:62px;height:62px;object-fit:cover;border:1px solid var(--bord);border-radius:8px;display:block">
            @if ($m->type === 'video')
              <span style="position:absolute;left:0;bottom:0;background:rgba(26,26,26,.8);color:#fff;font-size:10px;font-weight:800;padding:1px 4px;border-radius:0 6px 0 8px">vidéo</span>
            @endif
          </a>
        @endforeach
      </div>
    @endif

    {{--
      LA VIDÉO NE SE CHARGE QU'AU CLIC — `preload="none"`.
      Sur un forfait facturé au mégaoctet, une vidéo qui se précharge
      toute seule coûte de l'argent au client sans qu'il l'ait demandé.
      Le poids est annoncé avant, pour qu'il décide en connaissance de
      cause.
    --}}
    @foreach ($medias->where('type', 'video') as $v)
      <div style="margin-top:12px">
        <video controls preload="none" playsinline style="width:100%;border-radius:12px;border:1px solid var(--bord)"
               @if ($v->chemin_poster) poster="{{ Storage::disk('public')->url($v->chemin_poster) }}" @endif>
          <source src="{{ Storage::disk('public')->url($v->chemin_original) }}"
                  type="{{ $v->mime ?: 'video/mp4' }}">
        </video>
        <p style="font-size:12.5px;color:var(--gris);margin:4px 0 0">
          Vidéo @if ($v->poids_octets) — {{ round($v->poids_octets / 1048576, 1) }} Mo @endif.
          Elle ne se télécharge que si vous lancez la lecture.
        </p>
      </div>
    @endforeach
  </div>

  <div>
    <span class="vendeur">{{ $produit->boutique->emoji }} {{ $produit->boutique->nom }}</span>
    <h1 style="margin:6px 0 10px;font-size:24px">{{ $produit->nom }}</h1>
    <p style="color:var(--gris)">{{ $produit->description }}</p>

    @include('partiels.messages')

    <table>
      <tr><th>Déclinaison</th><th>Prix</th><th>Stock</th><th></th></tr>
      @foreach ($produit->variantes as $v)
        <tr>
          <td>{{ $v->libelle }}</td>
          {{-- Le prix effectif : celui de la variante, sinon celui du
               produit. Afficher la colonne brute donnerait « 0 FCFA »
               pour toute variante qui hérite du prix. --}}
          <td><b>{{ number_format($v->prixTtc(), 0, ',', ' ') }} FCFA</b></td>
          <td>
            @if ($v->stock > 0)
              {{ $v->stock }} en stock
            @else
              <span style="color:var(--rouge);font-weight:700">épuisé</span>
            @endif
          </td>
          <td>
            @if ($v->stock > 0 && $v->actif)
              <form method="post" action="{{ route('panier.ajouter') }}">
                @csrf
                <input type="hidden" name="variante_id" value="{{ $v->id }}">
                <button type="submit" class="chip"
                        style="background:var(--brun);color:#fff;border-color:var(--brun)">
                  Ajouter
                </button>
              </form>
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
