<?php
require_once __DIR__.'/../libs/upnp_constants.inc';
	
class UPnPDiscover extends IPSModule {
	public function Create(){
		parent::Create();
		$this->RegisterAttributeString('DiscoverList', '');
	}
	public function GetConfigurationForm(){
		$f=json_decode(file_get_contents(__DIR__.'/form.json'),true);
		$values=$this->LoadDiscoverList();
		$ips=[];
		if($v=Sys_GetNetworkInfo())foreach($v as $n)$ips[]=['label'=>$n['IP'],'value'=>$n['IP']];
		$f['actions'][2]['popup']['items'][0]['options']=$ips;
		$f['actions'][3]['values']=$values;
		if(count($values)){
  			array_splice($f['actions'], 0,2);
		}
		
		return json_encode($f);
		
	}
	public function RequestAction($Ident, $Value){
		if($Ident=="DISCOVER"){
			$UpdateProgress=method_exists($this, 'UpdateFormField');
			list($bindIP,$timeout,$useCache)=explode(',',$Value);
			if($UpdateProgress)$this->UpdateFormField('DiscoverButton', 'visible', false);
			$l=$this->DiscoverNetwork($timeout,$bindIP,$useCache, $UpdateProgress);
			$this->MergeDiscoverList($l);
			if($UpdateProgress){
				$this->UpdateFormField('DiscoverList', 'values', json_encode($this->LoadDiscoverList()));
				$this->UpdateFormField('StartUp', 'visible', false);
				$this->UpdateFormField('TimeUpDown', 'visible', false);
				$this->UpdateFormField('DiscoverButton', 'visible', true);
			}
		}
		
	}

	public function Destroy(){
		parent::Destroy();
		$this->DiscoverCleanUpCache();
	}
	public function Reset(){
		$this->DiscoverCleanUpCache();
		$this->WriteAttributeString('DiscoverList', '');
		$this->ReloadForm();
	}
	
	
	private function MergeDiscoverList(array $DiscoverList){
		$myList = json_decode($this->ReadAttributeString('DiscoverList'),true);
		if(empty($myList)){
			$this->WriteAttributeString('DiscoverList', json_encode($DiscoverList));
			return;
		}
		$nextID = $myList[count($myList)-1]['id']+1;
		$newRootIndex = array_filter($DiscoverList,function($i){return empty($i['parent']);});
		foreach($newRootIndex as $newItem){
			$rootID=$newItem['id'];
			$newSubItems=array_filter($DiscoverList,function($i)use($rootID){return !empty($i['parent']) && $i['parent']==$rootID;});
			$found=false;
			foreach($myList as $myItem){
				if($found=$myItem['name']==$newItem['name'] && $myItem['type']==$newItem['type'])break;
			}
			if(!$found){
				$newItem['id']=$nextID++;
				$myList[]=$newItem;
				foreach($newSubItems as $item){
					$item['id']=$nextID++;
					$item['parent']=$newItem['id'];
					$myList[]=$item;
				}
			}
			else {
				$parentId=$myItem['id'];
				foreach($newSubItems as $item){
					$item['parent']=$parentId;
					$found=false;
					foreach($myList as $myItem){
						if($found=$myItem['name']==$item['name'] && $myItem['type']==$item['type'] )break;
					}
					if(!$found){
						$item['id']=$nextID++;
						$myList[]=$item;
					}
				}
			}
			
		}
		$this->WriteAttributeString('DiscoverList', json_encode($myList));
	}
	/**
	 * @brief Cleanup the Discover Cache
	 */
	private function DiscoverCleanUpCache(){
		$f = scandir(UPNP_LIB_DIR.'/upnpcache/');
		if($f)foreach($f as $n)if($n[0]!='.')unlink(UPNP_LIB_DIR."/upnpcache/$n");
	}
	
