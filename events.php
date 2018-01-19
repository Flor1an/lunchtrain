<?php
    require "include/init.php";
    header('Content-Type: application/json');

    $postdata = file_get_contents('php://input');
    $postjson = json_decode($postdata,true);

    // Echo challenge token back to Slack for Request URL validation
    if (isset($postjson["challenge"])){
        echo $postjson["challenge"];
        exit;
    }

    // Slack verification token
    $token = $postjson['token'];

    if($token != $GLOBALS['slack_verification_token']){
        header('HTTP/1.0 403 Forbidden');
        echo 'You are forbidden!';
        exit;
    }

    $event_data = $postjson['event'];
    $event_type = $event_data['type'];

    // A new scope has been granted
    if($event_type == "scope_granted"){
        $scopes = $event_data['scopes'];
        $install_message = $GLOBALS['install_message'];
        $install_team_info = app_installs_get($postjson['team_id']);

        if(in_array("commands", $scopes) && in_array("chat:write", $scopes) && in_array("users:read", $scopes)){
            // The command and channel write scopes has been granted, update the install message accordingly.
            $install_message['attachments'] =  Array(
                Array(
                    "mrkdwn_in" => Array("text"),
                    'fallback' => '...',
                    'text' => ":white_check_mark: You've added the `/LunchTrain` command and allowed posting to channels\n:robot_face: Add me to a channel `/invite @lunchtrain` and start a train"
                )
            );
        }

        $ret = slack_chat_update_message(
            $install_team_info ['team_id'],
            $install_team_info ['installer_dm_channel_id'],
            $install_message,
            $install_team_info ['install_message_ts']
        );

        $url=$GLOBALS['base_api_url']."apps.permissions.info?token=".$install_team_info ['token'];
        $response = curl_get($url);
    };
