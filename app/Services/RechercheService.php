<?php

namespace App\Services;

use App\Models\RechercheInfructueuse;
use App\Models\TermeRecherche;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 *  RECHERCHE — synonymes, noms locaux, fautes de frappe
 * =====================================================================
 *  LE PROBLÈME, POSÉ CORRECTEMENT.
 *
 *  Le catalogue est écrit dans la langue du vendeur, la recherche tapée
 *  dans celle du client, et ce ne sont pas les mêmes. Au Burkina,
 *  presque personne ne tape « hibiscus » : on tape bissap, oseille, ou
 *  da. Personne ne tape « Vitellaria paradoxa » ni même toujours
 *  « karité » : on tape shea, ou sii.
 *
 *  Une recherche qui ne compare que des chaînes rend « aucun résultat »
 *  sur un produit qui est en rayon. Et le client n'en conclut pas que
 *  son mot était différent : il en conclut que la plateforme est vide.
 *
 *  C'EST LA PANNE LA PLUS COÛTEUSE D'UN CATALOGUE, parce qu'elle est
 *  silencieuse. Elle ne lève aucune exception, ne remplit aucun journal
 *  d'erreur, ne produit aucune réclamation. Elle ne se voit que dans une
 *  courbe de ventes qui ne décolle pas.
 *
 *  QUATRE COUCHES, DE LA MOINS CHÈRE À LA PLUS COÛTEUSE.
 *
 *    1. NORMALISER   minuscules, accents et ponctuation retirés.
 *    2. ÉTENDRE      chaque mot devient sa famille de synonymes.
 *    3. TOLÉRER      distance d'édition, EN DERNIER RECOURS SEULEMENT.
 *    4. RETENIR      toute recherche vide est enregistrée.
 *
 *  LE PIÈGE DE LA COUCHE 3 : appliquer la tolérance aux fautes EN MÊME
 *  TEMPS que la correspondance exacte. Sur « maison », l'exact remonte
 *  les constructions ; le flou y ajoute « Miel de karité », dont la
 *  description contient « saison », à une lettre près. Le client voit du
 *  miel dans une recherche de maçonnerie et cesse de croire aux
 *  résultats. Le flou est un FILET, pas une voie parallèle : on ne le
 *  déroule que si l'exact n'a rien donné. Celui qui écrit correctement
 *  ne doit jamais subir les approximations faites pour celui qui écrit
 *  mal.
 *
 *  LA COUCHE 4 EST LA PLUS RENTABLE. Le journal des recherches
 *  infructueuses fait écrire le dictionnaire par les clients eux-mêmes,
 *  dans leurs mots, plutôt que deviné depuis un bureau. Chaque ligne
 *  traitée rouvre une vente — pour tous les suivants.
 * =====================================================================
 */
