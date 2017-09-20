<?php
require_once("../config.php");
//require_once("$app_path/config.php");
//die();
$m = tgparseinput();
if(!$m) httpdie(400, "1");

//error_log(json_encode($m));
$db = DbConfig::getConnection();

if(isset($m['from']) && (!isset($m['text']) || strpos(strtolower($m['text']), '/start') !== 0 )){
	tgstart(NULL, $m, $db, $token, FALSE);
}
if(isset($m['text'])){
	$text = $m['text'];
	if(strpos(strtolower($text), '/start') === 0){
	  	tgstart("¡Hola! Puedes enviarme un mensaje o comando con la parada que deseas revisar. Si no la conoces, prueba enviándome tu localización y te mandaré una lista de paradas cercanas para que elijas. Para ejemplos envía /ayuda o /help",$m, $db, $token, TRUE);
	}
	else if(strpos(strtolower($text), '/ayuda') === 0){
		tgsend_msg("Puedes preguntarme por mensaje privado sobre un paradero del Transantiago o sobre paraderos cercanos. Si conoces el código de paradero, que aparecen en los carteles de Transantiago en la calle, basta que lo escribas como texto o como comando, por ejemplo PF865 o /PF865. También, si mandas tu geolocalización te propondré las 5 paradas más cercanas a tí.", $m['chat']['id'], $token);
}
	else if(strpos(strtolower($text), '/help') === 0){
		tgsend_msg("You can ask me via private message (or slashtags on groups) about the incomming buses to a certain bus stop. If you know the code, just send it to me and I'll reply with the latest info. Bus stop codes are marked on the Bus stop signs. E.g. try sending me PF865 or /PF865. You can can also share me your geolocation using the movile Telegra apps and I'll reply with the 5 nearest stops. Results only in Spanish by now.",$m['chat']['id'], $token);
	}
	else if(strpos(strtolower($text), '/cerca') === 0){
		request_geo($m, $token);
	}
	else if(strpos(strtolower($text), '/') === 0){
 	 	parsecmd($m, $token);
	}
	else if(strpos(strtolower($text), 'paradero') === 0){
		return;
	}
	else parsecmd($m,$token);
}
else if(isset($m['query']) && strlen($m['query'])>2 ){
    $stop = get_stop($m['query']);
    if(!$stop) return;
    $str = make_text($stop);
    $arr = [
    ['title' => "Paradero ".$stop['id'].' - '.$stop['descripcion'], 'msg' => $str],
    ];
    tgshowoptions($arr,$m['id'], $token);
}
else if(isset($m['location'])){
	tgchat_action("typing",$m['chat']['id'], $token);
	if(!check_location($m,$mapstoken)){
		tgsend_msg("Servicio solo disponible en Santiago, Chile", $m['chat']['id'], $token);
		die();
	}
	$paradas = get_paradas($m, $mapstoken);
	$msg = "Encontré estas paradas";
	tgshow_options($msg, $paradas, $m['chat']['id'], $token);
	savegeo($m, $db);
}

function request_geo($m, $token){
	tgchat_action("typing", $m['chat']['id'], $token);
	if($m['chat']['id'] === $m['from']['id']){
		tgrequest_geo("Por favor acepta enviarme tu localización", $m['chat']['id'], $token);
	}
	else{
		tgsend_msg("Solo disponible por mensaje privado", $m['chat']['id'], $token);
	}
}

function check_location($m, $mapstoken){
	$lat = $m['location']['latitude'];
    $lng = $m['location']['longitude'];
	$cmd = "https://maps.googleapis.com/maps/api/geocode/json?latlng=$lat,$lng&key=$mapstoken";
	$res = file_get_contents($cmd);
	$res = json_decode($res, TRUE);
	if(!isset($res['status']) || $res['status'] != 'OK' || count($res['results']) == 0){
		return FALSE;
	}
	$country = FALSE;
	$city = FALSE;
	foreach($res['results'][0]['address_components'] as $c){
		if(isset($c['types'])){
			foreach($c['types'] as $t){
				if($t == 'country' && $c['short_name'] == 'CL') $country = TRUE;
				if($t == 'administrative_area_level_2' && $c['short_name'] == 'Santiago') $city = TRUE;
			}
		}
		if($country && $city) break;
	}
	return $country && $city;
}

function get_paradas($m, $mapstoken){
	$lat = $m['location']['latitude'];
    $lng = $m['location']['longitude'];
	$cmd = "https://maps.googleapis.com/maps/api/place/nearbysearch/json?location=$lat,$lng&sensor=true&key=$mapstoken&rankby=distance&types=bus_station";
	$res = file_get_contents($cmd);
	$res = json_decode($res, TRUE);
	$out = [];
	foreach($res['results'] as $r){
		$out[] = $r['name']; 
		if(count($out) >= 5) break;
	}
	return $out;
}

