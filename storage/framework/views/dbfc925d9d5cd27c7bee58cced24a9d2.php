
<?php $actif = request()->path(); ?>

<a href="/vendeur" class="<?php echo e($actif === 'vendeur' ? 'text-brun' : 'hover:text-brun'); ?>">Tableau de bord</a>
<a href="/vendeur/commandes" class="<?php echo e(str_starts_with($actif, 'vendeur/commandes') ? 'text-brun' : 'hover:text-brun'); ?>">Commandes</a>
<a href="/vendeur/produits" class="<?php echo e(str_starts_with($actif, 'vendeur/produits') ? 'text-brun' : 'hover:text-brun'); ?>">Produits</a>
<a href="/vendeur/reversements" class="<?php echo e(str_starts_with($actif, 'vendeur/reversements') ? 'text-brun' : 'hover:text-brun'); ?>">Reversements</a>
<?php /**PATH F:\Afrishop\Afrishop\3-Backend-laravel\resources\views/vendeur/_navigation.blade.php ENDPATH**/ ?>