# Changelog

Toutes les évolutions notables de ce projet sont documentées ici.

Le format s'appuie sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/)
et le projet suit le [versionnage sémantique](https://semver.org/lang/fr/).

## [Non publié]

<!-- Ajouter ici les changements de la prochaine version, sous Ajouté / Modifié / Corrigé / Supprimé. -->

## [1.10.0] - 2026-11-03

### Ajouté

- **Remise d'aplomb des cibles des écoutes écrites** (issue #291). En prod, 85 %
  des `target_id` de `scrobble_sync` visent des morceaux disparus de Navidrome
  (identifiants changés depuis l'écriture). La commande
  `app:scrobbles:repair-targets` (`--dry-run`) et l'outil MCP
  `matched_targets_repair` (`dry_run` par défaut) font de nouveau pointer
  chaque écoute vers le morceau sous lequel Navidrome la garde, retrouvé à la
  même seconde. Une écoute sans correspondance, ou avec plusieurs morceaux
  différents à cette seconde, est laissée et comptée. Navidrome n'est que lu,
  seul `data.db` est écrit, sans arrêt du conteneur. C'est un préalable à
  `relink` (#285), qui laisse ces écoutes de côté.

### Corrigé

- **Le matching servait des morceaux disparus de la bibliothèque** (issue #291).
  Une entrée positive du cache, ou un alias, dont le morceau n'existe plus
  (identifiants renouvelés par Navidrome) était rendue telle quelle. Le sync
  écrivait alors une écoute orpheline (clés étrangères non imposées), et
  `relink` aurait déplacé des écoutes vers un morceau inexistant. Le matcher
  vérifie désormais que la cible existe. Sinon, l'entrée de cache est purgée,
  l'alias ignoré, et la cascade rejouée.

## [1.9.1] - 2026-11-03

### Corrigé

- **Sync et rematch tués par « database is locked » quand le web ou le MCP
  écrivait pendant la passe** (issue #290). La passe lisait les écoutes en
  attente par un curseur ouvert d'un bout à l'autre, tout en écrivant sur la
  même connexion. Une écriture concurrente (ici l'émission d'un jeton OAuth du
  connecteur MCP) formait un interblocage, et SQLite échouait aussitôt, malgré
  le `busy_timeout` de 30 s. `ScrobbleSyncRepository::streamPending()` lit
  désormais par pages de 500, curseur fermé avant le traitement, dans le même
  ordre et avec la même limite. Le passage de `data.db` en WAL ne suffisait
  pas : reproduit, il échoue aussi avec un curseur ouvert.
- **`app:scrobbles:relink` pouvait doubler des écoutes** (issue #290, sur
  #285). Si notre ligne Navidrome était introuvable par la cible enregistrée,
  la nouvelle écoute était écrite quand même. Or, en prod, 85 % des cibles de
  `scrobble_sync` visent des morceaux disparus de Navidrome (#291). Une écoute
  dont la cible a disparu n'est plus proposée (`stale_target`), et une ligne
  introuvable n'entraîne plus aucune écriture.
- **Mémoire** : `memory_limit` passe à 512M dans l'image. L'index de la
  bibliothèque gardé en mémoire par le matching (#279, #282) occupe 57 Mo
  (pic à 73 Mo) pour 31 600 morceaux, et `relink` dépassait les 128 Mo par
  défaut de PHP.

## [1.9.0] - 2026-11-03

### Ajouté

- **Déplacer des écoutes déjà écrites dans Navidrome** (issue #285). Le matching
  actuel est rejoué sur les écoutes écrites par navidrome-tools (`matched`,
  hors alias et MBID). Celles qu'il envoie sur un autre morceau sont retirées
  de l'ancien et réécrites sur le nouveau (ou passent en `duplicate` si
  l'écoute y est déjà), puis les compteurs des deux morceaux sont recalculés.
  Les écoutes enregistrées par Navidrome lui-même ne sont jamais touchées.
  Sauvegarde avant écriture. Commande `app:scrobbles:relink` (`--dry-run`,
  filtres sur l'écoute et sur l'album actuel). Outils MCP
  `matched_relink_preview` et `matched_relink` (écriture mise en file : le
  worker arrête Navidrome le temps de l'écriture). Sert à réparer l'historique
  rattaché aux versions instrumentales avant #284.

## [1.8.2] - 2026-11-03

### Corrigé

- **Docker : web et worker appliquaient les migrations en même temps au
  démarrage** (issue #287). Le perdant sortait en erreur (`UNIQUE constraint
  failed: doctrine_migration_versions.version`), puis redémarrait. Une
  migration non idempotente aurait pu s'appliquer à moitié. La migration de
  `docker/entrypoint.sh` passe sous un verrou fichier (`flock
  var/.migrations.lock`) sur le volume partagé : le second conteneur attend,
  puis n'a plus rien à migrer.

## [1.8.1] - 2026-11-03

### Corrigé

- **Matching : des écoutes allaient aux éditions instrumentales au lieu de
  l'album** (issue #284). Le cache de matching, indexé par (artiste, titre) sans
  l'album, était consulté avant le triplet. Un couple résolu une fois (ici IAM
  « Petit frère » vers « … - Instrumentales ») donnait ce morceau à toutes les
  écoutes suivantes, quel que soit leur album. MBID et triplet passent
  désormais avant le cache. Entre des titres en double, le couple écarte les
  éditions (instrumentales, karaoké, bonus, démos, live, inédits, remixes), sauf
  si l'écoute en nomme une, puis préfère l'album le plus proche de celui
  scrobblé. Migration `Version20261103160000` : vide les positifs en stratégie
  `couple` du cache, recalculés au prochain passage. Les écoutes déjà écrites
  dans Navidrome ne sont pas déplacées (#285).

### Modifié

- **Matching : étapes triplet et couple servies par l'index en mémoire de la
  bibliothèque** (issue #282). Chaque recherche balayait toute la table
  `media_file` en recalculant `np_normalize()` sur chaque ligne, plusieurs fois
  par scrobble non trouvé. Sur 3 000 scrobbles réels : réponses identiques,
  0,2 ms par recherche au lieu de 149 ms. Un sync ou un rematch ne paie plus,
  par écoute ratée, que les appels Last.fm.

## [1.8.0] - 2026-11-03

### Ajouté

- **Rattrapage automatique des non-matchés à chaque sync Navidrome** (issue
  #278). Un scrobble non
  matché n'était jamais retenté sans rematch manuel : un album ajouté après
  l'écoute ne récupérait pas ses scrobbles, et la durée de vie du cache négatif
  ne servait à rien. Au début de chaque sync, `UnmatchedCatchUp` repasse en
  pending les non-matchés des artistes ajoutés à la bibliothèque depuis le sync
  précédent (repère `media_file.created_at`), puis une part de ceux dont l'échec
  a expiré, les plus écoutés d'abord (`LASTFM_UNMATCHED_RETRY_PER_RUN`, 200). Le
  premier sync ne fait que poser le repère.
- **Outil MCP `unmatched_retry_many`** (issue #278) : relance en un appel tous
  les couples non matchés d'une page de `unmatched_list`, avec les mêmes filtres
  (artiste, titre, période).

### Modifié

- **Matching : comparaison « à nu » symétrique** (issue #279). En dernier
  recours, le titre et l'artiste sont comparés sous une forme débarrassée, des
  deux côtés (bibliothèque et scrobble), des `feat.` (avec ou sans
  parenthèses), des `(avec …)` et des suffixes de version (« - Original
  Version », « - … Mix », « - Remasterisé », « - Bonus Track », « (Live) »).
  Cette forme ignore aussi le préfixe horaire « 22h31 - » et réduit « Part » à
  « Pt. ». L'artiste est essayé en entier, sans `feat.`, puis réduit à
  l'artiste principal d'un crédit multiple (`,` `•` `&` `;` `/` `x` `avec`…).
- **Diagnostic des non-matchés** (issue #280) :
  - il suggère aussi les titres de l'artiste qui commencent par le titre écouté
    ou le contiennent ;
  - il diagnostique un crédit multiple sous l'artiste principal ;
  - il ne suggère plus d'artiste éloigné pour les noms courts (une modification
    tolérée par tranche de 5 caractères : fin de « Colas » → « Coins ») ;
  - `unmatched_list` diagnostique tous les groupes servis, sans coupure à 25.
- Un match obtenu par un alias de morceau a la stratégie `alias` (et non plus
  vide, « stratégie ? » côté MCP) (issue #280).

### Corrigé

- Le worker gardait l'index en mémoire de la bibliothèque d'un message à
  l'autre : les morceaux ajoutés restaient invisibles au diagnostic jusqu'au
  redémarrage (`NavidromeRepository` est remis à zéro entre deux messages,
  issue #278).

## [1.7.0] - 2026-10-31

### Ajouté

- **Serveur MCP sur `/mcp`** (issue #276), pour un assistant (connecteur claude.ai,
  Claude Code) : stats d'écoute (`stats_overview`, `stats_daily`, `stats_top`),
  matching (`matching_summary`, `unmatched_list`, `unmatched_diagnose`),
  bibliothèque (`library_search`, `playlists_list`), playlists écrites dans
  Navidrome (`playlist_create` à partir d'ids, `described_playlist_create` via
  AudioMuse) et réconciliation (`alias_create_track`, `alias_create_artist`,
  `unmatched_retry`). `mcp/sdk` 0.9 + `symfony/mcp-bundle` 1.0.0-BETA1.
- **Serveur OAuth 2.1 intégré** (issue #276, `league/oauth2-server-bundle`) :
  découverte (RFC 9728, RFC 8414), enregistrement dynamique de client restreint
  (`authorization_code` + `refresh_token`, adresses de retour claude.ai et
  loopback, 10 demandes/h par IP), PKCE S256 obligatoire, écran de consentement
  derrière la connexion. `/mcp` n'accepte que le jeton. Scopes `mcp` et
  `mcp_write` : les outils d'écriture sont refusés à l'exécution sans
  `mcp_write`. Clés créées au démarrage dans `var/oauth`
  (`app:oauth:ensure-keys`). Nouvelles variables `APP_PUBLIC_URL`,
  `TRUSTED_PROXIES`, `MCP_ALLOWED_HOSTS`, `OAUTH_ALLOWED_REDIRECT_URIS`.
  Migration `Version20261031180000`. Exploitation : README « Serveur MCP ».

### Modifié

- « Re-tenter le matching » (non-matchés) et la création de playlist décrite
  passent par des services partagés avec le MCP (`UnmatchedRetrier`,
  `DescribedPlaylistCreator`), sans changement visible (issue #276).

## [1.6.3] - 2026-10-08

### Corrigé

- **« Kickstart énergique » : le critère « dansant » ne filtrait presque rien**
  (issue #271). Le score `danceable` d'AudioMuse est tassé (médiane ≈ 0,60 sur
  toute la bibliothèque) : le seuil absolu de 0,5 laissait entrer 23 morceaux
  lents sur 50, à peine plus dansants que la moyenne. Le critère devient
  relatif : `danceable` ≥ le `PLAYLIST_KICKSTART_DANCEABLE_PERCENTILE`-ième
  percentile de la bibliothèque (75), comme pour l'énergie.
  `AudioFeatureRepository::moodPercentile()`.

## [1.6.2] - 2026-10-08

### Corrigé

- **Les playlists en erreur passaient inaperçues** (issue #273). Le générateur
  isole chaque playlist : une définition en échec ne bloque pas les autres, mais
  son erreur n'apparaissait que dans le tableau de la commande. Le run de
  génération restait en `success` dans l'historique, sans message ni
  notification. Le run passe désormais en `error`, avec un message par playlist
  en échec (secrets masqués), et la notification part. `app:playlists:generate`
  sort aussi en erreur, pour que `navidrome-playlists.sh` alerte. Nouveau
  paramètre `extractFailure` de `RunHistoryRecorder::record()`.
- **« Empreinte sonore » vide sans erreur** (issue #273). AudioMuse répond `[]`
  en HTTP 200 quand il ne lit pas le top de l'utilisateur (identifiants
  Navidrome périmés dans son registre, ou flask et worker non redémarrés après
  un changement de mot de passe). Une empreinte vide est maintenant une erreur,
  avec un message qui indique où regarder.

## [1.6.1] - 2026-10-08

### Corrigé

- **Images Docker versionnées et `latest` fiable** (issue #261). Les tags de
  release (`1.6.0`, sans `v`) ne déclenchaient pas la CI : aucune image `X.Y.Z`
  n'était publiée et `APP_VERSION` valait `main-<sha>`. La CI réagit désormais
  aux tags `X.Y.Z` et publie `X.Y.Z`, `X.Y`, `X` et `latest`. `latest` désigne
  la dernière release au lieu de suivre `develop` (branche par défaut du dépôt).
  Un groupe `concurrency` annule le run précédent d'une même branche, pour qu'un
  build plus ancien ne puisse plus écraser `:develop`, et la notification ne
  signale plus ces runs annulés comme des échecs. Tags d'image documentés dans
  le README.

## [1.6.0] - 2026-10-08

### Ajouté

- **Playlists « Kickstart énergique » et « Empreinte sonore »** (issue #257).
  « Kickstart énergique » : la Kickstart (morceaux qui ouvrent le plus souvent
  la journée, dans son ordre) limitée aux morceaux dont l'énergie AudioMuse
  dépasse le `PLAYLIST_KICKSTART_ENERGY_PERCENTILE`-ième percentile de la
  bibliothèque (60) et dont le tempo atteint `PLAYLIST_KICKSTART_MIN_BPM` (110)
  ou qui sont dansants (BPM souvent détecté à moitié) ; la Kickstart d'origine
  ne change pas. « Empreinte sonore » : reprend la playlist qu'AudioMuse
  produisait (`POST /api/sonic_fingerprint/generate`), calculée sur le compte
  Navidrome configuré dans AudioMuse, sans envoi d'identifiants.

## [1.5.2] - 2026-10-08

### Sécurité

- **Dépendances vulnérables mises à jour** (issue #252). Symfony passe de 7.2
  (fin de maintenance) à **7.4 LTS** (7.4.20 : `http-foundation`,
  `security-http`, `cache`, `routing`, `runtime`…), Twig de 3.24.0 à **3.30.0**
  (17 avis, dont des critiques) et `squizlabs/php_codesniffer` (outil de dev) à
  3.13.6. `composer audit` : aucun avis restant. Contrainte Flex
  `extra.symfony.require` et `symfony/process` passées à `7.4.*`.

## [1.5.1] - 2026-10-08

### Corrigé

- **`app:audiomuse:sync` échouait en HTTP 400** (issue #257). Les lots de 500
  ids envoyés à `/api/sync?ids=…` donnaient une URL d'environ 11,5 Ko, alors que
  gunicorn (devant AudioMuse) refuse toute ligne de requête de plus de 4 094
  octets : l'import ne passait jamais, et « Course », « Calmes et peu écoutés »
  et « Énergiques oubliées » restaient vides. Le client découpe désormais les
  ids par taille encodée (≤ 3 000 octets, environ 120 ids par appel).

### Sécurité

- **Jetons Subsonic et clés Last.fm masqués dans les logs** (issue #265). Le
  client HTTP de Symfony journalise chaque URL (niveau `info`, affiché par le
  worker `-vv`) : le couple `t`/`s` de Subsonic, rejouable tant que le mot de
  passe ne change pas, se retrouvait dans les logs du conteneur. Le client HTTP
  journalise désormais via `RedactingLogger`, qui masque `t`, `s`, `p`,
  `api_key`, `api_sig`, `sk`, `token`… (`***`) ; les messages d'erreur des
  clients Subsonic et Last.fm, et ceux enregistrés dans l'historique et les
  notifications, sont masqués de la même façon. ⚠️ Changer le mot de passe
  Navidrome du compte utilisé pour invalider les jetons déjà journalisés.

## [1.5.0] - 2026-10-08

### Ajouté

- **Caractéristiques audio d'AudioMuse-AI importées + 3 playlists « soniques »**
  (issue #257). `app:audiomuse:sync` importe tempo, tonalité, énergie, ambiances
  et genres pondérés dans la table `audio_feature` (migration
  `Version20261008080000`), de façon incrémentale : manifeste `GET /api/sync?fields=index`,
  puis détail (sans embeddings) des seuls morceaux nouveaux ou réanalysés, par
  lots de 500 ; les morceaux disparus sont retirés, mais un manifeste vide
  n'efface jamais la table. Run `audiomuse-sync` dans l'historique ;
  `navidrome-playlists.sh` le lance avant la génération (un échec n'empêche pas
  de générer). Nouvelles playlists : **« Course »** (morceaux écoutés entre
  `PLAYLIST_COURSE_BPM_MIN` et `_MAX` BPM, demi-tempo toléré),
  **« Calmes et peu écoutés »** (ambiance « relaxed », ≤ `PLAYLIST_CALMES_MAX_PLAYS`
  écoutes) et **« Énergiques oubliées »** (les plus énergiques des pépites
  oubliées). `NavidromeRepository::getPlayCountsByMediaFileId()` découpe
  désormais ses requêtes par lots de 500.
- **Playlists décrites en texte** (issue #258) — sur `/playlists`, une phrase
  (« piano calme, pluie ») + un nombre de morceaux (+ un plafond d'écoutes
  facultatif) crée une playlist Navidrome à partir de la recherche texte CLAP
  d'AudioMuse-AI (`POST /api/clap/search`), dans l'ordre de pertinence.
  Enregistrée (table `described_playlist`, migration
  `Version20261007220000`), elle apparaît dans « Playlists générées » (slug
  `decrite-<id>`) et se régénère avec les autres ; « Retirer » arrête sa
  régénération sans supprimer la playlist Navidrome. `PlaylistGenerator`
  accepte des fournisseurs de définitions issues des données
  (`PlaylistDefinitionProviderInterface`, tag
  `app.playlist_definition_provider`). Les erreurs d'AudioMuse remontent avec
  leur `error_message` (ex. CLAP désactivé).
- **Playlist « Du connu vers l'oubli »** (issue #259) — un parcours sonore
  d'AudioMuse-AI (`/api/find_path`) qui glisse d'un morceau du top des 30
  derniers jours vers une pépite oubliée, par des titres soniquement proches,
  dans l'ordre du chemin. Extrémités tirées au hasard à chaque génération ;
  jusqu'à 3 paires tentées si AudioMuse ne trouve pas de chemin, puis échec
  (la playlist existante est conservée). Réglages `PLAYLIST_PARCOURS_LENGTH`
  (25) et `PLAYLIST_PARCOURS_START_DAYS` (30), seuils `PLAYLIST_PEPITES_*`
  pour l'arrivée. Nouvelle `AudioMuseClient::findPath()` ; délai des appels
  AudioMuse porté de 30 à 60 s.

## [1.4.1] - 2026-10-07

### Corrigé

- **« Mix de la semaine » ne contenait plus aucune découverte** (issue #255).
  AudioMuse-AI (≥ 3.6) renvoie la réponse de `/api/similar_tracks` en liste à
  la racine du JSON, et non plus sous `similar_songs` : chaque seed revenait
  vide, sans erreur, et la playlist ne contenait que ses seeds. Les deux formes
  sont désormais lues. Si **aucun** seed ne ramène de similaire, la génération
  échoue au lieu d'écraser la playlist par les seuls seeds.
- **AudioMuse : clé envoyée dans le mauvais en-tête** (issue #256). La clé
  part maintenant en `Authorization: Bearer` (l'`API_TOKEN` d'AudioMuse), au
  lieu de `X-API-Key` qu'AudioMuse n'a jamais lu. Un 401/403 fait échouer la
  génération du mix (`AudioMuseAuthException`) au lieu d'être avalé ; un 404
  (morceau non analysé) reste ignoré. `.env.dist` précise la valeur attendue.
- **`app:lastfm:fetch --max-scrobbles=0` ne récupérait plus rien** (issue #253).
  Documentée comme « pas de limite », la valeur `0` était lue comme un plafond
  à zéro : la boucle s'arrêtait avant le premier scrobble, le run finissait en
  `success` avec `fetched=0` et le curseur smart-date ne bougeait pas. Le
  `navidrome-sync.sh` versionné passant cette option, **plus aucun scrobble
  n'était importé** par le cycle nocturne. `0` (ou négatif) = pas de limite ;
  l'option est retirée du script.

## [1.4.0] - 2026-08-23

### Ajouté

- **API JSON `GET /api/stats/daily` (+ `/{day}`)** — séries quotidiennes
  d'écoute agrégées (issue #250), authentifiées par token Bearer
  (`APP_API_TOKEN`). Deux sources nommées (`?source=navidrome|lastfm`), jours
  découpés en heure locale (`APP_TIMEZONE`) et **zéro-remplis**. Par jour :
  morceaux, distincts, artistes, albums, `duration_seconds` +
  `duration_coverage_pct` (durée via `media_file`, rapprochée par `scrobble_sync`
  côté Last.fm), `loved_added` (toujours `annotation.starred_at`) et top artiste.
  Firewall `^/api` stateless + `ApiTokenAuthenticator` (fail-closed si token
  vide), `DailyListeningStatsService`, requêtes par jour sur
  `NavidromeRepository` / `ScrobbleRepository`.

### Modifié

- **`Kernel`** pose désormais aussi `TZ` (C runtime) depuis `APP_TIMEZONE`, pour
  que le bucketing SQLite `'localtime'` de l'API corresponde au fuseau PHP.

## [1.3.0] - 2026-07-17

### Ajouté

- **Playlist « Pépites redécouvertes »** — morceaux rejoués dans le dernier mois
  après ~12 mois de silence, et déjà écoutés avant ce silence (fenêtre de 24
  mois) : de vraies re-découvertes, en aléatoire. Nouvelle
  `PepitesRedecouvertesDefinition` +
  `NavidromeRepository::findRediscoveredGems()` (requête sur l'historique par
  écoute `scrobbles`). Fenêtres configurables via
  `PLAYLIST_PEPITES_REDECOUVERTES_{WINDOW,SILENCE,RECENT}_MONTHS`.

## [1.2.0] - 2026-07-01

### Ajouté

- **Bouton « ↻ Rematcher » par morceau sur `/navidrome/unmatched`** — pour
  déboguer au cas par cas : purge le cache négatif du couple, relance une
  tentative de matching et affiche le résultat (stratégie + cible sur succès,
  ou raison du diagnostic sur échec). Sur succès, les scrobbles du couple sont
  remis en `pending` pour insertion au prochain sync. Lecture seule → n'arrête
  pas Navidrome. Nouvelle route `app_navidrome_unmatched_retry` +
  `ScrobbleSyncRepository::resetCoupleToPending()`.

## [1.1.0] - 2026-07-01

### Ajouté

- **`scripts/navidrome-stats.sh`** — wrapper cron dédié au recalcul des stats
  (`app:stats:compute`, `app:lastfm:stats:compute`, `app:navidrome:stats:compute`).
  Lecture seule, n'arrête pas Navidrome et ne fait pas de backup → cronnable
  fréquemment, y compris en journée. Best-effort (un échec n'interrompt pas les
  autres).

## [1.0.0] - 2026-07-01

Première version taguée de la réécriture v2 (Symfony 7 / PHP 8.4 / FrankenPHP).
L'ancienne POC reste accessible via le tag `poc-v0`.

### Ajouté

- **Import Last.fm** : commande `app:lastfm:fetch` (+ déclenchement UI), table
  `scrobbles` comme source de vérité, suivi de dates intelligent, page
  d'historique des scrobbles avec filtres et statut de matching.
- **Matching & alias** : cascade de matching (`ScrobbleMatcher`), cache de
  matching positif/négatif, génération automatique d'alias
  (`app:aliases:generate`) et suggestions en ligne via MusicBrainz
  (`app:aliases:musicbrainz`).
- **Synchronisation Navidrome** : écriture des écoutes dans `scrobbles`
  (Navidrome ≥ 0.55), arrêt/redémarrage du conteneur, backups automatiques,
  checkpoints intermédiaires pendant les longs runs, résilience aux erreurs
  Last.fm transitoires, commandes `sync-navidrome`, `rematch`,
  `requeue-unmatched`, `wipe-scrobbles`.
- **Synchronisation Strawberry** : import, upload/download, suivi des
  non-matchés et traitement.
- **Loves** : synchronisation des favoris Last.fm ↔ Navidrome (dans les deux
  sens).
- **Playlists** : génération « plugin » via l'API Subsonic (create/replace),
  description en commentaire, activation par playlist, et un large jeu de
  définitions (Hit parade, Retour en arrière, Mix de la semaine, Kickstart,
  Happy birthday, Vieilles/Très vieilles pépites, coups de cœur, découvertes
  récentes, fidèles compagnons, tops mois/année/all-time…).
- **Recommandations** : moteur d'artistes Last.fm avec snapshot asynchrone,
  source ListenBrainz, page de revue et ajout à Lidarr en 1 clic.
- **Stats & dashboard** : commande `app:stats`, pages de stats Last.fm et
  Navidrome, disparité Last.fm ↔ Navidrome, écran « Stats non-matchés »,
  courbe de couverture, streaks d'écoute, heatmap d'activité 12 mois,
  historique quotidien de la bibliothèque, tops artistes/albums/morceaux avec
  filtres de date.
- **Diagnostic** : explication « pourquoi non-matché » sur `/navidrome/unmatched`,
  liens Lidarr / MusicBrainz.
- **Interface** : refonte « Console » (thème sombre, sidebar, design system),
  nouvelle charte graphique, navigation responsive, page d'aide `/help`.
- **Outillage / crontab** : wrappers bash versionnés partageant `navidrome-lib.sh`
  (config via `.env`, notif Gotify) — `navidrome-backup.sh`, `navidrome-sync.sh`
  (cycle de maintenance), `navidrome-rematch.sh`, `navidrome-rematch-full.sh`,
  `navidrome-unmatched-requeue.sh`, `navidrome-playlists.sh`.
- **Infrastructure** : worker Symfony Messenger (transport Doctrine/SQLite),
  `RunHistoryRecorder`, `Notifier` (Gotify/Slack/Discord/Pushover),
  `BackupService`, sessions persistantes ; CI (phpcs, PHPStan, PHPUnit, lint
  Twig, build Docker).

[Non publié]: https://github.com/kgaut/navidrome-tools/compare/1.10.0...HEAD
[1.10.0]: https://github.com/kgaut/navidrome-tools/compare/1.9.1...1.10.0
[1.9.1]: https://github.com/kgaut/navidrome-tools/compare/1.9.0...1.9.1
[1.9.0]: https://github.com/kgaut/navidrome-tools/compare/1.8.2...1.9.0
[1.8.2]: https://github.com/kgaut/navidrome-tools/compare/1.8.1...1.8.2
[1.8.1]: https://github.com/kgaut/navidrome-tools/compare/1.8.0...1.8.1
[1.8.0]: https://github.com/kgaut/navidrome-tools/compare/1.7.0...1.8.0
[1.7.0]: https://github.com/kgaut/navidrome-tools/compare/1.6.3...1.7.0
[1.6.3]: https://github.com/kgaut/navidrome-tools/compare/1.6.2...1.6.3
[1.6.2]: https://github.com/kgaut/navidrome-tools/compare/1.6.1...1.6.2
[1.6.1]: https://github.com/kgaut/navidrome-tools/compare/1.6.0...1.6.1
[1.6.0]: https://github.com/kgaut/navidrome-tools/compare/1.5.2...1.6.0
[1.5.2]: https://github.com/kgaut/navidrome-tools/compare/1.5.1...1.5.2
[1.5.1]: https://github.com/kgaut/navidrome-tools/compare/1.5.0...1.5.1
[1.5.0]: https://github.com/kgaut/navidrome-tools/compare/1.4.1...1.5.0
[1.4.1]: https://github.com/kgaut/navidrome-tools/compare/1.4.0...1.4.1
[1.4.0]: https://github.com/kgaut/navidrome-tools/compare/1.3.0...1.4.0
[1.3.0]: https://github.com/kgaut/navidrome-tools/compare/1.2.0...1.3.0
[1.2.0]: https://github.com/kgaut/navidrome-tools/compare/1.1.0...1.2.0
[1.1.0]: https://github.com/kgaut/navidrome-tools/compare/1.0.0...1.1.0
[1.0.0]: https://github.com/kgaut/navidrome-tools/releases/tag/1.0.0