	private function LoadDiscoverList(){
		if(empty($values=json_decode($this->ReadAttributeString('DiscoverList'),true)))return [];
		foreach($values as &$item){
			if(empty($item['parent']))$item['expanded']=true;
			$moduleIDs=IPS_GetInstanceListByModuleID($item['create']['moduleID']);
			foreach($moduleIDs as $id){
				$cfg=json_decode(IPS_GetConfiguration($id),true);
				if($cfg['Config']==$item['create']['configuration']['Config']){
					$item['instanceID']=$id;
					break;
				}
			}
		}
		return $values;
	}
	protected function DiscoverNetwork($Timeout,$BindIp,$UseCache,$UpdateProgress=false){
		if($UpdateProgress){
			$this->UpdateFormField('ProgressBar', 'caption', '0');
 			$this->UpdateFormField('ProgressBar', 'indeterminate', true);
			$this->UpdateFormField('ProgressBar', 'visible', true);
		}
		if(!$UseCache)$this->DiscoverCleanUpCache();
		$debug=function($msg, $toLog=false){
			if($toLog)IPS_LogMessage(IPS_GetName($this->InstanceID),'DiscoverNetwork::'.$msg);
			$this->SendDebug('DiscoverNetwork', $msg, 0);
		};
		$retrys=1; 
		$debug('Discover starting...',true);	
		if(empty($Timeout)||$Timeout<2)$Timeout=2;else if ($Timeout>10&&$retrys>1)$Timeout=5;
		if(empty($retrys)||$retrys<1)$retrys=1;
		$request = "M-SEARCH * HTTP/1.1\r\nHOST: 239.255.255.250:1900\r\nMAN: \"ssdp:discover\"\r\nMX: $Timeout\r\nST: ssdp:all\r\nUSER-AGENT: MacOSX/10.8.2 UPnP/1.1 PHP-UPnP/0.0.1a\r\n\r\n";
		$find=[];
		for($j=0;$j<$retrys;$j++){
			$socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
			if(!empty($BindIp))socket_bind($socket, $BindIp);
			socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, true);
			socket_sendto($socket, $request, strlen($request), 0, '239.255.255.250', 1900);
			socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec'=>$Timeout, 'usec'=>'0'));
			$read = [$socket];
			$write = $except = [];
			$name = $port = $m=null;
			$response = '';
			while (socket_select($read, $write, $except, $Timeout) && $read) {
				socket_recvfrom($socket, $response, 2048, null, $name, $port);
				if(is_null($response) || !preg_match('/HTTP\/.+200 OK/',$response)) continue;
				if(preg_match('/SERVER:(.*)/i',$response,$m))$server=trim($m[1]);else continue;
				if(preg_match('/LOCATION:(.*)/i',$response,$m))$url=trim($m[1]);else continue;
				$usn=preg_match('/USN:(.*)/i',$response,$m)?preg_replace('/::.*/','',trim($m[1])):'';
				if(empty($find[$name])){
					$find[$name]=['server'=>$server,'host'=>parse_url($url,PHP_URL_SCHEME).'://'.$name,'port'=>parse_url($url,PHP_URL_PORT), 'usn'=>$usn,'urls'=>[$url]];
 					if($UpdateProgress)$this->UpdateFormField('ProgressBar', 'caption', 'Found ('.count($find).') '.$name);
					if(preg_match('/fritz!box/i', $server)){
						array_unshift($find[$name]['urls'], $find[$name]['host'].':'.$find[$name]['port'].'/tr64desc.xml');
					}
					else if(preg_match('/homematic/i', $server)){
						array_push($find[$name]['urls'],'homematic.xml');
					}
					else if(preg_match('/[.\-\d]*vserver-\d+-bigmem.+upnp/i',$server)){
						array_unshift($find[$name]['urls'],'enigma2.xml');
					}
					$debug("found $name => $server");
				}
				else if(!in_array($url, $find[$name]['urls'])){
					$find[$name]['urls'][]=$url;
					$debug("add url $url on $name");
				}
			}
			socket_close($socket);
		}
		$debug('Discover finishd. Found '.count($find). ' network devices',true);	

