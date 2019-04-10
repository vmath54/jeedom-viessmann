<?php
/* 
  -------------- interrogation de l'API viessmann pour recuperation infos chaudiere, et transmission a jeedom --------------------
     script initial recupere a https://github.com/jpty/Jeedom-viessmann

  fonctionne dans l'environnement jeedom. Doit etre depose dans /var/www/html/plugins/script/core/ressources/.../
  Il faut creer dans ce repertoire un fichier viessmann-credentials.txt avec une info par ligne :
    . login
	. password
	. installation
	. gateway
	
  Si les infos "installation" et "gateway" ne sont pas renseignees, il faut executer le script avec le parametre "GetInstallationInfos" 
     pour les recuperer et les saisir
  
  Pour tests, acces a l'url http://<IP jeedom>/plugins/script/core/ressources/ViessmannVM/viessmannVM.php
  Il faut modifier le fichier  /var/www/html/plugins/script/core/ressources/.htaccess pour autoriser le réseau local à accéder au script.
  exemple d'ajout en fin de fichier : "Allow from 192.168.0"
  
  Coté jeedom, il faut créer un virtuel 'viessmann', comprenant autant de commandes que d'infos à mémoriser. Les infos sont de type numérique, on ne saisi pas de valeur.
  Ce script enregistre les infos de token dans jeedom via des variables du genre "Viessmann_<parametre>" ; ceci permet de ne pas avoir
    à redemander le token à chaque exécution (durée de vie du token = 3600s).
  Ce script écrit les infos utiles directement dans les commandes du virtuel
  
  Il faut renseigner 'en dur' dans ce script les infos suivantes : installation, gateway, virtualID, et le tableau virtualCMDs
  
  Ce script doit necessairement recevoir un parametre :
  si celui-ci est execute en dehors de jeedom, le nom du parametre est "fct="
  voici les parametres possibles :
    . GetAllInformation : recup de tous les parametres souhaites, et mise a jour des variables jeedom relatives
	. GetDownloadTimeOneInfo : recup de l'info de temperature exterieure : "heating.sensors.temperature.outside"
	                           a ete developpe pour determiner le temps necessaire a la recuperation de cette info
	. GetInstallationInfos : retourne les infos "installation" et "gateway". Utile la première fois
							   
  A noter que le fichier json resultat se trouve dans /tmp/jeedom/viessmann.json
*/

$t0 = microtime(true);
$params = [
  "username"     => 'username',    // ne pas renseigner ces 2 infos : se trouvent dans le fichier credentials 
  "password"     => 'password',
  "installation" => 'xxxxxxx',      // a renseigner avec le numero d'installation viessmann
  "gateway"      => 'xxxxxxxx',  // a renseigner avec le numero de gatewat viessmann
  "token"        => '',
  "tokenExpires" => '',
  "virtualID"    => 34,           // a renseigner avec l'ID du virtuel viessmann, dans jeedom
  "jeedom"       => 1       // php dans Jeedom
];

$virtualCMDs = array(       // ID des commandes du virtuel Viessmann. A adapter
  "OutsideTemperature"          => 235,
  "RoomTemperature"             => 236,
  "BoilerTemperature"           => 237,
  "SupplyTemperature"           => 238,
  "HotWaterStorageTemperature"  => 239,
  "DhwGasConsumptionToday"      => 241,
  "HeatingGasConsumptionToday"  => 240
);

require_once dirname(__FILE__) . '/../../../../../core/php/core.inc.php';

$credFile = __DIR__ ."/viessmann-credentials.txt";
$JsonFile = "/tmp/jeedom/viessmann.json"; // fichier cache retour de Viessmann

// Ce script requiert un parametre : la fonction souhaitee (fct)
//
// valeurs possibles de fct :
// . GetAllInformation
// . 


setlocale(LC_TIME,"fr_FR.utf8");
$resource="";      // le json brut
$dec="";           // le json decode

  // test si php lance par jeedom ou en direct
if (isset($argv)) { // script jeedom var/www/html/plugins/script/core/ressources/ViessmannVM/viessmann.php GetAllInformation
  $TTL = 50; // duree de vie en secondes du fichier cache ($JsonFile)
  $fct=$argv[1];
}
else {  // ie URL : http://jeedom/plugins/script/core/ressources/viessmann.php?fct=GetAllInformation
  $TTL=0; // 0 = recharge resource chez Viessmann a chaque demande
  $params['jeedom'] = 0; // php en dehors de Jeedom
  if (isset( $_GET['fct'])) $fct=$_GET['fct'];
}

  // DEBUT Traitement suivant la demande en parametre
