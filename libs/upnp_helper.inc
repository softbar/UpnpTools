<?php
class UPnPDeviceEventHelper{
	public static function LastError(){
		return self::$ErrNo;
	}
	public static function LastErrorMsg(){
		return self::$ErrMsg;
	}
	public static function MaintainEvent(array &$Event, $EventUrl, $CallbackUrl=null, $RunTimeSec=300 ){
	  	if(empty($RunTimeSec))$RunTimeSec="Infinite";else $RunTimeSec="Second-$RunTimeSec";
	  	$host=parse_url($EventUrl);
	  	$EventUrl=$host['path'];
	  	$host=$host['host'].':'.$host['port'];
	  	if(is_string($CallbackUrl)){ // Register
			$mode=1;
	  		$request=self::CreatePacket('SUBSCRIBE', $EventUrl ,['HOST'=>$host,'CALLBACK'=>"<$CallbackUrl>",'NT'=>'upnp:event', 'TIMEOUT'=>$RunTimeSec]);
	  	}elseif($Event['sid']){
		  	if(is_null($CallbackUrl)){ // Update
  				$mode=2;
		  		$request=self::CreatePacket('SUBSCRIBE', $EventUrl ,['HOST'=>$host,'SID'=>$Event['sid'],'Subscription-ID'=>$Event['sid'],	'TIMEOUT'=>$RunTimeSec],null);
  			}elseif(is_bool($CallbackUrl)&& $CallbackUrl===true){ // UnRegister
  				$mode=3;
 				$request=self::CreatePacket('UNSUBSCRIBE', $EventUrl ,['HOST'=>$host,'SID'=>$Event['sid'],'Subscription-ID'=>$Event['sid']],null);
  			}else return self::Error('Invalid Callback', -2);
  		}else return self::Error('Invalid or empty event sid', -1);;
  		$result=self::SendPacket($host,$request);
IPS_LogMessage('upnp_helper', $request); 		
IPS_LogMessage('upnp_helper', $result); 		
if(empty($result) || self::$ErrNo){
  			return empty(self::$ErrNo)?self::Error('Empty Response', -3):null;
  		}
		elseif($mode==3){ 
			$Event['sid']='';$Event['lease']=0;
		}elseif(empty($result['SID'])){
			return self::Error('No Response SID',1);
		}elseif(!($timeout=$result['TIMEOUT']) || !$timeout=intval(str_ireplace('Second-', '', $timeout))){
			return self::Error('Invalid Timeout Response',2);	
		}elseif($mode==1){
			$Event['sid']=$result['SID'];
			$Event['lease']=time()+($timeout*1000);
		}elseif($mode==2){
			if($result['SID'] != $Event['sid'])	return self::Error('result sid and event sid are not equal', -3);	
			else $Event['lease']=time()+($timeout*1000);
		}
		return true;
	}
	
	private static function CreatePacket( $Method, $Url='/', array $Arguments=null, $Content=null){
		$out=["$Method $Url HTTP/1.1"];
		if($Arguments)foreach($Arguments as $vN=>$v)$out[]="$vN: $v";
		if(!is_null($Content)){
			$out[]="CONTENT-LENGTH: ".strlen($Content);
			if($Content)$out[]=$Content;
		}
		return implode("\n",$out)."\n\n";
	}
	private static function SendPacket( $url,  $content ){
		$p=parse_url($url);
		$port=empty($p['port']) ? 80 : $p['port'];
		$host=empty($p['path']) ? $p['host'] : $p['path'];
		$errno=self::$ErrNo=0;$errmsg=self::$ErrMsg='';
		if(!($fp = fsockopen($host, $port, $errno, $errmsg, 2))){
			return self::Error($errmsg,$errno);
		}
		fputs ($fp,$content);
		stream_set_timeout ($fp,1);
		$response = ""; $retries=2;
		while (!feof($fp)){
			$response.= fgets($fp,128); // filters xml answer
			if(--$retries == 0 && !$response)break;
		}
		fclose($fp);
IPS_LogMessage(__LINE__, $response);		
		
		return self::DecodePacket($response);
	}
	private static $ErrNo = 0;
	private static $ErrMsg= '';
	private static function Error($msg,$code){
		self::$ErrMsg=$msg;
		self::$ErrNo=$code;
		return null;
	}
	private static function DecodePacket( $Result){
		if(empty($Result)) return self::Error('Empty Response',-2);
		$data=$m=null; 
		$response = preg_split("/\n/", $Result);
		if(preg_match('/HTTP\/(\d.\d) (\d+) ([ \w]+)/', $response[0],$m)){
			$code=intval($m[2]);
			$msg=empty($m[3])?'Unknown':(is_numeric($m[3])?"Unknown Message ({$m[3]})":$m[3]);
			if($code!=200&&$code!=202)return self::Error('Invalid Response => '.$msg,$code);
			array_shift($response);
			$data=['HTTP_VERSION'=>$m[1], 'HTTP_CODE'=>$code, 'HTTP_MSG'=>$msg];
		}
		$count=count($response);
		for($j=0;$j<$count;$j++){
			$line=$response[$j];
			if(($pos=strpos($line,':'))===false || $pos > 20){ // is Content
				$data['CONTENT']=implode("\n",array_slice($response, $j));
				break;
			}else {
				$m=explode(':',trim($line));
				if(isSet($m[1])){
					$b=strtoupper(trim(array_shift($m)));
					if($b=='SUBSCRIPTION-ID')$b='SID';
					$data[$b]=trim(implode(':',$m));
				}
			}	
		}
		if(is_null($data))return self::Error('Invalid Response => '.json_decode($Result),-3);
		return $data;			
	}	
}
?>