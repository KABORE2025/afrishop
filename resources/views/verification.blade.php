{{--
  =====================================================================
   PAGE DE VÉRIFICATION D'ÉTIQUETTE — ce que voit le client qui scanne
  =====================================================================
   Contraintes qui expliquent chaque choix de cette page :

   1. Le client est DEBOUT, dans un marché, sur un téléphone d'entrée de
      gamme, en 2G/3G instable. Tout est en un seul fichier, sans
      JavaScript, sans police externe, sans image distante. La page
      s'affiche en une requête.

   2. Le verdict doit être compris EN UNE SECONDE, sans lire. D'où le
      grand bandeau coloré en haut : vert = bon, rouge = danger.
      La couleur est doublée d'un symbole et d'un texte, parce qu'une
      partie des lecteurs distingue mal le rouge du vert.

   3. Le client peut ne pas savoir lire couramment le français. Les
      informations critiques (dates) sont donc aussi en gros chiffres.
  =====================================================================
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Vérification produit — Afrishop</title>
<style>
  /* Aucune police externe : elle mettrait 3 secondes à charger en 2G
     et la page s'afficherait en Times New Roman entre-temps. */
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
       background:#f6f1e8;color:#1d1a17;line-height:1.5;padding:0 0 40px}
  .bandeau{padding:28px 20px;text-align:center;color:#fff}
  .bandeau .symbole{font-size:52px;line-height:1}
  .bandeau h1{font-size:22px;margin:10px 0 6px;font-weight:800}
  .bandeau p{font-size:14.5px;opacity:.95;max-width:420px;margin:0 auto}
  .ok        {background:#1a7f4b}
  .alerte    {background:#b3261e}
  .prudence  {background:#b26a00}
  .carte{background:#fff;border-radius:14px;margin:16px;padding:18px;
         box-shadow:0 1px 3px rgba(0,0,0,.08)}
  .carte h2{font-size:12px;text-transform:uppercase;letter-spacing:.8px;
            color:#8a7f72;margin-bottom:12px;font-weight:700}
  .ligne{display:flex;justify-content:space-between;gap:14px;
         padding:9px 0;border-bottom:1px solid #f0ebe3;font-size:14.5px}
  .ligne:last-child{border-bottom:0}
  .ligne span{color:#6b625a}
  .ligne strong{text-align:right;font-weight:700}
  .dates{display:flex;gap:12px;margin-top:6px}
  .date-bloc{flex:1;background:#faf7f2;border-radius:10px;padding:12px;text-align:center}
  .date-bloc em{display:block;font-style:normal;font-size:11px;text-transform:uppercase;
                letter-spacing:.5px;color:#8a7f72;margin-bottom:4px}
  .date-bloc b{font-size:19px;font-weight:800;letter-spacing:-.3px}
  .code{font-family:ui-monospace,"SF Mono",Menlo,monospace;font-size:16px;
        letter-spacing:1px;background:#f0ebe3;padding:8px 12px;border-radius:8px;
        display:inline-block;margin-top:4px}
  .pied{text-align:center;font-size:12.5px;color:#8a7f72;padding:18px 24px}
</style>
</head>
<body>

{{-- Le verdict pilote la couleur ET le symbole ET le texte : trois
     canaux indépendants pour la même information. --}}
@php
  $classe = match($verdict) {
      'authentique' => 'ok',
      'perime', 'rappele', 'inconnu', 'desactive' => 'alerte',
      'suspect' => 'prudence',
      default => 'prudence',
  };
  $symbole = match($verdict) {
      'authentique' => '✓',
      'suspect'     => '!',
      default       => '✕',
  };
  $titre = match($verdict) {
      'authentique' => 'Produit authentique',
      'perime'      => 'Produit périmé',
      'rappele'     => 'Rappel produit',
      'suspect'     => 'Étiquette à vérifier',
      'desactive'   => 'Étiquette désactivée',
      'inconnu'     => 'Produit non reconnu',
      default       => 'Vérification',
  };
@endphp

<div class="bandeau {{ $classe }}">
  <div class="symbole">{{ $symbole }}</div>
  <h1>{{ $titre }}</h1>
  <p>{{ $message }}</p>
</div>

@isset($produit)
  <div class="carte">
    <h2>Produit</h2>
    <div class="ligne"><span>Désignation</span><strong>{{ $produit['nom'] }}</strong></div>
    <div class="ligne"><span>Vendu par</span><strong>{{ $produit['boutique'] }}</strong></div>
    <div class="ligne"><span>Ville</span><strong>{{ $produit['ville'] }}</strong></div>
    <div class="ligne"><span>Fabriqué par</span><strong>{{ $lot['fabricant'] }}</strong></div>
  </div>

  <div class="carte">
    <h2>Dates</h2>
    {{-- En gros chiffres : c'est l'information la plus consultée, et
         elle doit être lisible sans effort par quelqu'un qui déchiffre
         mal le texte courant. --}}
    <div class="dates">
      <div class="date-bloc"><em>Fabrication</em><b>{{ $lot['date_fabrication'] }}</b></div>
      <div class="date-bloc"><em>À consommer avant</em><b>{{ $lot['date_expiration'] }}</b></div>
    </div>
    @if($lot['jours_restants'] >= 0)
      <div class="ligne" style="margin-top:10px">
        <span>Encore valable</span><strong>{{ $lot['jours_restants'] }} jours</strong>
      </div>
    @endif
    @if($lot['mentions'])
      <div class="ligne"><span>Mentions</span><strong style="font-weight:500">{{ $lot['mentions'] }}</strong></div>
    @endif
  </div>

  <div class="carte">
    <h2>Étiquette</h2>
    <div class="ligne"><span>Numéro</span><strong class="code">{{ $etiquette['code_lisible'] }}</strong></div>
    <div class="ligne"><span>Lot</span><strong>{{ $lot['reference'] }}</strong></div>

    {{-- COMPTEUR PUBLIC DE SCANS
         Affiché volontairement. Un client qui vient d'acheter un
         produit neuf et lit « scanné 63 fois depuis 9 villes »
         comprend immédiatement que l'étiquette a été copiée.
         C'est la meilleure défense contre la duplication : elle
         transforme chaque client en contrôleur. --}}
    <div class="ligne">
      <span>Nombre de vérifications</span>
      <strong>{{ $etiquette['nb_scans'] }}{{ $etiquette['villes'] > 1 ? ' — '.$etiquette['villes'].' villes' : '' }}</strong>
    </div>
    @if($etiquette['premier_scan'])
      <div class="ligne"><span>Première vérification</span><strong>{{ $etiquette['premier_scan'] }}</strong></div>
    @endif
  </div>
@endisset

<p class="pied">
  Vérification fournie par Afrishop.<br>
  Un doute ? Écrivez-nous au +226 70 00 00 00 en citant le numéro d'étiquette.
</p>

</body>
</html>
