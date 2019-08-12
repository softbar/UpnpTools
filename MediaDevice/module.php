<?php
require_once __DIR__.'/../libs/upnp_module.inc';

class MediaDevice extends UPnPPropModule {
	/**
	 * {@inheritDoc}
	 * @see BaseRpcModule::Destroy()
	 */
	public function Destroy(){
		parent::Destroy();
		if($this->IsLastInstance('{5638FDC0-C110-UPNP-DE00-20190801MEDI}')){
			if (IPS_VariableProfileExists('UPnP_PlayState'))IPS_DeleteVariableProfile('UPnP_PlayState');
 			if (IPS_VariableProfileExists('UPnP_BassTreble'))IPS_DeleteVariableProfile('UPnP_BassTreble');
		}
	}
	public function GetConfigurationForm(){
		if($this->GetStatus()!=102){
			$f=json_decode(file_get_contents(__DIR__.'/form.json'));
 			$f->elements[0]->expanded=true;
			return json_encode($f); 			
 		}
	}
	
	
	public function RequestAction($Ident, $Value){
		if(parent::RequestAction($Ident, $Value))return true;
		$this->WriteValue($Ident,$Value);
	}
	
	/**
	 * @param string $Ident
	 * @param string $Value
	 * @return void|NULL
	 */
	public function WriteValue(string $Ident, string $Value){
		$this->StopTimer();
		$ok=false;
		if( ($prop=$this->GetPropertyByIdent($Ident)) && $this->GetPropertys() & $prop){
			$ok=($this->GetDeviceState()<DEVICE_ERROR && $this->CheckOnline())?true:null;
			if($ok){
				$ok=$this->_writeValue($Ident, $Value);
			} else {
				
			}
		}
		$this->StartTimer();
		return $ok;
	}
	public function RequestUpdate(){
		$this->StopTimer();
		$ok=($this->GetDeviceState()<DEVICE_ERROR && $this->CheckOnline());
		if($ok){
			$myProps= $this->GetPropertys();
			foreach($this->propNames as $prop=>$ident){
				if($myProps & $prop)$this->_readValue($ident);
			}
		}
		$this->StartTimer();
		return $ok;
	}
	// ****************************
	// * Overrides
	// ****************************
	protected function ApplyNewConfig($config){
		if($cfg=$this->GetDeviceConfig()){
			$this->SetPropertys($cfg[D_PROPS]);
			IPS_SetName($this->InstanceID, $cfg[D_NAME]);
			
		}
	}
	
	protected function GetPropertyDef(int $Prop){
		switch($Prop){
 			case PROP_VOLUME_CONTROL	: return [1,'Volume','~Intensity.100','Intensity',2];
			case PROP_MUTE_CONTROL		: return [0,'Mute','~Switch','Speaker',1];
			case PROP_TREBLE_CONTROL	: return [1,'Treble','UPnP_BassTreble','Music',3];
			case PROP_BASS_CONTROL		: return [1,'Bass','UPnP_BassTreble','Music',4];
			case PROP_LOUDNESS_CONTROL	: return [0,'Loudness','~Switch',5];
			case PROP_BRIGHTNESS_CONTROL: return [1,'Brightness','~Intensity.100','',6];
			case PROP_CONTRAST_CONTROL	: return [1,'Contrast','~Intensity.100','',7];
			case PROP_SHARPNESS_CONTROL	: return [1,'Sharpness','~Intensity.100','',8];
			case PROP_COLOR_CONTROL		: return [1,'Color','~Intensity.100','',9];
			case PROP_SOURCE_CONTROL	: return [1,'Source','','',10];
			case PROP_PLAY_CONTROL		: return [1,'Playstate','UPnP_PlayState','',11];
		}
	}

	protected $propNames =  [PROP_VOLUME_CONTROL=>'VOLUME',PROP_MUTE_CONTROL=>'MUTE',PROP_TREBLE_CONTROL=>'TREBLE',PROP_BASS_CONTROL=>'BASS',PROP_LOUDNESS_CONTROL=>'LOUDNESS',PROP_BRIGHTNESS_CONTROL=>'BRIGHTNESS',PROP_CONTRAST_CONTROL=>'CONTRAST',PROP_SHARPNESS_CONTROL=>'SHARPNESS',PROP_COLOR_CONTROL=>'COLOR',PROP_SOURCE_CONTROL=>'SOURCE',PROP_PLAY_CONTROL=>'PLAYSTATE'];
	
