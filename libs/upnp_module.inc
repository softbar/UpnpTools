<?php
require_once'upnp_constants.inc';
trait UPnPEvents {
	// ****************************
	// * DEVICE Events
	// ****************************
	protected function CreateEvents(){
		$this->RegisterPropertyBoolean('EnableEvents', false);
		$this->RegisterPropertyInteger('EventUserScriptID', 0);
		$this->RegisterPropertyString('EventServer', ($v=Sys_GetNetworkInfo())?$v[0]['IP']:'');
		$this->RegisterAttributeString('DeviceEvents', '[]');
	}
	protected function LoadEventList(){
		return json_decode($this->ReadAttributeString('DeviceEvents'),true);
	}
	protected function SaveEventList($EventList){
		$this->WriteAttributeString('DeviceEvents', json_encode($EventList));
	}
	protected function RegisterEvents(){
// 		
		if(!$this->ReadPropertyString('EventServer')){
			return;
		}
		$hook=$this->RegisterHook(__CLASS__.$this->InstanceID, true);
		$callbackUrl='http://'.$this->ReadPropertyString('EventServer').':3777'.$hook;
		$this->SendEvents($callbackUrl);
		
	}
	protected function UnRegisterEvents(){
		$this->SendEvents(null);
	}
	protected function UpdateEventList(){
		$eventList=[];
		if($cfg=$this->GetDeviceConfig()){
			if($cfg[D_PROPS] & PROP_EVENTS){
				foreach ($cfg[D_SERVICES] as $sn=>$s) {
					if(!empty($s[S_EURL])){
						$eventList[]=['service'=>$sn,'events'=>empty($s[S_EVENTS])?'Unknown':$s[S_EVENTS],'sid'=>'','lease'=>0];
					}
				}
			}
		}
		$this->SaveEventList($eventList);
	}
	
	protected function ProcessEventData($Data){
		
	}
	private  function SendEvents( $CallbackUrl,  $RunTimeSec = 300){
		$eventList=$this->LoadEventList();
		$config=$this->GetDeviceConfig();
		require_once 'upnp_helper.inc';
		$result=false;
		foreach($eventList as &$event){
			$service=$config[D_SERVICES][$event['service']];
			$eventUrl=$config [D_HOST].':'.$service[S_PORT].$service[S_EURL];
	  		$result=UPnPDeviceEventHelper::MaintainEvent($event, $eventUrl,$CallbackUrl,0 );
// 	  		if($result!==true)break;
 		}
		$this->SaveEventList($eventList);
		return $result;
	}
	
}

