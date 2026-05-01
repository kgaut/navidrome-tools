1. ~~Créer un fichier changelog.MD et l'ammender à chaque nouvelle fonctionnalité, préparer le template pour les tags.~~
2. ~~Afficher sur le dashboard le nombre de scrobles présent dans la db navidrome~~
3. Le nombre total d'écoute sur la page stats ne semble pas se mettre à jour, même quand on clique sur refresh.
4. Faire dans le menu statistiques une page d'historique last.fm pour afficher les 100 derniers morceaux sur last.fm. stocker le tout en base pour éviter de surcharger l'api, et ajouter un bouton refresh
5. Faire dans le menu statistiques une page d'historique navidrome pour afficher les 100 derniers morceaux sur la db navidrome. stocker le tout en base pour éviter de surcharger l'api, et ajouter un bouton refresh
6. Pour chaque import depuis last.fm stocker dans la base local le statut de chaque morceaux (avoir comme colonne, l'ensemble des données retournées par last.fm) avec une visualisation sur la page détails de l'import dans la section historique (et pouvoir filtrer les morceaux selon leur statut d'import, par défaut n'afficher que les non matchés).
7. ~~Mettre une pause de 10 seconde (valeur surchargeable en variable d'environement) entre le chargement de chaque page sur l'api de lastfm pour éviter de la surchager.~~
8. ~~Pouvoir stocker en variable d'environnement son nom d'utilisateur lastfm pour éviter d'avoir à le renseigner à chaque fois.~~
9. ~~Lors de la génération d'un wrapped j'ai l'erreur `An exception has been thrown during the rendering of a template ("Warning: A non-numeric value encountered") in wrapped/show.html.twig at line 57.`~~
10. ~~Dans l'historique des import, ajoute en colonne la date-min et date-max~~
11. ~~Sur la preview d'une playlist, la colonne `Plays` ne semble pas indiquer le total de lecture de la période concernée.~~
12. ~~Tu peux m'ajouter une favicon (note de musique par exemple, comme pour le logo)~~
13. Je voudrais héberger une copie de ce dépot sur mon instance gitlab, peux tu me générer un fichier .gitlab-ci.yml avec les même jobs que github actions.