if (!strcasecmp($fct,'GetAllInformation')) {
  if(file_exists($JsonFile)) {
    if ($params['jeedom'] == 0) {  // php en dehors de jeedom
      echo "$JsonFile was last modified: " .date("F d Y H:i:s.",filemtime($JsonFile)).'<br/>';
      echo "Current time: " . date ("F d Y H:i:s.", time()) .'<br/>';
      echo 'Mtime-cur = ' .(time() - filemtime($JsonFile)) .'<br/>';
      echo 'Mtime = ' .filemtime($JsonFile) .'<br/>';
      echo 'Curre = ' . time() .'<br/>';
    }
    if(time() - filemtime($JsonFile) < $TTL ) {
      $resource = file_get_contents($JsonFile);
      if ($params['jeedom'] == 0) echo 'Resource from CACHE<br/>';
    }
    else unlink($JsonFile); // perime
  }

  if($resource == "") { // Pas de json interrogation de Viessmann
    $resource = getResourceViessmann($params,$credFile,$JsonFile,'');
  }
  if($dec == "" && $resource != "") {
    $dec = json_decode($resource,true);
    if($dec == null) {
      if ( $params['jeedom'] == 0 )
        echo "<h3>Json_decode error : " .json_last_error_msg() ."</h3><br/>\n";
      else {
        log::add('script', 'error', __FILE__ ." " .__LINE__ ." Json_decode error : " .json_last_error_msg());
        log::add('script', 'error', substr($resource,0,50));
      }
      exitOnError();
    }
  }
  GetAllInformation($dec,$jeedom,$params,$virtualCMDs,$JsonFile);
}
else if (!strcasecmp($fct,'GetDownloadTimeOneInfo')) { // Test requete reduite. ici, temperature exterieure
  $t0 = microtime(true);

  $resource = getResourceViessmann($params,$credFile,$Jsonfile,"heating.sensors.temperature.outside");
  // echo "$resource<br/>";
  $dec = json_decode($resource,true);
  $k1 = "value"; $k2 = "value";
  if(array_key_exists($k1,$dec["properties"]))
    $val = $dec ["properties"][$k1][$k2];
  else $val = '-666';
  $GetOutsideTemperature = $val;
  if ( $params['jeedom'] == 0 ) {
    echo "GetOutsideTemperature: $GetOutsideTemperature<br/>";
    $t1 = microtime(true);
    echo "<br/>DOWNLOAD VIESSMANN DATA: ".round($t1-$t0,3)."s<br/><br/>";
  }
}
else if (!strcasecmp($fct,'GetInstallationInfos')) {   // affichage infos installation et gateway
  getUserPass($params,$credFile);
  if ( ($ret = getTokenViessmann($params)) ) return ( "" );  // Erreur recup token
  getInstGwViessmann($params, true);
}
else echo "$fct non disponible";

if ($params['jeedom'] == 0) {
  $t1 = microtime(true);
  echo "<br/>Traitement OK ".round($t1-$t0,3)."s<br/>";
  echo "UserName: " .$params['username'] ."<br/>";
  // echo "Password: " .$params['password'] ."<br/>";
  // echo "TokenExpiresAt: " .$params['tokenExpires'] ."<br/>";
  // echo "Gateway: " .$params['gateway'] ."<br/>";
  // echo "Installation: " .$params['installation'] ."<br/>";
  // echo "Token: " .$params['token'] ."<br/>";
}
exit();

