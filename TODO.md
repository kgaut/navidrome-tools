1. ~~Créer un fichier changelog.MD et l'ammender à chaque nouvelle fonctionnalité, préparer le template pour les tags.~~
2. ~~Afficher sur le dashboard le nombre de scrobles présent dans la db navidrome~~
3. ~~Le nombre total d'écoute sur la page stats ne semble pas se mettre à jour, même quand on clique sur refresh.~~
4. ~~Faire dans le menu statistiques une page d'historique last.fm pour afficher les 100 derniers morceaux sur last.fm. stocker le tout en base pour éviter de surcharger l'api, et ajouter un bouton refresh~~
5. ~~Faire dans le menu statistiques une page d'historique navidrome pour afficher les 100 derniers morceaux sur la db navidrome. stocker le tout en base pour éviter de surcharger l'api, et ajouter un bouton refresh~~
6. ~~Pour chaque import depuis last.fm stocker dans la base local le statut de chaque morceaux (avoir comme colonne, l'ensemble des données retournées par last.fm) avec une visualisation sur la page détails de l'import dans la section historique (et pouvoir filtrer les morceaux selon leur statut d'import, par défaut n'afficher que les non matchés).~~
7. ~~Mettre une pause de 10 seconde (valeur surchargeable en variable d'environement) entre le chargement de chaque page sur l'api de lastfm pour éviter de la surchager.~~
8. ~~Pouvoir stocker en variable d'environnement son nom d'utilisateur lastfm pour éviter d'avoir à le renseigner à chaque fois.~~
9. ~~Lors de la génération d'un wrapped j'ai l'erreur `An exception has been thrown during the rendering of a template ("Warning: A non-numeric value encountered") in wrapped/show.html.twig at line 57.`~~
10. ~~Dans l'historique des import, ajoute en colonne la date-min et date-max~~
11. ~~Sur la preview d'une playlist, la colonne `Plays` ne semble pas indiquer le total de lecture de la période concernée.~~
12. ~~Tu peux m'ajouter une favicon (note de musique par exemple, comme pour le logo)~~
13. ~~Je voudrais héberger une copie de ce dépot sur mon instance gitlab, peux tu me générer un fichier .gitlab-ci.yml avec les même jobs que github actions.~~
14. Gestion des playlists Navidrome — feature à découper en sous-tickets (epic #71) :
    - Page `/playlists` : liste des playlists Navidrome avec aperçu (nombre de morceaux, durée, date de création, date de modification, owner, public/privé). Étendre `SubsonicClient::getPlaylists()` pour récupérer `songCount`, `created`, `changed`, `duration`, `public`, `comment`.
    - Page `/playlists/{id}` : voir le contenu d'une playlist (tracks avec artiste/album/durée/play count/statut starred). Ajouter `SubsonicClient::getPlaylist(string $id)` (wrap `getPlaylist.view`).
    - Renommer une playlist : action POST + nouveau `SubsonicClient::updatePlaylist(string $id, ?string $name = null, ?string $comment = null, ?bool $public = null)` (wrap `updatePlaylist.view`).
    - Supprimer une playlist depuis l'UI : réutiliser `SubsonicClient::deletePlaylist()` ; si la playlist est rattachée à un `PlaylistDefinition`, nettoyer `lastSubsonicPlaylistId`.
    - Star / unstar un morceau : réutiliser `SubsonicClient::starTracks(...$ids)` / `unstarTracks(...$ids)` (déjà livrés via la sync loved↔starred).
    - Bulk star : bouton « tout starrer » sur la page détail (un seul appel `starTracks()` avec tous les `songId`).
    - Idées complémentaires :
        - Ajouter / retirer / réordonner des morceaux (`updatePlaylist.view` accepte `songIdToAdd` et `songIndexToRemove`).
        - Dupliquer une playlist (createPlaylist + bulk add).
        - Statistiques de playlist : durée totale, top artistes, distribution par année, % de morceaux jamais joués (réutiliser `NavidromeRepository`).
        - Détection des morceaux « morts » (présents dans la playlist mais absents de `media_file`) avec proposition de purge.
        - Bulk delete depuis la liste (cases à cocher + action groupée).
        - Bouton « Export M3U » sur la page détail (mutualise l'idée déjà roadmap dans CLAUDE.md).
