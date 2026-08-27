
<?php if(session('succes')): ?>
  <div class="note" style="border-left-color:var(--vert);background:#f2f9f5">
    <?php echo e(session('succes')); ?>

  </div>
<?php endif; ?>

<?php if(session('erreur')): ?>
  <div class="note" style="border-left-color:var(--rouge);background:#fdf3f2">
    <b>Impossible de continuer</b><?php echo e(session('erreur')); ?>

  </div>
<?php endif; ?>


<?php if($errors->any()): ?>
  <div class="note" style="border-left-color:var(--rouge);background:#fdf3f2">
    <b>Vérifiez ces points</b>
    <ul style="margin:4px 0 0;padding-left:18px">
      <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $e): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <li><?php echo e($e); ?></li>
      <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </ul>
  </div>
<?php endif; ?>
<?php /**PATH F:\Afrishop\Afrishop\3-Backend-laravel\resources\views/partiels/messages.blade.php ENDPATH**/ ?>