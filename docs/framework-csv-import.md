# Importer un référentiel CSV

Dans **Conformité → Référentiels → Importer un référentiel CSV**, télécharger le
modèle, renseigner un nom et une version, puis choisir le fichier. La
prévisualisation ne crée aucune donnée. La confirmation crée un nouveau
référentiel et ses exigences dans une transaction.

Ce catalogue est partagé, comme les référentiels existants : l'import est réservé
aux administrateurs. Il ne crée pas une évaluation de conformité et ne copie pas
de preuve. Importer uniquement les textes dont la réutilisation est autorisée ;
aucun catalogue propriétaire EGERIE ni texte de norme ISO n'est fourni.

## Format

CSV UTF-8, avec ou sans BOM, séparateur virgule, au plus 1 Mio et 500 exigences.
En-tête exact et cinq colonnes :

```csv
reference,title,category,description,parentReference
A,Gestion des accès,Sécurité,,
A.1,Revoir les habilitations,Sécurité,,A
```

Les références sont uniques et conservées comme du texte. Le nom du parent peut
désigner une ligne située plus loin dans le fichier. Les champs contenant des
virgules ou des sauts de ligne doivent être entourés de guillemets CSV.
Les parents absents, les boucles et les champs vides ou trop longs sont refusés.
Le fichier n'est pas sauvegardé sur disque.

## API

- `POST /api/frameworks/import/preview` : JSON avec `name`, `version`, `csv` ;
  paramètres optionnels du référentiel : `description`, `publisher`, `status`.
  Retourne `count`, `requirements` et une empreinte `checksum`.
- `POST /api/frameworks/import/confirm` : même contenu avec `checksum`.
  Retourne `id` et `count` (201). Toute modification du contenu validé impose
  une nouvelle empreinte (422).
- Une version déjà présente renvoie 409, sans modification. Les imports de la
  même version sont sérialisés par verrou transactionnel PostgreSQL.

Le checksum contrôle la cohérence du contenu, pas l'identité de l'utilisateur ni
une signature serveur. L'autorisation administrateur s'applique aux deux routes.
Cette première version ne prend pas en charge YAML, XLSX, la mise à jour d'un
référentiel existant, les traductions de bibliothèque ou les mappings externes.
