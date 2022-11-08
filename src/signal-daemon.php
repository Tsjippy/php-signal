<?php
use SIM\SIGNAL\SignalBus;
use SIM;

if(!empty($argv) && count($argv) == 2){
    $data      = json_decode($argv[1]);

    if(!isset($data->envelope->dataMessage)){
        return;
    }

    
    /* Remove the execution time limit */
    set_time_limit(0);

    include_once __DIR__.'/Signal.php';

    $signal = new SignalBus();

    if($data->account != $signal->phoneNumber){
        return;
    }

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

    if($message == 'test'){
        return 'Awesome!';
    }elseif($message == 'hi' || strpos($message, 'hello') !== false){
        return "Hi $name";
    }else{
        return 'I have no clue, do you know?';
    }
}