class UPnPModule extends IPSModule {
	protected static $DEVICE_STATE_NAMES = ['Connected','Disconnected','Error','Unknown'];
	public function Create(){
		parent::Create();
		require_once 'utils.inc';
		$this->RegisterPropertyString('Config', '');
		$this->RegisterPropertyString('Host','');
		$this->RegisterPropertyString('User', '');
		$this->RegisterPropertyString('Password', '');
		$this->RegisterPropertyBoolean('ShowState', true);
		$this->RegisterPropertyBoolean('ShowRefresh', false);
		if(method_exists($this, 'CreateEvents')){
			$this->CreateEvents();
		}
		
		$this->SetBuffer('DeviceState',DEVICE_ONLINE);
	}
	public function ApplyChanges() {
		parent::ApplyChanges();
		if(empty($this->GetBuffer('BlockApply'))){
			$state=102;
			if(empty($this->GetBuffer('Config')) && !empty($config=$this->ReadPropertyString('Config'))){
				if($this->ApplyNewConfig($config)!==false)
					$this->SetBuffer('Config',$config);
			}
			if($cfg=$this->GetDeviceConfig()){
				if(empty($host=$this->ReadPropertyString('Host'))){
				 	if(!empty($host=$cfg[D_HOST])){
						$this->BlockApply();
						IPS_SetProperty($this->InstanceID, 'Host', $host);
						IPS_ApplyChanges($this->InstanceID);
					}
					else $state=STATE_HOST_MISSING;
				}
				elseif(stripos($host,'://')===false){
					$this->BlockApply();
					IPS_SetProperty($this->InstanceID, 'Host', 'http://'.$host);
					IPS_ApplyChanges($this->InstanceID);
				}
				if($state==102){
					$u=$this->ReadPropertyString('User');
					$p=$this->ReadPropertyString('Password');
					if($cfg[D_OPTIONS]&DEVICE_REQUIRE_LOGIN && empty($u)&&empty($p)){
						$state=STATE_USER_MISSING;
					}
				}
			}
			else $state=STATE_CFG_INVALID;
			$this->SetStatus($state);
			if($state==102){
				$this->MaintainDeviceStateVariable($this->ReadPropertyBoolean('ShowState'));
				$this->MaintainDeviceRefreshVariable($this->ReadPropertyBoolean('ShowRefresh'));
				$this->CheckOnline();
				
			}
		}else $this->UnBlockApply();
	}	
	public function Destroy(){
		parent::Destroy();
		if ( (IPS_VariableProfileExists('UPnP_DeviceState')||IPS_VariableProfileExists('UPnP_DeviceRefresh'))  && 
			$this->IsLastInstance('{5638FDC0-C110-UPNP-DE00-20190801GENE}') && 
			$this->IsLastInstance('{5638FDC0-C110-UPNP-DE00-20190801MEDI}') 
		){
			@IPS_DeleteVariableProfile('UPnP_DeviceState');
			@IPS_DeleteVariableProfile('UPnP_DeviceRefresh');
		}
	}
	public function GetApi(){
		
		return $this->CreateApi();
	}
	
	protected function ApplyBlocked(){
		return !empty($this->GetBuffer('BlockApply'));
	}
	protected function BlockApply(){
		$this->SetBuffer('BlockApply',1);
	}
	protected function UnBlockApply(){
		$this->SetBuffer('BlockApply',0);
	}
	protected function ApplyNewConfig($config){
		
	}
	// ****************************
	// * Module Status
	// ****************************
	protected function OnStatusChanged(int $OldStatus, int $NewStatus){
		
	}
	protected function SetStatus($Status){
		$old=$this->GetStatus();
		if($old!=$Status){
			parent::SetStatus($Status);
			$this->OnStatusChanged($old, $Status);
		}
	}
	// ****************************
	// * DEVICE Refresh Button
	// ****************************
	protected function MaintainDeviceRefreshVariable(bool $Enabled){
		$id=@$this->GetIDForIdent('DEVICE_REFRESH');
		if($Enabled && !$id){
			if(!IPS_VariableProfileExists('UPnP_DeviceRefresh')){
				require_once 'utils.inc';
				UTILS::RegisterProfileIntegerEx('UPnP_DeviceRefresh','Hourglass', '', '', [
					[0, $this->Translate('Refresh'), '', 32768]
				]);
			}
			$name=$this->Translate('Refresh');
			$this->RegisterVariableInteger('DEVICE_REFRESH', $name, 'UPnP_DeviceRefresh', 0);
 			$this->EnableAction('DEVICE_REFRESH');
		}
		elseif(!$Enabled && $id){
			$this->DisableAction('DEVICE_REFRESH');
			$this->UnregisterVariable('DEVICE_REFRESH');
		}
	}
	
	// ****************************
	// * DEVICE State 
	// ****************************

