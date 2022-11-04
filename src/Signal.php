<?php

namespace SIM\SIGNAL;
use SIM;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use GuzzleHttp;
use mikehaertl\shellcommand\Command;

/* Install java apt install openjdk-17-jdk -y
export VERSION=0.11.3
wget https://github.com/AsamK/signal-cli/releases/download/v"${VERSION}"/signal-cli-"${VERSION}"-Linux.tar.gz
sudo tar xf signal-cli-"${VERSION}"-Linux.tar.gz -C /opt
sudo ln -sf /opt/signal-cli-"${VERSION}"/bin/signal-cli /usr/local/bin/ */

// data is stored in $HOME/.local/share/signal-cli


class Signal {
    public function __construct(){
        require_once( MODULE_PATH  . 'lib/vendor/autoload.php');

        $this->valid    = true;

        $this->OS       = 'macOS';
        $this->basePath = WP_CONTENT_DIR.'/signal-cli';
        if (!is_dir($this->basePath )) {
            mkdir($this->basePath , 0777, true);
        }

        // .htaccess to prevent access
        if(!file_exists($this->basePath.'/.htaccess')){
            file_put_contents($this->basePath.'/.htaccess', 'deny from all');
        }

        if(strpos(php_uname(), 'Windows') !== false){
            $this->OS               = 'Windows';
            $this->basePath         = str_replace('\\', '/', $this->basePath);
        }elseif(strpos(php_uname(), 'Linux') !== false){
            $this->OS               = 'Linux';
        }
        
        $this->programPath      = $this->basePath.'/program/';
        if (!is_dir($this->programPath )) {
            mkdir($this->programPath , 0777, true);
        }

        $this->phoneNumber      = '';
        if(file_exists($this->basePath.'/phone.signal')){
            $this->phoneNumber      = trim(file_get_contents($this->basePath.'/phone.signal'));
        }

        $this->path             = $this->programPath.'bin/signal-cli';

        $this->daemon           = false;

        $this->osUserId         = "";

        $this->checkPrerequisites();
    }

    public function baseCommand(){
        $prefix = '';
        if($this->daemon){
            $prefix = "export DISPLAY=:0.0; export DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/$this->osUserId/bus; ";
        }
        $this->command = new Command([
            'command' => $prefix.$this->path,
            // This is required for binary to be able to find libzkgroup.dylib to support Group V2
            'procCwd' => dirname($this->path),
        ]);

        if($this->daemon){
            $this->command->addArg('--dbus');
        }

        if($this->OS == 'Windows'){
            $this->command->useExec  = true;
        }
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
        //dbus-send --session --dest=org.asamk.Signal --type=method_call --print-reply /org/asamk/Signal org.asamk.Signal.link string:"My secondary client" | tr '\n' '\0' | sed 's/.*string //g' | sed 's/\"//g' | qrencode -s10 -tANSI256

        file_put_contents($this->basePath.'/phone.signal', $phone);

        $captcha    = str_replace('signalcaptcha://', '', $captcha);


        $this->baseCommand();

        $this->command->addArg('-a', $phone);

        $this->command->addArg('register');

        if($voiceVerification){
            $this->command->addArg('--voice', null);
        }

        if(!empty($captcha)){
            $this->command->addArg('--captcha', $captcha);
        }

        $this->command->execute();

        if($this->command->getExitCode()){
            unlink($this->basePath.'/phone.signal');
        }

        return $this->parseResult();
    }

    /**
     * Disable push support for this device, i.e. this device won’t receive any more messages.
     * If this is the master device, other users can’t send messages to this number anymore.
     * Use "updateAccount" to undo this. To remove a linked device, use "removeDevice" from the master device.
     * @return bool|string
     */
    public function unregister()
    {
        $this->baseCommand();

        $this->command->addArg('-a', $this->phoneNumber);

        $this->command->addArg('unregister', null);

        $this->command->execute();

        if(!$this->command->getExitCode()){
            unlink($this->basePath.'/phone.signal');
        }

        return $this->parseResult();
    }

     /**
     * Uses a list of phone numbers to determine the statuses of those users.
     * Shows if they are registered on the Signal Servers or not.
     * @param string|array $recipients One or more numbers to check.
     * @return string|string
     */
    
     public function getUserStatus($recipients)
    {
        if(!is_array($recipients)){
            $recipients    = [$recipients];
        }

        $this->baseCommand();

        $this->command->addArg('getUserStatus', $recipients);

        $this->command->execute();

        return $this->parseResult();
    }