		$count=count($find);
		if($UpdateProgress){
			$this->UpdateFormField('ProgressBar', 'caption', '1 / '.$count);
			$this->UpdateFormField('ProgressBar', 'indeterminate', false);
			IPS_Sleep(50);
			$this->UpdateFormField('ProgressBar', 'minimum', 0);
			$this->UpdateFormField('ProgressBar', 'maximum', $count);
			$this->UpdateFormField('ProgressBar', 'current', 0);
			
		}
		$options = (int)$UseCache;
		$options = $options|DISCOVER_EVENTS;
 		$location = $this->Translate('UPnP Devices');
		$discoverList = [];
		$id=$current=1;
		foreach ($find as $found){
			if($UpdateProgress){
				$this->UpdateFormField('ProgressBar', 'current', $current++);
			}
    		$d=$this->DiscoverDevice($found['urls'],$options|DISCOVER_SAVECONVIG);
    		if(empty($d['file'])){
    			continue;
    		}
			$this->UpdateFormField('ProgressBar', 'caption', substr($d[D_NAME],0,40).'...');
 			$item=[
				'id'=>$id,
				'address'=>$d[D_HOST],
				'name'=>$d[D_INFO],
 				'type'=>"GenericDevice",
				'create'=>[
					'moduleID'=>'{5638FDC0-C110-UPNP-DE00-20190801GENE}',
					'configuration'=>['Config'=> $d['file']],
					'location'=>[$location]
				]		
			];
			$discoverList[]=$item;

			if(preg_match('/fritz!box/i',$d[D_INFO])){
				$parent=$id;
				$guid='{5638FDC0-C112-UPNP-DE00-20190801FLOG}';
				if(IPS_ModuleExists($guid)){
					$item=[
						'id'=>++$id,
						'parent'=>$parent,
						'address'=>$d[D_HOST],
						'name'=>$d[D_NAME]. ' Systemlog',
						'type'=>"FritzLog",	
						'create'=>[
							'moduleID'=>$guid,
							'configuration'=>['Config'=>$d['file']],
							'location'=>[$location]
								
						]		
					];
					$discoverList[]=$item;
				}
				$guid='{5638FDC0-C112-UPNP-DE00-20190801FSTA}';
				if(IPS_ModuleExists($guid)){
					$item=[
						'id'=>++$id,
						'parent'=>$parent,
						'address'=>$d[D_HOST],
						'name'=>$d[D_NAME]. ' Status',
						'type'=>"FritzStatus",	
						'create'=>[
							'moduleID'=>$guid,
							'configuration'=>['Config'=>$d['file']],
							'location'=>[$location]
								
						]		
					];
					$discoverList[]=$item;
				}
				$guid='{5638FDC0-C112-UPNP-DE00-20190801FDSW}';
				if(IPS_ModuleExists($guid)){
					$item=[
						'id'=>++$id,
						'parent'=>$parent,
						'address'=>$d[D_HOST],
						'name'=>$d[D_NAME]. ' Smarthome Device',
						'type'=>"FritzDectSwitch",	
						'create'=>[
							'moduleID'=>$guid,
							'configuration'=>['Config'=>$d['file']],
							'location'=>[$location]
								
						]		
					];
					$discoverList[]=$item;
				}
				
				$guid='{5638FDC0-C112-UPNP-DE00-20190801FCMO}';
				if(IPS_ModuleExists($guid)){
					$item=[
						'id'=>++$id,
						'parent'=>$parent,
						'address'=>$d[D_HOST],
						'name'=>$d[D_NAME]. ' Phone & Call Monitor',
						'type'=>"FritzCallMon",	
						'create'=>[
							'moduleID'=>$guid,
							'configuration'=>['Config'=>$d['file']],
							'location'=>[$location]
								
						]		
					];
					$discoverList[]=$item;
				}
				
			}
			if(PROP_ALL_MEDIA & $d[D_PROPS]){
				$parent=$id;
				$item=[
					'id'=>++$id,
					'parent'=>$parent,
					'address'=>$d[D_HOST],
					'name'=>$d[D_NAME]. ' Media Device',
					'type'=>"MediaDevice",
					'create'=>[
						'moduleID'=>'{5638FDC0-C110-UPNP-DE00-20190801MEDI}',
						'configuration'=>['Config'=>$d['file']],
						'location'=>[$location]
							
					]		
				];
				$discoverList[]=$item;
				
				if(preg_match('/Sony.*STR-/i',$d[D_INFO])){
					$guid='{5638FDC0-C112-UPNP-DE00-20190801SORM}';
					if(IPS_ModuleExists($guid)){
						$item=[
							'id'=>++$id,
							'parent'=>$parent,
							'address'=>$d[D_HOST],
							'name'=>$d[D_NAME].' Remote FB',
							'type'=>"SonyRemote",	
							'create'=>[
								'moduleID'=>$guid,
								'configuration'=>['Config'=>$d['file']],
								'location'=>[$location]
									
							]		
						];
						$discoverList[]=$item;
					}
					
				}
				elseif(preg_match('/Samsung.*UE.*F/i',$d[D_INFO])){
					$guid='{5638FDC0-C112-UPNP-DE00-20190801SARM}';
					if(IPS_ModuleExists($guid)){
						$item=[
							'id'=>++$id,
							'parent'=>$parent,
							'address'=>$d[D_HOST],
							'name'=>$d[D_NAME].' Remote FB',
							'type'=>"SamsungRemote",	
							'create'=>[
								'moduleID'=>$guid,
								'configuration'=>['Config'=>$d['file']],
								'location'=>[$location]
									
							]		
						];
						$discoverList[]=$item;
					}
					
				}			
			}
			
			
			
			$id++;	
		}
		if($UpdateProgress){
			$this->UpdateFormField('ProgressBar', 'visible', false);
		}
		return $discoverList;
	}
	protected function DiscoverDevice($urls, $options=1, $filter=[]){
		$cache=$loadXml=$addStateVariable=$importFunctions=$importDevice=null;
		if(!is_array($urls))$urls=explode(',', $urls);
		$device=[D_HOST=>parse_url($urls[0],PHP_URL_SCHEME).'://'.parse_url($urls[0],PHP_URL_HOST),D_PROPS=>0, D_NAME=>'',	D_INFO=>'',	D_TYPE=>'',D_URLS=>'',D_OPTIONS=>0,  D_SERVICES=>[], ID_VARTABLE=>[], 'infos'=>[]];
		if($device[D_HOST]=='://')$device[D_HOST]='';
		if(is_array($filter)&&count($filter)>0){
			foreach($filter as $id=>$v){
				unset($filter[$id]);
				if(strpos($v,'.')===false)$v='*.'.$v;
				list($sn,$fn)=explode('.',$v);
				if(empty($filter[$sn]))$filter[$sn]=[];
				if(!in_array($fn, $filter[$sn]))$filter[$sn][]=$fn;
			}
		}else $filter=null;
		$isValidFilter=function($sn,$fn)use($filter){
			if(!$filter)return true;
			$m=null;$shortsn=preg_match('/(.*)\d/',$sn,$m)?$m[1]:$sn; 
			foreach($filter as $fsn=>$ffn){
				if($fsn=='*' || strcasecmp($fsn,$sn)==0 || strcasecmp($fsn,$shortsn)==0){
					foreach($ffn as $fff){
						if($fff=='*'|| strcasecmp($fff,$fn)==0){
							return true;	
						}
					}
				}
			}
			return false;
		};
		$getFunctionProp=function ($fn){
			foreach(PropFunctions as $prop=>$propfuncs)if($ok=in_array($fn, $propfuncs))break;
			return $ok?$prop:false;
		};
		$loadXml=function($name, $force=false)use(&$cache){
			if(stripos($name,'http')===false){
				if($name[0]==':')$name=substr($name,2);
	 			$name=UPNP_LIB_DIR.'/predefined/'.$name;
				return @simplexml_load_file($name);
			}
			if(is_null($cache))return @simplexml_load_file($name);
			return $cache->Load($name,$force);
		};
		$convertVar=function($xml_var)use($options){
				$var=json_decode(json_encode($xml_var),true);
				unset($var['@attributes']);
				if(isset($var['dataType'])){
					$var[V_TYPE]=$var['dataType'];
					unset($var['dataType']);
				}
				if(!empty($var['allowedValueRange'])){
					foreach(['minimum'=>V_MIN,'maximum'=>V_MAX,'step'=>V_STEP] as $k=>$v){
						if(isset($var['allowedValueRange'][$k]))$var[$v]=$var['allowedValueRange'][$k];
					}
					unset($var['allowedValueRange']);
				}
				if(!empty($var['allowedValueList']))  {
					$values=$var['allowedValueList']['allowedValue'];
					if(!is_array($values))$values=[$values];
					$var[V_ALLOWED]=$values;
					unset($var['allowedValueList']);
				}
				if(isset($var['defaultValue'])){
					$var[V_DEFAULT]=$var['defaultValue'];
					unset($var['defaultValue']);
				}
				unset($var['name']);
	// 			if(empty($var['defaultValue']) && !empty($var['allowed']))
	// 				$var['defaultValue']=$var['allowed'][0];
				
				if(isset($var[V_DEFAULT])){
					if($var[V_TYPE]!='string'){
						if(is_numeric($var[V_DEFAULT]))$var[V_DEFAULT]=is_float($var[V_DEFAULT])?floatval($var[V_DEFAULT]):intval($var[V_DEFAULT]);
						if($var[V_TYPE]!='boolean')$var[V_DEFAULT]=$var[V_DEFAULT]=='false'?false:(bool)$var[V_DEFAULT];
					}
				}
	
				if($options & DISCOVER_SMALCONFIG){
					unset($var[V_TYPE]);
					if(count($var)==0) $var=null; 
				}
				return $var;		
		};
		
		$getStateVar=function($xml,$var_name)use($options,$convertVar){
			$r=null;
			foreach($xml->serviceStateTable->stateVariable as $var){
				if(strcasecmp((string)$var->name, $var_name)==0){
					$r=$convertVar($var);
					break;
				}
			}
	 		return $r;
		};
		$addStateVariable=function($var_name,$var, &$curService)use(&$device){
		
			if(empty($device[ID_VARTABLE][$var_name])){
				$device[ID_VARTABLE][$var_name]=$var;
			}else {
				if($device[ID_VARTABLE][$var_name]!=$var){
					if(empty($curService[ID_VARTABLE][$var_name])){
	// 					echo "duplicate $var_name add to service\n";
						$curService[ID_VARTABLE][$var_name]=$var;	
					}else if($curService[ID_VARTABLE][$var_name]!=$var) {
	// 					echo "new duplicate $var_name\n";
	// 					echo var_export($var,true)."\n".var_export($curService[ID_VARTABLE][$var_name],true);
		 				return null;
					}
				}
			}
		};
		$importEvents=function($xml, &$curService)use(&$device,$addStateVariable,$convertVar){
			if(empty($xml->serviceStateTable->stateVariable))return;
			$evars=[];
			foreach($xml->serviceStateTable->stateVariable as $var){
				if($var->attributes()['sendEvents']=='yes'){
					$name=trim((string)$var->name);
					if(!in_array($name,$evars)){
						$evars[]=$name;
						$addStateVariable( $name,$convertVar($var), $curService,true);
					}
				}
			}
			if(count($evars)>0){
				$curService[S_EVENTS]=implode(',',$evars);
				$device[D_PROPS]=$device[D_PROPS]|PROP_EVENTS;
			}
		};
		
		$importFunctions=function($xml, &$curService,$sn)use(&$device,$options,$addStateVariable,$getStateVar,$getFunctionProp,$isValidFilter,$importEvents){
			if(empty($xml->actionList))return null;
			$funcs=null;
			foreach($xml->actionList->action as $action){
				$n=(string)$action->name;
				
				if($prop=$getFunctionProp($n))$device[D_PROPS]=$device[D_PROPS]|$prop;
				if( $options&DISCOVER_PROPS_ONLY && !$prop)continue;
				else if(!$isValidFilter($sn,$n))continue;
				$r=[];
				if($action->argumentList)foreach($action->argumentList->argument as $arg ){
					$var=$getStateVar($xml,(string)$arg->relatedStateVariable);
					$name=(string)$arg->name;
					if((string)$arg->direction=='in')$r[]=$name;
					if($var)$addStateVariable($name,$var,$curService);
				}
				$funcs[$n]=implode(',',$r);
				if($prop=$getFunctionProp($n))$device[D_PROPS]=$device[D_PROPS]|$prop;
			}
			$curService[S_FUNCS]=$funcs;
			if($funcs && count($funcs)>0 && ($options&DISCOVER_EVENTS)>0){
				$importEvents($xml,$curService);
			}
			
		};
		$importDevice=function($xml,$port)use(&$device,&$importDevice,$options,$loadXml,$importFunctions){
			$d=null;
			if(!empty($xml->device))$d=$xml->device;
			else if(!empty($xml->serviceList))$d=$xml;
			if(!$d)return;
			foreach($d[0] as $k=>$v)if(($v=trim((string)$v)) && empty($device['infos'][$k]))$device['infos'][$k]=$v;
			if($d->serviceList){
				foreach($d->serviceList->service as $service){
					$s=explode(':',(string)$service->serviceId); $s=array_pop($s);
					if(empty($device[D_SERVICES][$s])){
						$device[D_SERVICES][$s][S_PORT]=isset($service->connectionPort)?(int)$service->connectionPort:$port;
						$device[D_SERVICES][$s][S_CON]=isset($service->connectionType)?(int)$service->connectionType:0;
						if(isset($service->lowerNames))$device[D_SERVICES][$s][S_LOWERNAMES]=true;
						$curService=&$device[D_SERVICES][$s];
						$curService[S_CURL]=trim((string)$service->controlURL);
						if($options&DISCOVER_EVENTS)$curService[S_EURL]=trim((string)$service->eventSubURL);
	  					$urn=trim((string)$service->serviceType);
	 					if($urn!='urn:schemas-upnp-org:service:'.$s.':1')$curService[S_URN]=$urn;
						$scpd=trim((string)$service->SCPDURL);
						if(!empty($scpd)){
							if($scpd[0]!='/')$scpd='/'.$scpd;
							$scpd=$port?$device[D_HOST].':'.$port.$scpd:$scpd ;
							if($x=$loadXml($scpd))$importFunctions($x,$curService,$s);
						}
						if(empty($curService[S_FUNCS])){
							unset($device[D_SERVICES][$s]);
						}
					}
				}
			}
			if(!empty($d->deviceList)){
				foreach ($d->deviceList->device as $d){
					$importDevice($d,$port);
				}
			}
			
		};
		$finishProcess=function()use(&$device,$options){
			if(empty($device[D_HOST])){
				foreach($device[D_URLS] as $url){
					$p=parse_url($url);
					if(!empty($p['scheme']) && !empty($p['host'])){
						$device[D_HOST]=$p['scheme'].'://'.$p['host'];
						break;
					}
				}
			}
			$device[D_URLS]=implode(',', $device[D_URLS]);
			$infos=$device['infos'];
			$GetInfo=function($keys, $maxlen=0, $trimNumbers=false)use($infos){
				$words=[];$return='';
				foreach($keys as $key){
					if(isset($infos[$key]))foreach (explode(' ',$infos[$key]) as $w){
						if(empty($w=trim(str_replace(',','',$w))))continue;
						if(in_array($w,['-','+']))continue;
						$m=[];
						if($trimNumbers && preg_match('/^[ \d.\-\+]*/',$w,$m)){
							$w=str_replace($m[0], '', $w);
						}
						if(empty(trim($w)))continue;
						
						
						if(empty(array_filter($words,function($s)use($w){return strcasecmp($s,$w)==0;})))$words[]=$w;
					}
				}
				if(empty($maxlen))
					$return = implode(' ', $words);
				else foreach($words as $w){
					if(strlen($return.$w)<$maxlen) $return.=' '.$w;else break; 
				}
				return trim($return);
			};
			$device[D_NAME]=$GetInfo(['manufacturer','modelName','modelNumber'],80,false);
			$device[D_INFO]=$GetInfo(['friendlyName','manufacturer','modelDescription','modelName','modelNumber','modelDescription'],200);
			if(preg_match('/fritz!box/i',$device[D_INFO]))$device[D_OPTIONS]=$device[D_OPTIONS]|DEVICE_REQUIRE_LOGIN;
			if(!($options&DISCOVER_SMALCONFIG+DISCOVER_MINIMIZED)){
				$device[D_INFOS]=$device['infos'];
			}
	 		unset($device['infos']);
		};
		if($options&DISCOVER_USE_CACHE)$cache=new Cache();
		
		$device[D_URLS]=[];
		foreach($urls as $url){
			if($xml=$loadXml($url)){
				$importDevice($xml,parse_url($url,PHP_URL_PORT));
				$device[D_URLS][]=str_replace($device[D_HOST], '', $url);
			}
		}
		$finishProcess();
		if($options&DISCOVER_SAVECONVIG && count($device[D_SERVICES])>0){
 			$device['file']=pathinfo( $this->DiscoverSaveDevice($device),PATHINFO_BASENAME);
		}
	// 	$device[D_CRC]=crc32(json_encode($device));
 		return $device;
	}
	protected function DiscoverSaveDevice($device, $override=true, $filename=''){
		$file=UPNP_LIB_DIR . '/upnpconfig/';
		if(!is_dir($file))mkdir($file,755);
		$file.=$filename?$filename:str_replace([':','\\','/','?','*'],'', $device[D_NAME]).CONFIG_EXTENTION;
		if(!file_exists($file) || $override) file_put_contents($file, json_encode($device));
		return $file;
	}
	/**
	 * @param array $device
	 * @param string $filename
	 */
	protected function DiscoverDeleteDevice($device, $filename=''){
		$file=UPNP_LIB_DIR . '/upnpconfig/';
		$file.=$filename?$filename:str_replace([':','\\','/','?','*'],'', $device[D_NAME]).CONFIG_EXTENTION;
		if(file_exists($file))unlink($file);
	}
}

