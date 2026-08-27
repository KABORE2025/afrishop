<?php $__env->startSection('titre', 'Mon panier — Afrishop'); ?>

<?php $__env->startSection('contenu'); ?>

<h1 style="font-size:24px;margin:18px 0 4px">Mon panier</h1>

<?php echo $__env->make('partiels.messages', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>


<?php $__currentLoopData = $alertes; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $a): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
  <div class="note"><b>Panier mis à jour</b><?php echo e($a); ?></div>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

<?php if($lignes->isEmpty()): ?>
  <div class="vide">
    <p><strong>Votre panier est vide.</strong></p>
    <p><a href="<?php echo e(route('vitrine')); ?>" style="color:var(--brun);font-weight:700">Voir le catalogue</a></p>
  </div>
<?php else: ?>

  
  <?php $__currentLoopData = $par_boutique; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $lignesBoutique): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <?php $b = $lignesBoutique->first()['boutique']; ?>

    <h2 style="font-size:16px;margin:20px 0 6px">
      <?php echo e($b->emoji); ?> <?php echo e($b->nom); ?>

    </h2>

    <table>
      <tr><th>Article</th><th>Prix</th><th>Quantité</th><th>Total</th><th></th></tr>
      <?php $__currentLoopData = $lignesBoutique; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $l): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr>
          <td>
            <a href="<?php echo e(route('produit', $l['produit']->slug)); ?>" style="font-weight:700">
              <?php echo e($l['produit']->nom); ?>

            </a>
            <span style="display:block;font-size:12.5px;color:var(--gris)"><?php echo e($l['variante']->libelle); ?></span>
          </td>
          <td><?php echo e(number_format($l['prix_cfa'], 0, ',', ' ')); ?> FCFA</td>
          <td>
            
            <form method="post" action="<?php echo e(route('panier.modifier')); ?>" style="display:flex;gap:6px">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="variante_id" value="<?php echo e($l['variante']->id); ?>">
              <input type="number" name="quantite" value="<?php echo e($l['quantite']); ?>"
                     min="0" max="<?php echo e(min(99, $l['variante']->stock)); ?>"
                     style="width:64px;padding:5px;border:1px solid var(--bord);border-radius:6px">
              <button class="chip" type="submit">OK</button>
            </form>
            <?php if($l['variante']->stock <= 5): ?>
              <span style="font-size:12px;color:var(--rouge)">
                plus que <?php echo e($l['variante']->stock); ?>

              </span>
            <?php endif; ?>
          </td>
          <td><b><?php echo e(number_format($l['total_cfa'], 0, ',', ' ')); ?> FCFA</b></td>
          <td>
            <form method="post" action="<?php echo e(route('panier.modifier')); ?>">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="variante_id" value="<?php echo e($l['variante']->id); ?>">
              <input type="hidden" name="quantite" value="0">
              <button type="submit" class="chip" style="color:var(--rouge)">Retirer</button>
            </form>
          </td>
        </tr>
      <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </table>
  <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

  <?php if($par_boutique->count() > 1): ?>
    <div class="note">
      <b><?php echo e($par_boutique->count()); ?> boutiques dans ce panier</b>
      Vous recevrez <?php echo e($par_boutique->count()); ?> colis distincts. Les frais de livraison
      sont réduits sur les colis suivants, mais ils s'additionnent : le détail
      apparaît à l'étape suivante.
    </div>
  <?php endif; ?>

  <div class="carte" style="margin-top:20px;padding:16px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
      <div>
        <div style="font-size:13px;color:var(--gris)">Total des articles</div>
        <div style="font-size:22px;font-weight:800">
          <?php echo e(number_format($total_articles_cfa, 0, ',', ' ')); ?> FCFA
        </div>
        <div style="font-size:12.5px;color:var(--gris)">
          Hors livraison — calculée à l'étape suivante selon votre ville.
        </div>
      </div>

      <div style="display:flex;gap:10px;align-items:center">
        <form method="post" action="<?php echo e(route('panier.vider')); ?>">
          <?php echo csrf_field(); ?>
          <button type="submit" class="chip">Vider le panier</button>
        </form>
        <a href="<?php echo e(route('commander')); ?>" class="chip"
           style="background:var(--brun);color:#fff;border-color:var(--brun);padding:10px 18px;font-size:15px">
          Commander →
        </a>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH F:\Afrishop\Afrishop\3-Backend-laravel\resources\views/panier.blade.php ENDPATH**/ ?>