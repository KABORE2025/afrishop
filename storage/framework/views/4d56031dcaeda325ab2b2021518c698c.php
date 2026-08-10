<?php $__env->startSection('titre', 'Afrishop — produits d\'Afrique de l\'Ouest'); ?>

<?php $__env->startSection('contenu'); ?>

<div class="hero">
  <h1>Les meilleurs produits d'Afrique de l'Ouest,<br>dans un seul panier</h1>
  <p>Commandez chez plusieurs boutiques en une fois. Payez par Mobile Money,
     à la livraison, ou retirez sur place.</p>
</div>

<div class="filtres">
  <a href="<?php echo e(route('vitrine')); ?>" class="chip <?php echo e($categorieActive ? '' : 'on'); ?>">Toutes</a>
  <?php $__currentLoopData = $categories; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $c): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <a href="<?php echo e(route('vitrine', ['categorie' => $c->slug])); ?>"
       class="chip <?php echo e($categorieActive === $c->slug ? 'on' : ''); ?>">
      <?php echo e($c->emoji); ?> <?php echo e($c->nom); ?>

    </a>
  <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
</div>

<?php if($produits->isEmpty()): ?>
  <div class="vide">
    <p><strong>Aucun produit dans cette base.</strong></p>
    <p>Chargez le jeu de démonstration :<br>
       <code>mysql -u root afrishope &lt; database/seeders/donnees-demonstration.sql</code></p>
  </div>
<?php else: ?>
  <div class="grille">
    <?php $__currentLoopData = $produits; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
      <?php
        // Le prix qui compte est celui de la variante la moins chère,
        // jamais celui du produit : c'est la variante qui porte le prix
        // ET le stock.
        $dispo    = $p->variantes->sum('stock');
        $prixMini = $p->variantes->min('prix_ttc_cfa') ?? $p->prix_ttc_cfa;
      ?>
      <a href="<?php echo e(route('produit', $p->slug)); ?>" class="carte">
        <div class="vis"><?php echo e($p->categorie->emoji ?? '📦'); ?></div>
        <div class="corps">
          <?php if($dispo <= 0): ?>
            <span class="etiq rupture">Rupture</span>
          <?php elseif($p->tracable): ?>
            <span class="etiq">Traçable QR</span>
          <?php endif; ?>
          <span class="vendeur"><?php echo e($p->boutique->emoji); ?> <?php echo e($p->boutique->nom); ?></span>
          <span class="titre"><?php echo e($p->nom); ?></span>
          <span class="decl">
            <?php echo e($p->variantes->count() > 1
                 ? $p->variantes->count().' déclinaisons'
                 : ($p->variantes->first()->libelle ?? '')); ?>

          </span>
          <span class="prix">
            <?php echo e($p->variantes->count() > 1 ? 'dès ' : ''); ?><?php echo e(number_format($prixMini, 0, ',', ' ')); ?> FCFA
          </span>
        </div>
      </a>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
  </div>

  
  <?php if($produits->hasPages()): ?>
    <div style="display:flex;gap:10px;align-items:center;margin-top:22px">
      <?php if($produits->onFirstPage()): ?>
        <span class="chip" style="opacity:.4">← Précédent</span>
      <?php else: ?>
        <a class="chip" href="<?php echo e($produits->previousPageUrl()); ?>">← Précédent</a>
      <?php endif; ?>
      <span style="font-size:13.5px;color:var(--gris)">
        page <?php echo e($produits->currentPage()); ?> sur <?php echo e($produits->lastPage()); ?>

      </span>
      <?php if($produits->hasMorePages()): ?>
        <a class="chip" href="<?php echo e($produits->nextPageUrl()); ?>">Suivant →</a>
      <?php else: ?>
        <span class="chip" style="opacity:.4">Suivant →</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH F:\Afrishop\Afrishop\3-Backend-laravel\resources\views/vitrine.blade.php ENDPATH**/ ?>