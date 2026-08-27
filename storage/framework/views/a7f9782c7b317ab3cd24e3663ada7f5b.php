<?php $__env->startSection('titre', $produit->nom.' — Afrishop'); ?>

<?php $__env->startSection('contenu'); ?>

<p style="margin:16px 0"><a href="<?php echo e(route('vitrine')); ?>" style="color:var(--gris)">← Retour au catalogue</a></p>

<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.2fr);gap:24px;align-items:start">

  <div>
    <?php $medias = $produit->medias; $principal = $medias->first(); ?>

    <div class="carte" style="border-radius:14px">
      <div class="vis" style="font-size:110px">
        <?php if($principal && ($principal->chemin_grand || $principal->chemin_original)): ?>
          <img src="<?php echo e(Storage::disk('public')->url($principal->chemin_grand ?: $principal->chemin_original)); ?>"
               alt="<?php echo e($principal->texte_alternatif ?: $produit->nom); ?>" width="1200" height="1200">
        <?php else: ?>
          <?php echo e($produit->categorie->emoji ?? '📦'); ?>

        <?php endif; ?>
      </div>
    </div>

    
    <?php if($medias->count() > 1): ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
        <?php $__currentLoopData = $medias; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $m): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
          <a href="<?php echo e(Storage::disk('public')->url($m->chemin_grand ?: $m->chemin_original)); ?>"
             style="position:relative;display:block">
            <img src="<?php echo e(Storage::disk('public')->url($m->chemin_miniature ?: $m->chemin_original)); ?>"
                 alt="<?php echo e($m->texte_alternatif ?: $produit->nom); ?>" loading="lazy"
                 style="width:62px;height:62px;object-fit:cover;border:1px solid var(--bord);border-radius:8px;display:block">
            <?php if($m->type === 'video'): ?>
              <span style="position:absolute;left:0;bottom:0;background:rgba(26,26,26,.8);color:#fff;font-size:10px;font-weight:800;padding:1px 4px;border-radius:0 6px 0 8px">vidéo</span>
            <?php endif; ?>
          </a>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
      </div>
    <?php endif; ?>

    
    <?php $__currentLoopData = $medias->where('type', 'video'); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $v): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
      <div style="margin-top:12px">
        <video controls preload="none" playsinline style="width:100%;border-radius:12px;border:1px solid var(--bord)"
               <?php if($v->chemin_poster): ?> poster="<?php echo e(Storage::disk('public')->url($v->chemin_poster)); ?>" <?php endif; ?>>
          <source src="<?php echo e(Storage::disk('public')->url($v->chemin_original)); ?>"
                  type="<?php echo e($v->mime ?: 'video/mp4'); ?>">
        </video>
        <p style="font-size:12.5px;color:var(--gris);margin:4px 0 0">
          Vidéo <?php if($v->poids_octets): ?> — <?php echo e(round($v->poids_octets / 1048576, 1)); ?> Mo <?php endif; ?>.
          Elle ne se télécharge que si vous lancez la lecture.
        </p>
      </div>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
  </div>

  <div>
    <span class="vendeur"><?php echo e($produit->boutique->emoji); ?> <?php echo e($produit->boutique->nom); ?></span>
    <h1 style="margin:6px 0 10px;font-size:24px"><?php echo e($produit->nom); ?></h1>
    <p style="color:var(--gris)"><?php echo e($produit->description); ?></p>

    <?php echo $__env->make('partiels.messages', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

    <table>
      <tr><th>Déclinaison</th><th>Prix</th><th>Stock</th><th></th></tr>
      <?php $__currentLoopData = $produit->variantes; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $v): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr>
          <td><?php echo e($v->libelle); ?></td>
          
          <td><b><?php echo e(number_format($v->prixTtc(), 0, ',', ' ')); ?> FCFA</b></td>
          <td>
            <?php if($v->stock > 0): ?>
              <?php echo e($v->stock); ?> en stock
            <?php else: ?>
              <span style="color:var(--rouge);font-weight:700">épuisé</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if($v->stock > 0 && $v->actif): ?>
              <form method="post" action="<?php echo e(route('panier.ajouter')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="variante_id" value="<?php echo e($v->id); ?>">
                <button type="submit" class="chip"
                        style="background:var(--brun);color:#fff;border-color:var(--brun)">
                  Ajouter
                </button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </table>

    <?php if($produit->tracable): ?>
      <div class="note">
        <b>Produit traçable</b>
        Chaque exemplaire porte une étiquette QR unique. La scanner mène à une
        page qui indique le lot, la date de fabrication et la date limite.
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if($offres->isNotEmpty()): ?>
  <h2 style="font-size:18px;margin:28px 0 6px">Le même produit ailleurs</h2>
  <p style="color:var(--gris);font-size:14px;margin:0 0 10px">
    D'autres boutiques vendent un article portant le même nom. Les prix et les
    stocks sont les leurs.
  </p>
  <table>
    <tr><th>Boutique</th><th>À partir de</th><th>Stock total</th><th></th></tr>
    <?php $__currentLoopData = $offres; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $o): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
      <tr>
        <td><?php echo e($o->boutique->emoji); ?> <?php echo e($o->boutique->nom); ?></td>
        <td><b><?php echo e(number_format($o->variantes->min('prix_ttc_cfa') ?? 0, 0, ',', ' ')); ?> FCFA</b></td>
        <td><?php echo e($o->variantes->sum('stock')); ?></td>
        <td><a href="<?php echo e(route('produit', $o->slug)); ?>" style="color:var(--brun);font-weight:700">Voir</a></td>
      </tr>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
  </table>
<?php endif; ?>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH F:\Afrishop\Afrishop\3-Backend-laravel\resources\views/produit.blade.php ENDPATH**/ ?>