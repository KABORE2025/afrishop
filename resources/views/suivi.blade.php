{{-- Suivi de commande sans compte : numéro de commande + téléphone.
     Rendu serveur, sans JavaScript, comme le reste de la vitrine. --}}
@extends('layout')
@section('titre', 'Suivre ma commande — Afrishop')

@section('contenu')

@include('partiels.messages')

@php $champ = 'width:100%;padding:11px;border:1px solid var(--bord);border-radius:8px;font:inherit'; @endphp

<div class="carte" style="padding:22px;margin:22px auto;max-width:480px">
  <h1 style="font-size:22px;margin:0 0 6px">Suivre ma commande</h1>
  <p style="margin:0 0 16px;color:var(--gris);font-size:14px">
    Saisissez le numéro reçu par SMS après votre achat, et le téléphone utilisé pour commander.
  </p>

  <form method="post" action="{{ route('suivi.rechercher') }}" style="display:grid;gap:12px">
    @csrf
    <label>
      <span style="display:block;font-size:13px;color:var(--gris);margin-bottom:4px">Numéro de commande</span>
      <input name="reference" required value="{{ old('reference') }}" autocapitalize="characters"
             placeholder="BF-CMD-2026-…" style="{{ $champ }}">
    </label>
    <label>
      <span style="display:block;font-size:13px;color:var(--gris);margin-bottom:4px">Téléphone de la commande</span>
      <input name="telephone" required inputmode="tel" value="{{ old('telephone') }}"
             placeholder="70 00 11 22" style="{{ $champ }}">
    </label>
    <button type="submit" class="chip"
            style="background:var(--brun);color:#fff;border-color:var(--brun);padding:12px 18px;justify-self:start">
      Voir ma commande
    </button>
  </form>

  <p style="margin:16px 0 0;font-size:13px;color:var(--gris)">
    Sur la page de votre commande, vous pourrez suivre chaque colis, renvoyer votre code de
    livraison, télécharger votre reçu et, après la livraison, confirmer la réception ou signaler un problème.
  </p>
  <p style="margin:10px 0 0;font-size:13px;color:var(--gris)">
    Numéro de commande perdu ? Appelez le service client :
    <a href="{{ telephone_support(true) }}" style="font-weight:700;color:var(--brun)">{{ telephone_support() }}</a>
  </p>
</div>

@endsection
