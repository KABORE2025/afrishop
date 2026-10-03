{{-- Confirmation de commande.
     Cette page ne montre RIEN de sensible — ni téléphone complet, ni
     adresse. Elle est accessible par la seule référence, pour qu'un
     client sans compte y revienne depuis son SMS ; une référence peut
     donc être devinée ou partagée. --}}
@extends('layout')
@section('titre', 'Commande '.$commande->reference.' — Afrishop')

@section('contenu')

@include('partiels.messages')

@php
  /*
    L'ÉTAT DU PAIEMENT DÉCIDE DE TOUT CE QUI SUIT.
    Cette page annonçait « commande enregistrée » et décrivait la suite
    comme si l'argent était encaissé — sans jamais regarder
    `statut_paiement`. Un client dont le prélèvement avait échoué
    repartait donc convaincu que son colis arrivait.
  */
  $paye     = $commande->statut_paiement === 'encaisse';
  $rembourse = $commande->statut_paiement === 'rembourse';
  $echoue   = $commande->statut_paiement === 'echoue';
  $enCours  = ! $paye && ! $echoue && ! $rembourse;
@endphp

<div class="carte" style="padding:22px;margin:22px 0;border-left:4px solid
     {{ $paye ? 'var(--vert)' : ($echoue ? 'var(--rouge)' : '#a1690f') }}">
  <h1 style="font-size:22px;margin:0 0 6px">
    @if ($paye)      Commande confirmée
    @elseif ($rembourse) Commande remboursée
    @elseif ($echoue) Paiement non abouti
    @else            Paiement en attente
    @endif
  </h1>
  <p style="margin:0;color:var(--gris)">
    Référence <b style="color:var(--texte);font-size:16px">{{ $commande->reference }}</b> —
    notez-la : avec votre téléphone, elle permet de revenir ici depuis
    <a href="{{ route('suivi') }}">« Suivre ma commande »</a>.
  </p>
</div>

@php
  $champ = 'width:100%;padding:9px;border:1px solid var(--bord);border-radius:8px;font:inherit';
  $etiquetteTel = 'display:block;font-size:13px;color:var(--gris)';
@endphp

{{-- REÇU PDF — seulement une fois le paiement encaissé : un reçu pour un
     paiement non abouti attesterait d'un paiement qui n'a pas eu lieu. --}}
@if ($paye)
  <div class="carte" style="padding:14px 16px;margin-bottom:16px">
    @if ($verifiee)
      <a href="{{ route('commande.recu', $commande->reference) }}" class="chip"
         style="display:inline-block;padding:10px 18px">Télécharger le reçu (PDF)</a>
    @else
      <form method="post" action="{{ route('commande.recu', $commande->reference) }}"
            style="display:flex;flex-wrap:wrap;gap:8px;align-items:end">
        @csrf
        <label style="flex:1;min-width:200px">
          <span style="{{ $etiquetteTel }}">Téléphone utilisé pour la commande</span>
          <input name="telephone" inputmode="tel" required value="{{ old('telephone') }}" style="{{ $champ }}">
        </label>
        <button type="submit" class="chip" style="padding:10px 18px">Télécharger le reçu (PDF)</button>
      </form>
    @endif
    <p style="margin:6px 0 0;font-size:12px;color:var(--gris)">Reçu de paiement — il ne vaut pas facture.</p>
  </div>
@endif

{{-- Relance d'un paiement EN ATTENTE uniquement. Une commande dont
     le paiement a échoué ou expiré est annulée et son stock rendu :
     la faire payer encaisserait des articles peut-être déjà revendus
     (CommandeWebController::payer() le refuse aussi). --}}
@if ($echoue)
  <div class="carte" style="padding:16px;margin-bottom:16px;border-left:4px solid var(--brun)">
    <p style="margin:0 0 10px">
      Le paiement n'a pas abouti dans le délai : la commande est annulée et les
      articles ont été remis en vente. Pour les obtenir, ajoutez-les de nouveau au panier.
    </p>
    <a href="{{ route('vitrine') }}" class="chip"
       style="background:var(--brun);color:#fff;border-color:var(--brun);padding:10px 18px;display:inline-block">
      Retour à la boutique
    </a>
  </div>
