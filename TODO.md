1. Créer un fichier changelog.MD et l'ammender à chaque nouvelle fonctionnalité, préparer le template pour les tags.
2. Faire dans le menu statistiques une page d'historique last.fm pour afficher les 100 derniers morceaux sur last.fm. stocker le tout en base pour éviter de surcharger l'api, et ajouter un bouton refresh
2. Faire dans le menu statistiques une page d'historique navidrome pour afficher les 100 derniers morceaux sur la db navidrome. stocker le tout en base pour éviter de surcharger l'api, et ajouter un bouton refresh
3. Pour chaque import depuis last.fm stocker dans la base local le statut de chaque morceaux (avoir comme colonne, l'ensemble des données retournées par last.fm) avec une visualisation sur la page détails de l'import dans la section historique.
4. Mettre une pause de 10 seconde (valeur surchargeable en variable d'environement) entre le chargement de chaque page sur l'api de lastfm pour éviter de la surchager.
5. Pouvoir stocker en variable d'environnement son nom d'utilisateur lastfm pour éviter d'avoir à le renseigner à chaque fois.
6. Lors de la génération d'un wrapped j'ai l'erreur `An exception has been thrown during the rendering of a template ("Warning: A non-numeric value encountered") in wrapped/show.html.twig at line 57.`