    /**
     * Verify the number using the code received via SMS or voice.
     * @param string $code The verification code e.g 123-456
     * @return bool|string
     */
    public function verify(string $code)
    {

        $this->baseCommand();

        $this->command->addArg('-a', $this->phoneNumber);

        $this->command->addArg('verify', $code);

        $this->command->execute();

        if($this->command->getExitCode()){
            unlink($this->basePath.'/phone.signal');
        }

        return $this->parseResult();
    }

    /**
     * Send a message to another user or group
     * @param string|array $recipients Specify the recipients’ phone number or a group id
     * @param string $message Specify the message, if missing, standard input is used
     * @param string $attachments Image file path
     * @return bool|string
     */
    public function send($recipients, string $message, string $attachments = null)
    {
        $groupId    = null;
        if(!is_array($recipients)){
            if(strpos( $recipients , '+' ) === 0){
                $recipients    = [$recipients];
            }else{
                $groupId    = $recipients;
                $recipients = null;
            }
        }

        $this->baseCommand();

        $this->command->nonBlockingMode = true;

        $this->command->addArg('-a', $this->phoneNumber);
        
        $this->command->addArg('send', $recipients);

        $this->command->addArg('-m', $message);

        if($groupId){
            $this->command->addArg('-g', $groupId);
        }

        if(!empty($attachments)){
            if(!is_array($attachments)){
                $attachments    = [$attachments];
            }
            SIM\printArray($attachments);
            $this->command->addArg('-a', $attachments);
        }

        $this->command->execute();

        return $this->parseResult();
    }

    public function markAsRead($recipient, $timestamp){
        // Mark as read
        $this->baseCommand();

        $this->command->addArg('-a', $this->phoneNumber);

        $this->command->addArg('sendReceipt', $recipient);

        $this->command->addArg('-t', $timestamp);

        $this->command->addArg('--type', 'read');

        $this->command->execute();

        return $this->parseResult();
    }

    public function sentTyping($recipient, $timestamp, $groupId=null){
        // Mark as read
        $this->markAsRead($recipient, $timestamp);

        // Send typing
        $this->baseCommand();

        $this->command->addArg('-a', $this->phoneNumber);

        $this->command->addArg('sendTyping', $recipient);

        if(!empty($groupId)){
            $this->command->addArg('-g', $groupId);
        }

        $this->command->execute();

        return $this->parseResult();
    }

    protected function parseResult($returnJson=false){
        if($this->command->getExitCode()){
            SIM\printArray($this->command);
            
            $this->error    = "<div class='error'>".$this->command->getError()."</div>";
            if($returnJson){
                return json_encode($this->error);
            }

            return $this->error;
        }

        $output = $this->command->getOutput();

        if($returnJson && (empty($output) || json_decode($output) == $output)){
            return json_encode($output);
        }
        return $output;
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
        $this->baseCommand();

        $this->command->addArg('updateProfile', null);

        $this->command->addArg('--name', $name);

        if($avatarPath){
            $this->command->addArg('--avatar', $avatarPath);
        }

        if($removeAvatar){
            $this->command->addArg('--removeAvatar', null);
        }

        $this->command->execute();

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
        #| tr '\n' '\0' | sed 's/.*string //g' | sed 's/\"//g' | qrencode -s10 -tANSI256
        if(empty($name)){
            $name   = get_bloginfo('name');
        }
        $this->baseCommand();

        $this->command->nonBlockingMode = false;

        $this->command->addArg('link', null);

        $this->command->addArg('-n', $name);

        // TODO: Better response handling
        $randFile = sys_get_temp_dir().'/'.rand() . time() . '.device';
        $this->command->addArg(" > $randFile 2>&1 &", null, false); // Ugly hack!
        sleep(1); // wait for file to get populated

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

        $link   = '';
        while(empty($link)){
            $link   = file_get_contents($randFile);
        }
        unlink($randFile);

        SIM\clearOutput();
        header("X-Accel-Buffering: no");
        header('Content-Encoding: none');

        // Turn on implicit flushing
        ob_implicit_flush(1);

        echo "Link is <code>$link</code>";

        #https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=$link

        return "<img loading='lazy' src='https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=$link'/><br>$link";
        if (!extension_loaded('imagick')){
            return $link;
        }

        $renderer       = new ImageRenderer(
            new RendererStyle(400),
            new ImagickImageBackEnd()
        );
        $writer         = new Writer($renderer);
        $qrcodeImage    = base64_encode($writer->writeString($link));

        return "<img loading='lazy' src='data:image/png;base64, $qrcodeImage'/><br>$link";
    }

