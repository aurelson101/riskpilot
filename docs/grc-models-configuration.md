# Modèles et configurations GRC

Les exemples sont fictifs et sans secrets utilisables. Deux niveaux sont séparés :
les configurations reconnues par le code actuel et les modèles de conception
qui nécessitent encore une implémentation. Aucun fichier de cette page n'est
automatiquement importé ou appliqué.

## Configurations disponibles aujourd'hui

### Installation et secrets

Partir de [`.env.example`](../.env.example), pas d'un fichier Compose d'un autre
produit. Pour une installation locale :

```dotenv
APP_ENV=dev
APP_URL=http://localhost:8080
MAILER_DSN=smtp://mailpit:1025
VITE_API_URL=/api
```

En production, `APP_URL` doit être l'URL HTTPS exacte. Générer les secrets hors
Git et conserver les credentials dans un stockage protégé. Ne pas remplacer
`APP_SECRET` sur une installation existante sans migrer les données chiffrées.
Mailpit est destiné au développement. Les réglages de messagerie par organisation
se saisissent dans **Paramètres → Messagerie**, pas dans un fichier public.

### Messagerie : OAuth distinct du SSO

| Champ / usage | Microsoft 365 | Google Workspace |
| --- | --- | --- |
| `provider` de messagerie | `MICROSOFT_365` | `GOOGLE_WORKSPACE` |
| Identifiants saisis dans l'interface | `oauthClientId`, `oauthClientSecret` | `oauthClientId`, `oauthClientSecret` |
| `oauthTenant` | UUID du tenant recommandé ; `organizations` accepté | Sans objet |
| Permissions demandées par le code | `openid email offline_access User.Read Mail.Send` | `openid email https://www.googleapis.com/auth/gmail.send` |
| Callback relatif à `APP_URL` | `/api/settings/email/oauth/microsoft_365/callback` | `/api/settings/email/oauth/google_workspace/callback` |
| Envoi | Graph `/v1.0/me/sendMail` | Gmail `/gmail/v1/users/me/messages/send` |

Le code utilise un compte connecté et des droits délégués : pas d'envoi Graph
app-only, de boîte partagée dédiée ou de synchronisation d'agenda validés ici.
Enregistrer, connecter le compte, puis envoyer un test autorisé et contrôler sa
réception. Une configuration enregistrée seule ne prouve pas son fonctionnement.
Les échecs d'envoi du test distinguent `OAUTH_MAIL_SEND_FAILED` de
`SMTP_CONNECTION_FAILED`, avec un message FR/EN sans détails techniques sensibles.

Pour SMTP personnalisé, utiliser `provider=CUSTOM`, `host`, `port`,
`encryption=tls` (STARTTLS) ou `ssl` (TLS implicite), `username`, `senderEmail`,
`senderName` et éventuellement `replyTo`. Saisir `password` uniquement dans
l'interface sécurisée. Confirmer les paramètres avec le fournisseur ; l'activation
et le test refusent le transport non chiffré. Le preset SMTP2GO fixe le port 587.

### Clé de service : exemple de corps API

`POST /api/v1/integrations`, avec session administrateur autorisée :

```json
{
  "type": "API_KEY",
  "provider": "GENERIC",
  "name": "Lecture GRC de preproduction",
  "enabled": true,
  "expiresInDays": 30,
  "configuration": {
    "scopes": ["risks:read", "controls:read", "actions:read"]
  }
}
```

La clé retournée une seule fois s'utilise dans `X-RiskPilot-Key` pour les listes
`/api/v1/service/risks`, `/controls` et `/actions`. La conserver hors documentation
et journaux ; rotation/révocation disponibles dans l'administration. Cet exemple
n'accorde aucun droit d'écriture et ne configure ni Graph ni une connexion SSO.

### Annuaire et fournisseur open source

Pour le diagnostic LDAPS, saisir un compte de lecture dédié et :

- `host=ldaps://ad.example.com`, `port=636` ;
- `baseDn=DC=example,DC=com`, `bindDn=CN=svc-riskpilot,OU=Service,DC=example,DC=com` ;
- `userFilter=(&(objectClass=user)(sAMAccountName={username}))` ;
- `testUsername` correspondant à un utilisateur de test ;
- `groupMappings`, par exemple
  `{"CN=RiskPilotReaders,OU=Groups,DC=example,DC=com":"ROLE_VIEWER"}`, et,
  si nécessaire, `caCertificate` contenant la CA PEM approuvée.

Le mot de passe de bind doit être saisi comme credential protégé. Le test effectue
un bind et une recherche avec certificat exigé et filtre échappé ; il ne connecte
pas les utilisateurs et ne synchronise pas leur appartenance aux groupes.
La syntaxe des filtres dépend de l'annuaire ; ne pas appliquer le filtre AD
tel quel à OpenLDAP sans adaptation.

