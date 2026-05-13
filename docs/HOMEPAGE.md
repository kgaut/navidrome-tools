# Widget Homepage (gethomepage.dev)

Endpoint JSON `/api/status` consommable par le widget
[Custom API](https://gethomepage.dev/widgets/services/customapi/) de
[Homepage](https://gethomepage.dev/). Sert aussi de healthcheck Docker.

## Deux modes d'accès

| Sans token (no-auth)                                     | Avec token (`HOMEPAGE_API_TOKEN`)                                  |
|----------------------------------------------------------|--------------------------------------------------------------------|
| `GET /api/status`                                        | `GET /api/status?token=…` ou `Authorization: Bearer …`             |
| Payload minimal `{status, navidrome_db}`                 | Payload enrichi : compteurs, dernier run, statut conteneur         |
| Codes HTTP 200 (ok) / 503 (degraded) — Docker friendly   | Code HTTP 200 ; 401 si token erroné, 404 si feature désactivée     |

Le mode no-auth reste toujours dispo : c'est ce qui permet de l'utiliser
comme `healthcheck` Docker sans exposer de secret. Le mode enrichi est
opt-in via `HOMEPAGE_API_TOKEN`.

## Activer le mode enrichi

Générer un token et l'injecter dans l'environnement du conteneur :

```bash
openssl rand -hex 32       # copier dans HOMEPAGE_API_TOKEN
```

Puis configurer le widget Homepage (`services.yaml`) :

```yaml
- Navidrome Tools:
    icon: navidrome.png
    href: https://navidrome-tools.example.com
    widget:
      type: customapi
      url: https://navidrome-tools.example.com/api/status?token={{HOMEPAGE_VAR_NAVIDROME_TOOLS_TOKEN}}
      refreshInterval: 60000
      mappings:
        - field: scrobbles_total
          label: Scrobbles
          format: number
        - field: unmatched_total
          label: Unmatched
          format: number
        - field: { last_run: status }
          label: Dernier run
        - field: { last_run: started_at }
          label: À
          format: relativeDate
```

Et déclarer la variable Homepage côté docker-compose :

```yaml
environment:
  HOMEPAGE_VAR_NAVIDROME_TOOLS_TOKEN: ${HOMEPAGE_VAR_NAVIDROME_TOOLS_TOKEN}
```

## Payload enrichi

```json
{
  "status": "ok",
  "navidrome_db": true,
  "scrobbles_total": 142387,
  "unmatched_total": 312,
  "missing_mbid": 47,
  "navidrome_container": "running",
  "last_run": {
    "type": "lastfm-import",
    "reference": "me",
    "label": "Last.fm import (me)",
    "status": "success",
    "started_at": "2026-05-03T08:00:01+00:00",
    "finished_at": "2026-05-03T08:03:05+00:00",
    "duration_ms": 184230
  }
}
```

- `status` — `ok` / `degraded`.
- `navidrome_db` — booléen, accès à la SQLite Navidrome.
- `navidrome_container` — enum `ContainerStatus` :
  `disabled` / `running` / `stopped` / `notfound` / `unknown`.
- `last_run` — null quand `run_history` est vide.

## Healthcheck Docker

Sans `HOMEPAGE_API_TOKEN`, l'endpoint reste un healthcheck pratique :

```yaml
healthcheck:
  test: ["CMD", "curl", "-fsS", "http://localhost:8080/api/status"]
  interval: 30s
  timeout: 5s
  retries: 3
```

Le payload no-auth reste minimal (`{status, navidrome_db}`), donc
aucune information sensible n'est exposée même si l'endpoint est
joignable depuis l'extérieur.