	protected function CreateMissedProfile($name){
		if($name=='UPnP_PlayState'){
			UTILS::RegisterProfileIntegerEx($name, 'Remote', '', '', [
				[0, $this->Translate('Stop'), '', -1],
				[1, $this->Translate('Play'), '', -1],
				[2, $this->Translate('Pause'), '', -1],
				[3, $this->Translate('Next'), '', -1],
				[4, $this->Translate('Previous'), '', -1]
			]);
			return true;
		}else if($name=='UPnP_BassTreble'){
			UTILS::RegisterProfileInteger($name, 'Music', '', '', -10, 10, 1);
			return true;
		}
		IPS_LogMessage(IPS_GetName($this->InstanceID), __FUNCTION__."::Profile ->$name<- not found");
	}
	
	// ****************************
	// * Private Read/Write Value
	// ****************************
	
	private function _readValue(string $Ident){
		$r=null;
		if(!$this->CreateApi())return null;
		switch($Ident){
			case 'VOLUME'	: $r=$this->CallApi('RenderingControl.GetVolume', []);break;
			case 'MUTE'		: $r=$this->CallApi('RenderingControl.GetMute', []);break;
			case 'BASS'		: $r=$this->CallApi('RenderingControl.GetBass', []);break;
			case 'TREBLE'	: $r=$this->CallApi('RenderingControl.GetTreble', []);break;
			case 'LOUDNESS'	: $r=$this->CallApi('RenderingControl.GetLoudness', []);break;
			case 'BRIGHTNESS':$r=$this->CallApi('GetBrightness', []);break;
			case 'SHARPNESS': $r=$this->CallApi('GetSharpness', []);break;
			case 'CONTRAST'	: $r=$this->CallApi('GetContrast', []);break;
			case 'COLOR'	: $r=$this->CallApi('GetColor', []);break;
			case 'PLAYSTATE': $r=$this->CallApi('GetPlayState', []);if($r>2)$r=0; break;
			default:$r=null;
		}
		if(!is_null($r))$this->SetValueByIdent($Ident,$r);
		return $r;
	}	
	private function _writeValue(string $Ident, string $Value){
		if(!$this->CreateApi())return null;
		switch($Ident){
			case 'VOLUME'	: $ok=$this->CallApi('RenderingControl.SetVolume', [0,null,$Value=(int)$Value]);break;
			case 'MUTE'		: $ok=$this->CallApi('RenderingControl.SetMute', [0,null,$Value=(bool)$Value]);break;
			case 'BASS'		: $ok=$this->CallApi('RenderingControl.SetBass', [0,null,$Value=(int)$Value]);break;
			case 'TREBLE'	: $ok=$this->CallApi('RenderingControl.SetTreble', [0,null,$Value=(int)$Value]);break;
			case 'LOUDNESS'	: $ok=$this->CallApi('RenderingControl.SetLoudness', [0,null,$Value=(bool)$Value]);break;
			case 'BRIGHTNESS':$ok=$this->CallApi('SetBrightness', [0,$Value=(int)$Value]);break;
			case 'SHARPNESS': $ok=$this->CallApi('SetSharpness', [0,$Value=(int)$Value]);break;
			case 'CONTRAST'	: $ok=$this->CallApi('SetContrast', [0,$Value=(int)$Value]);break;
			case 'COLOR'	: $ok=$this->CallApi('SetColor', [0,$Value=(int)$Value]);break;
			case 'PLAYSTATE': if(($ok=$this->CallApi('SetPlayState', [0,$Value=(int)$Value]))!==false){$Value=$ok;$ok=true;}break;
			default:$ok=null;
		}
		if(!is_null($ok))$this->SetValueByIdent($Ident,$Value);
		return $ok;
	}
	
}
?>