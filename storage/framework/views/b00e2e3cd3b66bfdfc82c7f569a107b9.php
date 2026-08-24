
<!DOCTYPE html>
<html lang="fr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo $__env->yieldContent('titre', 'Espace vendeur'); ?> — Afrishop</title>

    <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css', 'resources/js/app.js']); ?>
</head>
<body class="h-full bg-fond font-sans text-[15px] leading-relaxed text-texte">

<header class="sticky top-0 z-10 border-b border-bord bg-surface">
    <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-4 px-4 py-3">
        <a href="<?php echo e(route('vitrine')); ?>" class="flex items-center gap-2 text-lg font-extrabold">
            <span class="grid h-8 w-8 place-items-center rounded-lg bg-brun-clair font-extrabold text-white">A</span>
            Afrishop
        </a>

        <nav class="flex flex-1 gap-4 text-sm font-semibold text-gris">
            <?php echo $__env->yieldContent('navigation'); ?>
        </nav>

        <div x-data="session">
            <button type="button" @click="deconnecter" class="text-sm font-semibold text-gris hover:text-brun">
                Se déconnecter
            </button>
        </div>
    </div>
</header>

<main class="mx-auto max-w-6xl px-4 py-6">
    <?php echo $__env->yieldContent('contenu'); ?>
</main>


<noscript>
    <div class="mx-auto max-w-6xl px-4">
        <div class="note">
            <b>Cette page a besoin de JavaScript.</b>
            Activez-le, ou rechargez la page si votre connexion est instable.
            Le catalogue et la vérification d'étiquette, eux, fonctionnent sans.
        </div>
    </div>
</noscript>


<?php echo $__env->yieldPushContent('scripts'); ?>

</body>
</html>
<?php /**PATH F:\Afrishop\Afrishop\3-Backend-laravel\resources\views/layouts/app.blade.php ENDPATH**/ ?>