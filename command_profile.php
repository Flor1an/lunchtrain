<?php
	require "include/lib_profile.php";
	require "include/init.php";
	header('Content-Type: application/json');
	profile_init();
	$destination = $_POST['text'];

	if (substr($destination, 0, 3) =="to "){
		$destination = substr($destination, 3);
	}

	if (!$destination){
		$destination = 'somewhere';
	}

	$team_id = $_POST['team_id'];
	$team_domain = $_POST['team_domain'];

	$channel_id = $_POST['channel_id'];
	$channel_name = $_POST['channel_name'];
	$creator_id = $_POST['user_id'];
	$creator_name = $_POST['user_name'];


	if ($destination == 'help'){
		$message = array(
			"text" => 'Typing `/lunchtrain [optional place or food genre] [at optional time]` will start a lunch train. ',
			);

		print json_encode($message);
		exit;
	}


	profile_mark('initialized');
	$timezone = slack_get_user_timezone($team_id, $creator_id);
	profile_mark('get_timezone_from_slack');
	date_default_timezone_set($timezone);

	$parsed_result = time_parser_reminders_v2_parse_time_with_text($destination);
	profile_mark('parse_time');
	if ($parsed_result['ok']){
		$destination = $parsed_result['text'];
		$time = $parsed_result['time_obj']['ts'];

		if ($time > time() + 3600 * 6){


			$new_time = floor(time()/900) * (900) + 900;

			$message = array("text" => "Ouch, we couldn't start this train. Your departure time should be with-in six hours from now. Try `/lunchtrain <destination> at ". date("g:i a", $new_time)."`");
			print json_encode($message);
		}else{
			print json_encode(message_slash_command_with_time($destination, $time));

		}
		profile_mark('message drafted');

	}else{
		print json_encode(message_slash_command($destination));
	}

	profile_echo();