	protected function OnDeviceStateChanged(int $OldState, int $NewState){
		if($this->ReadPropertyBoolean('ShowState'))$this->SetValue('DEVICE_STATE',$NewState);
		if($OldState==DEVICE_ONLINE || $NewState==DEVICE_ONLINE){
			$n=$ns=IPS_GetName($this->InstanceID);
			$names = self::$DEVICE_STATE_NAMES;
			foreach($names as $k=>$v){
				$names[$k]=' ['.$this->Translate($v).']';
				$n=str_replace($names[$k], '', $n);
			}
			if($OldState==DEVICE_ONLINE)$n.=$names[$NewState];
			if($ns!=$n)IPS_SetName($this->InstanceID,$n);
		}
	}
	protected function MaintainDeviceStateVariable(bool $Enabled){
		$id=@$this->GetIDForIdent('DEVICE_STATE');
		if($Enabled && !$id){
			if(!IPS_VariableProfileExists('UPnP_DeviceState')){
				require_once 'utils.inc';
				UTILS::RegisterProfileIntegerEx('UPnP_DeviceState','Heart', '', '', [
					[DEVICE_ONLINE, $this->Translate(self::$DEVICE_STATE_NAMES[DEVICE_ONLINE]), '', -1],	
					[DEVICE_OFFLINE, $this->Translate(self::$DEVICE_STATE_NAMES[DEVICE_OFFLINE]), '', -1],
					[DEVICE_ERROR, $this->Translate(self::$DEVICE_STATE_NAMES[DEVICE_ERROR]), '', -1],	
					[DEVICE_UNKNOWN, $this->Translate(self::$DEVICE_STATE_NAMES[DEVICE_UNKNOWN]), '', -1]	
				]);
			}
			$name=$this->Translate('State');
			$id=$this->RegisterVariableInteger('DEVICE_STATE', $name, 'UPnP_DeviceState', 0);
			SetValue($id,$this->GetDeviceState());
		}
		elseif(!$Enabled && $id){
			$this->UnregisterVariable('DEVICE_STATE');
		}
	}
	
	/** @brief Get device state
	 * @return int State of device 0=connected|1=disconnected|2=error
	 */
	protected function GetDeviceState(){
		return intval($this->GetBuffer('DeviceState'));
	}
	/** @brief Set device state
	 * @param int $State Valid states are 0=connected|1=disconnected|2=error
	 */
	protected function SetDeviceState($State){
		$old=$this->GetDeviceState();
		if($old!=$State){
			$this->SetBuffer('DeviceState', $State);
			$this->OnDeviceStateChanged($old, $State);
		}
	}
	/** 
	 * Checks if host online and setup devicestate
	 * @return bool True if device is online
	 */
	private $onlineCache=null;
	protected function CheckOnline() {
		if(!is_null($this->onlineCache))return $this->onlineCache;
		$host=parse_url($this->ReadPropertyString('Host'),PHP_URL_HOST);
		$this->onlineCache= !empty($host) && @Sys_Ping($host,1);
 		$this->SetDeviceState($this->onlineCache?DEVICE_ONLINE:DEVICE_OFFLINE);
		return $this->onlineCache;
	}	
	// ****************************
	// * DEVICE Api
	// ****************************
	/** @brief Get config array to initilize RpcApi
	 * @return NULL|array Api config
	 */
	private $cfgCache=null;
	protected function GetDeviceConfig(){
		if(!is_null($this->cfgCache))return $this->cfgCache;
		if(empty($cfg=$this->ReadPropertyString('Config'))){
			$this->SendDebug(__FUNCTION__, "API device config is empty", 0);
			return null;
		}
		if(strlen($cfg)<255 && stripos($cfg, CONFIG_EXTENTION)){ // is a file
			if(!file_exists(UPNP_LIB_DIR.'/upnpconfig/'. $cfg) ){
				$this->SendDebug(__FUNCTION__, sprintf("API device config file %s not found",UPNP_LIB_DIR .'/upnpconfig/'. $cfg), 0);
				return null;
			}else $cfg=file_get_contents(UPNP_LIB_DIR.'/upnpconfig/'. $cfg);
		}
		if(!($cfg=json_decode($cfg,true))) {
			$this->SendDebug(__FUNCTION__, "Invalid API device config", 0);
			return null;
		}
		return $this->cfgCache=$cfg;		
	}
	/** @brief Return UPnPDevice object 
	 * @return NULL|UPnPDevice Api object
	 */
	private $apiCache=null;

