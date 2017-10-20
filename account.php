<?php

function get_user_by_email_hash($email_hash){
	$email_hash_hash = crc32($email_hash);
	$sql = "SELECT * FROM users WHERE email_hash_hash=\"" . $email_hash_hash . "\"";
	$rows = get_db_rows($sql);
	$c = count($rows);
	if($c == 0)
		return false;	
	
	elseif($c > 1){
		$sql = "SELECT * FROM users WHERE email_hash=\"" . $email_hash . "\"";
		$rows = get_db_rows($sql);
		if(count($rows)>1)
			die(dLog("db Error: email_hash duplicated"));
	}
	return $rows[0];
}

function login_user(&$rq, &$resp){ //$email_hash, $password_hash, &$status){
	global $token;
	dLog("login_user() rq:", $rq);
	$row = get_user_by_email_hash($rq->data->email_hash);
	if(!$row){
		$resp['status'] = 'user_not_found';
		return false;
	}
	
	if(!password_verify($rq->data->password_hash, $row->password_hash)){
		$resp['status'] = 'incorrect_password';
		return false;
	}
	
	$resp['status'] = 'logged_in';
	
	$t = time();
	$token = array(
		'user_id' => $row->user_id,
		'created' => $t,
		'issued_by' => $_SERVER['SERVER_ADDR'],
		'check_after' => $t + STEP_TIME_BEFORE_PASSWORD_UPDATE_CHECK, // the time after which it should be checked to see if there has been a password update
	);
	return true;
}


function mk_web_hash($str){
	global $server_salt;
	$h = hash("SHA256", $str . pack('C*', ...$server_salt));
	return password_hash( $h, PASSWORD_BCRYPT );
}

function verify_web_password_hash($user_id, $hash){
	$sql = "SELECT password_hash FROM users WHERE user_id=$user_id";
	$rows = get_db_rows($sql);
	if(count($rows)!=1){
		dLog("verify_web_password_hash() Error: Returning false");
		return FALSE;
	}
	$p_hash = hash("SHA256", $rows[0]->password_hash . pack('C*', ...$server_salt), TRUE);
	return password_verify($p_hash, $hash);	
}

function web_update_password(&$rq, &$resp){
	global $server_salt;
	$tkn = decode_token($rq->jwt);
	
	if(!$tkn){
		$resp['message'] = 'An error has occurred. Please restart the reset process';
		$resp['status'] = 'token_error';
		return;
	}
	if($tkn->expiry < time()){
		$resp['message'] = 'Link validity has expired. Please restart the reset process';
		$resp['status'] = 'link_expired';
		return;
	}

	if(!verify_web_password_hash($tkn->user_id, $tkn->password_hash)){
		$resp['message'] = 'Password has already been changed';
		$resp['status'] = 'already_reset';		
		return;
	}
	
	$resp['message'] = 'Password successfully changed';
	$resp['status'] = 'password_changed';
	return update_password($tkn->user_id, $rq->data->password_hash);	
}

function update_password($user_id, $password){
	$sql = 'UPDATE users SET password_hash="' . hash_password($password, PASSWORD_BCRYPT) . '";';
	return do_sql($sql);
}

function send_email($email, $user_id, &$resp, $template_name, $message_callback){	
	dLog("send_confirmation_email() cwd:" . getcwd());
	$ip = $_SERVER['REMOTE_ADDR'];
	$t = time() - 3600;
	$sql = "SELECT COUNT(*) AS count FROM email WHERE (user_id=$user_id OR ip=\"$ip\") AND ((sent IS NOT NULL AND sent > $t) OR requested > $t);"; // MySql already makes sure that only one pending email exists per user_id
	$rows = get_db_rows($sql);
	if(!$rows || !count($rows))
		die(dLog("Error in send_confirmation_email(), bailing"));
	
	if($rows[0]->count > MAX_EMAILS_PER_HOUR){
		$resp['status'] = 'email_limit_exceeded';
		return false;		
	}

	$sql = "INSERT INTO email (email, user_id, ip) VALUES($email, $user_id, " . $_SERVER['SERVER_ADDR'] . " )";
	
	$message = file_get_contents($template_name);
	if(!$message){
		dLog("Error retrieving file in send_confirmation_email()");
		return NULL;		
	}

	$message = $message_callback($message, $user_id);
	
	
	dLog("send_confirmation_email() message:\n $message");
	
	$email_headers = [ 'MIME-Version: 1.0', 'Content-type: text/html; charset=iso-8859-1', 'From: ChatSpark <' . ACTIVATION_EMAIL_ADDRESS . '>' ];
	
	if(mail( $email, "Account activation for your new chatSpark account", $message, implode("\r\n", $email_headers))){
		$resp['status'] = 'confirmation_email_sent';
		return TRUE;
	}	
	$resp['status'] = 'email_send_error';
	return NULL;
}


