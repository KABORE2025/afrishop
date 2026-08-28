<?php

namespace App\Http\Requests;

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
        ]);
    }
}
