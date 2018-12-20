<?php
/*********************************************************************************************************************\
VTO2000A User Detector
******************************************
The idea for this server was found here and a lot has been adopted from there
        https://www.ip-phone-forum.de/threads/dahua-vto-2000-zutrittskontrolle-dee1010b-fingerprint-vt02000a-f.300801/page-2#post-2306476
The original source of the socket server was found here:
        https://gist.github.com/nmmmnu/1408434
which again is based on:
        http://devzone.zend.com/209/writing-socket-servers-in-php/

This is server written in PHP that employ socket select() method. This way it doesn't loop at a 100% CPU capacity but
rather "waits" for a connection to process it. It is single threaded and does not fork, but rather process the clients
"very" fast one by one. Redis, nginx and Lighttpd works in similar way.

Hints:
- You need the curl extension for php to run this script
- The timezone of this server executing the script should be equal to the one of the VTO
\*********************************************************************************************************************/
include('config.php');

/******************************\
| Do not modify the code below |
\******************************/
// Check environment for curl since this isn't always installed
if(!extension_loaded('curl')) exit("You have to install curl first");

error_reporting(~E_ALL);

// INITIALIZE VARIABLES
// --------------------
// Array that will hold client information
$clients = array();
// Array that will hold the matches from the regex on the result
$matches = array();
// Array that will hold the final resultset
$resultset = array();
// Holds the highest result from the VTO-Feedback
$vtomax = 0;

// FUNCTIONS
// ---------
// Log text with either 'debug', 'notice', 'info' or 'warn'
function logger($loglevel, $loggertext) {
    global $debuglevel, $debugfacility;
    switch ($debugfacility) {
        case 1:
            echo strtoupper($loglevel) . ": " . $loggertext . "\n";
            break;
        case 2:
            switch ($loglevel) {
                case 'warn':
                    $sysloglevel = LOG_WARNING;
                    break;
                case 'notice':
                    $sysloglevel = LOG_NOTICE;
                    break;
                case 'debug':
                    $sysloglevel = LOG_DEBUG;
                    break;
                default:
                    $sysloglevel = LOG_INFO;
                    break;
            }
            syslog($sysloglevel, $loggertext);
            break;
        default:
            // Includes zero
            break;
    }
}

function notification_telegram($user, $time) {
    global $notification;
    if ($notification['telegram']['enable'] === true) {
        $mime = mime_content_type($notification['telegram']['snapshotlocation']);
        $name = 'Doorbell at ' . str_replace('%20', ' ', $etime);
        $photo = new CURLFile($notification['telegram']['snapshotlocation'], $mime, $name);
        $photo_send = array(
            'chat_id'   => $notification['telegram']['chatid'],
            'photo'  => $photo,
            'caption'   => 'The door was opened on ' . $time . ' by user ' . $user
        );
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,"https://api.telegram.org/bot".$notification['telegram']['apikey']."/sendPhoto");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $photo_send);
        $result=curl_exec ($ch);
        if (curl_errno($ch)) {
            $result = curl_error($ch);
        }
    }
}


// START PROCESSING
// ----------------
// Open the syslog and set wuud as identifier
openlog('wuud', LOG_CONS | LOG_NDELAY | LOG_PID, LOG_USER | LOG_PERROR);

// Create a TCP Stream socket
$master_socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

// Bind the socket to an address/port
socket_bind($master_socket, $address, $port);

// Start listening for connections
socket_listen($master_socket);

