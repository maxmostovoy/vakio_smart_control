<?php

use function PHPSTORM_META\type;

chdir(dirname(__FILE__) . '/../');
include_once("./config.php");
include_once("./lib/loader.php");
include_once("./lib/threads.php");

set_time_limit(0);

include_once("./load_settings.php");
include_once(DIR_MODULES . "control_modules/control_modules.class.php");

$ctl = new control_modules();

include_once(ROOT . "3rdparty/phpmqtt/phpMQTT.php");
include_once(DIR_MODULES . 'vakio/vakio.class.php');

$vakio_module = new vakio();
$vakio_module->getConfig();

$tmp = SQLSelectOne("SELECT `ID` FROM `vakio_devices` LIMIT 1");
if (!isset($tmp['ID']))
   exit; // no devices added -- no need to run this cycle

echo date("H:i:s") . " running " . basename(__FILE__) . PHP_EOL;
$latest_check=0;
$checkEvery=2; // poll every 5 seconds

if (isset($vakio_module->config["MQTT_CLIENT"])) {
   $client_name = $vakio_module->config['MQTT_CLIENT'];
} else {
   $client_name = "majordomo-vakio-client-" . random_int(1, 100);
}

if (isset($vakio_module->config['MQTT_AUTH'])) {
   $username = $vakio_module->config['MQTT_USERNAME'];
   $password = $vakio_module->config['MQTT_PASSWORD'];
} else {
   $username = "";
   $password = "";
}

$host = 'localhost';

if (isset($vakio_module->config['MQTT_HOST'])) {
   $host = $vakio_module->config['MQTT_HOST'];
}
if (isset($vakio_module->config['MQTT_PORT'])) {
   $port = $vakio_module->config['MQTT_PORT'];
} else {
   $port = 1883;
}

$mqtt_client = new Bluerhinos\phpMQTT($host, $port, $client_name);

$connect = $mqtt_client->connect(true, NULL, $username, $password);

if (!$connect) {
   exit(1);
}

$query_list = array();
$rec = SQLSelect("SELECT `VAKIO_DEVICE_MQTT_TOPIC` FROM `vakio_devices` WHERE 1");
foreach($rec as $row) {
   $query_list[] = $row['VAKIO_DEVICE_MQTT_TOPIC'];
}
unset($row);

$total = count($query_list);
echo date('H:i:s') . " Topics to watch: $total\n";

for ($i = 0; $i < $total; $i++) {
   $path = trim($query_list[$i]) . "/#";
   echo date('H:i:s') . " Path: $path\n";
   $topics[$path] = array("qos" => 0, "function" => "procmsg");
}

// SQLExec("UPDATE `vakio_devices` SET `VAKIO_DEVICE_STATE`='{}'");

$mqtt_client->subscribe($topics, 0);
$previousMillis = 0;

while ($mqtt_client->proc()){
   $operations = checkOperationsQueue('vakio');
   for ($i=0; $i<count($operations); $i++) {
      $topic = $operations[$i]["DATANAME"];
      $value = $operations[$i]["DATAVALUE"];
	  echo date("H:i:s")." Send data to ".$topic.": ".$value.PHP_EOL;
      $mqtt_client->publish($topic, $value, 0, true);
   }
   $currentMillis = round(microtime(true) * 10000);
   if ($currentMillis - $previousMillis > 200000) {
      $previousMillis = $currentMillis;
      setGlobal((str_replace('.php', '', basename(__FILE__))) . 'Run', time(), 1);

      if (file_exists('./reboot') || IsSet($_GET['onetime'])) {
         $mqtt_client->close();
         exit;
      }
   }
}


$mqtt_client->close();

/**
 * По полученному топику определяет устройство, которому обновляет поле VAKIO_DEVICE_STATE и привязанные свойства.
 * @param mixed $topic Topic
 * @param mixed $msg Message
 * @return void
 */
function procmsg($topic, $msg) {
   echo date("H:i:s")." Receive data from ". $topic.": ".$msg;
   if (!isset($topic) || !isset($msg)) return false;
   $topic_parts = explode("/", $topic);
   $topic_parts_count = count($topic_parts);
   $topic_db_format = $topic_parts[0];
   for ($i = 1; $i < $topic_parts_count - 1; $i++){
      $topic_db_format = $topic_db_format . "/" . $topic_parts[$i];
   }
   $endpoint = $topic_parts[$topic_parts_count - 1];
   $rec = SQLSelectOne("SELECT * FROM `vakio_devices` WHERE `VAKIO_DEVICE_MQTT_TOPIC`='$topic_db_format'");
   
   if(!isset($rec['ID'])) {
      echo date("Y-m-d H:i:s") . " Ignore received from {$topic} : $msg\n";
      return false;
   }
   $state = json_decode($rec["VAKIO_DEVICE_STATE"], true);
   $state[$endpoint] = $msg;
   $rec["VAKIO_DEVICE_STATE"] = json_encode($state);
   SQLUpdate("vakio_devices", $rec);
   $info = SQLSelectOne('SELECT * FROM vakio_info WHERE DEVICE_ID="'.$rec['ID'].'" AND TITLE="'.$endpoint.'"');
   if($msg == "on") $msg = 1;
   else if($msg == "off") $msg = 0;
   if($info['VALUE'] != $msg){
      global $vakio_module;
      $vakio_module->setProperty($info, $msg);
	  echo ". Property updated";
   }
   echo PHP_EOL;
}

