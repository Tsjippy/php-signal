<?php
use SIM\SIGNAL\SignalBus;
use SIM;

if(!empty($argv) && count($argv) == 2){
    $data      = json_decode($argv[1]);

    if(!isset($data->envelope->dataMessage)){
        return;
    }

    // load wp
    ob_start();
    define( 'WP_USE_THEMES', false ); // Do not use the theme files
    define( 'COOKIE_DOMAIN', false ); // Do not append verify the domain to the cookie

    require(__DIR__."/../../../../../../../wp-load.php");
    require_once ABSPATH . WPINC . '/functions.php';

    $discard = ob_get_clean();

    /* Remove the execution time limit */
    set_time_limit(0);

    include_once __DIR__.'/../php/__module_menu.php';
    include_once __DIR__.'/../php/classes/Signal.php';

    $signal = new SignalBus();

    if($data->account != $signal->phoneNumber){
        return;
    }

    SIM\printArray($data);

    // message to group
    if(
        isset($data->envelope->dataMessage->groupInfo)      &&
        isset($data->envelope->dataMessage->mentions)
    ){
        foreach($data->envelope->dataMessage->mentions as $mention){
            if($mention->number == $signal->phoneNumber){
                $groupId    = $signal->groupIdToByteArray($data->envelope->dataMessage->groupInfo->groupId);
                $signal->sendGroupTyping($groupId);
                $signal->sendGroupMessage(getAnswer(trim(explode('?', $data->envelope->dataMessage->message)[1]), $data->envelope->source), $groupId);
            }
        }
    }elseif(!isset($data->envelope->dataMessage->groupInfo)){
        $signal->sentTyping($data->envelope->source, $data->envelope->dataMessage->timestamp);
        $signal->send($data->envelope->source, getAnswer($data->envelope->dataMessage->message, $data->envelope->source));
    }
}

function getAnswer($message, $source){
    $message = strtolower($message);

    //Change the user to the adminaccount otherwise get_users will not work
    wp_set_current_user(1);

    $name = false;
    $users = get_users(array(
        'meta_key'     => 'phonenumbers',
    ));

    foreach($users as $user){
        $phonenumbers = get_user_meta($user->ID,'phonenumbers',true);
        if(in_array($source,$phonenumbers)){
            $name = $user->first_name;
        }
    }

    if($message == 'test'){
        return 'Awesome!';
    }elseif(strpos($message, 'prayer') !== false && $name){
        return "This is the prayer for today:\n\n".SIM\PRAYER\prayerRequest(true, $name);
    }elseif($message == 'hi' || strpos($message, 'hello') !== false){
        return "Hi $name";
    }else{
        return 'I have no clue, do you know?';
    }
}