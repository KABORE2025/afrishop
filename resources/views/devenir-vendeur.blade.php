{{-- « Ouvrir ma boutique » — candidature vendeur, sans JavaScript. --}}
@extends('layout')
@section('titre', 'Ouvrir ma boutique — Afrishop')

@section('contenu')

@include('partiels.messages')

@php
  $champ = 'width:100%;padding:11px;border:1px solid var(--bord);border-radius:8px;font:inherit';
  $etiquette = 'display:block;font-size:13px;color:var(--gris);margin-bottom:4px';
@endphp

<div class="carte" style="padding:22px;margin:22px auto;max-width:560px">
  <h1 style="font-size:22px;margin:0 0 6px">Ouvrir ma boutique sur Afrishop</h1>
  <p style="margin:0 0 16px;color:var(--gris);font-size:14px">
    Présentez votre activité : Afrishop examine chaque demande et vous rappelle.
    Une fois acceptée, vous recevez vos accès à l'espace vendeur.
  </p>

  <form method="post" action="{{ route('devenir-vendeur.enregistrer') }}" style="display:grid;gap:12px">
    @csrf
    <label>
      <span style="{{ $etiquette }}">Nom de la boutique</span>
      <input name="nom_boutique" required value="{{ old('nom_boutique') }}" style="{{ $champ }}" placeholder="Ex. Kôrô Pagnes">
    </label>
    <label>
      <span style="{{ $etiquette }}">Votre nom (responsable)</span>
      <input name="responsable" required value="{{ old('responsable') }}" style="{{ $champ }}">
    </label>
    <label>
      <span style="{{ $etiquette }}">Téléphone — il deviendra votre identifiant de connexion</span>
      <input name="telephone" required inputmode="tel" value="{{ old('telephone') }}" style="{{ $champ }}" placeholder="70 00 11 22">
    </label>
    <label>
      <span style="{{ $etiquette }}">Ville de la boutique</span>
      <select name="ville_id" required style="{{ $champ }}">
        <option value="">— Choisir —</option>
        @foreach ($villes as $pays => $liste)
          <optgroup label="{{ $pays }}">
            @foreach ($liste as $v)
              <option value="{{ $v->id }}" @selected((string) old('ville_id') === (string) $v->id)>{{ $v->nom }}</option>
            @endforeach
          </optgroup>
        @endforeach
      </select>
    </label>
    <label>
      <span style="{{ $etiquette }}">Ce que vous vendez surtout (facultatif)</span>
      <select name="categorie_id" style="{{ $champ }}">
        <option value="">— Plusieurs catégories —</option>
        @foreach ($categories as $c)
          <option value="{{ $c->id }}" @selected((string) old('categorie_id') === (string) $c->id)>{{ $c->nom }}</option>
        @endforeach
      </select>
    </label>
    <label>
      <span style="{{ $etiquette }}">Votre activité</span>
      <textarea name="description" required minlength="30" maxlength="2000" rows="5" style="{{ $champ }}"
                placeholder="Ce que vous vendez, depuis quand, où se trouve votre boutique, vos canaux actuels (marché, WhatsApp…).">{{ old('description') }}</textarea>
    </label>

    <button type="submit" class="chip"
            style="background:var(--brun);color:#fff;border-color:var(--brun);padding:12px 18px;justify-self:start">
      Envoyer ma demande
    </button>
  </form>

  <p style="margin:16px 0 0;font-size:13px;color:var(--gris)">
    Afrishop retient le paiement de chaque client jusqu'à la livraison et 72 h de vérification,
    puis vous le verse. Une commission est prélevée sur chaque vente.
  </p>
</div>

@endsection