	protected function CreateApi(){
		if(!is_null($this->apiCache))return $this->apiCache;
		if( 	$this->GetDeviceState()>1 ||  
			!$this->CheckOnline() ||
		    	(!($cfg=$this->GetDeviceConfig()))
		) return null;
		$u=$this->ReadPropertyString('User');
		$p=$this->ReadPropertyString('Password');
		if($cfg[D_OPTIONS]&DEVICE_REQUIRE_LOGIN){
			if(empty($u)&&empty($p)){
				$this->SetStatus(STATE_USER_MISSING);
				return null;
			}
		}
		require_once 'upnp_api.inc';
		try {
			$this->apiCache=new UPnPDevice($cfg ,$this->ReadPropertyString('Host'),$u,$p);
			$this->SendDebug(__FUNCTION__, 'Api Created', 0);
			$this->apiCache->SetErrorHandler(function($msg,$code){
IPS_LogMessage('UPnP-Device',"($code) $msg");
			});
		}catch(Exception $e){
			$this->SendDebug(__FUNCTION__, 'Error Api Creation =>'.$this->lastError=$e->getMessage(), 0);
			$this->apiCache=null;
		}
		return $this->apiCache;
	}
	/** @brief Make a rpc call
	 * @param string $function Function name to call
	 * @param array $arguments Function arguments
	 * @return NULL|mixed Result of api call
	 */
	protected function CallApi(string $function, array $arguments=[]){
  		if(!($api=$this->CreateApi()))return;
		$this->SendDebug('CallApi',"$function(".implode(',',$arguments).')',0);
		$r=$api->__call($function,$arguments);
 		$this->SendDebug('CallApi',"returns => ".json_encode($r),0);
		return $r; 
	}	
	// ****************************
	// * DEVICE Hooks
	// ****************************
	/** @brief Register a Hook for external calls
 	 * @param string $Name Name of the hook
 	 * @param bool $Create if true Hook creating , if false delete hook
 	 * @return string|bool if creating then returns full hook name or false if error or nothing change
 	 */
 	protected function RegisterHook($Name, bool $Create) {
		$ids = IPS_GetInstanceListByModuleID("{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}");
		if(sizeof($ids) > 0) {
			$hookname= "/hook/$Name";
			$hooks = json_decode(IPS_GetProperty($ids[0], "Hooks"), true);
			$found = false;
			foreach($hooks as $index => $hook) {
				if(trim($hook['Hook']) == $hookname) {
					if($hook['TargetID'] == $this->InstanceID){
						if($Create)	return $hookname;
						$found=$index;
						break;
					}
					$hooks[$index]['TargetID'] = $this->InstanceID;
					$found = $index;
					break;
				}
			}
			$changed = false;
			if(!$Create && $found!==false){
				unset($hooks[$found]);
				$changed=true;
			}else if($Create && $found===false){
				$hooks[] = Array("Hook" => $hookname, "TargetID" => $this->InstanceID);
				$changed=true;
			}
			if($changed){
				IPS_SetProperty($ids[0], "Hooks", json_encode($hooks));
				IPS_ApplyChanges($ids[0]);
			}
			return $Create?$hookname:$changed;
		}else IPS_LogMessage(IPS_GetName($this->InstanceID),'ERROR Instance WebHook not found');
		return false;
	}
	/** @brief Process the hook data
	 * @return bool True if proceed successful 
	 */
	protected function ProcessHookData(){}
	
	
	// ****************************
	// * Protected Tools
	// ****************************
	
