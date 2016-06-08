<?php
//check if another instance already running
$check = shell_exec("ps ax | grep check_usv");
preg_match_all("/check_usv.php/", $check, $cnt);
if(count($cnt[0]) > 1) die("one instance already runing!");

class soapclientd extends soapclient
{
	public $action = false;
	public function __construct($wsdl, $options = array())
	{
		parent::__construct($wsdl, $options);
	}
	public function __doRequest($request, $location, $action, $version, $one_way = 0)
	{
	return parent::__doRequest($request, $location, $action, $version, $one_way);
	}
	
}
class usv_toolkit{
	private $devices;
	public $service;
	public $session;
	private $tmpobject;
	function __construct($Devices){
		$status = shell_exec("apcaccess status");
		$status_codes = explode("\n", $status);
		foreach($status_codes as $status_code){
			if($timeleft = strstr( $status_code, 'TIMELEFT')){
				preg_match("/[0-9]{1,}/", $timeleft, $mytime);		
				$mytime = 1;		
				if($mytime < 4 && $mytime != 0) {
					$this->devices = $Devices;
					$this->shutdown();
				}
			}
		}
	}
	function waitforme($task){
		/* Warte auf Fertigstellung des Tasks*/
		$request = new stdClass();
		$request->_this = $this->service->propertyCollector;
		$request->specSet = array ('propSet' => array ('type' => 'Task', 'pathSet' => array('info.state', 'info.result')),'objectSet' =>  array('obj' =>$task) );
		$status = "";
		while(!preg_match("/error|succes/", $status)){
			$response = $this->session->__soapCall('RetrieveProperties', array((array)$request));
			if(is_array($response->returnval->propSet)){
				$status = $response->returnval->propSet[1]->val;
				if($status == "success") $this->tmpobject =$response->returnval->propSet[0];
			}
			else $status = $response->returnval->propSet->val;
			sleep(1);
		}
	}
	function shutdown_esx($device){
		//function for shutdown ESX Server over SOAP
		$this->session = new soapclientd("https://".$device['ip']."/sdk/vimService.wsdl", array ('location' => "http://".$device['ip']."/sdk", 'trace' => 1, 'exceptions' => 1,"stream_context" => stream_context_create(array('ssl' => array('verify_peer' => false,'verify_peer_name' => false,)))));
		$request = new stdClass();
		$request->_this = array ('_' => 'ServiceInstance', 'type' => 'ServiceInstance');
		$response = $this->session->__soapCall('RetrieveServiceContent', array((array)$request));
		$this->service = $response->returnval;
		$request = new stdClass();
		$request->_this = $this->service->sessionManager;
		$request->userName = $device['user'];
		$request->password =  $device['pw'];
		$this->session->__soapCall('Login', array((array)$request));
		//bulid Soapvars
		$subselect1 = new soapvar(new soapvar(array ('type' => 'ComputeResource', "path" => "host", "skip" => false), SOAP_ENC_OBJECT, null, null, 'selectSet', null), SOAP_ENC_OBJECT, 'TraversalSpec');
		$subselect2 = new soapvar(new soapvar(array ('type' => 'Datacenter', "path" => "hostFolder", "skip" => false, new soapvar(array ('name' => 'folder_to_content'), SOAP_ENC_OBJECT, null, null, 'selectSet', null)), SOAP_ENC_OBJECT, null, null, 'selectSet', null), SOAP_ENC_OBJECT, 'TraversalSpec');
		$main_select = new soapvar(new soapvar(array ('name' => 'folder_to_content', "type" => "Folder", "path" => "childEntity", "skip" => false, $subselect1, $subselect2), SOAP_ENC_OBJECT, null, null, 'selectSet', null), SOAP_ENC_OBJECT, 'TraversalSpec');
		
		$request->_this = $this->service->propertyCollector;
		$request->specSet = array("propSet" => array("type" => "HostSystem", "all" => "false"),"objectSet" => array("obj" => $this->service->rootFolder, "skip" => false,"selectSet" => array($main_select)));
		$host_object = $this->session->__soapCall('RetrieveProperties', array((array)$request))->returnval->obj;
		$request->_this = $host_object;
		$request->force = true;
		print_r($host_object);
		$session->__soapCall('ShutdownHost_Task', array((array)$request));
	}
	function shutdown_ssh($device){
		//function for shutdown any ssh device (root need)
		if($con = ssh2_connect($device['ip'], $device['port'])){
			if(ssh2_auth_password($con, $device['user'], $device['pw'])){
				ssh2_exec($con, "poweroff");
			}
		}
	}
	function shutdown(){
		foreach($this->devices as $device){
			switch ($device['type']) {
				case "esx":
				$this->shutdown_esx($device);
				break;
				case "ssh":
				$this->shutdown_ssh($device);
				break;
			}
		}
	}
}

//definition of devices, start with ESX Servers
$device_array[] = array("type" => "esx", "ip" => "", "user" => "root", "pw" => "");
$device_array[] = array("type" => "ssh", "ip" => "", "user" => "root", "pw" => "", "port" => 22);

$usv = new usv_toolkit($device_array);
?>