function GetAllInformation($dec,$jeedom,&$params,&$virtualCMDs,$JsonFile) {
  $virtual = eqLogic::byId($params['virtualID']);
  $GetBoilerTemperature = GetBoilerTemperature($dec);
  if ( $GetBoilerTemperature == -1 ) {
    if ( $params['jeedom'] == 0 ) echo "Erreur recup temp bruleur<br/>";
    else log::add('script','warning',"Erreur recup temp bruleur");
    exitOnError();
  }
  echo "BoilerTemperature: $GetBoilerTemperature. " . $params['virtualID'] . " - " . $virtualCMDs['BoilerTemperature'] . "<br/>";
  $cmd = cmd::byId($virtualCMDs['BoilerTemperature']);
  $virtual->checkAndUpdateCmd($cmd, $GetBoilerTemperature);
  if ( $params['jeedom'] == 0 ) echo "GetBoilerTemperature: $GetBoilerTemperature<br/>";
    //
  $UpdateDate = UpdateDate($JsonFile);
  scenario::setData('Viessmann_UpdateDate', $UpdateDate);
  if ( $params['jeedom'] == 0 ) echo "UpdateDate: $UpdateDate<br/>";
    //
  $GetOutsideTemperature = GetOutsideTemperature($dec);
  $cmd = cmd::byId($virtualCMDs['OutsideTemperature']);
  $virtual->checkAndUpdateCmd($cmd, $GetOutsideTemperature);
  if ( $params['jeedom'] == 0 ) echo "GetOutsideTemperature: $GetOutsideTemperature<br/>";
    //
  $GetRoomTemperature = GetRoomTemperature($dec);
  $cmd = cmd::byId($virtualCMDs['RoomTemperature']);
  $virtual->checkAndUpdateCmd($cmd, $GetRoomTemperature);
  if ( $params['jeedom'] == 0 ) echo "GetRoomTemperature: $GetRoomTemperature<br/>";
    //
  $GetSupplyTemperature = GetSupplyTemperature($dec);
  $cmd = cmd::byId($virtualCMDs['SupplyTemperature']);
  $virtual->checkAndUpdateCmd($cmd, $GetSupplyTemperature);
  if ( $params['jeedom'] == 0 ) echo "GetSupplyTemperature: $GetSupplyTemperature<br/>";
    //
  $GetHotWaterStorageTemperature = GetHotWaterStorageTemperature($dec);
  $cmd = cmd::byId($virtualCMDs['HotWaterStorageTemperature']);
  $virtual->checkAndUpdateCmd($cmd, $GetHotWaterStorageTemperature);
  if ( $params['jeedom'] == 0 ) echo "GetHotWaterStorageTemperature: $GetHotWaterStorageTemperature<br/>";
    //
  $GetDhwGasConsumptionToday = GetDhwGasConsumptionToday($dec);
  $cmd = cmd::byId($virtualCMDs['DhwGasConsumptionToday']);
  $virtual->checkAndUpdateCmd($cmd, $GetDhwGasConsumptionToday);
  if ( $params['jeedom'] == 0 ) echo "GetDhwGasConsumptionToday: $GetDhwGasConsumptionToday<br/>";
    //
  $GetHeatingGasConsumptionToday = GetHeatingGasConsumptionToday($dec);
  $cmd = cmd::byId($virtualCMDs['HeatingGasConsumptionToday']);
  $virtual->checkAndUpdateCmd($cmd, $GetHeatingGasConsumptionToday);  
  if ( $params['jeedom'] == 0 ) echo "GetHeatingGasConsumptionToday: $GetHeatingGasConsumptionToday<br/>";
    //
  if ( $params['jeedom'] == 0 ) {   // on ne recupere les infos suivantes que pour lancement manuel
    $GetDhwTemperature = GetDhwTemperature($dec);
    echo "GetDhwTemperature: $GetDhwTemperature<br/>";
    // scenario::setData('Viessmann_GetDhwTemperature', $GetDhwTemperature);
    //
    $GetNormalProgramTemperature = GetNormalProgramTemperature($dec);
    echo "GetNormalProgramTemperature: $GetNormalProgramTemperature<br/>";
    //scenario::setData('Viessmann_GetNormalProgramTemperature', $GetNormalProgramTemperature);
    //
    $GetEcoProgramTemperature = GetEcoProgramTemperature($dec);
    echo "GetEcoProgramTemperature: $GetEcoProgramTemperature<br/>";
    //scenario::setData('Viessmann_GetEcoProgramTemperature', $GetEcoProgramTemperature);
    //
    $GetReducedProgramTemperature = GetReducedProgramTemperature($dec);
    echo "GetReducedProgramTemperature: $GetReducedProgramTemperature<br/>";
    //scenario::setData('Viessmann_GetReducedProgramTemperature', $GetReducedProgramTemperature);
    //
    $GetComfortProgramTemperature = GetComfortProgramTemperature($dec);
    echo "GetComfortProgramTemperature: $GetComfortProgramTemperature<br/>";
    //scenario::setData('Viessmann_GetComfortProgramTemperature', $GetComfortProgramTemperature);
    //
    $GetActiveMode = GetActiveMode($dec);
    echo "GetActiveMode: $GetActiveMode<br/>";
    //scenario::setData('Viessmann_GetActiveMode', $GetActiveMode);
    //
    $GetActiveProgram = GetActiveProgram($dec);
    echo "GetActiveProgram: $GetActiveProgram<br/>";
    //scenario::setData('Viessmann_GetActiveProgram', $GetActiveProgram);
    //
    $GetHeatingBurnerModulation = GetHeatingBurnerModulation($dec);
    echo "GetHeatingBurnerModulation: $GetHeatingBurnerModulation<br/>";
    //scenario::setData('Viessmann_GetHeatingBurnerModulation', $GetHeatingBurnerModulation);
    //
    $GetHeatingBurnerAutomatic = GetHeatingBurnerAutomatic($dec);
    echo "GetHeatingBurnerAutomatic: $GetHeatingBurnerAutomatic<br/>";
    //scenario::setData('Viessmann_GetHeatingBurnerAutomatic', $GetHeatingBurnerAutomatic);
    //
    $GetHeatingBurnerStatisticsHours = GetHeatingBurnerStatisticsHours($dec);
    echo "GetHeatingBurnerStatisticsHours: $GetHeatingBurnerStatisticsHours<br/>";
    //scenario::setData('Viessmann_GetHeatingBurnerStatisticsHours', $GetHeatingBurnerStatisticsHours);
    //
    $GetHeatingBurnerStatisticsStarts = GetHeatingBurnerStatisticsStarts($dec);
    echo "GetHeatingBurnerStatisticsStarts: $GetHeatingBurnerStatisticsStarts<br/>";
    //scenario::setData('Viessmann_GetHeatingBurnerStatisticsStarts', $GetHeatingBurnerStatisticsStarts);
    //
    $GetShift = GetShift($dec);
    echo "GetShift: $GetShift<br/>";
    //scenario::setData('Viessmann_GetShift', $GetShift);
    //
    $GetSlope = GetSlope($dec);
    echo "GetSlope: $GetSlope<br/>";
    //scenario::setData('Viessmann_GetSlope', $GetSlope);
    //
    $GetHeatingGasConsumptionDays = GetGasConsumption($dec,"Heating","day");
    echo "GetHeatingGasConsumptionDays: $GetHeatingGasConsumptionDays<br/>";
    //scenario::setData('Viessmann_GetHeatingGasConsumptionDays', $GetHeatingGasConsumptionDays);
    //
    $GetHeatingGasConsumptionWeeks = GetGasConsumption($dec,"Heating","week");
    echo "GetHeatingGasConsumptionWeeks: $GetHeatingGasConsumptionWeeks<br/>";
    //scenario::setData('Viessmann_GetHeatingGasConsumptionWeeks', $GetHeatingGasConsumptionWeeks);
    //
    $GetHeatingGasConsumptionMonths = GetGasConsumption($dec,"Heating","month");
    echo "GetHeatingGasConsumptionMonths: $GetHeatingGasConsumptionMonths<br/>";
    //scenario::setData('Viessmann_GetHeatingGasConsumptionMonths', $GetHeatingGasConsumptionMonths);
    //
    $GetHeatingGasConsumptionYears = GetGasConsumption($dec,"Heating","year");
    echo "GetHeatingGasConsumptionYears: $GetHeatingGasConsumptionYears<br/>";
    //scenario::setData('Viessmann_GetHeatingGasConsumptionYears', $GetHeatingGasConsumptionYears);
    //
    $GetDhwGasConsumptionDays = GetGasConsumption($dec,"Dhw","day");
    echo "GetDhwGasConsumptionDays: $GetDhwGasConsumptionDays<br/>";
    //scenario::setData('Viessmann_GetDhwGasConsumptionDays', $GetDhwGasConsumptionDays);
    //
    $GetDhwGasConsumptionWeeks = GetGasConsumption($dec,"Dhw","week");
    echo "GetDhwGasConsumptionWeeks: $GetDhwGasConsumptionWeeks<br/>";
    //scenario::setData('Viessmann_GetDhwGasConsumptionWeeks', $GetDhwGasConsumptionWeeks);
    //
    $GetDhwGasConsumptionMonths = GetGasConsumption($dec,"Dhw","month");
    echo "GetDhwGasConsumptionMonths: $GetDhwGasConsumptionMonths<br/>";
    //scenario::setData('Viessmann_GetDhwGasConsumptionMonths', $GetDhwGasConsumptionMonths);
    //
    $GetDhwGasConsumptionYears = GetGasConsumption($dec,"Dhw","year");
    echo "GetDhwGasConsumptionYears: $GetDhwGasConsumptionYears<br/>";
    //scenario::setData('Viessmann_GetDhwGasConsumptionYears', $GetDhwGasConsumptionYears);
  }
  date_default_timezone_set( "UTC" );
  $seconds = timezone_offset_get( timezone_open("Europe/Paris"), new DateTime() );
  $TokenExpiresAt = date("Y-m-d H:i:s",$params['tokenExpires']+$seconds);
  scenario::setData('Viessmann_TokenExpiresAt', $TokenExpiresAt);
  
  if ( $params['jeedom'] == 0 ) echo "TokenExpiresAt: $TokenExpiresAt " .$params['tokenExpires'] ."<br/>";
}

