<?php

use App\Models\Parametre;
use Illuminate\Support\Facades\Cache;

if (! function_exists('telephone_support')) {
    /**
     * Numéro du service client, lisible : « +226 76 92 12 02 ».
     * Avec $lien = true : la forme « tel:+22676921202 » d'un lien cliquable,
     * qui lance l'appel d'un toucher sur téléphone.
     */
    function telephone_support(bool $lien = false): string
    {
        $chiffres = preg_replace('/\D/', '', (string) config('afrishop.support.telephone'));

        if ($lien) {
            return 'tel:+'.$chiffres;
        }

        // Indicatif ouest-africain à 3 chiffres, puis le numéro par paires.
        $indicatif = substr($chiffres, 0, 3);
        $numero = trim(chunk_split(substr($chiffres, 3), 2, ' '));

        return "+{$indicatif} {$numero}";
    }
}

if (! function_exists('parametre')) {
    /**
     * Lit un paramètre métier, avec repli sur la valeur par défaut.
     *
     * Les règles négociables (délai de confirmation, commission,
     * plafonds) vivent en base et non dans le code : les changer ne
     * doit jamais demander de redéployer. Le cache évite une requête
     * par appel — ces valeurs changent quelques fois par an.
     */
    function parametre(string $cle, mixed $defaut = null, ?int $paysId = null): mixed
    {
        return Cache::remember("parametre:{$cle}:{$paysId}", 3600, function () use ($cle, $defaut, $paysId) {
            $valeur = Parametre::where('cle', $cle)
                ->where(fn ($q) => $q->where('pays_id', $paysId)->orWhereNull('pays_id'))
                ->orderByRaw('pays_id IS NULL')   // la valeur du pays prime sur le défaut
                ->value('valeur');

            return $valeur ?? $defaut;
        });
    }
}
