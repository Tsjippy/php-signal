<?php

namespace SIM\SIGNAL;
use SIM;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use GuzzleHttp;
use mikehaertl\shellcommand\Command;
use stdClass;

/* Install java apt install openjdk-17-jdk -y
export VERSION=0.11.3
wget https://github.com/AsamK/signal-cli/releases/download/v"${VERSION}"/signal-cli-"${VERSION}"-Linux.tar.gz
sudo tar xf signal-cli-"${VERSION}"-Linux.tar.gz -C /opt
sudo ln -sf /opt/signal-cli-"${VERSION}"/bin/signal-cli /usr/local/bin/ */

// data is stored in $HOME/.local/share/signal-cli


class SignalBus extends Signal {
    public function __construct($dbusType='session'){
        parent::__construct();

        $this->osUserId         = "";

        $this->dbusType         = $dbusType;

        $phone                  = str_replace('+', '', $this->phoneNumber);
        $this->prefix           = "dbus-send --$this->dbusType --dest=org.asamk.Signal --type=method_call --print-reply=literal /org/asamk/Signal/_$phone org.asamk.Signal.";
        if($this->dbusType == 'session'){
            // find dbus address
            $id     = [];
            exec("bash -c 'echo \$UID'", $id);

            $this->osUserId = $id[0];

            $this->prefix   = "export DISPLAY=:0.0; export DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/$this->osUserId/bus; $this->prefix";
        }

        // Check daemon
        $this->daemonIsRunning();
        $this->startDaemon();
    }

     /**
     * Register a phone number with SMS or voice verification. Use the verify command to complete the verification.
     * Default verify with SMS
     * @param bool $voiceVerification The verification should be done over voice, not SMS.
     * @param string $captcha - from https://signalcaptchas.org/registration/generate.html
     * @return bool|string
     */
    public function register(string $phone, string $captcha, bool $voiceVerification = false)
    {
        $voice  = false;
        if($voiceVerification){
            $voice  = 'false';
        }

        file_put_contents($this->basePath.'/phone.signal', $phone);

        $captcha    = str_replace('signalcaptcha://', '', $captcha);

        $this->command = new Command([
            'command' => "dbus-send --$this->dbusType --dest=org.asamk.Signal --type=method_call --print-reply=literal /org/asamk/Signal org.asamk.Signal.registerWithCaptcha string:'$phone' boolean:$voiceVerification string:'$captcha'"
        ]);

        $this->command->execute();

        if($this->command->getExitCode()){
            unlink($this->basePath.'/phone.signal');
        }

        return $this->parseResult();
    }

     /**
     * Verify the number using the code received via SMS or voice.
     * @param string $code The verification code e.g 123-456
     * @return bool|string
     */
    public function verify(string $code)
    {

        $this->command = new Command([
            'command' => "dbus-send --$this->dbusType --dest=org.asamk.Signal --type=method_call --print-reply=literal /org/asamk/Signal org.asamk.Signal.verify string:'$this->phoneNumber' string:'$code'"
        ]);

        $this->command->execute();

        if($this->command->getExitCode()){
            unlink($this->basePath.'/phone.signal');
        }

        return $this->parseResult();
    }
    