@elseif ($enCours)
  <div class="carte" style="padding:16px;margin-bottom:16px;border-left:4px solid var(--brun)">
    <p style="margin:0 0 10px">
      Validez la demande de paiement sur votre téléphone. Si vous n'avez rien reçu,
      relancez-la ci-dessous. Sans paiement confirmé sous
      {{ (int) parametre('paiement_delai_expiration_minutes', 30) }} minutes,
      la commande est annulée.
    </p>
    <form method="post" action="{{ route('commande.payer', $commande->reference) }}">
      @csrf
      <button type="submit" class="chip"
              style="background:var(--brun);color:#fff;border-color:var(--brun);padding:10px 18px">
        Relancer la demande de paiement
      </button>
    </form>
  </div>
@endif

{{--
  CE QUI SE PASSE MAINTENANT, EN TROIS PHRASES.
  Un client qui ne sait pas ce qui l'attend rappelle le support le
  lendemain. La différence entre les deux modes de paiement est dite
  ici parce que c'est le moment où elle devient concrète.
--}}
<div class="note">
  <b>Et maintenant ?</b>
  @if ($paye)
    Votre paiement est encaissé. La somme est <b>retenue par Afrishop</b> et ne sera
    versée à la boutique qu'après votre livraison. Un <b>code à 6 chiffres</b> vous
    sera envoyé par SMS à l'expédition : donnez-le au livreur à la remise du colis.
    Vous aurez ensuite <b>{{ (int) parametre('delai_confirmation_auto_jours', 3) * 24 }} heures</b>
    pour vérifier le contenu et, si besoin, signaler un problème sur cette page :
    la boutique n'est payée qu'après ce délai.
  @elseif ($rembourse)
    Cette commande a été annulée après paiement : le montant vous est remboursé
    sur le compte Mobile Money qui a servi à payer.
  @elseif ($echoue)
    Rien ne vous a été prélevé. Tant que le paiement n'aboutit pas, la boutique
    ne prépare pas le colis.
  @else
    La boutique ne préparera votre colis <b>qu'une fois le paiement encaissé</b>.
    Ensuite, la somme est retenue par Afrishop jusqu'à votre livraison.
  @endif
</div>

@php
  $totalArticles = $commande->sousCommandes->sum('montant_articles_ttc_cfa');
  $frais         = (int) $commande->total_frais_livraison_cfa;
@endphp

<h2 style="font-size:17px;margin:24px 0 6px">
  {{ $commande->sousCommandes->count() > 1
       ? $commande->sousCommandes->count().' colis' : 'Votre colis' }}
</h2>

