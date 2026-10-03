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

        {{--
          QUI EST CONNECTÉ.
          Le bandeau ne montrait qu'un bouton « Se déconnecter » : rien
          ne disait sous quel compte on travaillait. Un administrateur
          renvoyé de /vendeur vers /console n'avait aucun moyen de
          comprendre pourquoi.
        --}}
        <div x-data="session" class="flex items-center gap-3">

            {{-- Pendant le chargement, un gabarit de la bonne taille
                 plutôt que rien : sans lui, le bandeau saute quand le
                 nom arrive, et le clic part à côté. --}}
            <div x-show="chargement" class="h-5 w-28 animate-pulse rounded bg-bord" x-cloak></div>

            <template x-if="!chargement && utilisateur">
                <div class="flex items-center gap-3">
                    <div class="text-right leading-tight">
                        <div class="text-sm font-bold" x-text="utilisateur.nom"></div>

                        {{-- Le rôle, et la boutique quand il y en a une.
                             Un gérant doit voir laquelle il gère avant de
                             modifier un prix. --}}
                        <div class="text-xs text-gris">
                            <span x-text="libelleRole"></span>
                            <template x-if="nomBoutique">
                                <span> — <span class="font-semibold" x-text="nomBoutique"></span></span>
                            </template>
                        </div>
                    </div>

                    {{-- Pastille d'initiale. Repère visuel immédiat quand
                         on jongle entre un compte admin et un compte
                         vendeur de test. --}}
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full text-sm font-extrabold text-white"
                          :class="estAdmin ? 'bg-brun' : 'bg-brun-clair'"
                          x-text="(utilisateur.nom || '?').charAt(0).toUpperCase()"></span>

                    <a href="/compte/mot-de-passe" class="text-sm font-semibold text-gris hover:text-brun">Mot de passe</a>

                    <button type="button" @click="deconnecter"
                            class="text-sm font-semibold text-gris hover:text-brun">
                        Quitter
                    </button>
                </div>
            </template>

            {{-- Jeton absent ou expiré. Le dire vaut mieux qu'un bouton
                 « Se déconnecter » sur une session qui n'existe plus. --}}
            <template x-if="!chargement && !utilisateur">
                <a href="/vendeur/connexion" class="text-sm font-semibold text-brun hover:underline">
                    Se connecter
                </a>
            </template>
        </div>
    </div>
</header>

<main class="mx-auto max-w-6xl px-4 py-6">
    @yield('contenu')
</main>

{{-- Le numéro du service client, sur tous les espaces connectés : c'est
     là qu'un vendeur ou un livreur lit « code bloqué, appelez-nous ». --}}
<footer class="mx-auto max-w-6xl px-4 pb-8 text-sm text-gris">
    Service client Afrishop :
    <a href="{{ telephone_support(true) }}" class="font-semibold text-brun">{{ telephone_support() }}</a>
</footer>

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

{{--
  Les écrans déclarent ici leurs propres composants Alpine.
  Alpine ne démarre qu'au `DOMContentLoaded` (voir `resources/js/app.js`) :
  ces scripts ont donc le temps de s'enregistrer avant qu'il ne parcoure
  le DOM. Inverser cet ordre rendrait les écrans inertes, sans erreur.
--}}
@stack('scripts')

</body>
</html>