function UpdateDate($JsonFile) {
  echo "Fichier $JsonFile " .filemtime($JsonFile) ."<br/>";
  // Don't know where the server is or how its clock is set, so default to UTC
  date_default_timezone_set( "UTC" );
  $seconds = timezone_offset_get( timezone_open("Europe/Paris"), new DateTime() );
  // echo strftime("%Y-%m-%d %H:%M:%S", filemtime($JsonFile)+$seconds);
  $UpdateDate = date("Y-m-d H:i:s", filemtime($JsonFile)+$seconds);
  return $UpdateDate;
}
function GetOutsideTemperature($dec) {
  return(getValueByFeature($dec,"heating.sensors.temperature.outside","value","value"));
}
function GetRoomTemperature($dec) {
  return(getValueByFeature($dec,"heating.circuits.0.sensors.temperature.room","value","value"));
}
function GetBoilerTemperature($dec) {
  return(getValueByFeature($dec,"heating.boiler.sensors.temperature.main","value","value"));}
function GetHotWaterStorageTemperature($dec) {
  return(getValueByFeature($dec,"heating.dhw.sensors.temperature.hotWaterStorage","value","value"));
}
function GetDhwTemperature($dec) {
  return(getValueByFeature($dec,"heating.dhw.temperature","value","value"));
}
function GetNormalProgramTemperature($dec) {
  return(getValueByFeature($dec,"heating.circuits.0.operating.programs.normal","temperature","value"));
}
function GetEcoProgramTemperature($dec) {
  return(getValueByFeature($dec,"heating.circuits.0.operating.programs.eco","temperature","value"));
}
function GetReducedProgramTemperature($dec) {
  return(getValueByFeature($dec,"heating.circuits.0.operating.programs.reduced","temperature","value"));
}
function GetComfortProgramTemperature($dec) {
  return(getValueByFeature($dec,"heating.circuits.0.operating.programs.comfort","temperature","value"));
}
function GetActiveMode($dec) {
  return(getValueByFeature($dec,"heating.circuits.0.operating.modes.active","value","value"));
}
function GetActiveProgram($dec) {
  return(getValueByFeature($dec,"heating.circuits.0.operating.programs.active","value","value"));
}
function GetSupplyTemperature($dec) {
  return(getValueByFeature($dec,"heating.circuits.0.sensors.temperature.supply","value","value"));
}
function GetHeatingBurnerModulation($dec) {
  return(getValueByFeature($dec,"heating.burner.modulation","value","value"));
}
function GetHeatingBurnerAutomatic($dec) {
  return(getValueByFeature($dec,"heating.burner.automatic","status","value"));
}
function GetDhwGasConsumptionToday($dec) {
  $val = getValueByFeature($dec,"heating.gas.consumption.dhw","day","value");
  if ( count($val) >= 1 ) {
    return $val[0];
  }
  else return -1;
}
function GetHeatingGasConsumptionToday($dec) {
  $val = getValueByFeature($dec,"heating.gas.consumption.heating","day","value");
  if ( count($val) >= 1 ) {
    return $val[0];
  }
  else return -1;
}
function GetHeatingBurnerStatisticsHours($dec) {
  return(getValueByFeature($dec,"heating.burner.statistics","hours","value"));
}
function GetHeatingBurnerStatisticsStarts($dec) {
  return(getValueByFeature($dec,"heating.burner.statistics","starts","value"));
}
// parallele
function GetShift($dec) {
  return(getValueByFeature($dec,"heating.circuits.0.heating.curve","shift","value"));
}
// pente
function GetSlope($dec) {
  return(getValueByFeature($dec,"heating.circuits.0.heating.curve","slope","value"));
}
function GetGasConsumption($dec,$type,$period) {
  if ($type=='Dhw') $fct = "heating.gas.consumption.dhw";
  else $fct = "heating.gas.consumption.heating";
  $val = getValueByFeature($dec,$fct,$period,"value");
  $conso ='';
  $nb = count($val);
  $tabstyle = "<style> th, td { padding : 2px; } </style><style> th { text-align:center; } </style><style> td { text-align:right; } </style>";
  $conso .= "$tabstyle<table border=1><tr>";
  if ( $period == "day" )
  { 
    for ( $i = $nb-1; $i>=0; $i--)
    // for ( $i = 0; $i<$nb; $i++)
    { $conso .= "<td>";
      if ( $i == 0 ) $conso .= "Auj.";
      else $conso .= ucfirst(strftime("%a %d",time()-86400*$i));
      $conso .= "<br/>".$val[$i]."kW";
      $conso .= "</td>";
    }
  }
  else if ( $period == "week" )
  { // $max = $nb-1;
    $st = 1;
    $max = 25; // Si le texte est trop long, il n'est pas affiche
    for ( $i = $max; $i>=0; $i--) if ( $val[$i] > 0 || $st == 0 )
    { $st = 0;
      $conso .= "<td>";
      if ( $i == 0 ) $conso .= "Cette semaine";
      else $conso .= "S ".strftime("%V",time()-86400*7*$i);
      $conso .= "<br/>".$val[$i]."kW";
      $conso .= "</td>";
    }
  }
  else if ( $period == "month" )
  { for ( $i = $nb-1; $i>=0; $i--) if ( $val[$i] > 0 )
    { $conso .= "<td>";
      if ( $i == 0 ) $conso .= "Ce mois";
      else $conso .= ucfirst(strftime("%h %Y",mktime(0,0,0,date("n")-$i)));
      $conso .= "<br/>".$val[$i]."kW";
      $conso .= "</td>";
    }
  }
  else if ( $period == "year" )
  { $year = date("Y",time());
    for ( $i = $nb-1; $i>=0; $i--) if ( $val[$i] > 0 )
    { $conso .= "<td>";
      if ( $i == 0 ) $conso .= "Cette annee";
      else $conso .= ($year - $i);
      $conso .= "<br/>".$val[$i]."kW";
      $conso .= "</td>";
    }
  }
  else
  { for ( $i =0; $i<$nb; $i++) $conso .= "<td>$i</td>";
    $conso .= "</tr><tr>";
    for ( $i =0; $i<$nb; $i++) $conso .= "<td>".$val[$i]."kW</td>";
  }
  $conso .= "</tr></table>";
  return($conso);
}