// Start of the master loop
while (true) {
    // Setup clients listen socket for reading
    $read   = array();
    $read[] = $master_socket;
    // Add clients to the $read array
    foreach($clients as $client){
        $read[] = $client;
    }

    // Initialize the variables for the socket_select()
    $write  = NULL;
    $except = NULL;
    $ready = socket_select($read, $write, $except, NULL);

    // If there is no event, skip the loop.
    if ($ready == 0) continue;
    // But this time there is an event
    logger("debug", "$ready events: " . implode(' - ',$read));

    // if a new connection is being made add it to the client array
    if (in_array($master_socket, $read)) {
        if (count($clients) <= $max_clients) {
            logger("info", "Accept client");
            $clients[] = socket_accept($master_socket);
        } else {
            logger("warn", "Max number of clients reached");
        }
        // remove master socket from the read array
        $key = array_search($master_socket, $read);
        unset($read[$key]);
    }
    logger("debug", "client list: " . implode(", ", $clients));

    // If a client is trying to write - handle it now
    foreach($read as $client) {
        $input = socket_read($client, 1024);
        // Zero length string means disconnected, so remove from clients array
        if ($input == null) {
            logger("debug", "$client has disconnected");
            $key = array_search($client, $clients);
            unset($clients[$key]);
        }
        // Remove whitespaces at beginning and end
        $n = trim($input);
        if ($input) {
            // At this point we do have a connection from a VTO2000A, therefore reply with time.
            logger("debug", "Received input from $client: $input");
            socket_write($client, 'The local time is ' . date('n/j/Y g:i a') . "\n");
            // We do see some JSON data coming in, thus we can decode this once the garbage is stripped
            $input_array = json_decode(strstr($input,"{"), true);
            // from the JSON, the IP of the VTO can be extracted
            $vto_ip=$input_array["params"]["ipAddr"];
            logger("debug", "Received input from VTO $vto_ip, waiting a couple seconds");
            // Now a wait time is introduced since the VTO needs to process some stuff.
            sleep(5);
            // At this point a call to the VTO needs to be executed to get more details.
            // the VTO requires to set a start and end time to basically get only the relevant records
            // End Time
            $etime= date('Y-m-d')."%20".date('H:i:s');
            // Start time (current time minus sixty seconds)
            $stime= date('Y-m-d')."%20".date('H:i:s',time()-60);
            // Build the URL
            $url='http://'.$vto_ip.'/cgi-bin/recordFinder.cgi?action=find&name=AccessControlCardRec&StartTime='.$stime.'&EndTime='.$etime;
            logger("debug", "URL for Details: $url");
            // Run the call via curl
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            // Do not include headers in the response
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
            curl_setopt($ch, CURLOPT_USERPWD, $vto2000a[$vto_ip]['user'] . ":" . $vto2000a[$vto_ip]['pass']);
            // Return the result to a variable
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            $response = curl_exec($ch);
            logger("debug", "The response was:\n $response");
            curl_close($ch);
            $re = '/^[a-z]{7}\[(\d+)\]\.([a-z]+)\=(.*)$/im';
            preg_match_all($re, trim($response), $matches, PREG_SET_ORDER, 0);
            // Remove the variable since it is no longer needed
            $resultset=array();
            foreach($matches as $value) {
                // 1 = Resultset, e.g. 0,1,2
                // 2 = Key, e.g. UserID with the modification then to be lowercased.
                // 3 = Value, e.g. 102 with the modification to only allow alphanumeric characters plus _, - and #
                $resultset[$value[1]][strtolower($value[2])] = preg_replace('/[^\w-#]/', '', $value[3]);
            }
            var_dump($resultset);
            // Remove the variable since it is no longer needed
            $vtomax=count($resultset)-1;
            logger("debug", "The maximum response from the VTO was $vtomax");
            // Determine the user now. If the door is opened via the Webinterface, the system cannot determine the user.
            logger("debug", "The door was opened via method " . $resultset[$vtomax]["method"]);
            switch ($resultset[$vtomax]['method']) {
                case 4:
                    // Has been opened via the Webinterface
                    $user="Webinterface";
                    break;
                case 6:
                    // Opened via Fingerprint
                    $ru=$resultset[$vtomax]['userid'];
                    logger("debug", "The transmitted userid was $ru");
                    $user=$vto2000a[$vto_ip]['userids'][$ru];
                    logger("debug", "The determined user was $user");
                    break;
                default:
                    logger("warn", "The opening method " . $resultset[$vtomax]['method'] . "is unknown. Please contact the author of the script for details.");
                    break;
            };
            logger('info', 'The door was opened on ' . str_replace('%20', ' ', $etime) . ' using method ' . $resultset[$vtomax]['method'] . ' by user ' . $user . ' (' . $ru . ')');
            notification_telegram($user, str_replace('%20', ' ', $etime));
            unset($etime, $stime, $response, $matches, $user, $ru, $resultset);
        }
    }
} // End of the master loop

// Close the master sockets
socket_close($master_socket);