Pour Keycloak/Authentik, conserver une fiche de préparation : issuer HTTPS du
realm/tenant, client, callback prévu, claims et mapping de groupes, rotation des
clés et politique de session. **Ce n'est pas une configuration activable aujourd'hui** :
le diagnostic OIDC actuel accepte les émetteurs Google/Entra prévus par le code,
et aucun parcours SSO complet n'est raccordé. Garder un accès administrateur
local de secours lors de futurs tests d'identité.

## Modèles proposés — non importables aujourd'hui

### Bibliothèque portable originale

Contrat de conception inspiré des principes publics de bibliothèques versionnées,
sans copie de catalogue tiers. Ce JSON n'est pas un schéma d'import existant :

```json
{
  "schemaVersion": "proposal-1",
  "id": "urn:riskpilot:library:example-access",
  "version": "1.0.0",
  "source": "Politique interne fictive",
  "rights": "A definir avant diffusion",
  "translations": {
    "fr": {"name": "Acces privilegies"},
    "en": {"name": "Privileged access"}
  },
  "requirements": [{
    "id": "urn:riskpilot:requirement:example-access:1",
    "ref": "AC-1",
    "assessable": true,
    "parentId": null,
    "translations": {
      "fr": {"name": "Revoir les droits administrateur"},
      "en": {"name": "Review administrator permissions"}
    }
  }],
  "mappings": []
}
```

L'adaptateur futur devra résoudre ces identifiants vers les IDs locaux du tenant,
valider les relations, refuser les références inconnues, prévisualiser les changements
et préserver les évaluations historiques. Un mapping proposé comporte source,
cible, direction, relation (`equal`, `subset`, `superset`, `intersect`), justification
et approbateur ; ces types ne sont pas encore des champs de `RequirementMapping`.
Ne pas transformer une correspondance en preuve automatique de conformité.

### Fiches métier et parcours simple

- **Risque** : périmètre, actif, menace, scénario, responsable, cotation brute/
  actuelle/résiduelle, contrôles, preuves, traitement, acceptation et prochaine revue.
- **Exigence/contrôle** : référence/source, applicabilité motivée, mesure,
  test d'efficacité, preuve versionnée, résultat, action et validation indépendante.
- **Collecte** : destinataire, périmètre accessible, questions FR/EN, échéance,
  relance, expiration et validation des réponses avant modification métier.

Ces fiches réutilisent les entités existantes. L'interface cible doit guider
« périmètre → analyse → preuve → action → décision » sans exiger du JSON métier.
L'IA prépare des champs éditables ; la confirmation humaine reste obligatoire.

### Modèle de notification FR/EN à implémenter

Les emails de reset et de test FR/EN sont déjà centralisés dans
`EmailTemplateRenderer` ; le reset suit la langue du destinataire, le test celle
de l'administrateur. Le modèle ci-dessous concerne les notifications métier dont
le texte reste français ; seul le libellé du lien ajouté est déjà bilingue.
L'habillage HTML échappé est commun à tous les envois via `OrganizationMailer` :
SMTP/Gmail conservent une alternative texte, Graph reçoit le HTML. La largeur
fluide et les styles intégrés sont testés structurellement, pas encore dans les
clients Thunderbird/Outlook/Gmail réels.

| Élément | Français | English |
| --- | --- | --- |
| Sujet | `[RiskPilot] Action à traiter : {title}` | `[RiskPilot] Action to complete: {title}` |
| Corps | `Bonjour {firstName}, votre action arrive à échéance le {dueDate}.` | `Hello {firstName}, your action is due on {dueDate}.` |
| Action | `Consulter mon action` | `View my action` |

Sélectionner la langue du destinataire, localiser la date et utiliser une URL
interne approuvée. Échapper les variables HTML et conserver une alternative texte.
Ne jamais inclure de mot de passe, clé API ou détails sensibles inutiles. Le reset
nécessite un modèle distinct avec son lien à usage unique ; ne pas réutiliser celui
d'une notification d'action.

## Validation avant activation

La boîte d'envoi borne les tentatives à cinq (`DEAD_LETTER` au plafond) et annule
les destinataires inactifs/verrouillés ou d'une autre organisation (`CANCELLED`).
Les nouveaux échecs conservent un code générique, pas le texte fournisseur.
Ces états ne sont pas repris automatiquement ; une supervision et un parcours
administrateur de reprise restent à concevoir. Les lignes `DISPATCHED` bloquées
ne disposent pas encore d'une récupération automatique.

Tester sur préproduction : refus inter-tenant/RBAC, import en simulation,
versionnement sans perte, réception FR/EN, échecs/reprises de file et révocation
des abonnements `.ics`. Pour l'agenda, consigner client/version, import ou abonnement,
actualisation et résultat : Thunderbird, Outlook web/classique, Google Calendar.
Ne pas conclure à une synchronisation bidirectionnelle ni à une compatibilité
universelle à partir d'un téléchargement `.ics` réussi.