/**
 * @author Xavier
 *
 */
class Cache {
	/**
	 * @var string $_cachePath 
	 */
	protected $_cachePath = UPNP_LIB_DIR . '/upnpcache/';
	function __construct(){
		@mkdir($this->_cachePath,755);		
	}
	/**
	 * @param string $Name
	 * @param boolean $force
	 * @return NULL|SimpleXMLElement
	 */
	public function Load($Name, $force=false){
		$fn = $this->_buildCacheFilename($Name);
		if($force===true || !file_exists($fn)){
			$content=file_get_contents($Name);
			file_put_contents($fn, $content);
			return empty($content)?null:@simplexml_load_string($content);
		}else $Name=$fn;
		return @simplexml_load_file($Name);
	}
	/**
	 * @param string $filename
	 * @param string $defExt
	 * @return string
	 */
	private function _buildCacheFilename($filename, $defExt='xml'){
		return $this->_cachePath . str_ireplace(['.xml',':','//','/','.'],['','_','','_','-'], $filename).'.'.$defExt;
	}
}

const 
	PropFunctions=[PROP_VOLUME_CONTROL=>['GetVolume','SetVolume'],PROP_MUTE_CONTROL=>['GetMute','SetMute'],PROP_TREBLE_CONTROL=>['GetTreble','SetTreble'],PROP_BASS_CONTROL=>['GetBass','SetBass'],PROP_LOUDNESS_CONTROL=>['GetLoudness','SetLoudness'],PROP_BRIGHTNESS_CONTROL=>['GetBrightness','SetBrightness'],PROP_CONTRAST_CONTROL=>['GetContrast','SetContrast'],PROP_SHARPNESS_CONTROL=>['GetSharpness','SetSharpness'],PROP_COLOR_CONTROL=>['GetColor','SetColor'] ,PROP_SOURCE_CONTROL=>['GetSource','SetSource'],PROP_PLAY_CONTROL=>['Stop','Play','Pause','Next','Previous','Seek','GetTransportInfo','SetAVTransportURI'],PROP_CONTENT_BROWSER=>['Browse','Search']];
// Discover Options
CONST DISCOVER_USE_CACHE = 1;
CONST DISCOVER_MINIMIZED = 2;
CONST DISCOVER_PROPS_ONLY= 4;
CONST DISCOVER_SMALCONFIG= 8;
CONST DISCOVER_EVENTS    = 16;
CONST DISCOVER_SAVECONVIG= 256;



?>