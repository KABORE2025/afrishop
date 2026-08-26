{{-- Tunnel de commande — UNE SEULE PAGE.
     Pas d'assistant en quatre étapes : chaque étape est un aller-retour
     réseau de plus, et sur une connexion instable c'est quatre
     occasions d'échouer au lieu d'une. --}}
@extends('layout')
@section('titre', 'Commander — Afrishop')

@section('contenu')

<p style="margin:16px 0"><a href="{{ route('panier') }}" style="color:var(--gris)">← Retour au panier</a></p>

<h1 style="font-size:24px;margin:0 0 14px">Finaliser la commande</h1>

@include('partiels.messages')

<form method="post" action="{{ route('commander.enregistrer') }}">
  @csrf

  <div style="display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,1fr);gap:24px;align-items:start">

    <div>
      {{-- ----------------------------------------------------------
           COORDONNÉES
           ---------------------------------------------------------- --}}
      <div class="carte" style="padding:16px;margin-bottom:16px">
        <h2 style="font-size:16px;margin:0 0 12px">Vos coordonnées</h2>

        <label style="display:block;margin-bottom:10px">
          <span style="display:block;font-size:13px;font-weight:700;color:var(--gris);margin-bottom:3px">Nom complet</span>
          <input type="text" name="nom" required maxlength="120" value="{{ old('nom') }}"
                 style="width:100%;padding:9px;border:1px solid var(--bord);border-radius:8px">
        </label>

        <label style="display:block;margin-bottom:10px">
          <span style="display:block;font-size:13px;font-weight:700;color:var(--gris);margin-bottom:3px">Téléphone</span>
          <input type="tel" name="telephone" required maxlength="20" value="{{ old('telephone') }}"
                 placeholder="+226 70 00 00 00"
                 style="width:100%;padding:9px;border:1px solid var(--bord);border-radius:8px">
          {{-- Le téléphone n'est pas un détail administratif : c'est par
               lui qu'arrive le code de livraison à usage unique, et
               c'est ce code qui prouve la remise du colis. --}}
          <span style="display:block;font-size:12.5px;color:var(--gris);margin-top:3px">
            C'est à ce numéro que sera envoyé le code de livraison à remettre au livreur.
          </span>
        </label>
      </div>

      {{-- ----------------------------------------------------------
           LIVRAISON
           ---------------------------------------------------------- --}}
      <div class="carte" style="padding:16px;margin-bottom:16px">
        <h2 style="font-size:16px;margin:0 0 12px">Livraison</h2>

        <label style="display:block;margin-bottom:10px">
          <span style="display:block;font-size:13px;font-weight:700;color:var(--gris);margin-bottom:3px">Pays</span>
          <select name="pays_id" required
                  style="width:100%;padding:9px;border:1px solid var(--bord);border-radius:8px">
            @foreach ($pays as $p)
              <option value="{{ $p->id }}" @selected(old('pays_id') == $p->id)>{{ $p->nom }}</option>
            @endforeach
          </select>
        </label>

        <label style="display:block;margin-bottom:10px">
          <span style="display:block;font-size:13px;font-weight:700;color:var(--gris);margin-bottom:3px">Ville</span>
          <select name="ville_id"
                  style="width:100%;padding:9px;border:1px solid var(--bord);border-radius:8px">
            <option value="">— Autre ville —</option>
            @foreach ($villes as $v)
              <option value="{{ $v->id }}" @selected(old('ville_id') == $v->id)>{{ $v->nom }}</option>
            @endforeach
          </select>
          <span style="display:block;font-size:12.5px;color:var(--gris);margin-top:3px">
            Les frais de livraison dépendent de la ville.
          </span>
        </label>

        <label style="display:block;margin-bottom:10px">
          <span style="display:block;font-size:13px;font-weight:700;color:var(--gris);margin-bottom:3px">Quartier</span>
          <input type="text" name="quartier" required maxlength="120" value="{{ old('quartier') }}"
                 style="width:100%;padding:9px;border:1px solid var(--bord);border-radius:8px">
        </label>

        <label style="display:block">
          <span style="display:block;font-size:13px;font-weight:700;color:var(--gris);margin-bottom:3px">Repère</span>
          <input type="text" name="repere" maxlength="255" value="{{ old('repere') }}"
                 placeholder="Ex. : en face de la pharmacie du marché"
                 style="width:100%;padding:9px;border:1px solid var(--bord);border-radius:8px">
          {{-- Le repère n'est pas facultatif dans les faits : dans la
               plupart des quartiers, il n'y a ni nom de rue ni numéro,
               et c'est lui qui permet au livreur de trouver. --}}
          <span style="display:block;font-size:12.5px;color:var(--gris);margin-top:3px">
            Très utile : c'est souvent le repère, plus que l'adresse, qui permet au livreur de vous trouver.
          </span>
        </label>
      </div>

      {{-- ----------------------------------------------------------
           PAIEMENT
           ---------------------------------------------------------- --}}
      <div class="carte" style="padding:16px">
        <h2 style="font-size:16px;margin:0 0 12px">Paiement</h2>

        @foreach ([
          'mobile_money'      => ['Mobile Money', 'Orange Money, Moov, Wave. Vous validez sur votre téléphone.'],
          'especes_livraison' => ['À la livraison', 'Vous payez le livreur en espèces à la remise du colis.'],
        ] as $code => [$titre, $aide])
          <label style="display:flex;gap:10px;align-items:flex-start;padding:10px;border:1px solid var(--bord);border-radius:8px;margin-bottom:8px">
            <input type="radio" name="mode_paiement" value="{{ $code }}" required
                   @checked(old('mode_paiement', 'mobile_money') === $code) style="margin-top:3px">
            <span>
              <b>{{ $titre }}</b>
              <span style="display:block;font-size:12.5px;color:var(--gris)">{{ $aide }}</span>
            </span>
          </label>
        @endforeach

        {{--
          LA PROTECTION DE L'ACHETEUR, DITE AU MOMENT OÙ ELLE COMPTE.
          C'est ce qui distingue Afrishop d'un annuaire, et l'endroit
          où le client hésite est précisément là où il faut le dire.
        --}}
        <div class="note" style="margin-top:12px">
          <b>Votre argent est protégé</b>
          En paiement en ligne, la somme est retenue par Afrishop et n'est versée à la
          boutique qu'après la livraison. Si le colis n'arrive pas ou ne correspond pas,
          vous signalez le problème et les fonds restent bloqués le temps de l'arbitrage.
        </div>
      </div>
    </div>

    {{-- ----------------------------------------------------------
         RÉCAPITULATIF
         ---------------------------------------------------------- --}}
    <div class="carte" style="padding:16px;position:sticky;top:80px">
      <h2 style="font-size:16px;margin:0 0 12px">Votre commande</h2>

      @foreach ($lignes as $l)
        <div style="display:flex;justify-content:space-between;gap:10px;font-size:14px;margin-bottom:8px">
          <span>
            {{ $l['produit']->nom }}
            <span style="display:block;font-size:12px;color:var(--gris)">
              {{ $l['variante']->libelle }} × {{ $l['quantite'] }}
            </span>
          </span>
          <b style="white-space:nowrap">{{ number_format($l['total_cfa'], 0, ',', ' ') }} F</b>
        </div>
      @endforeach

      <hr style="border:0;border-top:1px solid var(--bord);margin:12px 0">

      <div style="display:flex;justify-content:space-between;font-size:14px">
        <span>Articles</span>
        <b>{{ number_format($total_articles_cfa, 0, ',', ' ') }} FCFA</b>
      </div>

      {{-- Les frais ne sont PAS affichés ici : ils dépendent de la ville
           et du nombre de boutiques, et sont calculés par le serveur à
           la validation. Afficher une estimation fausse serait pire que
           de ne rien afficher. --}}
      <div style="display:flex;justify-content:space-between;font-size:13px;color:var(--gris);margin-top:6px">
        <span>Livraison</span>
        <span>calculée à la validation</span>
      </div>

      @if ($par_boutique->count() > 1)
        <p style="font-size:12.5px;color:var(--gris);margin:10px 0 0">
          {{ $par_boutique->count() }} boutiques → {{ $par_boutique->count() }} colis,
          donc plusieurs frais de livraison.
        </p>
      @endif

      <button type="submit" class="chip"
              style="width:100%;margin-top:16px;background:var(--brun);color:#fff;border-color:var(--brun);padding:12px;font-size:15px">
        Valider la commande
      </button>

      <p style="font-size:12px;color:var(--gris);margin:10px 0 0">
        En validant, vous acceptez que les produits soient vendus par les boutiques
        référencées, qui en répondent. Afrishop est un intermédiaire technique.
      </p>
    </div>
  </div>
</form>

@endsection