function getFeatureId($dec,$feature)
{ if ( array_key_exists( "entities", $dec)) {
    $nb=count($dec["entities"]);
    for($i=0;$i<$nb;$i++)
      if($dec ["entities"] [$i] ["entities"] [0] ["properties"] ["feature"] == $feature)
        return($i);
  }
  return(-1);
}

function getValueByFeature($dec,$feature,$k1="",$k2="") {
  global $jeedom;
  $i = getFeatureId($dec,$feature);
  if($i==-1) {
    if ($jeedom == 0) 
      echo "getValueByFeature Error. Feature not found: [$feature]<br/>";
    else
      log::add('script', 'warning',  __FILE__ ." getValueByFeature. Feature not found: [$feature]");
    exitOnError();
  }
  else {
    if(array_key_exists($k1,$dec["entities"] [$i] ["properties"]))
      $val = $dec ["entities"] [$i] ["properties"][$k1][$k2];
    else {
      if ($jeedom == 0) 
        echo "getValueByFeature Error. Feature [$feature] found, with bad value : [$i], [$k1], [$k2]<br/>";
      else
        log::add('script', 'warning',  __FILE__ ." getValueByFeature Error. Feature [$feature] found, with bad value : [$i], [$k1], [$k2]");
      exitOnError();
	}
    return($val);
  }
}

