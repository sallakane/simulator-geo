# Donnée RGA — relevé du millésime

> À remplir **avant** tout développement, par l'inspection du shapefile
> (SPEC §4.1). Ne rien supposer : c'est ce document qui permettra de rejouer le
> chargement au prochain zonage.

## Source

- Géorisques — « Retrait gonflement des argiles, carte d'exposition (2026) »,
  arrêté du 9 janvier 2026.
- Licence Etalab 2.0 — réutilisation libre, **mention de la source obligatoire**
  et visible dans le widget (SPEC §15).
- URL de téléchargement : *à renseigner*
- Somme de contrôle (`sha256sum`) du fichier récupéré : *à renseigner*

La donnée n'est pas versionnée (plusieurs centaines de Mo) : elle se dépose
dans `data/`, gitignoré.

## Relevé (`make ogrinfo`)

| Élément | Valeur relevée |
|---|---|
| Fichier | *à renseigner* |
| SRID annoncé | *à renseigner* (attendu : EPSG:2154, Lambert 93) |
| Encodage du `.dbf` | *à renseigner* (hypothèse par défaut : LATIN1) |
| Champ du niveau d'exposition | *à renseigner* |
| Valeurs distinctes exactes (casse comprise) | *à renseigner* |
| Nombre de polygones | *à renseigner* |

Le mapping « valeur source → `niveau_code` » qui en découle vit dans
`migrations/rga/<millesime>-niveaux.sql`, pas dans le code applicatif.

## Périmètre à vérifier au chargement (SPEC §3)

- **Paris intra-muros** : aucun polygone attendu. Ce n'est pas une erreur, c'est
  un cas fonctionnel avec son propre message.
- **Corse** : couverture à confirmer.
- **DOM-TOM** : hors périmètre.

`bin/charger-rga.sh` teste les deux premiers points à chaque chargement.