     /**
     * Link another device to this device.
     * Only works, if this is the master device
     * @param string $uri Specify the uri contained in the QR code shown by the new device.
     *                    You will need the full uri enclosed in quotation marks, such as "tsdevice:/?uuid=…​.."
     * @return bool|string
     */
    public function addDevice(string $uri)
    {
        $this->baseCommand();

        $this->command->addArg('--uri', $uri);

        $this->command->execute();

        return $this->parseResult();
    }

     /**
     * Show a list of connected devices
     * @return array|null
     */
    public function listDevices()
    {
        $this->baseCommand();

        $this->command->addArg('-o', 'json');

        $this->command->addArg('listDevices', null);

        $this->command->execute();

        return json_decode($this->parseResult(true));
    }

     /**
     * Remove a connected device. Only works, if this is the master device
     * @param int $deviceId Specify the device you want to remove. Use listDevices to see the deviceIds
     * @return bool|string
     */
    public function removeDevice(int $deviceId)
    {
        $this->baseCommand();

        $this->command->addArg('removeDevice', null);

        $this->command->addArg('-d', $deviceId);

        $this->command->execute();

        return $this->parseResult();
    }

     /**
     * Update the account attributes on the signal server.
     * Can fix problems with receiving messages
     * @return bool
     */
    public function updateAccount()
    {
        $this->baseCommand();

        $this->command->addArg('updateAccount', null);

        $this->command->execute();

        return $this->parseResult();
    }

     /**
     * Private function to create group, update group and add members in the group
     * @param string|null $name Specify the new group name
     * @param array $members Specify one or more members to add to the group
     * @param string|null $avatarPath Specify a new group avatar image file
     * @param string|null $groupId Specify the recipient group ID in base64 encoding.
     *                             If not specified, a new group with a new random ID is generated
     * @return bool|string
     */
    private function _createOrUpdateGroup(string $name = null, array $members = [], string $avatarPath = null, string $groupId = null)
    {
        $this->baseCommand();

        $this->command->addArg('updateGroup', null);

        if(!empty($groupId)){
            $this->command->addArg('-g', $groupId);
        }

        if($name){
            $this->command->addArg('-n', $name);
        }

        if(!empty($members)){
            $this->command->addArg('-m', $members);
        }

        if(!empty($avatarPath)){
            $this->command->addArg('-a', $avatarPath);
        }

        $this->command->execute();

        return $this->parseResult();
    }

     /**
     * Create Group
     * @param string $name
     * @param array $members
     * @param string|null $avatarPath
     * @return bool
     */
    public function createGroup(string $name, array $members = [], string $avatarPath = null): bool
    {
        return $this->_createOrUpdateGroup($name, $members, $avatarPath);
    }

    public function updateGroup(string $groupId, string $name = null, array $members = [], string $avatarPath = null): bool
    {
        return $this->_createOrUpdateGroup($name, $members, $avatarPath, $groupId);
    }

    public function addMembersToGroup(string $groupId, array $members)
    {
        return $this->_createOrUpdateGroup(null,$members,null,$groupId);
    }

     /**
     * List Groups
     * @return array|string
     */
    public function listGroups()
    {
        $this->baseCommand();

        $this->command->addArg('-a', $this->phoneNumber);

        $this->command->addArg('-o', 'json');

        $this->command->addArg('listGroups', null);

        $this->command->execute();

        return json_decode($this->parseResult(true));
    }

     /**
     * Join a group via an invitation link.
     * To be able to join a v2 group the account needs to have a profile (can be created with the updateProfile command)
     * @param string $uri The invitation link URI (starts with https://signal.group/#)
     * @return bool|string
     */
    public function joinGroup(string $uri)
    {
        $this->baseCommand();

        $this->command->addArg('joinGroup', null);

        $this->command->addArg('--uri', $uri);

        $this->command->execute();

        return $this->parseResult();
    }

    /**
     * Send a quit group message to all group members and remove self from member list.
     * If the user is a pending member, this command will decline the group invitation
     * @param string $groupId Specify the recipient group ID in base64 encoding
     * @return bool|string
     */
    public function quitGroup(string $groupId)
    {
        $this->baseCommand();

        $this->command->addArg('quitGroup', null);

        $this->command->addArg('-g', $groupId);

        $this->command->execute();

        return $this->parseResult();
    }

     /**
     * Query the server for new messages.
     * New messages are printed on standard output and attachments are downloaded to the config directory.
     * In json mode this is outputted as one json object per line
     * @param int $timeout Number of seconds to wait for new messages (negative values disable timeout). Default is 5 seconds
     * @return object|string
     */
    public function receive(int $timeout = 5)
    {
        $this->baseCommand();

        $this->command->addArg('-o', 'json');

        $this->command->addArg('receive', null);

        $this->command->addArg('-t', $timeout);

        $this->command->execute();

        return json_decode($this->parseResult(true));
    }

