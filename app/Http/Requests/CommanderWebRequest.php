<?php

namespace App\Http\Requests;

use App\Models\Ville;
use App\Services\PanierSession;

/**
 * =====================================================================
 *  VALIDATION DU TUNNEL DE COMMANDE WEB
 * =====================================================================
 *  POURQUOI CETTE CLASSE EXISTE.
 *
 *  `CreerCommandeRequest` exige un tableau `articles` : c'est correct
 *  pour l'API, où le client envoie son panier dans le corps de la
 *  requête. Sur le web, le panier vit dans la SESSION — le formulaire
 *  de commande n'a aucune raison de le renvoyer, et le renvoyer serait
 *  même dangereux : un champ caché contenant les articles se modifie
 *  dans le navigateur.
 *
 *  Résultat de ce décalage : la validation échouait sur
 *  « articles.required » avec le message « Le panier est vide. », alors
 *  que le panier était plein. La commande ne pouvait jamais aboutir.
 *
 *  Cette classe hérite de toutes les règles de `CreerCommandeRequest`
 *  et se contente d'injecter les articles depuis la session AVANT la
 *  validation. Une seule définition des règles, deux sources d'entrée.
 * =====================================================================
 */
class CommanderWebRequest extends CreerCommandeRequest
{
    /**
     * Les articles viennent de la session, jamais du formulaire.
     *
     * `merge()` écrase délibérément tout `articles` qui serait présent
     * dans la requête : la session fait autorité. Sans cet écrasement,
     * un champ ajouté à la main dans le navigateur permettrait de
     * commander un article jamais mis au panier — donc à un prix ou une
     * quantité choisis par l'acheteur.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'articles' => app(PanierSession::class)->articlesPourCommande(),

            /*
             * LE PAYS SE DÉDUIT DE LA VILLE, il n'est plus demandé.
             *
             * Le formulaire posait les deux questions séparément, et
             * rien n'empêchait de répondre « Burkina Faso » puis
             * « Abidjan ». La zone de livraison est cherchée sur le
             * COUPLE pays + ville : le couple incohérent n'en trouvait
             * aucune, et le client lisait « Aucune livraison n'est
             * assurée à cette adresse » sans comprendre pourquoi.
             *
             * Une seule question, une seule réponse, aucune
             * contradiction possible. `Ville::find()` plutôt que
             * `findOrFail()` : un `ville_id` inventé doit produire un
             * message de validation propre, pas une erreur 404.
             */
            'pays_id' => Ville::find($this->input('ville_id'))?->pays_id,
        ]);
    }

    /**
     * La ville devient OBLIGATOIRE.
     *
     * Elle était facultative, et l'écran offrait même « — Autre
     * ville — ». Sans ville, aucune zone de livraison ne peut être
     * trouvée : l'option ne pouvait donc mener qu'à un refus.
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'ville_id' => ['required', 'integer', 'exists:villes,id'],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'ville_id.required' => 'Choisissez votre ville de livraison.',
            'pays_id.required' => "Cette ville n'est pas reconnue. Choisissez-en une dans la liste.",
        ]);
    }
}
