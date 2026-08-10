<?php $__env->startSection('titre', $produit->nom.' — Afrishop'); ?>

<?php $__env->startSection('contenu'); ?>

<p style="margin:16px 0"><a href="<?php echo e(route('vitrine')); ?>" style="color:var(--gris)">← Retour au catalogue</a></p>

<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.2fr);gap:24px;align-items:start">

  <div class="carte" style="border-radius:14px">
    <div class="vis" style="font-size:110px"><?php echo e($produit->categorie->emoji ?? '📦'); ?></div>
  </div>

  <div>
    <span class="vendeur"><?php echo e($produit->boutique->emoji); ?> <?php echo e($produit->boutique->nom); ?></span>
    <h1 style="margin:6px 0 10px;font-size:24px"><?php echo e($produit->nom); ?></h1>
    <p style="color:var(--gris)"><?php echo e($produit->description); ?></p>

    <table>
      <tr><th>Déclinaison</th><th>Prix</th><th>Stock</th></tr>
      <?php $__currentLoopData = $produit->variantes; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $v): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr>
          <td><?php echo e($v->libelle); ?></td>
          <td><b><?php echo e(number_format($v->prix_ttc_cfa, 0, ',', ' ')); ?> FCFA</b></td>
          <td>
            <?php if($v->stock > 0): ?>
              <?php echo e($v->stock); ?> en stock
            <?php else: ?>
              <span style="color:var(--rouge);font-weight:700">épuisé</span>
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