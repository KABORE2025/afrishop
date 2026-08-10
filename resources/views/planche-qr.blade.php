{{--
  =====================================================================
   PLANCHE D'ÉTIQUETTES À IMPRIMER
  =====================================================================
   Sortie destinée à une imprimante, pas à un écran. D'où :
     - des dimensions en millimètres et non en pixels
     - une grille calée sur des planches d'étiquettes autocollantes
       standard (format 48,5 × 25,4 mm, 44 par feuille A4)
     - « break-inside: avoid » pour qu'aucune étiquette ne soit coupée
       entre deux pages
     - les images QR intégrées en base64 : la planche est un fichier
       unique, imprimable hors ligne, sans dépendre du serveur
  =====================================================================
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Planche {{ $lot->reference }} — {{ $lot->produit->nom }}</title>
<style>
  @page { size: A4; margin: 8mm; }
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,sans-serif;color:#000}
  .entete{margin-bottom:6mm;padding-bottom:3mm;border-bottom:1px solid #000}
  .entete h1{font-size:12pt}
  .entete p{font-size:8pt;color:#444;margin-top:1mm}
  .grille{display:grid;grid-template-columns:repeat(4,1fr);gap:3mm}
  .etiquette{border:1px dashed #bbb;padding:2mm;text-align:center;break-inside:avoid}
  .etiquette img{width:100%;max-width:22mm;height:auto;display:block;margin:0 auto}
  .etiquette .num{font-family:ui-monospace,monospace;font-size:6.5pt;
                  margin-top:1mm;letter-spacing:.2px}
  .etiquette .dates{font-size:5.5pt;color:#333;margin-top:.5mm}
  /* L'en-tête ne s'imprime pas sur les pages suivantes : on ne gaspille
     pas d'étiquettes autocollantes pour du texte. */
  @media print { .entete { display:none } }
</style>
</head>
<body>

<div class="entete">
  <h1>{{ $lot->reference }} — {{ $lot->produit->nom }} ({{ $lot->produit->boutique->nom }})</h1>
  <p>
    {{ $lot->quantite }} étiquettes, n° {{ $lot->numero_debut }} à {{ $lot->numeroFin() }} ·
    Fabriqué le {{ $lot->date_fabrication->format('d/m/Y') }} ·
    À consommer avant le {{ $lot->date_expiration->format('d/m/Y') }} ·
    Fabricant : {{ $lot->fabricant }}
  </p>
</div>

<div class="grille">
  @foreach($etiquettes as $e)
    <div class="etiquette">
      {{-- Le QR contient l'URL /v/{jeton}, jamais le code lisible. --}}
      <img src="{{ $e['qr_data_uri'] }}" alt="">
      <div class="num">{{ $e['code_lisible'] }}</div>
      <div class="dates">
        Fab. {{ $lot->date_fabrication->format('d/m/y') }} ·
        Exp. {{ $lot->date_expiration->format('d/m/y') }}
      </div>
    </div>
  @endforeach
</div>

</body>
</html>