     /**
     * Link to an existing device, instead of registering a new number.
     * This shows a "tsdevice:/…" URI.
     * If you want to connect to another signal-cli instance, you can just use this URI.
     * If you want to link to an Android/iOS device, create a QR code with the URI (e.g. with qrencode) and scan that in the Signal app.
     * @param string|null $name Optionally specify a name to describe this new device. By default "cli" will be used
     * @return string
     */
    public function link(string $name = ''): string
    {
        if(empty($name)){
            $name   = get_bloginfo('name');
        }

        $this->command = new Command([
            'command' => "dbus-send --$this->dbusType --dest=org.asamk.Signal --type=method_call --print-reply=literal /org/asamk/Signal org.asamk.Signal.link string:'$name'"
        ]);

        $this->command->execute();

        if($this->command->getExitCode()){
            $error  = "<div class='error'>";
                $error  .= $this->command->getError()."<br>";
                $error  .= "Try to do the linking yourself<br><br>";
                $error  .= "Open a command line and run this:<br>";
                $error  .= "<code>$this->path link -n \"$name\"</code><br><br>";
            $error  .= "</div>";
            return $error;
        }

        $link   = $this->command->getOutput();

        SIM\printArray("Link is <code>$link</code>", true);

        $link   = str_replace(['\n', '"'], ['\0', ''], $link);

        if (!extension_loaded('imagick')){
            return $link;
        }

        $renderer       = new ImageRenderer(
            new RendererStyle(400),
            new ImagickImageBackEnd()
        );
        $writer         = new Writer($renderer);
        $qrcodeImage    = base64_encode($writer->writeString($link));

        return "<img src='data:image/png;base64, $qrcodeImage'/><br>$link";
    }
     
     /**
     * Shows if a number is registered on the Signal Servers or not.
     * @param   string          $recipient Number to check.
     * @return  string
     */
    public function isRegistered($recipient)
    {

        $this->command = new Command([
            'command' => "{$this->prefix}isRegistered string:'$recipient'"
        ]);

        $this->command->execute();

        $result = $this->parseResult();

        if(empty($this->error)){
            $result = (str_replace('boolean ', '', $result) === 'true');
        }
        return $result;
    }

    public function sendGroupMessage($message, $groupId, $attachments=''){
        $this->command = new Command([
            'command' => "{$this->prefix}sendGroupMessage string:'$message' array:string:$attachments array:byte:$groupId"
        ]);

        $this->command->execute();

        return $this->parseResult();
    }

     /**
     * List Groups
     * @return array|string
     */
    public function listGroups()
    {
        $this->command = new Command([
            'command' => "{$this->prefix}listGroups"
        ]);

        $this->command->execute();

        $result = $this->parseResult();

        if(empty($this->error)){
            preg_match_all('/struct {.*?array of bytes \[(.*?)\](.*?)}/s', $result, $matches);

            $result = [];
            foreach($matches[1] as $key=>$match){
                $group = new stdClass();
                $id = trim(str_replace("\n", '', $match));

                $idString   = [];
                foreach(explode(' ', $id) as $digid){
                    if(empty($digid)){
                        continue;
                    }

                    $idString[] = hexdec($digid);
                }

                $idString   = implode(',', $idString);

                $group->{"id"}      = $idString;
                $group->{"name"}    = trim(str_replace("\n", '', $matches[2][$key]));

                $result[]           = $group;
            }
        }

        return $result;
    }

    /**
     * Send a message to another user or group
     * @param string|array $recipients Specify the recipients’ phone number or a group id
     * @param string $message Specify the message, if missing, standard input is used
     * @param string|array $attachments Image file path or array of file paths
     * @return bool|string
     */
    public function send($recipients, string $message, $attachments = '')
    {
        if(!is_array($recipients)){
            if(strpos( $recipients , '+' ) === 0){
                $recipient    = "string:'$recipients'";
            }else{
                Parent::send($recipients, $message, $attachments);
            }
        }else{
            $recipient    = "array:string:".implode(',', $recipients);
        }

        if(!empty($attachments) && is_array($attachments)){
            $attachments    = implode(',', $attachments);
        }

        $this->command = new Command([
            'command' => "{$this->prefix}sendMessage string:'$message' array:string:'$attachments' $recipient"
        ]);

        $this->command->execute();

        return $this->parseResult();
    }

    public function markAsRead($recipient, $timestamp){
        // Mark as read
        $this->command = new Command([
            'command' => "{$this->prefix}sendReadReceipt string:'$recipient' array:int64:$timestamp"
        ]);

        $this->command->execute();

        return $this->parseResult();
    }