	/** @brief Checks if more instances created with then given module ID
	 * @param string $guid Module ID
	 * @return bool True if no more instance created
	 */
	protected function IsLastInstance($guid){
 		return 	(count(IPS_GetInstanceListByModuleID($guid))==0);
	}
	
}
abstract class UPnPPropModule extends UPnPModule {
	public function Create(){
		parent::Create();
		$this->RegisterPropertyBoolean('TranslateStateNames', true);
		$this->RegisterPropertyInteger('UpdateInterval', 0);
		$this->RegisterTimer('UpdateTimer', 0,"IPS_RequestAction($this->InstanceID,'DEVICE_REFRESH',1);");
		$this->RegisterAttributeInteger('DevicePropertys', 0);
	}
	public function ApplyChanges(){
		$blocked=$this->ApplyBlocked();
		parent::ApplyChanges();
		if(!$blocked && $this->GetStatus()==102){
			if(($t=$this->GetUpdateInterval())&&$t<15){
				$this->BlockApply();
				IPS_SetProperty($this->InstanceID, 'UpdateInterval', $t<0?0:15);
				IPS_ApplyChanges($this->InstanceID);
			}
			$this->StartTimer(IPS_GetKernelRunlevel()==KR_READY?1:random_int(10, 20));
		}
		
	}
	public function RequestAction($Ident, $Value){
		if($Ident=='DEVICE_REFRESH'){
			$this->RequestUpdate();
			return true;
		}
	}
	public function RequestUpdate(){
	}
	/** @brief Returns variable definition
	 * @param integer $Prop Property to get Definition
	 * @return null|array Variable config array or null if Property not valid
	 * @note Must Override in your own Module
	 */
	protected abstract function GetPropertyDef(int $Prop);
 	/** 
 	 * Returns Property by given Ident
 	 * @param string $Ident Ident to get Property
	 * @return integer Property of Ident or 0 if not found
	 * @note Must Override in your own Module
 	 */
	protected $propNames = [];
	protected function GetPropertyByIdent($Ident){
		return array_search($Ident, $this->propNames);
	}
	protected function GetIdentByProperty(int $Prop){
		return isset($this->propNames[$Prop])?$this->propNames[$Prop]:null;
	}
 	
	protected function UpdateVariablesByPropertys(bool $AddUsed, bool $RemoveUnused){
		$myProps=$this->GetPropertys();	
		foreach($this->propNames as $prop=>$ident){
			$id=@$this->GetIDForIdent($ident);
			if($myProps & $prop){
				if(!$id && $AddUsed){
					$this->CreateVariableByIdent($ident);
				}
			}
			elseif($id && $RemoveUnused){
				@$this->DisableAction($ident);
				$this->UnregisterVariable($ident);
			}
		}
	}
	