{{-- Une sous-commande = un colis = une boutique. Le dire explicitement
     évite l'appel « je n'ai reçu qu'une partie de ma commande ». --}}
@if ($commande->sousCommandes->count() > 1)
  <p style="color:var(--gris);font-size:14px;margin:0 0 10px">
    Votre commande vient de plusieurs boutiques : les colis arriveront séparément,
    chacun avec son propre délai.
  </p>
@endif

@foreach ($commande->sousCommandes as $sc)
  <div class="carte" style="padding:14px;margin-bottom:10px">
    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px">
      <b>{{ $sc->boutique->emoji }} {{ $sc->boutique->nom }}</b>
      <span style="font-size:13px;color:var(--gris)">{{ $sc->reference }}</span>
    </div>

    <table style="margin:10px 0 0">
      @foreach ($sc->lignes as $l)
        <tr>
          <td>{{ $l->nom_produit }}
            <span style="display:block;font-size:12px;color:var(--gris)">{{ $l->libelle_variante }}</span>
          </td>
          <td style="white-space:nowrap">× {{ $l->quantite }}</td>
          <td style="white-space:nowrap;text-align:right">
            <b>{{ number_format($l->total_ttc_cfa, 0, ',', ' ') }} FCFA</b>
          </td>
        </tr>
      @endforeach
    </table>

    {{-- ANNULATION : tant que la boutique n'a rien préparé. --}}
    @if ($paye && $sc->statut === 'a_preparer' && $sc->etat_fonds->value === 'sequestre')
      <details style="margin-top:12px;padding-top:12px;border-top:1px solid var(--bord);font-size:14px">
        <summary style="cursor:pointer;color:var(--gris)">Annuler ce colis</summary>
        <form method="post" action="{{ route('commande.annuler', [$commande->reference, $sc->reference]) }}"
              style="margin-top:10px;display:grid;gap:8px"
              onsubmit="return confirm('Annuler ce colis ? Vous serez remboursé.')">
          @csrf
              @unless ($verifiee)
              <label>
                <span style="display:block;font-size:13px;color:var(--gris)">Téléphone utilisé pour la commande</span>
                <input name="telephone" inputmode="tel" required value="{{ old('telephone') }}" style="{{ $champ }}">
              </label>
              @endunless
          <p style="margin:0;font-size:12px;color:var(--gris)">
            Possible tant que la boutique n'a pas commencé à préparer le colis. Vous êtes remboursé sur le compte qui a payé.
          </p>
          <div><button type="submit" class="chip" style="padding:10px 18px">Confirmer l'annulation</button></div>
        </form>
      </details>
    @elseif ($sc->statut === 'annulee' && $sc->etat_fonds->value === 'rembourse')
      <p style="margin:12px 0 0;font-size:14px;color:var(--gris)">Colis annulé : vous êtes remboursé.</p>
    @elseif ($sc->statut === 'retournee')
      <p style="margin:12px 0 0;font-size:14px;color:var(--gris)">Article retourné à la boutique : vous êtes remboursé.</p>
    @endif

    {{-- RENVOI DU CODE : le même code, vers le téléphone de la commande —
         jamais vers un numéro saisi ici (CodeRemiseService). --}}
    @if ($paye && $codes->codeEnAttente($sc))
      <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--bord);font-size:14px">
        <p style="margin:0 0 8px">
          @if ($commande->mode_livraison === 'retrait_boutique')
            Votre colis est prêt : présentez en boutique le <b>code reçu par SMS</b>.
          @else
            Votre colis est en route : donnez au livreur le <b>code reçu par SMS</b>, après avoir vérifié le colis.
          @endif
        </p>
        <form method="post" action="{{ route('commande.renvoyer-code', [$commande->reference, $sc->reference]) }}"
              style="display:flex;flex-wrap:wrap;gap:8px;align-items:end">
          @csrf
          @unless ($verifiee)
            <label style="flex:1;min-width:200px">
              <span style="{{ $etiquetteTel }}">Téléphone utilisé pour la commande</span>
              <input name="telephone" inputmode="tel" required value="{{ old('telephone') }}" style="{{ $champ }}">
            </label>
          @endunless
          <button type="submit" class="chip" style="padding:10px 18px">Je n'ai pas reçu mon code — le renvoyer</button>
        </form>
        <p style="margin:6px 0 0;font-size:12px;color:var(--gris)">
          Le code est renvoyé au numéro de la commande, 3 fois par jour au plus.
        </p>
      </div>
    @endif

    {{--
      FENÊTRE DE PROTECTION. Le code prouve que le colis a été remis,
      pas que son contenu est conforme : c'est ici, après ouverture du
      paquet, que le client confirme ou signale. Les deux formulaires
      demandent le téléphone de la commande — la page, elle, s'ouvre
      avec la seule référence.
    --}}
    @php
      $litigeOuvert  = $sc->litiges->first(fn ($l) => $l->estOuvert());
      $litigeTranche = $sc->litiges->sortByDesc('id')->first(fn ($l) => ! $l->estOuvert());
      $fin = $protection->finProtection($sc);
      $retour = $sc->retours->sortByDesc('id')->first();
    @endphp

    @if ($sc->statut === 'livree')
      <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--bord);font-size:14px">
        @if ($litigeOuvert)
          <p style="margin:0;color:#a1690f">
            <b>Signalement {{ $litigeOuvert->reference }} en cours d'examen.</b>
            Le paiement de la boutique est bloqué jusqu'à la décision d'Afrishop.
          </p>
        @elseif ($litigeTranche)
          <p style="margin:0">
            <b>Signalement {{ $litigeTranche->reference }} traité</b>
            — {{ $litigeTranche->statut === 'resolu_client' ? 'en votre faveur : vous êtes remboursé.' : 'en faveur de la boutique.' }}
            @if ($litigeTranche->resolution)
              <span style="display:block;color:var(--gris)">{{ $litigeTranche->resolution }}</span>
            @endif
          </p>
        @elseif ($retour && in_array($retour->statut, ['demande', 'accepte', 'en_transit', 'recu'], true))
          <p style="margin:0;color:#a1690f">
            <b>Retour {{ $retour->reference }}</b> —
            @if ($retour->statut === 'demande')
              en attente de la réponse de la boutique.
            @else
              accepté : rapportez l'article à la boutique{{ $retour->frais_a_la_charge === 'client' ? ' (renvoi à votre charge)' : '' }}.
              Vous serez remboursé dès qu'elle l'aura reçu.
            @endif
          </p>
        @elseif ($sc->confirme_par_client_le)
          <p style="margin:0;color:var(--vert)">
            Vous avez confirmé la bonne réception le {{ $sc->confirme_par_client_le->format('d/m/Y') }}. Merci !
          </p>
        @elseif ($protection->peutAgir($sc))
          <p style="margin:0 0 10px">
            Colis remis le {{ $sc->livre_le->format('d/m/Y à H:i') }}.
            Vérifiez le contenu : vous avez jusqu'au <b>{{ $fin->format('d/m/Y à H:i') }}</b>
            pour signaler un problème. Passé ce délai, la boutique est payée.
          </p>

          <details style="margin-bottom:8px">
            <summary class="chip" style="cursor:pointer;display:inline-block;background:var(--vert);color:#fff;border-color:var(--vert)">
              Tout est conforme
            </summary>
            <form method="post" action="{{ route('commande.confirmer', [$commande->reference, $sc->reference]) }}"
                  style="margin-top:10px;display:flex;flex-wrap:wrap;gap:8px;align-items:end">
              @csrf
              @unless ($verifiee)
              <label style="flex:1;min-width:200px">
                <span style="display:block;font-size:13px;color:var(--gris)">Téléphone utilisé pour la commande</span>
                <input name="telephone" inputmode="tel" required value="{{ old('telephone') }}" style="{{ $champ }}">
              </label>
              @endunless
              <button type="submit" class="chip" style="background:var(--vert);color:#fff;border-color:var(--vert);padding:10px 18px">
                Confirmer la bonne réception
              </button>
            </form>
            <p style="margin:6px 0 0;font-size:12px;color:var(--gris)">
              La boutique est alors payée tout de suite : vous ne pourrez plus signaler de problème sur ce colis.
            </p>
          </details>

          <details @if (old('motif')) open @endif>
            <summary class="chip" style="cursor:pointer;display:inline-block;border-color:var(--rouge);color:var(--rouge)">
              Signaler un problème
            </summary>
            <form method="post" action="{{ route('commande.signaler', [$commande->reference, $sc->reference]) }}"
                  enctype="multipart/form-data" style="margin-top:10px;display:grid;gap:8px">
              @csrf
              @unless ($verifiee)
              <label>
                <span style="display:block;font-size:13px;color:var(--gris)">Téléphone utilisé pour la commande</span>
                <input name="telephone" inputmode="tel" required value="{{ old('telephone') }}" style="{{ $champ }}">
              </label>
              @endunless
              <label>
                <span style="display:block;font-size:13px;color:var(--gris)">Le problème</span>
                <select name="motif" required style="{{ $champ }}">
                  @foreach ([
                    'non_recu'     => "Je n'ai pas reçu le colis",
                    'endommage'    => 'Le produit est endommagé',
                    'non_conforme' => "Ce n'est pas le produit commandé",
                    'incomplet'    => 'Il manque des articles',
                    'contrefacon'  => 'Je soupçonne une contrefaçon',
                    'autre'        => 'Autre problème',
                  ] as $valeur => $libelle)
                    <option value="{{ $valeur }}" @selected(old('motif') === $valeur)>{{ $libelle }}</option>
                  @endforeach
                </select>
              </label>
              <label>
                <span style="display:block;font-size:13px;color:var(--gris)">Ce que vous avez constaté</span>
                <textarea name="description" rows="4" required minlength="20" maxlength="2000" style="{{ $champ }}"
                          placeholder="Ex. : l'écran est fissuré à l'ouverture du carton, la boîte était intacte.">{{ old('description') }}</textarea>
              </label>
              {{-- Facultatif. La photo qui compte dépend du problème :
                   le produit abîmé et son carton, l'étiquette du produit
                   reçu, tout le contenu étalé, le logo ou le numéro de série. --}}
              <label>
                <span style="display:block;font-size:13px;color:var(--gris)">
                  Photos (facultatif, 2 au plus) — le produit et son étiquette ou le carton
                </span>
                <input type="file" name="photos[]" accept="image/*" multiple style="{{ $champ }}"
                       data-photos-litige data-max="{{ \App\Models\Litige::PHOTOS_MAX }}">
                <span data-photos-etat style="display:block;font-size:12px;color:var(--gris);margin-top:4px"></span>
              </label>
              <p style="margin:0;font-size:12px;color:var(--gris)">
                Le paiement de la boutique sera bloqué. Elle donnera sa version, puis Afrishop tranchera.
              </p>
              <div>
                <button type="submit" class="chip" style="background:var(--rouge);color:#fff;border-color:var(--rouge);padding:10px 18px">
                  Envoyer le signalement
                </button>
              </div>
            </form>
          </details>

          @if ($retour?->statut === 'refuse')
            <p style="margin:10px 0 0;font-size:13px;color:var(--gris)">
              Votre demande de retour a été refusée par la boutique : {{ $retour->motif_refus }}.
            </p>
          @elseif (! $retour)
            <details style="margin-top:8px" @if (old('motif_retour')) open @endif>
              <summary class="chip" style="cursor:pointer;display:inline-block">Retourner l'article</summary>
              <form method="post" action="{{ route('commande.retour', [$commande->reference, $sc->reference]) }}"
                    style="margin-top:10px;display:grid;gap:8px">
                @csrf
              @unless ($verifiee)
              <label>
                <span style="display:block;font-size:13px;color:var(--gris)">Téléphone utilisé pour la commande</span>
                <input name="telephone" inputmode="tel" required value="{{ old('telephone') }}" style="{{ $champ }}">
              </label>
              @endunless
                <label>
                  <span style="display:block;font-size:13px;color:var(--gris)">Pourquoi ?</span>
                  <select name="motif" required style="{{ $champ }}">
                    <option value="ne_convient_pas">L'article ne me convient pas</option>
                    <option value="taille_incorrecte">Mauvaise taille</option>
                    <option value="erreur_commande">La boutique m'a envoyé un autre article</option>
                    <option value="autre">Autre raison</option>
                  </select>
                </label>
                <label>
                  <span style="display:block;font-size:13px;color:var(--gris)">Précisions (facultatif)</span>
                  <textarea name="commentaire" rows="2" maxlength="1000" style="{{ $champ }}"></textarea>
                </label>
                <p style="margin:0;font-size:12px;color:var(--gris)">
                  L'article doit être rendu dans l'état reçu. Le renvoi est à votre charge, sauf si la boutique s'est trompée.
                  Vous êtes remboursé quand la boutique l'a reçu. Pour un article cassé ou faux, utilisez plutôt « Signaler un problème ».
                </p>
                <div><button type="submit" class="chip" style="padding:10px 18px">Demander le retour</button></div>
              </form>
            </details>
          @endif
        @elseif ($fin && $fin->isPast())
          <p style="margin:0;color:var(--gris)">
            Délai de vérification écoulé : pour un problème, appelez le service client Afrishop au
            <a href="{{ telephone_support(true) }}" style="font-weight:700;color:var(--brun)">{{ telephone_support() }}</a>.
          </p>
        @endif
      </div>

      {{-- AVIS : un par produit du colis, seulement après la remise. --}}
      @php
        $produitsColis = $sc->lignes->filter(fn ($l) => $l->variante?->produit_id)->unique(fn ($l) => $l->variante->produit_id);
      @endphp
      @foreach ($produitsColis as $l)
        @php $avis = $avisDonnes[$sc->id.'-'.$l->variante->produit_id] ?? null; @endphp
        <div style="margin-top:10px;font-size:14px">
          @if ($avis)
            <p style="margin:0;color:var(--gris)">Votre avis sur « {{ $l->nom_produit }} » :
              <span style="color:var(--brun)">{{ str_repeat('★', $avis->note) }}{{ str_repeat('☆', 5 - $avis->note) }}</span></p>
          @else
            <details>
              <summary style="cursor:pointer;color:var(--brun);font-weight:600">Donner mon avis sur « {{ $l->nom_produit }} »</summary>
              <form method="post" action="{{ route('commande.avis', [$commande->reference, $sc->reference]) }}"
                    style="margin-top:8px;display:grid;gap:8px">
                @csrf
                <input type="hidden" name="produit_id" value="{{ $l->variante->produit_id }}">
              @unless ($verifiee)
              <label>
                <span style="display:block;font-size:13px;color:var(--gris)">Téléphone utilisé pour la commande</span>
                <input name="telephone" inputmode="tel" required value="{{ old('telephone') }}" style="{{ $champ }}">
              </label>
              @endunless
                <fieldset style="border:0;padding:0;margin:0;display:flex;gap:10px;flex-wrap:wrap">
                  <legend style="font-size:13px;color:var(--gris);margin-bottom:4px">Note</legend>
                  @for ($n = 5; $n >= 1; $n--)
                    <label style="cursor:pointer"><input type="radio" name="note" value="{{ $n }}" required>
                      <span style="color:var(--brun)">{{ str_repeat('★', $n) }}</span></label>
                  @endfor
                </fieldset>
                <textarea name="commentaire" rows="2" maxlength="1000" style="{{ $champ }}"
                          placeholder="Qualité, conformité à la photo, délai… (facultatif)"></textarea>
                <div><button type="submit" class="chip" style="padding:9px 16px">Publier mon avis</button></div>
              </form>
            </details>
          @endif
        </div>
      @endforeach
    @endif
  </div>
@endforeach

<div class="carte" style="padding:16px;margin-top:16px">
  <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:6px">
    <span>Articles</span><b>{{ number_format($totalArticles, 0, ',', ' ') }} FCFA</b>
  </div>
  <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:6px">
    <span>Livraison</span><b>{{ number_format($frais, 0, ',', ' ') }} FCFA</b>
  </div>
  <hr style="border:0;border-top:1px solid var(--bord);margin:10px 0">
  <div style="display:flex;justify-content:space-between;font-size:18px">
    <b>Total</b><b>{{ number_format($totalArticles + $frais, 0, ',', ' ') }} FCFA</b>
  </div>
</div>

<p style="margin:22px 0">
  <a href="{{ route('vitrine') }}" class="chip">← Continuer mes achats</a>
</p>

{{--
  RÉDUCTION DES PHOTOS AVANT L'ENVOI.
  Un téléphone produit des photos de 3 à 5 Mo ; les réduire sur le
  serveur ne sert à rien pour le client, qui a déjà payé l'envoi en
  data. On les ramène ici à 1600 px en JPEG (≈ 150 à 300 Ko), assez
  pour lire une étiquette ou voir une fissure.

  Bonus de confidentialité : le JPEG recréé ne contient plus les
  métadonnées EXIF, dont la position GPS du domicile.

  Si le navigateur ne sait pas faire (très vieux Android), la photo
  part telle quelle : le serveur la réduira comme avant. Rien n'est
  bloqué, c'est seulement plus lourd.
--}}
@if ($commande->sousCommandes->contains(fn ($sc) => $protection->peutAgir($sc)))
<script>
(function () {
  var COTE_MAX = 1600, QUALITE = 0.75;

  var ko = function (octets) { return Math.round(octets / 1024) + ' Ko'; };

  function reduire(fichier) {
    return new Promise(function (resolve) {
      if (!/^image\//.test(fichier.type) || !window.HTMLCanvasElement) return resolve(fichier);

      var url = URL.createObjectURL(fichier);
      var img = new Image();
      img.onerror = function () { URL.revokeObjectURL(url); resolve(fichier); };
      img.onload = function () {
        URL.revokeObjectURL(url);
        var ratio = Math.min(1, COTE_MAX / Math.max(img.naturalWidth, img.naturalHeight));
        var canvas = document.createElement('canvas');
        canvas.width = Math.round(img.naturalWidth * ratio);
        canvas.height = Math.round(img.naturalHeight * ratio);
        var ctx = canvas.getContext('2d');
        // Fond blanc : un PNG transparent deviendrait noir en JPEG.
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

        if (!canvas.toBlob) return resolve(fichier);
        canvas.toBlob(function (blob) {
          // On ne garde la version réduite que si elle est plus légère.
          if (!blob || blob.size >= fichier.size) return resolve(fichier);
          var nom = fichier.name.replace(/\.[^.]+$/, '') + '.jpg';
          resolve(new File([blob], nom, { type: 'image/jpeg' }));
        }, 'image/jpeg', QUALITE);
      };
      img.src = url;
    });
  }

  document.querySelectorAll('[data-photos-litige]').forEach(function (champ) {
    var etat = champ.parentNode.querySelector('[data-photos-etat]');
    var bouton = champ.form.querySelector('[type=submit]');
    var max = parseInt(champ.dataset.max, 10) || 2;
    // Sans DataTransfer, impossible de remplacer les fichiers du champ.
    var possible = typeof DataTransfer === 'function';

    champ.addEventListener('change', function () {
      var fichiers = Array.prototype.slice.call(champ.files || []);
      var avertissement = '';

      if (fichiers.length > max) {
        avertissement = 'Seules les ' + max + ' premières photos sont gardées. ';
        fichiers = fichiers.slice(0, max);
      }
      if (!fichiers.length) { etat.textContent = ''; return; }

      if (!possible) {
        var total0 = fichiers.reduce(function (s, f) { return s + f.size; }, 0);
        etat.textContent = avertissement + fichiers.length + ' photo(s) — ' + ko(total0) + ' à envoyer.';
        return;
      }

      // Pendant la réduction, on empêche d'envoyer les originaux.
      bouton.disabled = true;
      etat.textContent = 'Préparation des photos…';

      Promise.all(fichiers.map(reduire)).then(function (reduits) {
        var dt = new DataTransfer();
        reduits.forEach(function (f) { dt.items.add(f); });
        champ.files = dt.files;

        var avant = fichiers.reduce(function (s, f) { return s + f.size; }, 0);
        var apres = reduits.reduce(function (s, f) { return s + f.size; }, 0);
        etat.textContent = avertissement + reduits.length + ' photo(s) prête(s) — ' + ko(apres)
          + (apres < avant ? ' à envoyer au lieu de ' + ko(avant) + '.' : ' à envoyer.');
      }).catch(function () {
        etat.textContent = avertissement;
      }).then(function () {
        bouton.disabled = false;
      });
    });
  });
})();
</script>
@endif

@endsection