function exitOnError(){
  scenario::removeData('Viessmann_token');
  scenario::removeData('Viessmann_tokenExpires');
  exit();
}

function getAuthCode($params,$client_id,$callback_uri) {
  //  echo __FUNCTION__ .' ' .$params['username'] .":" .$params['password'] .'<br/>';
  $authorizeURL = 'https://iam.viessmann.com/idp/v1/authorize';
  $url = "$authorizeURL?client_id=$client_id&scope=openid&redirect_uri=$callback_uri&response_type=code";
  $header = array("Content-Type: application/x-www-form-urlencoded");
  $curloptions = array( CURLOPT_URL => $url, CURLOPT_HTTPHEADER => $header,
    CURLOPT_SSL_VERIFYPEER => false, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD => $params['username'].":".$params['password'],
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_TIMEOUT => 15,
    CURLOPT_POST => true);
  $curl = curl_init();
  curl_setopt_array($curl, $curloptions);
  $response = curl_exec($curl);
  /*
  $hdle = fopen(__DIR__ ."/viessmann-AuthCode.txt", "wb");
  if($hdle !== FALSE) {
    fwrite($hdle, $response);
    fclose($hdle);
  }
   */
  curl_close($curl);
  $matches = array();
  $pattern = '/code=(.*)"/';
  preg_match_all($pattern, $response, $matches);
  //if ($jeedom == 0)
    //echo " Code: " .$matches[1][0] ."<br/>";
  // log::add('script','error',__FUNCTION__ ." Code: " .$matches[1][0]);
  return ($matches[1][0]);
}

