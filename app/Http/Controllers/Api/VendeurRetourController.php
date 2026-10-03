<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Retour;
use App\Services\RetourService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Les retours demandés par les clients, traités par la boutique. Voir RetourService. */
class VendeurRetourController extends Controller
{
    public function __construct(private RetourService $retours) {}

    public function accepter(Request $r, Retour $retour): JsonResponse
    {
        $this->verifierProprietaire($r, $retour);

        return $this->executer(fn () => $this->retours->accepter($retour, $r->user()->id),
            'Retour accepté. Le client va rapporter l’article : confirmez sa réception quand vous l’avez en main.');
    }

    public function refuser(Request $r, Retour $retour): JsonResponse
    {
        $this->verifierProprietaire($r, $retour);
        $donnees = $r->validate(['motif' => ['required', 'string', 'min:10', 'max:1000']]);

        return $this->executer(fn () => $this->retours->refuser($retour, $donnees['motif'], $r->user()->id),
            'Retour refusé. Le client peut contester auprès du service client Afrishop.');
    }

    public function recevoir(Request $r, Retour $retour): JsonResponse
    {
        $this->verifierProprietaire($r, $retour);

        return $this->executer(fn () => $this->retours->recevoir($retour, $r->user()->id),
            'Article reçu : le client est remboursé et le stock est rendu.');
    }

    private function verifierProprietaire(Request $r, Retour $retour): void
    {
        $boutique = $r->user()?->boutique;
        abort_unless($boutique && $retour->sousCommande?->boutique_id === $boutique->id, 403,
            'Ce retour ne concerne pas votre boutique.');
    }

    private function executer(callable $action, string $message): JsonResponse
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => $message]);
    }
}
