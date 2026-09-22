{{-- Navigation de l'espace vendeur, en un seul endroit : ajouter un
     écran ne doit pas demander de modifier quatre gabarits. --}}
@php $actif = request()->path(); @endphp

<a href="/vendeur" class="{{ $actif === 'vendeur' ? 'text-brun' : 'hover:text-brun' }}">Tableau de bord</a>
<a href="/vendeur/commandes" class="{{ str_starts_with($actif, 'vendeur/commandes') ? 'text-brun' : 'hover:text-brun' }}">Commandes</a>
<a href="/vendeur/livreurs" class="{{ str_starts_with($actif, 'vendeur/livreurs') ? 'text-brun' : 'hover:text-brun' }}">Livreurs</a>
<a href="/vendeur/produits" class="{{ str_starts_with($actif, 'vendeur/produits') ? 'text-brun' : 'hover:text-brun' }}">Produits</a>
<a href="/vendeur/reversements" class="{{ str_starts_with($actif, 'vendeur/reversements') ? 'text-brun' : 'hover:text-brun' }}">Reversements</a>