function getToken(&$params) { 
  $client_id = '79742319e39245de5f91d15ff4cac2a8';
  $client_secret = '8ad97aceb92c5892e102b093c7c083fa';
  $callback_uri = "vicare://oauth-callback/everest";
  $token_url = 'https://iam.viessmann.com/idp/v1/token';
  $authorization_code = getAuthCode($params,$client_id,$callback_uri);
  if ( $authorization_code == '' ){
	if ($jeedom == 0) 
      echo "getToken - unable to get Authorization code<br/>";
    else
	  log::add('script','error', "getToken - unable to get Authorization code");
	return(-1);
  }
  $header = array("Content-Type: application/x-www-form-urlencoded;charset=utf-8");
  $paramCurl = array( "client_id" => $client_id, "client_secret" => $client_secret,
      "code" => $authorization_code, "redirect_uri" => $callback_uri,
      "grant_type" => "authorization_code");
  $curloptions = array( CURLOPT_URL => $token_url, CURLOPT_HEADER => false,
      CURLOPT_HTTPHEADER => $header, CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15,
      CURLOPT_POSTFIELDS => rawurldecode(http_build_query($paramCurl)));
  $curl = curl_init();
  curl_setopt_array($curl, $curloptions);
  $response = curl_exec($curl);
  curl_getinfo($curl);
  curl_close($curl);
  if ($response === false){
	if ($jeedom == 0) 
      echo "getToken - Failed curl_error: " .curl_error($curl) . "<br/>";
    else
	  log::add('script','error', "getToken - Failed curl_error: " .curl_error($curl));
	return(-1);
  }
  else if (!empty(json_decode($response)->error)) {
	if ($jeedom == 0) 
      echo "getToken error - AuthCode : $authorization_code Response $response<br/>";
    else
      log::add('script','info', "Error: getToken - AuthCode : $authorization_code Response $response");
	return(-1);
  }
  /* // Pour DEBUG verif du retour
  $hdle = fopen(__DIR__ ."/viessmann-Token.json", "wb");
  if($hdle !== FALSE) {
    fwrite($hdle, $response);
    fclose($hdle);
// echo 'Update ' .$JsonFile .' to :' .date ("F d Y H:i:s.", filemtime($JsonFile)) .'<br/>';
  }
*/
  // echo 'tokenExpiresIn ' .json_decode($response)->expires_in;
  $params['token'] = json_decode($response)->access_token;
    // expire 80s avant
  $params['tokenExpires'] = time() + json_decode($response)->expires_in -80;
  return(0);
}

function getResource($params, $api) {
  $header = array("Authorization: Bearer {$params['token']}");
  $curl = curl_init();
  curl_setopt_array($curl, array(
      CURLOPT_URL => $api, CURLOPT_HTTPHEADER => $header, CURLOPT_TIMEOUT => 15,
      CURLOPT_SSL_VERIFYPEER => false, CURLOPT_RETURNTRANSFER => true));
  $response = curl_exec($curl);
  // echo "response = $response<br/>";
  curl_close($curl);
  return ($response);
}

