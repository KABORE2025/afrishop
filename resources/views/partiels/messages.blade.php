{{-- Messages de session — succès et erreurs, au même endroit partout.
     Les écrire à la main dans chaque vue finit toujours par produire
     trois formulations différentes pour la même situation. --}}
@if (session('succes'))
  <div class="note" style="border-left-color:var(--vert);background:#f2f9f5">
    {{ session('succes') }}
  </div>
@endif

@if (session('erreur'))
  <div class="note" style="border-left-color:var(--rouge);background:#fdf3f2">
    <b>Impossible de continuer</b>{{ session('erreur') }}
  </div>
@endif

{{-- Erreurs de validation. Affichées ensemble : un client qui corrige
     un champ pour découvrir le suivant abandonne au troisième. --}}
@if ($errors->any())
  <div class="note" style="border-left-color:var(--rouge);background:#fdf3f2">
    <b>Vérifiez ces points</b>
    <ul style="margin:4px 0 0;padding-left:18px">
      @foreach ($errors->all() as $e)
        <li>{{ $e }}</li>
      @endforeach
    </ul>
  </div>
@endif
