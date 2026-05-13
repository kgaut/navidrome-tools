# Tagging — tracks sans MBID

La page **`/tagging/missing-mbid`** (menu **Tagging**) liste les
morceaux Navidrome dont les colonnes `mbz_track_id` ET
`mbz_recording_id` sont vides. Sans MBID, le palier le plus fiable de
la cascade de matching Last.fm est inutilisable, et l'outil retombe
sur les paliers (artiste, titre, album) + fuzzy, plus fragiles.

## Architecture read-only sur /music

L'architecture est volontairement **read-only** : navidrome-tools
**n'écrit jamais** dans vos fichiers audio. Le volume `/music` peut
rester monté `:ro` côté tool. Le workflow est :

1. **Audit** sur la page : filtres artiste/album, pagination, voir
   les chemins absolus.
2. **Export** vers un tagger externe (CSV ou queue beets).
3. **Rescan Navidrome** une fois le tagging fini pour pousser les
   nouveaux MBIDs dans `media_file.mbz_track_id`.

## Workflow CSV

Bouton **« ⬇ Export CSV »** : télécharge la liste des chemins
filtrés (id, path, artist, album, title…) que vous piper dans un
tagger sur la machine où le dossier de musique est en
lecture/écriture.

### Exemple avec beets

```bash
# extrait la colonne path et nourris beet
tail -n +2 missing-mbid-2026-05-02.csv \
  | awk -F'"' '{print $4}' \
  | xargs -d '\n' beet import -A --quiet
```

### Exemple avec MusicBrainz Picard

1. Ouvrir Picard.
2. Drag-and-drop le dossier de musique entier (Picard tolère les
   fichiers déjà taggés).
3. Lancer **Scan**, puis **Save**.

## Queue beets (intégration semi-automatique)

Si vous préférez ne pas copier-coller le CSV à chaque fois,
configurez `BEETS_QUEUE_PATH` (ex. `/shared/beets-queue.txt`). La page
expose alors un bouton **« 📋 Pousser dans la queue beets »** qui
appendit les chemins filtrés dans ce fichier sous `flock` (sûr en
concurrence).

Côté hôte beets, monter le même volume et lancer un cron qui consomme
la queue :

```bash
# /etc/cron.d/beets-queue : toutes les 15 min
*/15 * * * * beets   ( flock -x 9 ; \
   [ -s /shared/beets-queue.txt ] || exit 0 ; \
   mv /shared/beets-queue.txt /shared/beets-queue.processing ; \
 ) 9>/shared/beets-queue.lock && \
 beet import -A --quiet $(cat /shared/beets-queue.processing) && \
 rm /shared/beets-queue.processing
```

Avec ce pattern, navidrome-tools ne touche **que** le fichier de
queue (RW), `/music` reste `:ro`. Le push est tracé dans `/history`
sous le type `beets-queue-push` et le bandeau de la page affiche la
taille courante de la queue.

## Rescan Navidrome

Bouton **« ↻ Rescan Navidrome »** : déclenche `startScan` via
Subsonic une fois le tagging fini. Les nouveaux MBIDs apparaissent
dans `media_file.mbz_track_id` sans attendre le scan planifié. Logué
dans `/history` sous le type `navidrome-rescan`.

CLI équivalente : `php bin/console app:navidrome:rescan` (utilisable
en cron si vous voulez forcer un rescan régulier — ex. après un push
beets nocturne).

## Card dashboard

Le dashboard affiche un compteur **« Tracks sans MBID »** avec un
raccourci vers la page. Passe en gris quand le compteur vaut 0.
