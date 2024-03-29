<?php
if(!defined('UPNP_LIB_DIR'))define('UPNP_LIB_DIR', __DIR__);
CONST CONFIG_EXTENTION	= '.upnp';
// Device Propertys
CONST PROP_VOLUME_CONTROL 		= 1;
CONST PROP_MUTE_CONTROL  		= 2;
CONST PROP_TREBLE_CONTROL		= 8;
CONST PROP_BASS_CONTROL			= 16;
CONST PROP_LOUDNESS_CONTROL		= 32;
CONST PROP_BRIGHTNESS_CONTROL	= 128;
CONST PROP_CONTRAST_CONTROL		= 256;
CONST PROP_SHARPNESS_CONTROL	= 512;
CONST PROP_COLOR_CONTROL		= 1024;
CONST PROP_SOURCE_CONTROL		= 2048;
CONST PROP_PLAY_CONTROL			= 4096;
CONST PROP_CONTENT_BROWSER		= 16384;
CONST PROP_EVENTS				= 32768;
CONST PROP_ALL_MEDIA 			= 16383;

// Data structures
// Device
CONST D_HOST 		= 0;
CONST D_PROPS		= 1;
CONST D_NAME 		= 2;
CONST D_INFO 		= 3;
CONST D_TYPE 		= 4;
CONST D_URLS 		= 5;
CONST D_OPTIONS 	= 6;
CONST D_SERVICES 	= 7;
CONST D_INFOS		= 9;
CONST D_CRC			= 10;
// Device Options
CONST DEVICE_REQUIRE_LOGIN	= 1;

// Services
CONST S_PORT 		= 0;
CONST S_CON  		= 1;
CONST S_CURL 		= 2;
CONST S_URN  		= 3;
CONST S_FUNCS 		= 4;
CONST S_EURL 		= 5;
CONST S_LOWERNAMES	= 6; 
CONST S_EVENTS 		= 7;
// Device & $Services
CONST ID_VARTABLE 	= 8;
// Variables
CONST V_TYPE 		= 0;
CONST V_ALLOWED 	= 1;
CONST V_MIN 		= 2;
CONST V_MAX 		= 3;
CONST V_STEP		= 4;
CONST V_DEFAULT		= 5;
?>