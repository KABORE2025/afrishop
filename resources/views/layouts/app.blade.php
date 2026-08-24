{{--
  =====================================================================
   GABARIT DES ÉCRANS CONNECTÉS
  =====================================================================
   LE PROJET A DEUX GABARITS, ET CE N'EST PAS UNE INCOHÉRENCE.

   `layout.blade.php`  — vitrine, fiche produit, vérification QR.
                         CSS en ligne, zéro JavaScript, zéro requête
                         supplémentaire. Public visé : un inconnu dans
                         la rue, en 2G, sur un téléphone d'entrée de
                         gamme, qui n'a pas de compte et n'en aura pas.
                         NE PAS Y AJOUTER @vite.

   `layouts/app.blade.php` (ce fichier) — espace vendeur, console
                         d'administration, panier et tunnel. Tailwind et
                         Alpine. Public visé : quelqu'un qui se connecte
                         tous les jours, sur son propre téléphone ou
                         l'ordinateur de la boutique, et pour qui un
                         chargement initial se rentabilise sur la
                         journée.

   La règle de partage est simple : SI LA PAGE EXIGE UN COMPTE, elle
   utilise ce gabarit. Sinon, l'autre.

   La palette est la même des deux côtés — les couleurs de
   `tailwind.config.js` sont recopiées des variables CSS de
   `layout.blade.php`. Changer l'une sans l'autre fait diverger le
   produit en deux moitiés qui ne se ressemblent plus.
  ===================================================================== --}}
<!DOCTYPE html>
<html lang="fr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Les écrans connectés n'ont rien à faire dans un moteur de recherche. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('titre', 'Espace vendeur') — Afrishop</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-fond font-sans text-[15px] leading-relaxed text-texte">

<header class="sticky top-0 z-10 border-b border-bord bg-surface">
    <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-4 px-4 py-3">
        <a href="{{ route('vitrine') }}" class="flex items-center gap-2 text-lg font-extrabold">
            <span class="grid h-8 w-8 place-items-center rounded-lg bg-brun-clair font-extrabold text-white">A</span>
            Afrishop
        </a>

        <nav class="flex flex-1 gap-4 text-sm font-semibold text-gris">
            @yield('navigation')
        </nav>

        <div x-data="session">
            <button type="button" @click="deconnecter" class="text-sm font-semibold text-gris hover:text-brun">
                Se déconnecter
            </button>
        </div>
    </div>
</header>

<main class="mx-auto max-w-6xl px-4 py-6">
    @yield('contenu')
</main>

{{--
  Repli sans JavaScript. Il ne cherche pas à faire fonctionner l'écran
  sans JS — l'espace vendeur en a besoin — mais à DIRE POURQUOI la page
  est vide. Une page blanche sans explication est le pire des messages
  d'erreur, et c'est ce que voit un utilisateur dont le navigateur a
  bloqué le bundle ou dont le téléchargement a échoué en 2G.
--}}
<noscript>
    <div class="mx-auto max-w-6xl px-4">
        <div class="note">
            <b>Cette page a besoin de JavaScript.</b>
            Activez-le, ou rechargez la page si votre connexion est instable.
            Le catalogue et la vérification d'étiquette, eux, fonctionnent sans.
        </div>
    </div>
</noscript>

</body>
</html>
