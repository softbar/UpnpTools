<?php
require_once __DIR__.'/../libs/upnp_module.inc';

class GenericDevice extends UPnPModule {
	public function GetConfigurationForm(){
 		$service_values=[];//$event_values=[];
//  		$no=$this->Translate('No');
		if($d=$this->GetDeviceConfig()){
			foreach($d[D_SERVICES] as $sn=>$s){
// 				if(!empty($s[S_EVENTS])){
// 					$event_values[]=['service'=>$sn, "events"=>$s[S_EVENTS],"enabled"=>$no];
// 				}
				foreach($s[S_FUNCS] as $fn=>$args){
					$service_values[]=['s'=>$sn,'f'=>$fn,'a'=>$args];
				}
			}
		}
		
		
		
		$f=json_decode(file_get_contents(__DIR__.'/form.json'));
		$f->actions[0]->values=$service_values;
		if($this->GetStatus()!=102){
 			$f->elements[0]->expanded=true;
 		}
		return json_encode($f);
	}
	/**
	 * @param string $MethodName
	 * @param string $Arguments
	 * @return mixed
	 */
	public function CallMethod(string $MethodName,string $Arguments){
		return $this->CallApi($MethodName,$Arguments==''?[]:explode(',',$Arguments));
	}
	
}
?>