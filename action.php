<?php

	require "include/init.php";

	header('Content-Type: application/json');

    $payload = json_decode($_POST['payload'], true);

    // Slack verification token
    $token = $payload['token'];
    if($token != $GLOBALS['slack_verification_token']){
        header('HTTP/1.0 403 Forbidden');
        echo 'You are forbidden!';
        exit;
    }

	$id = $payload['callback_id'];
	$user = $payload['user'];
	$team_domain = $payload['team']['domain'];
    $team_id = $payload['team']['id'];
    $action = $payload['actions'][0]['name'];

	switch ($action){

        // A new scope has been added to a team's token
        case 'add_scopes':
            if ($payload["trigger_id"]){
                $channel_id = $payload['channel']['id'];
                $message_ts = $payload['message_ts'];
                $ret = app_installs_get($team_id);

                if ($ret['ok']){
                    $team_token = $ret['token'];
                }

                $url = $GLOBALS['base_api_url']."apps.permissions.request";

                $post = array(
                    'token' => $team_token,
                    'scopes' => "commands, chat:write:user, users:read",
                    'trigger_id' => $payload["trigger_id"]
                );

                $ret = curl_post($url, $post);
                $ret_arr = json_decode($ret, true);

                if($ret_arr["ok"] == true){
                    $install_info = app_installs_get($team_id);
                    $dm_id = $install_info["installer_dm_channel_id"];
                    $message_ts = $install_info["install_message_ts"];
                }
            }
            exit();

        // Create a train
		case 'create':
			$destination = $id;
            $creator_id = $user['id'];
            $channel_id = $payload['channel']['id'];

            $train_value = explode("-", $payload['actions'][0]['value']);
            $time = $train_value[0];

            $args = array(
			    'destination'  => $destination,
                'date_leaving' => $time,
                'creator_name' => $user['name'],
                'creator_id'   => $creator_id,
                'channel_id'   => $channel_id,
                'channel_name' => $payload['channel']['name'],
                'team_id'      => $payload['team']['id'],
                'team_domain'  => $team_domain,
                'payload'      => Array('participants' => Array()),
                'timezone'     => slack_get_user_timezone($team_id, $user['id']),
            );

			if (!$time){
				$time = $payload['actions'][0]['selected_options'][0]['value'];
				$args['train_type'] = '';
			}else{
				$args['train_type'] = $train_value[1];
			}
			date_default_timezone_set($args['timezone']);

			$train = start_new_train($args);

            if (!$train['ok']){
				message_ephemeral("Cannot start new train. Error: ". $train['error']);
				exit(1);
			}

			$train_id = $train['id'];

			$message = message_channel($train_id, $destination, $time, $creator_id, $args['train_type']);
			$ret = slack_chat_post_message($team_id, $channel_id, $message);
			$ret_arr = json_decode($ret, true);

            if (!$ret_arr['ok']){
				if ($ret_arr['error']=='channel_not_found'){
					message_ephemeral("Lunch Train is not authorized to start a train in this channel. Try `/lunchtrain` in another public channel.");
				}else{
					message_ephemeral("Cannot start a train. Please try again later.");
				}
				exit(1);
			}

			add_channel_message_ts($train_id, $ret_arr['ts']);

			$message = message_creator($train_id, $destination, $time, $creator_id);
            message_ephemeral($message['text'], $message['attachments'], true);
            add_creator_message_ts_and_channel_id($train_id, $ret_arr['ts'], $ret_arr['channel']);

            print json_encode($message);

			exit;

        // A user joins a train
		case 'join':

			$ret = get_train_by_id($id);
			if ($ret['ok']) $train = $ret['train'];

			if ($train['creator_id'] == $user['id']){
				message_ephemeral("You're already on the train, conductor!");
				exit;
			}

            $ret = join_train($id, $user['id'], $user['name']);
			if (!$ret['ok']){
				message_ephemeral($ret['error']);
			} else {
                $train = $ret['train'];
            }

            $payload = json_decode($train['payload'], true);
            $participants = $payload['participants'];

            $msg = message_participant($id, $train['destination'], $train['date_leaving'],$train['creator_id']);

            message_ephemeral("",$msg['attachments']);

            $message = message_channel($id, $train['destination'], $train['date_leaving'], $train['creator_id'], $train['train_type'], $participants);
            slack_chat_update_message($team_id, $train['channel_id'], $message, $train['channel_message_ts']);

            break;

        // Cancel a train
		case 'cancel':

			$ret = get_train_by_id($id);
			$train = $ret['train'];
			$ret = cancel_train($id);
            $payload = json_decode($train['payload'], true);

			if (!$ret['ok']){
				message_ephemeral($ret['error']);
			}

			$cancellation_message = '<@'.$user['name'].'> cancelled their train to *'.$train['destination'].'*.';

            // Update the creator's ephemeral message
            message_ephemeral('Your train to *'.$train['destination'].'* has been cancelled.', Null, true);
			$message = array(
				'text' => $cancellation_message,
                'attachments' => array()
            );

            // Update the channel message
			slack_chat_update_message($team_id, $train['channel_id'], $message, $train['channel_message_ts']);

            // If there are participants on the train
            // Start a thread to notify the participants that the train has been cancelled
            $participants = array_keys($payload["participants"]);
            if(count($participants)){
                $participants_str = join(" ", array_map(function($a){ return "<@".$a.">"; }, $participants));
                $message = array('text' => ":point_up: ".$participants_str);
                slack_chat_post_message($team_id, $train['channel_id'], $message, $train['channel_message_ts']);
            }

			exit;

        // Do not create a Lunch Train
		case 'undo':
			$message = array('text' => 'Okey Dokey. Not starting a lunch train. You can try again with `/lunchtrain <destination>`');
			print json_encode($message);
			exit;

        // The creator has decided to delay their train by 10min
		case 'delay':
			$ret = get_train_by_id($id);
			$train = $ret['train'];

			// Update the DB record
			delay_train($id, $train['date_leaving']+ 600);

			// Update the train message
            $payload = json_decode($train['payload'], true);
            $participants = $payload['participants'];
			$message = message_channel($id, $train['destination'], $train['date_leaving']+ 600, $train['creator_id'], $train['train_type'], $participants);
			slack_chat_update_message($team_id, $train['channel_id'], $message, $train['channel_message_ts']);
			exit;


        // The cron job has found a train that needs to leave now
		case 'leavenow':
			$ret = get_train_by_id($id);
			$train = $ret['train'];

			leave_now($id);

			$payload = json_decode($train['payload'], true);
			$participants = $payload['participants'];

			$message = message_channel($id, $train['destination'], time() + 120, $train['creator_id'], $train['train_type'], $participants);
			train_actions_messages_update($id);

			exit();


        // The cron job has found a train that needs to send a 2 minute warning
		case 'disembark':
			$ret = disembark_train($id, $user['id']);

			if (!$ret['ok']) message_ephemeral($ret['error']);

			$ret = get_train_by_id($id);
			$train = $ret['train'];

			$payload = json_decode($train['payload'], true);
			$participants = $payload['participants'];

			$message = message_channel($id, $train['destination'], $train['date_leaving'], $train['creator_id'], $train['train_type'], $participants);
			slack_chat_update_message($team_id, $train['channel_id'], $message, $train['channel_message_ts']);

            message_ephemeral("You've deboarded *<@".$train['creator_id'].">*'s train to *".$train['destination']."*.", array(), true);

			exit;

			break;



	}