function getUserPass(&$params,$credFile) {
    // init user et passwd, recherche dans fichier
  if(file_exists($credFile)) {
    $cred = file($credFile);
    $params['username'] = rtrim($cred[0]);
    $params['password'] = rtrim($cred[1]);
  }
}

function getTokenViessmann(&$params) {
  $params['token'] = scenario::getData('Viessmann_token');
  $params['tokenExpires'] = scenario::getData('Viessmann_tokenExpires');
  if ( $params['token'] == '' || $params['tokenExpires' == '']  ||
    ($params['tokenExpires'] != '' && $params['tokenExpires'] < time()) ) { // expired
    if ( $params['jeedom'] == 0 ) echo "Get new token<br/>";
    $ret = getToken($params);
    if ( $ret != -1 ) {
      scenario::setData('Viessmann_token', $params['token']);
      scenario::setData('Viessmann_tokenExpires', $params['tokenExpires']);
    }
	else {
	  exitOnError();
	  return(-1);
	}
  }
  if ( $params['jeedom'] == 0 ) {
    echo "Date : " . date("Y-m-d H:i:s") ."<br/>\n";
    echo "TokenExpires " . date("Y-m-d H:i:s",$params['tokenExpires'])
         ." dans " . ($params['tokenExpires'] - time()) ."s<br/>\n";
  }
  return ( $ret );
}
function getInstGwViessmann(&$params, $force=false) {
  //if ($params['installation'] == '' || $$params['gateway'] == '') {
  //  $params['installation'] = scenario::getData('Viessmann_installation');
  //  $params['gateway'] = scenario::getData('Viessmann_gateway');
  //}
  echo "force = $force<br/>\n";
  if ( $force == true || $params['installation'] == '' || $params['gateway'] == '' ) {
      // Recup installation et gateway
    $apiURLBase = 'https://api.viessmann-platform.io/general-management/installations';
    $resource = getResource($params, $apiURLBase);
    //echo "resource: $resource<br/>\n";
    /* Pour DEBUG verif contenu
    $hdle = fopen(__DIR__ ."/viessmann-InstGw.json", "wb");
    if($hdle !== FALSE) {
      fwrite($hdle, $resource);
      fclose($hdle);
    }
     */
    $params['installation'] = json_decode($resource, true)["entities"][0]["properties"]["id"];
    $params['gateway'] = json_decode($resource,true)["entities"][0]["entities"][0]["properties"]["serial"];
    //scenario::setData('Viessmann_installation', $params['installation']);
    //scenario::setData('Viessmann_gateway', $params['gateway']);
    echo "installation = $params[installation]<br/>\n";
    echo "gateway = $params[gateway]<br/>\n";
  }  
  if ($params['installation'] == '' || $params['gateway'] == '') {
	if ( $params['jeedom'] == 0 )
	  echo "getInstGwViessmann. Erreur recuperation installation ou gateway<br/>";
	else 
	  log::add('script','error',"getInstGwViessmann. Erreur recuperation installation ou gateway");
	exitOnError();
  }
  if ( $params['jeedom'] == 0 ) {
    echo "Installation = " .$params['installation'] ."<br/>\n";
    echo "Gateway = " .$params['gateway'] ."<br/>\n";
  }
}
function getResourceViessmann(&$params,$credFile,$JsonFile,$featOnly='') {
  getUserPass($params,$credFile);
// echo "UserName = " .$params['username'] ." Password = " .$params['password'] ."<br/>\n";
  if ( ($ret = getTokenViessmann($params)) ) exitOnError();  // Erreur recup token
  getInstGwViessmann($params);
  $features = "https://api.viessmann-platform.io/operational-data/installations/"
    .$params['installation'] ."/gateways/".$params['gateway'] ."/devices/0/features";
  if ( $featOnly != '' ) $features .= "/$featOnly";
  $t0 = microtime(true);
  $resource = getResource($params,$features);
  $diff = round(microtime(true)-$t0,3);
  if ($params['jeedom'] == 0) {
    echo "<br/>Download Viessmann data: ".$diff."s<br/><br/>";
  }
    // Si demande de toutes les features. Ecriture fichier pour reutilisation.
  if($featOnly == '' ) {
    $fichierJson = fopen($JsonFile, "w");
    if($fichierJson !== FALSE) {
      fwrite($fichierJson, $resource);
      fclose($fichierJson);
    }
    // scenario::setData('Viessmann_DlTimeAll', $diff);
  }
  // else scenario::setData('Viessmann_DlTimePart', $diff);
  return($resource);
}