    /**
     * Get Command to further get output, error or more details of the command.
     * @return Command
     */
    public function getCommand()
    {
        return $this->command;
    }

    public function checkPrerequisites(){
        $this->error   = '';

        $curVersion = str_replace('javac ', '', shell_exec('javac -version'));
        if(version_compare('17.0.0.0', $curVersion) > 0){
            $this->error    .= "Please install Java JDK, at least version 17<br>install dbus-x11";
            $this->valid    = false;
        }

        $release        = SIM\getLatestRelease('AsamK', 'signal-cli');

        $curVersion     = str_replace('signal-cli ', 'v', trim(shell_exec($this->path.' --version')));

        if(!file_exists($this->path) || empty($curVersion)){
            $this->installSignal(str_replace('v', '', $release['tag_name']));

            if(!file_exists($this->path)){
                $this->error    .= "Please install signal-cli<br>";
                $this->valid    = false;
            }
        }elseif($curVersion  != $release['tag_name']){
            echo "Updating <br>";

            $this->installSignal(str_replace('v', '', $release['tag_name']));
        }

        if(empty($this->error)){
            return true;
        }
    }

    private function installSignal($version){
        $pidFile    = __DIR__.'/installing.signal';
        if(file_exists($pidFile)){
            return;
        }
        file_put_contents($pidFile, 'running');

        echo "Downloading Signal version $version<br>";
        $path   = $this->downloadSignal("https://github.com/AsamK/signal-cli/releases/download/v$version/signal-cli-$version-$this->OS.tar.gz");

        echo "Download finished<br>";
        // Unzip the gz
        $fileName = str_replace('.gz', '', $path);

        if(!file_exists($fileName)){

            echo "Unzipping .gz archive<br>";
            // Raising this value may increase performance
            $bufferSize = 4096; // read 4kb at a time

            // Open our files (in binary mode)
            $file       = gzopen($path, 'rb');
            $outFile    = fopen($fileName, 'wb');

            // Keep repeating until the end of the input file
            while (!gzeof($file)) {
                // Read buffer-size bytes
                // Both fwrite and gzread and binary-safe
                fwrite($outFile, gzread($file, $bufferSize));
            }

            // Files are done, close files
            fclose($outFile);
            gzclose($file);
        }

        // unzip the tar
        $folder = str_replace('.tar.gz', '', $path);

        if(file_exists($folder)){
            if($this->OS == 'Windows'){
                // remove the folder
                exec("rmdir $folder /s /q");
            }else{
                exec("rm -R $folder");
            }
        }

        try {
            echo "Unzipping .tar archive to $folder<br>";

            $phar = new \PharData($fileName);
            $phar->extractTo($folder); // extract all files
        } catch (\Exception $e) {
            echo "<div class='error'>".$e->getMessage().'</div>';

            unlink($pidFile);

            // handle errors
            $this->error    = 'Installation error';
            return $this->error;
        }

        // remove the old folder
        if(file_exists($this->programPath)){
            echo "Removing old version<br>";

            if($this->OS == 'Windows'){
                $path   = str_replace('/', '\\', $this->programPath);
                // kill the process
                exec("taskkill /IM signal-cli /F");

                // remove the folder
                exec("rmdir $path /s /q");
            }else{
                // stop the deamon
                exec("kill $(ps -ef | grep -v grep | grep -P 'signal-cli.*daemon'| awk '{print $2}')");

                exec("rmdir -rf $this->programPath");
            }
        }

        // move the folder
        $result = rename("$folder/signal-cli-$version", $this->programPath);

        if($result){
            echo "Succesfully installed Signal version $version!<br>";
        }else{
            echo "Failed!<br>Could not move $folder/signal-cli-$version to $this->programPath";
        }

        unlink($pidFile);
    }

    private function downloadSignal($url){
        $filename   = basename($url);
        $path       = sys_get_temp_dir().'/'.$filename;

        $path = str_replace('\\', '/', $path);

        if(file_exists($path)){
            return $path;
        }

        $client     = new GuzzleHttp\Client();
        try{
            $client->request(
                'GET',
                $url,
                array('sink' => $path)
            );

            return $path;
        }catch (\GuzzleHttp\Exception\ClientException $e) {
            unlink($path);

            if($e->getResponse()->getReasonPhrase() == 'Gone'){
                return "The link has expired, please get a new one";
            }
            return $e->getResponse()->getReasonPhrase();
        }
    }
}
