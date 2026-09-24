{{-- Boîte SMS de test — voir BoiteSmsTestController. Page autonome,
     sans le gabarit du site : elle ne doit jamais être prise pour une
     page d'Afrishop. --}}
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
@if (request()->boolean('auto'))
  <meta http-equiv="refresh" content="10">
@endif
<title>Boîte SMS de test</title>
<style>
  :root { --fond:#f6f2ec; --carte:#fff; --bord:#e6dcd0; --texte:#1a1a1a; --gris:#6b6257; --alerte:#b3261e; --ok:#2f7d4f; }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--fond); color:var(--texte); font:15px/1.45 system-ui, sans-serif; }
  .bandeau { background:var(--alerte); color:#fff; padding:10px 16px; font-weight:700; text-align:center; }
  main { max-width:860px; margin:0 auto; padding:16px; }
  h1 { font-size:22px; margin:8px 0 4px; }
  .aide { color:var(--gris); margin:0 0 14px; font-size:14px; }
  .barre { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:14px; }
  input { padding:9px 10px; border:1px solid var(--bord); border-radius:8px; font:inherit; min-width:0; flex:1 1 200px; }
  button, .lien { padding:9px 14px; border-radius:8px; border:1px solid var(--bord); background:var(--carte); font:inherit; cursor:pointer; color:inherit; text-decoration:none; }
  .primaire { background:var(--texte); color:#fff; border-color:var(--texte); }
  .note { background:#f2f9f5; border-left:4px solid var(--ok); padding:8px 12px; margin-bottom:12px; }
  .sms { background:var(--carte); border:1px solid var(--bord); border-radius:10px; padding:12px 14px; margin-bottom:10px; }
  .entete { display:flex; flex-wrap:wrap; justify-content:space-between; gap:6px; font-size:13px; color:var(--gris); margin-bottom:6px; }
  .tel { font-weight:700; color:var(--texte); }
  .statut { border-radius:999px; padding:1px 8px; font-weight:700; font-size:12px; }
  .en_file { background:#fdf3e1; color:#8a5a00; }
  .envoye, .remis { background:#e8f5ee; color:var(--ok); }
  .echoue, .rejete { background:#fdeceb; color:var(--alerte); }
  .texte { overflow-wrap:anywhere; }
  .code { background:#fff3c4; padding:0 4px; border-radius:4px; font-weight:800; letter-spacing:1px; }
  .vide { text-align:center; color:var(--gris); padding:40px 0; }
</style>
</head>
<body>
<div class="bandeau">PAGE DE TEST — ne doit jamais être accessible en ligne</div>

<main>
  <h1>Boîte SMS de test</h1>
  <p class="aide">
    Aucun SMS ne part réellement (<code>SMS_DRIVER=journal</code>). Voici ce qui aurait été envoyé,
    du plus récent au plus ancien. Les codes à 6 chiffres sont surlignés.
  </p>

  @if (session('succes'))
    <div class="note">{{ session('succes') }}</div>
  @endif

  <div class="barre">
    <form method="get" style="display:flex;gap:8px;flex:1 1 280px">
      <input name="telephone" value="{{ $telephone }}" placeholder="Filtrer par téléphone (ex. 70001122)" inputmode="tel">
      @if (request()->boolean('auto')) <input type="hidden" name="auto" value="1"> @endif
      <button type="submit">Filtrer</button>
    </form>

    <form method="post" action="{{ route('dev.sms.envoyer') }}">
      @csrf
      <input type="hidden" name="telephone" value="{{ $telephone }}">
      <button type="submit" class="primaire" @disabled($enFile === 0)>
        Envoyer la file maintenant ({{ $enFile }})
      </button>
    </form>

    <a class="lien" href="{{ route('dev.sms', array_filter(['telephone' => $telephone, 'auto' => request()->boolean('auto') ? null : 1])) }}">
      {{ request()->boolean('auto') ? 'Arrêter l’actualisation' : 'Actualiser toutes les 10 s' }}
    </a>
  </div>

  @if ($enFile > 0)
    <p class="aide">
      {{ $enFile }} SMS « en file » : ils partent au prochain passage de
      <code>php artisan schedule:work</code>, ou tout de suite avec le bouton ci-dessus.
    </p>
  @endif

  @forelse ($sms as $m)
    <div class="sms">
      <div class="entete">
        <span>À <span class="tel">{{ $m['telephone'] ?? '—' }}</span></span>
        <span>
          {{ $m['cree_le'] ? \Illuminate\Support\Carbon::parse($m['cree_le'])->format('d/m/Y H:i:s') : '' }}
          · <span class="statut {{ $m['statut'] }}">{{ str_replace('_', ' ', $m['statut']) }}</span>
        </span>
      </div>
      {{-- Échappé AVANT le surlignage : le texte vient de la base. --}}
      <div class="texte">{!! preg_replace('/\b(\d{6})\b/', '<span class="code">$1</span>', e($m['texte'])) !!}</div>
    </div>
  @empty
    <p class="vide">Aucun SMS{{ $telephone ? ' pour ce numéro' : '' }}.</p>
  @endforelse
</main>
</body>
</html>
