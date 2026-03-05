# Checklist tests manuels + logs

## Pages / flows à tester

| Page / flow | Actions | Résultat attendu |
|-------------|---------|------------------|
| **Login** | Connexion avec identifiants valides | Redirection dashboard |
| **Paiements** | Ouvrir modal "Enregistrer un paiement", remplir, soumettre | Paiement enregistré, message succès |
| **Paiements (CSRF)** | Tenter POST sans token (curl/Postman) | 403 Token CSRF invalide |
| **Facture PDF** | Ouvrir `/public/view_facture.php?id=1` (ID valide) | PDF affiché ou régénéré |
| **Messagerie** | Envoyer un message avec image | Message + image affichés |
| **Messagerie recherche** | Rechercher SAV ou livraisons dans la messagerie | Résultats affichés (pas 500) |
| **Profil** | Rechercher un utilisateur (champ recherche) | Liste filtrée (pas 500) |
| **Maps** | Calculer un itinéraire | Tracé affiché (appel direct OSRM) |

## Logs PHP à surveiller

| Fichier / source | À vérifier |
|------------------|------------|
| `error_log` (PHP) | Pas de `Undefined variable: pdo` |
| | Pas de `Token CSRF invalide` pour requêtes légitimes |
| | Pas de SQL/params en clair (si `DEBUG_MODE` activé dans historique.php) |
| `view_facture.php` | `Fichier PDF non trouvé, régénération automatique` = comportement normal si PDF absent |
| `paiements_enregistrer.php` | Pas d’erreur 403 sur enregistrement normal |

## Commandes de vérification

```bash
# Syntaxe PHP (si PHP en PATH)
php -l public/view_facture.php
php -l API/paiements_enregistrer.php
php -l API/messagerie_search_sav.php

# Test CSRF paiement (doit retourner 403)
curl -X POST "https://votre-domaine/API/paiements_enregistrer.php" \
  -H "Cookie: cc_sess=..." \
  -F "facture_id=1" -F "montant=100" -F "date_paiement=2025-03-05" -F "mode_paiement=cb"
# Attendu: {"ok":false,"error":"Token CSRF invalide"}
```