 	/** @brief Is called if a profile missed
	 * @param string $name Create missing profile 
	 * @return bool True if profile created
	 * @note Override in your own Module
	 */
	protected function CreateMissedProfile($name){
		IPS_LogMessage(IPS_GetName($this->InstanceID), __FUNCTION__."::Profile ->$name<- not found");
	}
	/** @brief Create status variable by ident
	 * @param string $Ident Variable ident to create
	 * @param bool $CheckIdent Check Ident is Valid
	 * @return int Variable Instance ID
	 */
	protected function CreateVariableByIdent($Ident){
 		$id=@$this->GetIDForIdent($Ident);
		if(!$id){
			if(!($def=$this->GetPropertyDef($this->GetPropertyByIdent( $Ident)))){
				$this->SendDebug(__FUNCTION__, "Prop ->$Ident<- not defined", 0);
				return 0;
			}
			$profile=empty($def[P_PROFILE])?'':$def[P_PROFILE];
			if(!empty($profile) && !IPS_VariableProfileExists($profile)){
				require_once 'utils.inc';
				if(!$this->CreateMissedProfile($profile)){
					IPS_LogMessage(IPS_GetName($this->InstanceID), "Profile $profile for $Ident missing, set to none");
					$profile='';
				}
			}
			$name=$this->ReadPropertyBoolean('TranslateStateNames')?$this->Translate($def[P_NAME]):$Ident;
			$pos=empty($def[P_POS])?0:$def[P_POS];
			switch($def[P_TYPE]){
				case 0: $id=$this->RegisterVariableBoolean(	$Ident, $name, $profile, $pos);break;
				case 1: $id=$this->RegisterVariableInteger(	$Ident, $name, $profile, $pos);break;
				case 2: $id=$this->RegisterVariableFloat(	$Ident, $name, $profile, $pos);break;
				case 3: $id=$this->RegisterVariableString(	$Ident, $name, $profile, $pos);break;
				default:
					IPS_LogMessage(IPS_GetName($this->InstanceID), "Invalid Variable Type ".$def[P_TYPE]);
					return 0;
			}
			if(!empty($def[P_ICON]))IPS_SetIcon($id, $def[P_ICON]);
			if(!isset($def[P_ACTION])||$def[P_ACTION]>0)$this->EnableAction($Ident);
		}
		return $id;
	}
	/** @brief Get status variable value by ident
	 * @param string $Ident Status variable ident to get value from
	 * @return NULL|mixed The value for ident or NULL if ident invalid
	 */
	protected function GetValueByIdent($Ident){
		return @$this->GetValue($Ident);
	}
	/** @brief Set status variable value by ident
	 * @param string $Ident Variable ident to set value
	 * @param mixed $Value Data to set
	 * @param bool $force Set value if changed or not
	 * @param bool $AutoCreate Create variable if not exists
	 * @return bool True if changed or false if ident not found or not changed 
	 */
	protected function SetValueByIdent($Ident, $Value ){
		if(!($id=@$this->GetIDForIdent($Ident))){
			$id=$this->CreateVariableByIdent($Ident);
		}
		if($id){
			SetValue($id, $Value);
			return true;
		}
		return false;
	}
	// ****************************
	// * Instance Propertys 
	// ****************************
	protected function OnPropertysChanged(int $OldPropertys, int $NewPropertys){
		
	}
	protected function GetPropertys(){
		return $this->ReadAttributeInteger('DevicePropertys');
	}
	protected function SetPropertys(int $Propertys){
		if(($old=$this->GetPropertys())!=$Propertys){
			$this->WriteAttributeInteger('DevicePropertys', $Propertys);
			$this->OnPropertysChanged($old, $Propertys);
		}
	}
	// ****************************
	// * Module Timer
	// ****************************
	protected function GetUpdateInterval(){
		return $this->ReadPropertyInteger('UpdateInterval');
	}
	/**
	 * @brief Stop current Timer
	 */
	protected function StopTimer(){
		$this->SetTimerInterval('UpdateTimer', 0);
	}
	/**
	 * @brief Start timer based on UpdateInterval
	 * @param number $delay if greater 0 then this will used to setup timer interval
	 */
	protected function StartTimer($delay=0){
 		if(($state=$this->GetDeviceState())>DEVICE_OFFLINE) return;
 		$i=$delay>0?$delay:$this->GetUpdateInterval();
		if($i>0){
			if(empty($delay)){
				if($i<15)$i=15;
				if($state==DEVICE_OFFLINE){
					$mul=intval($this->GetBuffer('OfflineMult'));
					if(empty($mul))$mul=2;else $mul++;
					if($mul>10)$mul=0;
					$this->SetBuffer('OfflineMult',$mul);
					$i=$mul?$i*($mul*$mul):0;
				}else $this->SetBuffer('OfflineMult',0);
			}
			$this->SetTimerInterval('UpdateTimer', $i*1000);
		}
	}
	
}

CONST DEVICE_ONLINE	= 0;
CONST DEVICE_OFFLINE= 1;
CONST DEVICE_ERROR 	= 2;
CONST DEVICE_UNKNOWN= 3;

CONST STATE_CFG_INVALID = 200;
CONST STATE_HOST_MISSING = 201;
CONST STATE_USER_MISSING = 202;

CONST P_TYPE = 0;
CONST P_NAME = 1;
CONST P_PROFILE	= 2;
CONST P_ICON = 3;
CONST P_POS = 4;
CONST P_ACTION = 5;

?>