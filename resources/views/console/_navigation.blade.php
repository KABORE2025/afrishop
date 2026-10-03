@php $actif = request()->path(); @endphp

<a href="/console" class="{{ $actif === 'console' ? 'text-brun' : 'hover:text-brun' }}">Console</a>
<a href="/console/candidatures" class="{{ str_starts_with($actif, 'console/candidatures') ? 'text-brun' : 'hover:text-brun' }}">Candidatures</a>
<a href="/console/produits" class="{{ str_starts_with($actif, 'console/produits') ? 'text-brun' : 'hover:text-brun' }}">Modération</a>
<a href="/console/commandes" class="{{ str_starts_with($actif, 'console/commandes') ? 'text-brun' : 'hover:text-brun' }}">Commandes</a>
<a x-data x-show="window.estAdminPlein()" href="/console/sequestre" class="{{ str_starts_with($actif, 'console/sequestre') ? 'text-brun' : 'hover:text-brun' }}">Séquestre</a>
<a href="/console/litiges" class="{{ str_starts_with($actif, 'console/litiges') ? 'text-brun' : 'hover:text-brun' }}">Litiges</a>
<a x-data x-show="window.estAdminPlein()" href="/console/boutiques" class="{{ str_starts_with($actif, 'console/boutiques') ? 'text-brun' : 'hover:text-brun' }}">Boutiques</a>
<a x-data x-show="window.estAdminPlein()" href="/console/etiquettes" class="{{ str_starts_with($actif, 'console/etiquettes') ? 'text-brun' : 'hover:text-brun' }}">Étiquettes</a>
<a x-data x-show="window.estAdminPlein()" href="/console/avis" class="{{ str_starts_with($actif, 'console/avis') ? 'text-brun' : 'hover:text-brun' }}">Avis</a>
<a x-data x-show="window.estAdminPlein()" href="/console/reglages" class="{{ str_starts_with($actif, 'console/reglages') ? 'text-brun' : 'hover:text-brun' }}">Réglages</a>
<a x-data x-show="window.estAdminPlein()" href="/console/journal" class="{{ str_starts_with($actif, 'console/journal') ? 'text-brun' : 'hover:text-brun' }}">Journal</a>
<a x-data x-show="window.estAdminPlein()" href="/console/administrateurs" class="{{ str_starts_with($actif, 'console/administrateurs') ? 'text-brun' : 'hover:text-brun' }}">Personnel</a>
<a x-data x-show="window.estAdminPlein()" href="/console/reversements" class="{{ str_starts_with($actif, 'console/reversements') ? 'text-brun' : 'hover:text-brun' }}">Reversements</a>
