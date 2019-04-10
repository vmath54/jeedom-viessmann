# jeedom-viessmann
remontée d'infos d'une chaudière viessmann dans jeedom

viessmannVM.php est un script à exécuter dans l'environnement jeedom, afin de remonter des informations de fonctionnement d'une chaudière viessmann.
Il s'appuis sur le web-service mis à disposition de viessmann aux utilisateurs disposant d'un boitier vitoconnect

Ce script est directement dérivé du script dispo à https://github.com/jpty/Jeedom-viessmann , lui-même inspiré par le développement de https://github.com/thetrueavatar/Viessmann-Api

Mise en oeuvre
--------------
- sur le serveur jeedom, ajouter une ligne au fichier /var/www/html/plugins/script/core/ressources/.htaccess afin d'autoriser le réseau local à accéder directement aux fichiers présents ; ceci permettra de tester le script en dehors de jeedom
  exemple :
    Order deny,allow
    Deny from all
    Allow from 127.0.0.1
    Allow from 192.168.0
- Création du répertoire ViessmannVM (ou autre nom) dans /var/www/html/plugins/script/core/ressources
- dans ce répertoire :
  . dépot du script viessmannVM.php
  . créer le fichier viessmann-credentials.txt contenant sur la 1ère ligne l'username et sur la 2ème le password
  . vérifier les droits accès : '-rw-r--r--  www-data www-data'
- exécuter ce script avec le paramètre 'GetInstallationInfos' pour noter les paramètres 'installation' et 'gateway' de la chaudière
  peut être fait de 2 manières :
     . depuis un navigateur : http://192.168.0.147/plugins/script/core/ressources/ViessmannVM/viessmannVM.php?fct=GetInstallationInfos
     . en ligne de commande :
        sudo -u "www-data"  php /var/www/html/plugins/script/core/ressources/ViessmannVM/viessmannByVar.php GetInstallationInfos
- Dans jeedom, création d'un virtuel "viessmann" (ou autre nom), avec les commandes correspondant aux infos souhaitées. Infos de type numérique, aucune valeur indiquée
  Il faut noter l'ID du virtuel et l'ID des commandes pour mettre à jour les infos correspondantes dans le script : virtualID et virtualCMDs
- Editer le fichier viessmannVM.php pour y ajouter les infos suivantes :
  . $params[installation] : y mettre l'info 'installation' de la chaudière
  . $params[gateway] : y mettre l'info 'gateway' de la chaudière
  . $params[virtualID] : y mettre l'ID du virtuel créé
  . $virtualCMDs : y mettre l'ID des commandes du virtuel
- Exécuter ce script manuellement, avec le paramètre GetAllInformation ; vérifier qu'il n'y a pas d'erreur, et que le virtuel s'est mis à jour avec les bonnes valeurs
- Dans jeedom, créer un script à auto-actualisation (exécution toutes les minutes ... à voir) ; la requete est /var/www/html/plugins/script/core/ressources/ViessmannVM/viessmannVM.php GetAllInformation