    /**
     * Sends a typing indicator to number
     *
     * @param   string  $recipient  The phonenumber
     * @param   int     $timestamp  Optional timestamp of a message to mark as read
     *
     * @return string               The result
     */
    public function sentTyping($recipient, $timestamp='', $groupId=''){
        if(!empty($timestamp)){
            // Mark as read
            $this->markAsRead($recipient, $timestamp);
        }

        // Send typing
        $this->command = new Command([
            'command' => "{$this->prefix}sendTyping string:'$recipient' boolean:false"
        ]);

        $this->command->execute();

        return $this->parseResult();
    }

    public function sendGroupTyping($groupId){
        // Send typing
        $this->command = new Command([
            'command' => "{$this->prefix}sendGroupTyping array:byte:'$groupId' boolean:false"
        ]);

        $this->command->execute();

        return $this->parseResult();
    }

     /**
     * Update the name and avatar image visible by message recipients for the current users.
     * The profile is stored encrypted on the Signal servers.
     * The decryption key is sent with every outgoing messages to contacts.
     * @param string $name New name visible by message recipients
     * @param string $avatarPath Path to the new avatar visible by message recipients
     * @param bool $removeAvatar Remove the avatar visible by message recipients
     * @return bool|string
     */
    public function updateProfile(string $name, string $avatarPath = null, bool $removeAvatar = false)
    {
        if($removeAvatar){
            $removeAvatar   = 'true';
        }else{
            $removeAvatar   = 'false';
        }
        $this->command = new Command([
            'command' => "{$this->prefix}updateProfile string:'$name' string:'' string:'' string:'$avatarPath' boolean:$removeAvatar"
        ]);

        $this->command->execute();

        return $this->parseResult();
    }

    /**
     * Converts a base64 group id to an array of bytes
     *
     * @param   string  $id       the base64encode group id
     *
     * @return  string            A comma seperated list of bytes
     */
    public function groupIdToByteArray($id){
        $id = str_replace('_', '/', $id);

        $id = base64_decode($id);

        $id = unpack('C*',$id);

        $id = implode(',', $id);
        return $id;
    }

    private function daemonIsRunning(){
        // check if running
        $command = new Command([
            'command' => "ps -ef | grep -v grep | grep -P 'signal-cli.*daemon'"
        ]);

        $command->execute();

        $result = $command->getOutput();
        if(empty($result)){
            $this->daemon   = false;
        }else{
            $this->daemon   =  true;

            // Running daemon but not for this website
            if(strpos($result, $this->basePath) === false && strpos($result, 'do find -name signal-daemon.php') === false){
                $this->error    = 'The daemon is started but for another website in this user account.<br>';
                $this->error   .= "You can send messages just fine, but not receive any.<br>";
                $this->error   .= "To enable receiving messages add this to your crontab (crontab -e): <br>";
                $this->error   .= '<code>@reboot export DISPLAY=:0.0; export DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/'.$this->osUserId.'/bus;'.$this->path.' -o json daemon | while read -r line; do find -name signal-daemon.php 2>/dev/null -exec php "{}" "$line" \; ; done; &</code><br>';
                $this->error   .= "Then reboot your server";
            }
        }
    }

    public function startDaemon(){
        if($this->OS == 'Windows'){
            SIM\printArray('exiting', true);
            return;
        }

        if(!$this->daemon){
            $display    = 'export DISPLAY=:0.0;';
            $dbus       = "export DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/$this->osUserId/bus;";
            $cli        = "$this->path -o json daemon";
            $read       = 'while read -r line; do php '.__DIR__.'/../../daemon/signal-daemon.php "$line"; done;';
            
            $command = new Command([
                'command' => "bash -c '$display $dbus $cli | $read' &"
            ]);

            $command->execute();
        }

       /*  if(!$this->daemonIsRunning()){
            SIM\printArray($command, true);
            return;
        } */
 
        $this->daemon   = true;
    }
}
