
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $__env->yieldContent('titre', 'Afrishop'); ?></title>
<style>
:root{
  --brun:#7a3e12; --brun-clair:#c2703a; --fond:#faf7f3; --surface:#fff;
  --bord:#e6dcd0; --texte:#1a1a1a; --gris:#6b6257; --vert:#2f7d4f; --rouge:#b3261e;
}
*{box-sizing:border-box}
body{margin:0;background:var(--fond);color:var(--texte);
  font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
  font-size:15px;line-height:1.55}
a{color:inherit;text-decoration:none}
.wrap{max-width:1120px;margin:0 auto;padding:0 16px}

header{background:var(--surface);border-bottom:1px solid var(--bord);
  position:sticky;top:0;z-index:10}
.bar{display:flex;align-items:center;gap:18px;flex-wrap:wrap;padding:12px 0}
.logo{display:flex;align-items:center;gap:9px;font-weight:800;font-size:18px}
.logo i{width:30px;height:30px;border-radius:8px;background:var(--brun-clair);
  color:#fff;display:grid;place-items:center;font-style:normal;font-weight:800}
nav a{font-size:14px;font-weight:600;color:var(--gris);padding:6px 0}
nav a.on,nav a:hover{color:var(--brun)}

.hero{background:var(--brun);color:#fff;border-radius:14px;padding:26px;margin:18px 0}
.hero h1{margin:0 0 8px;font-size:26px;line-height:1.2}
.hero p{margin:0;opacity:.9;max-width:56ch}

.filtres{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0}
.chip{border:1px solid var(--bord);background:var(--surface);border-radius:20px;
  padding:7px 14px;font-size:13.5px;font-weight:600;color:var(--gris)}
.chip.on{background:var(--texte);color:#fff;border-color:var(--texte)}

.grille{display:grid;grid-template-columns:repeat(auto-fill,minmax(215px,1fr));gap:16px}
.carte{background:var(--surface);border:1px solid var(--bord);border-radius:12px;
  overflow:hidden;display:flex;flex-direction:column}
.carte .vis{aspect-ratio:1;display:grid;place-items:center;font-size:56px;
  background:linear-gradient(135deg,#efe4d6,#dcc9b0)}
.carte .corps{padding:12px;display:flex;flex-direction:column;gap:5px;flex:1}
.vendeur{font-size:12px;font-weight:700;color:var(--gris)}
.titre{font-weight:700;line-height:1.3}
.decl{font-size:12.5px;color:var(--gris)}
.prix{margin-top:auto;padding-top:8px;font-weight:800;font-size:16px}
.etiq{display:inline-block;font-size:11px;font-weight:800;padding:3px 8px;
  border-radius:20px;background:#e8f2ec;color:var(--vert)}
.etiq.rupture{background:#fdeceb;color:var(--rouge)}

.note{background:#fff8ef;border-left:3px solid var(--brun-clair);
  border-radius:0 8px 8px 0;padding:12px 14px;margin:16px 0;font-size:14px}
.note b{display:block;margin-bottom:3px}
.vide{text-align:center;padding:48px 16px;color:var(--gris)}

table{width:100%;border-collapse:collapse;margin:12px 0;font-size:14px;
  background:var(--surface);border:1px solid var(--bord);border-radius:10px;overflow:hidden}
th{background:#f4ece2;text-align:left;padding:9px 12px;font-size:12.5px;color:var(--brun)}
td{padding:9px 12px;border-top:1px solid var(--bord)}

footer{margin-top:40px;background:var(--surface);border-top:1px solid var(--bord);
  padding:20px 0;font-size:13px;color:var(--gris)}
</style>
</head>
<body>

<header>
  <div class="wrap bar">
    <a href="<?php echo e(route('vitrine')); ?>" class="logo"><i>A</i>Afrishop</a>
    <nav style="display:flex;gap:16px">
      <a href="<?php echo e(route('vitrine')); ?>" class="on">Marketplace</a>
      <a href="<?php echo e(url('/api/categories')); ?>">API</a>
    </nav>
  </div>
</header>

<main class="wrap">
  <?php echo $__env->yieldContent('contenu'); ?>
</main>

<footer>
  <div class="wrap">
    <b>Afrishop</b> — place de marché multi-boutiques d'Afrique de l'Ouest.<br>
    Afrishop est un intermédiaire technique : les produits sont vendus par les
    boutiques référencées, qui en répondent.<br>
    <b>Transport international et douane :</b> non pris en charge. Le colis est
    remis au transitaire désigné par le client ; les droits à l'arrivée sont à
    sa charge.
  </div>
</footer>

</body>
</html>
<?php /**PATH F:\Afrishop\Afrishop\3-Backend-laravel\resources\views/layout.blade.php ENDPATH**/ ?>