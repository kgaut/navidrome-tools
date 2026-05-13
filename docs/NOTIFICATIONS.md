# Notifications de fin de run

À chaque fin de traitement enveloppé par `RunHistoryRecorder` (backup
DB Navidrome, fetch Last.fm, process buffer, rematch, sync loved,
compute stats, purge history…), l'app peut pousser une notification
vers un ou plusieurs canaux. Le payload inclut le type du run, le
label humain, le status (`success` / `error`), la durée, les
métriques et, en cas d'échec, le message d'erreur tronqué.

> Configuration : voir [`ENVIRONMENT.md`](ENVIRONMENT.md) (variables
> `NOTIFY_*`).
> Page de test sur `/settings`.

## Canaux supportés

- **Gotify** — instance self-hosted, POST sur `${URL}/message?token=…`.
- **Slack** — webhook incoming (`https://hooks.slack.com/services/…`).
- **Discord** — webhook channel (Server Settings → Integrations).
- **Pushover** — token applicatif + user/group key
  ([pushover.net](https://pushover.net/)).

CSV dans `NOTIFY_DRIVERS` pour activer plusieurs canaux en broadcast
(ex. `gotify,slack`).

## Filtre `NOTIFY_ON`

- `error` (défaut) — n'envoie que les échecs (status `error` sur le
  `RunHistory`).
- `all` — notifie aussi les succès. Utile pour valider un nouveau
  pipeline d'observabilité ou suivre les durées d'un job en
  production ; déconseillé sur un cron qui tourne toutes les 15 min
  (spam).

## Tester depuis l'UI

La page **`/settings`** expose une card « Notifications » qui :

- liste chaque driver enregistré avec un ✓/— pour « listé dans
  `NOTIFY_DRIVERS` » et « configuré (credentials OK) » ;
- affiche le filtre `NOTIFY_ON` courant ;
- propose deux boutons « Envoyer un test (succès) » / « Envoyer un
  test (erreur) » qui dispatchent immédiatement en **bypassant**
  `NOTIFY_ON` (sinon le test « succès » ne partirait pas quand le
  filtre vaut `error`) ;
- remonte en flash multi-types le détail par driver (`sent`,
  `skipped:not-listed`, `skipped:not-configured`, `error:<message>`)
  — pratique pour repérer un canal mal configuré sans aller fouiller
  les logs.

## Isolation des drivers

L'orchestrateur catche chaque appel à `send()` : une erreur de
transport sur un canal est loggée via PSR-3 mais n'empêche pas les
autres canaux de partir, **et n'interrompt jamais le job lui-même**.
Un cron casse-cou peut quand même se brancher sur Pushover sans
risquer d'aborter un import Last.fm si Pushover répond 502.

## Étendre — ajouter un nouveau canal

Créer une classe dans `src/Notifier/Driver/` qui implémente
`App\Notifier\NotifierDriverInterface` :

```php
public function getName(): string;        // 'ntfy', 'telegram', …
public function isConfigured(): bool;     // crédits présents ?
public function send(Notification $n): void;
```

Le `_instanceof` dans `config/services.yaml` la tag automatiquement
`app.notifier_driver`, donc il suffit de :

1. Déclarer les paramètres de configuration du driver dans
   `services.yaml` (URL, token, etc.) ;
2. Ajouter les env vars correspondantes dans
   [les 5 endroits habituels](ENVIRONMENT.md#ajouter-une-nouvelle-variable) ;
3. L'activer en l'ajoutant à `NOTIFY_DRIVERS`.

Aucun changement nécessaire dans `RunHistoryRecorder` ou
`Notifier::notify()` — le tag fait le reste.

## Payload envoyé

Pour chaque driver, le `Notification` DTO expose :

- `type` — type du run (`lastfm-fetch`, `lastfm-process`, `playlist`,
  `stats`, …).
- `label` — libellé humain (ex. `Last.fm fetch — me`).
- `status` — `success` / `error`.
- `durationMs` — durée du job, formatée en `1m15s` / `750ms` / `2.5s`.
- `errorMessage` — null sur succès, message d'erreur tronqué à 500
  caractères sur échec.
- `metrics` — paires clé-valeur (ex. `considered=100 inserted=42`).
- `runId` — id du `RunHistory`, peut servir à construire un deep link
  vers `/history/{id}` dans un driver custom.

Le titre généré (`title()`) préfixe par `[OK]` ou `[ERROR]` selon le
status — facile à filtrer côté side rules Slack ou Gotify.