function get_stop($stop){
	$url = "http://dev.adderou.cl/transanpbl/busdata.php?paradero=".$stop;
	$res = file_get_contents($url);
	if(!$res){
        return NULL;
    }
	$json = json_decode($res, TRUE);
	if($json['id'] === "NULL"){
		return NULL;
	}
	return $json;
}

function make_text($json){
	$servicios = $json['servicios'];
	$out = [];
	foreach($servicios as $s){
		if($s['valido'] == 0){
			if(!isset($out[$s['descripcionError']])) $out[$s['descripcionError']] = [];
				$out[$s['descripcionError']][] = $s;
			}
			else{
				if(!isset($s['tiempo'])) continue;
				$t= trim($s['tiempo']);
				if(!isset($out[$t])) $out[$t] = [];
				$out[$t][] = $s;
			}
	}
	//$str = "Paradero ".$json['descripcion']."\n";
	$str = "";
	foreach($out as $key => $val){
		$str .= $key;
		$str .= " servicios <b>";
		foreach($val as $s){
			if($s['valido'] == 0) $str .= $s['servicio'].', ';
			else $str .= $s['servicio'].' (a '.$s['distancia'].'), ';
		}
		$str = substr($str,0,strlen($str)-2);
		$str .= "</b>\n";
	}
	if(strlen($str) == 0) $str = "No hay buses que se dirijan al paradero";
	$str = "Paradero ".$json['id']. ' - ' .$json['descripcion']."\n".$str;
	return $str;
}

function parsecmd($m, $token){
	tgchat_action("typing", $m['chat']['id'], $token);
	$text = trim(str_replace("@cuantofalta_bot","",$m['text']));
	$stop = get_cmd($text);
	if(!$stop) return;
	$db = DbConfig::getConnection();
	save($stop, $m, $db);
	//$url = "http://dev.adderou.cl/transanpbl/busdata.php?paradero=".$parada;
	//$res = file_get_contents($url);
	$json = get_stop($stop);
	if(!$json){
		$msg = "No existe la parada ".$stop;
		if($m['chat']['id'] == $m['from']['id']) tgsend_msg($msg, $m['chat']['id'], $token);
		return;
	}
	/*
	$servicios = $json['servicios'];
	$out = [];
	foreach($servicios as $s){
		if($s['valido'] == 0){
			if(!isset($out[$s['descripcionError']])) $out[$s['descripcionError']] = [];
			$out[$s['descripcionError']][] = $s; 
		}
		else{
			if(!isset($s['tiempo'])) continue;
			$t= trim($s['tiempo']);
			if(!isset($out[$t])) $out[$t] = [];
			$out[$t][] = $s;
		}
	}
	$str = "Paradero ".$json['descripcion']."\n";
	foreach($out as $key => $val){
		$str .= $key;
		$str .= " servicios <b>";
		foreach($val as $s){
			if($s['valido'] == 0) $str .= $s['servicio'].', ';
			else $str .= $s['servicio'].' (a '.$s['distancia'].'), ';
		}
		$str = substr($str,0,strlen($str)-2);
		$str .= "</b>\n";
	}
	if(strlen($str) == 0) $str = "No hay buses que se dirijan al paradero";
	*/
	$str = make_text($json);
	tgsend_msg($str, $m['chat']['id'], $token);
}

function save($parada, $m, $db){
	$parada = $db->real_escape_string($parada);
	$uid = $db->real_escape_string($m['chat']['id']);
	$sql = "INSERT INTO requests (tguser_id, parada) VALUES ('$uid', '$parada')";
	if(!DbConfig::update($db, $sql)){
        error_log($sql);
        error_log($db->error);
    }
}

function savegeo($m, $db){
	$lat = $db->real_escape_string($m['location']['latitude']);
    $lng = $db->real_escape_string($m['location']['longitude']);
    $uid = $db->real_escape_string($m['chat']['id']);
	$sql = "INSERT INTO georequests (uid, lat, lng) VALUES ('$uid', $lat, $lng)";
	if(!DbConfig::update($db, $sql)){
		error_log($sql);
		error_log($db->error);
	}
}

function get_cmd($txt){
	if($txt{0} === '/'){
	$p = explode(" ", $txt);
    if($p & strlen($p[0]) > 0 && $p[0]{0} == '/'){
        return str_replace("/","",$p[0]);
    }
	}
	else{
		$p = explode("-", $txt);
		return $p[0];
	}
    return NULL;
}