class RechercheService
{
    /** Normalisation : ce qui reste est ce que deux personnes pensant au
     *  même objet ont en commun, quelle que soit leur façon de l'écrire.
     *  Le « + » survit, sinon « R+1 » deviendrait « r 1 ». */
    public function normaliser(?string $s): string
    {
        $s = mb_strtolower(trim((string) $s));
        $s = \Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s;
        $s = preg_replace('/\p{Mn}+/u', '', $s);          // diacritiques
        $s = preg_replace('/[^a-z0-9+]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /** @return string[] */
    public function mots(?string $s): array
    {
        return array_values(array_filter(explode(' ', $this->normaliser($s))));
    }

    /**
     * Famille d'un terme : le mot et tous ses équivalents.
     *
     * L'équivalence est SYMÉTRIQUE par construction — on interroge la
     * table dans les deux sens. Déclarer les paires deux fois créerait
     * deux vérités à maintenir, donc tôt ou tard une divergence : un
     * jour « da » trouverait bissap sans que « bissap » trouve da, et
     * personne ne saurait pourquoi.
     *
     * @return string[]
     */
    public function famille(string $mot): array
    {
        $m = $this->normaliser($mot);

        return Cache::remember("recherche.famille.$m", 1800, function () use ($m) {
            $canons = TermeRecherche::where('actif', true)
                ->where(fn ($q) => $q->where(DB::raw('LOWER(canon)'), $m)
                                     ->orWhere(DB::raw('LOWER(variante)'), $m))
                ->pluck('canon')
                ->unique();

            if ($canons->isEmpty()) {
                return [$m];
            }

            $tous = TermeRecherche::where('actif', true)
                ->whereIn('canon', $canons)
                ->get(['canon', 'variante'])
                ->flatMap(fn ($t) => [$t->canon, $t->variante])
                ->map(fn ($x) => $this->normaliser($x))
                ->push($m)
                ->unique()
                ->values()
                ->all();

            return $tous;
        });
    }

    /**
     * Distance de Levenshtein bornée : on s'arrête dès que le seuil est
     * dépassé. Inutile de calculer précisément à quel point deux mots
     * diffèrent quand on sait déjà qu'ils diffèrent trop.
     */
    public function distance(string $a, string $b, int $max = 2): int
    {
        if (abs(strlen($a) - strlen($b)) > $max) {
            return $max + 1;
        }

        $d = levenshtein($a, $b);

        return $d > $max ? $max + 1 : $d;
    }

    /**
     * Recherche complète sur une collection d'offres déjà chargées.
     *
     * Chaque offre doit exposer une méthode motsIndexes() renvoyant
     * l'ENSEMBLE de ses mots — pas la concaténation de son texte. La
     * nuance décide de la qualité : avec une chaîne et strpos, chercher
     * « da » remonte « syscohada », « bazin damassé » et « dawadawa »,
     * qui contiennent tous ces deux lettres quelque part. Comparer des
     * MOTS ENTIERS supprime la classe entière de ces faux positifs.
     */
    public function filtrer(Collection $offres, string $requete): Collection
    {
        $motsQ = $this->mots($requete);

        if (empty($motsQ)) {
            return $offres;
        }

        $strict = $offres->filter(fn ($o) => $this->correspond($o->motsIndexes(), $motsQ, false));

        if ($strict->isNotEmpty()) {
            return $strict->values();
        }

        // Filet de sécurité — et seulement maintenant.
        $flou = $offres->filter(fn ($o) => $this->correspond($o->motsIndexes(), $motsQ, true));

        if ($flou->isEmpty()) {
            $this->journaliser($requete);
        }

        return $flou->values();
    }

    /**
     * TOUS les mots de la requête doivent être satisfaits — chercher
     * « pagne wax » ne doit pas remonter tout ce qui est un pagne.
     * Chaque mot, lui, peut l'être par n'importe quel membre de sa
     * famille.
     *
     * @param  string[]  $index
     * @param  string[]  $motsQ
     */
    private function correspond(array $index, array $motsQ, bool $flou): bool
    {
        $set = array_flip($index);

        foreach ($motsQ as $mot) {
            $trouve = false;

            foreach ($this->famille($mot) as $syn) {
                // Un synonyme en plusieurs mots (« oseille de guinée ») ne
                // compte que si TOUS ses mots significatifs sont présents,
                // sinon « de » suffirait à le déclencher partout.
                $sm = array_filter($this->mots($syn), fn ($x) => strlen($x) >= 3);
                if ($sm && !array_diff($sm, $index)) {
                    $trouve = true;
                    break;
                }
            }

            // Début de mot : « thei » trouve « théière ». Un client tape
            // rarement le mot entier avant de regarder les résultats.
            if (!$trouve && strlen($mot) >= 4) {
                foreach ($index as $t) {
                    if (str_starts_with($t, $mot)) {
                        $trouve = true;
                        break;
                    }
                }
            }

            if (!$trouve && $flou && strlen($mot) >= 5) {
                // Deux fautes à partir de sept lettres, une seule en
                // dessous. « karitay » pour « karité » fait deux
                // corrections : c'est exactement la faute de quelqu'un qui
                // écrit un mot qu'il n'a jamais lu. En dessous de sept
                // lettres, deux corrections rapprochent des mots sans
                // rapport.
                $seuil = strlen($mot) >= 7 ? 2 : 1;
                foreach ($index as $t) {
                    if (strlen($t) >= 5
                        && abs(strlen($t) - strlen($mot)) <= $seuil
                        && $this->distance($mot, $t, $seuil) <= $seuil) {
                        $trouve = true;
                        break;
                    }
                }
            }

            if (!$trouve) {
                return false;
            }
        }

        return true;
    }

    /**
     * Enregistre une recherche sans résultat.
     *
     * Ce n'est pas de la statistique décorative : c'est la file de
     * travail du responsable catalogue, et la seule source fiable de
     * synonymes réellement employés par les clients.
     */
    public function journaliser(string $requete): void
    {
        $n = $this->normaliser($requete);

        if ($n === '' || mb_strlen($n) > 120) {
            return;
        }

        RechercheInfructueuse::updateOrCreate(
            ['terme_normalise' => $n],
            [
                'terme_original' => mb_substr($requete, 0, 160),
                'derniere_fois'  => Carbon::now(),
            ]
        )->increment('occurrences');
    }

    /**
     * Ajoute un synonyme depuis le journal, et marque la ligne traitée.
     * Le cache des familles est vidé : sans cela, le nouveau synonyme
     * resterait sans effet jusqu'à expiration, et l'opérateur croirait
     * que la fonction ne marche pas.
     */
    public function ajouterSynonyme(string $canon, string $variante, string $nature = 'synonyme',
                                    ?string $langue = null): TermeRecherche
    {
        $terme = TermeRecherche::firstOrCreate(
            ['canon' => $this->normaliser($canon), 'variante' => $this->normaliser($variante)],
            ['nature' => $nature, 'langue' => $langue, 'actif' => true]
        );

        RechercheInfructueuse::where('terme_normalise', $this->normaliser($variante))
            ->update(['traitement' => 'synonyme_ajoute']);

        Cache::flush();

        return $terme;
    }

    /**
     * Les termes les plus cherchés en vain. Le tri est par occurrences :
     * un mot tapé cinquante fois vaut cinquante ventes possibles, un mot
     * tapé une fois vaut une faute de frappe.
     */
    public function aTraiter(int $limite = 50)
    {
        return RechercheInfructueuse::where('traitement', 'a_traiter')
            ->orderByDesc('occurrences')
            ->limit($limite)
            ->get();
    }
}