function create_new_account($rq, &$resp){
	global $mysql_conn, $genders;
	$valid = TRUE;
	$resp['fields'] = array();
	static $req_fields = array('email', 'email_hash', 'password_hash', 'gender', 'day', 'month', 'year');
	chk_fields_present($req_fields, $rq->data, $resp['fields']);

	if(!array_key_exists('invalid', $resp['fields']))
		$resp['fields']['invalid'] = array();
	
	if(!filter_var($rq->data->email, FILTER_VALIDATE_EMAIL))
		array_push($resp['fields']['invalid'], 'email');	
	
	$g = array_search($rq->data->gender, $genders);
	if($g===FALSE)
		array_push($resp['fields']['invalid'], 'gender');
	else
		$rq->data->gender = $g;
	
	chk_date($rq->data->day, $rq->data->month + 1, $rq->data->year, $resp['fields']['invalid']);
	
	foreach($resp['fields'] as $k => $v)
		if(count($v) > 0)
			$valid = FALSE;
		else
			unset($resp['fields'][$k]);

	if(!$valid){
		$resp['status'] = 'field_error';
		return FALSE;
	}

	$wr_fields = get_object_vars($rq->data);
	
	foreach(array('email', 'year', 'month', 'day') as $k)
		unset($wr_fields[$k]);
		
	$wr_fields['email_hash_hash'] = CRC32($rq->data->email_hash);
	
	$wr_fields['password_hash'] = password_verify($wr_fields['password_hash'], PASSWORD_BCRYPT);
	
	$wr_fields['birthday'] = mktime(0,0,0, $rq->data->month + 1, $rq->data->day, $rq->data->year);
	dLog("wr_fields:", $wr_fields);
	dLog("wr_fields av:", array_values($wr_fields));
	$sql = "INSERT INTO users(" . implode(',', array_keys($wr_fields)) . ") VALUES(" . implode_sql(array_values($wr_fields)) . ");";

	if(!do_sql($sql)){
		$err_num = mysqli_errno($mysql_conn);
		dLog("MySQL error in create_new_account(). Number: $err_num");
		switch($err_num){
			case ER_DUP_ENTRY:
				dLog("User account already created");
				$resp['status'] = 'existing_user';
				return FALSE;
			break;
			default:
				dLog("mysql Error");
				$resp['status'] = 'error';
				return FALSE;
		}
	}
	return send_confirmation_email($rq->data->email, $mysql_conn->insert_id, $resp);
}

function reset_password(&$rq, &$resp){
	return send_email($email, $user_id, $resp, PASSWORD_RESET_TEMPLATE, function($message){
		$sql = "SELECT password_hash FROM users WHERE user_id=$user_id";
		$ph = get_db_rows($sql)[0]->password_hash;
		$tkn = array(
			'user_id' => $user_id,		
			'type' => 'reset_password',
			'expiry' => time() + 3600,
			'password_hash' => mk_web_hash($user_id),
		);
		return str_replace('@reset_link@', rq_endpoint . '?' . encode_token($tkn), $message);
	});
}

function send_confirmation_email($email, $user_id, &$resp){
	return send_email($email, $user_id, $resp, ACTIVATION_EMAIL_TEMPLATE, function($message){
		$tkn = array(
			'user_id' => $user_id,		
			'type' => 'activate_account',
		);
		return str_replace('@activation_link@', rq_endpoint . '?' . encode_token($tkn), $message);
	});	
}

function chk_date($d, $m, $y, &$inv){
	$age = date('Y') - $y;
	
	if(($age < MIN_AGE) || ($age > MAX_AGE))
		array_push($inv, 'year');
	
	if(($m < 1) || ($m > 12))
		array_push($inv, 'month');
	
	if(!checkdate($m, $d, $y))
		array_push($inv, 'day');

}

?>
