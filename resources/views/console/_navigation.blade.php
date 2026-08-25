@php $actif = request()->path(); @endphp

<a href="/console" class="{{ $actif === 'console' ? 'text-brun' : 'hover:text-brun' }}">Console</a>
<a href="/console/candidatures" class="{{ str_starts_with($actif, 'console/candidatures') ? 'text-brun' : 'hover:text-brun' }}">Candidatures</a>
<a href="/console/litiges" class="{{ str_starts_with($actif, 'console/litiges') ? 'text-brun' : 'hover:text-brun' }}">Litiges</a>
<a href="/console/reversements" class="{{ str_starts_with($actif, 'console/reversements') ? 'text-brun' : 'hover:text-brun' }}">Reversements</a>
