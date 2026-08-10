# Exemple complet — générer un lot d'étiquettes

## 1. Marquer le produit comme traçable

Sur la fiche produit, cocher `tracable`. Sans cela, la génération est refusée :
on ne colle pas de date de péremption sur un panier en osier.

## 2. Créer le lot

```http
POST /api/admin/lots-qr
Authorization: Bearer <jeton administrateur>

{
  "produit_id":       1,
  "date_fabrication": "2026-08-02",
  "date_expiration":  "2028-08-02",
  "fabricant":        "Coopérative Wend-Panga — Ouagadougou",
  "numero_debut":     26,
  "quantite":         275,
  "description":      "Beurre de karité brut 500 g. À conserver à l'abri de la chaleur."
}
```

Réponse :

```json
{
  "reference":    "LOT-2026-0004",
  "quantite":     275,
  "premier_code": "02/08/2026/0026",
  "dernier_code": "02/08/2026/0300",
  "url_planche":  "https://afrishop.bf/api/admin/lots-qr/4/planche"
}
```

## 3. Ce qui est créé en base

| code_lisible      | jeton (dans le QR) | statut  |
|-------------------|--------------------|---------|
| 02/08/2026/0026   | `HRCCNTG6F69X`     | genere  |
| 02/08/2026/0027   | `UQPQBT3XPSV6`     | genere  |
| 02/08/2026/0028   | `9574UCN98QGH`     | genere  |
| …                 | …                  | …       |
| 02/08/2026/0300   | `RKCEWMER3NY3`     | genere  |

Les numéros se suivent. **Les jetons n'ont aucun lien entre eux** — c'est
tout l'intérêt : connaître le jeton de la 0026 n'apprend rien sur celui
de la 0027.

## 4. Imprimer

`GET /api/admin/lots-qr/4/planche` renvoie une page HTML calée sur des
planches d'étiquettes autocollantes A4 (44 par feuille). Le
téléchargement fait basculer le lot en statut `imprime`.

## 5. Le lot suivant

Omettre `numero_debut` : le système reprend automatiquement à **0301**.

## 6. En cas de problème

```http
POST /api/admin/lots-qr/4/rappel
{ "motif": "Contamination détectée sur le lot de matière première du 28/07." }
```

Toutes les étiquettes du lot, y compris celles déjà chez des clients,
afficheront un avertissement rouge au prochain scan. C'est le seul moyen
de joindre des acheteurs dont on ne connaît pas l'identité.
