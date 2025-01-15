<?php
/**
* Vakio Smart Control 
* @package project
* @author Wizard <sergejey@gmail.com>
* @copyright http://majordomo.smartliving.ru/ (c)
* @version 0.1 (wizard, 06:07:32 [Jul 03, 2023])
*/
//
//
class vakio extends module {
/**
* vakio
*
* Module class constructor
*
* @access private
*/
function __construct() {
  $this->name="vakio";
  $this->title="Vakio Smart Control";
  $this->module_category="<#LANG_SECTION_DEVICES#>";
  $this->checkInstalled();
}
/**
* saveParams
*
* Saving module parameters
*
* @access public
*/
function saveParams($data=1) {
 $p=array();
 if (IsSet($this->id)) {
  $p["id"]=$this->id;
 }
 if (IsSet($this->view_mode)) {
  $p["view_mode"]=$this->view_mode;
 }
 if (IsSet($this->edit_mode)) {
  $p["edit_mode"]=$this->edit_mode;
 }
 if (IsSet($this->tab)) {
  $p["tab"]=$this->tab;
 }
 return parent::saveParams($p);
}
/**
* getParams
*
* Getting module parameters from query string
*
* @access public
*/
function getParams() {
  global $id;
  global $mode;
  global $view_mode;
  global $edit_mode;
  global $tab;
  if (isset($id)) {
   $this->id=$id;
  }
  if (isset($mode)) {
   $this->mode=$mode;
  }
  if (isset($view_mode)) {
   $this->view_mode=$view_mode;
  }
  if (isset($edit_mode)) {
   $this->edit_mode=$edit_mode;
  }
  if (isset($tab)) {
   $this->tab=$tab;
  }
}
/**
* Run
*
* Description
*
* @access public
*/
function run() {
 global $session;
  $out=array();
  if ($this->action=='admin') {
   $this->admin($out);
  } else {
   $this->usual($out);
  }
  if (IsSet($this->owner->action)) {
   $out['PARENT_ACTION']=$this->owner->action;
  }
  if (IsSet($this->owner->name)) {
   $out['PARENT_NAME']=$this->owner->name;
  }
  $out['VIEW_MODE']=$this->view_mode;
  $out['EDIT_MODE']=$this->edit_mode;
  $out['MODE']=$this->mode;
  $out['ACTION']=$this->action;
  $out['TAB']=$this->tab;
  $this->data=$out;
  $p=new parser(DIR_TEMPLATES.$this->name."/".$this->name.".html", $this->data, $this);
  $this->result=$p->result;
}
/**
* BackEnd
*
* Module backend
*
* @access public
*/
function admin(&$out) {
  $this->getConfig();
  $out['MQTT_CLIENT'] = $this->config['MQTT_CLIENT'];
  $out['MQTT_HOST'] = $this->config['MQTT_HOST'];
  $out['MQTT_PORT'] = $this->config['MQTT_PORT'];

  if (!$out['MQTT_HOST']) {
    $out['MQTT_HOST'] = 'localhost';
  }
  if (!$out['MQTT_PORT']) {
    $out['MQTT_PORT'] = '1883';
  }

  $out['MQTT_USERNAME'] = $this->config['MQTT_USERNAME'];
  $out['MQTT_PASSWORD'] = $this->config['MQTT_PASSWORD'];
  $out['MQTT_AUTH'] = $this->config['MQTT_AUTH'];

 
  if ($this->view_mode=='update_settings') {
    $mqtt_client = gr('mqtt_client');
    $mqtt_host = gr('mqtt_host');;
    $mqtt_username = gr('mqtt_username');;
    $mqtt_password = gr('mqtt_password');;
    $mqtt_port = gr('mqtt_port');;
    $mqtt_auth = gr('mqtt_auth');;

    $this->config['MQTT_CLIENT'] = trim($mqtt_client);
    $this->config['MQTT_HOST'] = trim($mqtt_host);
    $this->config['MQTT_USERNAME'] = trim($mqtt_username);
    $this->config['MQTT_PASSWORD'] = trim($mqtt_password);
    $this->config['MQTT_AUTH'] = (int)$mqtt_auth;
    $this->config['MQTT_PORT'] = (int)$mqtt_port;

    $this->saveConfig();

    setGlobal('cycle_vakioControl', 'restart');

    $this->redirect("?");
  }
  if (isset($this->data_source) && !$_GET['data_source'] && !$_POST['data_source']) {
    $out['SET_DATASOURCE']=1;
  }
  if ($this->data_source=='vakio_devices' || $this->data_source=='') {
    if ($this->view_mode=='' || $this->view_mode=='search_vakio_devices') {
      $this->search_vakio_devices($out);
    }
    if ($this->view_mode=='edit_vakio_devices') {
      $this->edit_vakio_devices($out, $this->id);
    }
    if ($this->view_mode=='devices') {
      $this->devices($out, $this->id);
    }
    if ($this->view_mode=='delete_vakio_devices') {
      $this->delete_vakio_devices($this->id);
      $this->redirect("?");
    }
  }
}
/**
* FrontEnd
*
* Module frontend
*
* @access public
*/
function usual(&$out) {
  if ($this->ajax) {
    $op = gr('op');
    $data = array();
    if ($op=="poll") {
      $res = SQLSelect("SELECT * FROM `vakio_devices`");
      $devices = array();
      for ($i=0; $i<count($res); $i++) {
        $id = $res[$i]["ID"];
        $devices[$id] = json_decode($res[$i]['VAKIO_DEVICE_STATE'], true);
        if (isset($devices[$id]["workmode"]) && ($devices[$id]["workmode"] == "inflow_max" || $devices[$id]["workmode"] == "outflow_max")) {
          $devices[$id]["speed"] = "7";
        }
        if (isset($devices[$id]["gate"]) && isset($devices[$id]["speed"]) && intval($devices[$id]["speed"])>0) {
          $devices[$id]["gate"] = "4";
        }
      }
      $data["devices"] = $devices;
    }
    elseif ($op=="public") {
      $id = gr('id');
      $topic_endpoint = gr('topic');
      $value = gr('value');
      if (!isset($topic_endpoint) || !isset($value) || !isset($id)) {
        return;
      }
	
      $rec=SQLSelectOne("SELECT * FROM vakio_devices WHERE ID='$id'");
      // Формирование топика по запросу в БД (По AJAX приходит только конечная точка, топик определяется по ID устройства)
      $topic = $rec["VAKIO_DEVICE_MQTT_TOPIC"] . "/" . $topic_endpoint;
      // Добавление в очередь, которая обрабатывается в цикле
      addToOperationsQueue('vakio', $topic, $value);
	  
	  // Передаем значение в привязанное свойство/метод
	  $prop=SQLSelectOne("SELECT * FROM vakio_info WHERE DEVICE_ID='".$rec['ID']."' AND TITLE='".$topic_endpoint."'");
	  $this->setProperty($prop, $value);
      
    }
    echo json_encode($data);
    return;
  }

  $this->admin($out);
}

function api($params) {
	$device = SQLSelectOne("SELECT * FROM vakio_devices WHERE TITLE='".$params['name']."'");
	$topic = $device["VAKIO_DEVICE_MQTT_TOPIC"] . "/" . $params['topic'];
    addToOperationsQueue('vakio', $topic, $params['data']);
	// Обновляем значение в таблице свойств
	if($params['data'] == "on") $params['data'] = 1;
	else if($params['data'] == "off") $params['data'] = 0;
	$prop=SQLSelectOne("SELECT * FROM vakio_info WHERE DEVICE_ID='".$device['ID']."' AND TITLE='".$params['topic']."'");
	$this->setProperty($prop, $params['data']);	  	
}

/**
* vakio_devices search
*
* @access public
*/
 function search_vakio_devices(&$out) {
  require(dirname(__FILE__).'/vakio_devices_search.inc.php');
 }
  /**
  * vakio_devices search
  *
  * @access public
  */
  function devices(&$out) {
    require(dirname(__FILE__).'/devices.inc.php');
  }
  /**
  * vakio_devices edit/add
  *
  * @access public
  */
  function edit_vakio_devices(&$out, $id) {
    require(dirname(__FILE__).'/vakio_devices_edit.inc.php');
  }
  /**
  * vakio_devices delete record
  *
  * @access public
  */
  function delete_vakio_devices($id) {
    $rec=SQLSelectOne("SELECT * FROM vakio_devices WHERE ID='$id'");
    // some action for related tables
    SQLExec("DELETE FROM vakio_devices WHERE ID='".$rec['ID']."'");
	$properties=SQLSelect("SELECT * FROM vakio_info WHERE DEVICE_ID='".$rec['ID']."' AND LINKED_OBJECT != '' AND LINKED_PROPERTY != ''");
    foreach($properties as $prop) {
		removeLinkedProperty($prop['LINKED_OBJECT'], $prop['LINKED_PROPERTY'], $this->name);
	}
	SQLExec("DELETE FROM vakio_info WHERE DEVICE_ID='".$rec['ID']."'");
  }
  
  
  function processCycle() {
    $this->getConfig();
      //to-do
  }
   //Запись в привязанное свойство/метод
  function setProperty($prop, $value, $params = []){
	if($value == 'on') $value = 1;
	else if($value == 'off') $value = 0;
	if($prop['VALUE'] != $value);{
		// Обновляем значение в таблице свойств
		$prop['VALUE'] = $value;
		$prop['UPDATED'] = date('Y-m-d H:i:s');
		SQLUpdate("vakio_info", $prop);
		if (isset($prop['LINKED_OBJECT']) && isset($prop['LINKED_PROPERTY'])) {
			setGlobal($prop['LINKED_OBJECT'] . '.' . $prop['LINKED_PROPERTY'], $value, array($this->name=>1), $this->name);
		}
		if (isset($prop['LINKED_OBJECT']) && isset($prop['LINKED_METHOD'])) {
			$params['VALUE'] = $value;
			callMethodSafe($prop['LINKED_OBJECT'] . '.' . $prop['LINKED_METHOD'], $params);
		}
	}
}
	//Запись из привязанного свойства
  function propertySetHandle($object, $property, $value) {
   $this->getConfig();
   $table='vakio_info';
   $properties=SQLSelect("SELECT ID FROM $table WHERE LINKED_OBJECT LIKE '".DBSafe($object)."' AND LINKED_PROPERTY LIKE '".DBSafe($property)."'");
   $total=count($properties);
   if ($total) {
    for($i=0;$i<$total;$i++) {
     $property = SQLSelectOne("SELECT * FROM $table WHERE ID='".(int)$properties[$i]['ID']."'");
	 $device = SQLSelectOne("SELECT * FROM vakio_devices WHERE ID='".(int)$property['DEVICE_ID']."'");
	 if($property['VALUE'] == $value or $device['VAKIO_DEVICE_TYPE'] == 0) return;
	 else if($device['VAKIO_DEVICE_TYPE'] == 1){
		if($property['TITLE'] == "state"){
			if($value == "0" or $value == "off") $com = "off";
			else if($value == "1" or $value == "on") $com = "on";
		}else if($property['TITLE'] == "workmode"){
			if($value == 1) $com = "inflow";
			else if($value == 2) $com = "recuperator";
			else if($value == 3) $com = "inflow_max";
			else if($value == 4) $com = "winter";
			else if($value == 5) $com = "outflow";
			else if($value == 6) $com = "outflow_max";
			else if($value == 7) $com = "night";
			else $com = $value;
		}else if($property['TITLE'] == "speed"){
			if($value > 7) $value = 7;
			$com = $value;
		}else if($property['TITLE'] == "mode"){
			$com = $value;
		}
	 } else if($device['VAKIO_DEVICE_TYPE'] == 2){
		if($property['TITLE'] == "state"){
			if($value == "0" or $value == "off") $com = "off";
			else if($value == "1" or $value == "on") $com = "on";
		}else if($property['TITLE'] == "gate"){
			if($value > 4) $value = 4;
			$com = $value;
		}
	 } else if($device['VAKIO_DEVICE_TYPE'] == 3){
		 if($property['TITLE'] == "state"){
			if($value == "0" or $value == "off") $com = "off";
			else if($value == "1" or $value == "on") $com = "on";
		}else if($property['TITLE'] == "gate"){
			if($value > 4) $value = 4;
			$com = $value;
		}else if($property['TITLE'] == "speed"){
			if($value > 5) $value = 5;
			$com = $value;
		}else if($property['TITLE'] == "workmode"){
			if($value == 1) $com = "super_auto";
			else if($value == 2) $com = "manual";
			else $com = $value;
		}else if($property['TITLE'] == "temp" or $property['TITLE'] == "hud"){
			return;
		}
	 }
	 $topic = $device["VAKIO_DEVICE_MQTT_TOPIC"] . "/" . $property['TITLE'];
	 $property['VALUE'] = $value;
	 $property['UPDATED'] = date('Y-m-d H:i:s');
	 SQLUpdate($table, $property);
	 addToOperationsQueue('vakio', $topic, $com);
    }
   }
  }
/**
* Install
*
* Module installation routine
*
* @access private
*/
 function install($data='') {
  parent::install();
 }
/**
* Uninstall
*
* Module uninstall routine
*
* @access public
*/
 function uninstall() {
  $id = SQLSelect('SELECT ID FROM vakio_devices');
  for($i=0; $i<count($id); $i++){
	$this->delete_vakio_devices($id[$i]['ID']);
  }
  SQLExec('DROP TABLE IF EXISTS vakio_info');
  SQLExec('DROP TABLE IF EXISTS vakio_devices');
  parent::uninstall();
 }
 
 
/**
* dbInstall
*
* Database installation routine
*
* @access private
*/
 function dbInstall($data) {
/*
vakio_devices - 
*/
  $data = <<<EOD
 vakio_devices: ID int(10) unsigned NOT NULL auto_increment
 vakio_devices: TITLE varchar(100) NOT NULL DEFAULT ''
 vakio_devices: VAKIO_DEVICE_TYPE int(10) NOT NULL DEFAULT 0
 vakio_devices: VAKIO_DEVICE_MQTT_TOPIC varchar(255) NOT NULL DEFAULT ''
 vakio_devices: VAKIO_DEVICE_STATE varchar(255) NOT NULL DEFAULT ''
 vakio_info: ID int(10) unsigned NOT NULL auto_increment
 vakio_info: DEVICE_ID int(10) NOT NULL DEFAULT '0'
 vakio_info: TITLE varchar(100) NOT NULL DEFAULT ''
 vakio_info: NAME varchar(255) NOT NULL DEFAULT ''
 vakio_info: VALUE varchar(20) NOT NULL DEFAULT ''
 vakio_info: LINKED_OBJECT varchar(100) NOT NULL DEFAULT ''
 vakio_info: LINKED_PROPERTY varchar(100) NOT NULL DEFAULT ''
 vakio_info: LINKED_METHOD varchar(100) NOT NULL DEFAULT ''
 vakio_info: UPDATED datetime
EOD;
  parent::dbInstall($data);
 }
// --------------------------------------------------------------------
}
/*
*
* TW9kdWxlIGNyZWF0ZWQgSnVsIDAzLCAyMDIzIHVzaW5nIFNlcmdlIEouIHdpemFyZCAoQWN0aXZlVW5pdCBJbmMgd3d3LmFjdGl2ZXVuaXQuY29tKQ==
*
*/
