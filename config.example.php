<?php
// This file needs to be renamed to the name "config.php" to be used by the main program.


/*********************************\
| CONFIGURATION of the PHP Server |
\*********************************/
// The IP this server listens on. This must be the same IP as the SIP-Server!
// Use 0.0.0.0 to listen on all interfaces
$address = '0.0.0.0';
// Don't change the port, otherwise the script won't work
$port    = 5000;
// The server will support this many clients
$max_clients = 10;
// Send to Syslog (2), Send to CLI (1) or disable (0) the debug code
$debugfacility = 1;
// Set the debuglevel
// Can be either debug, info, notice or warning.
$debuglevel = 'debug';

/*********************************\
| CONFIGURATION for the VTO20000A |
\*********************************/
$vto2000a = [
    "192.168.178.42" => [
        "user" => "admin",
        "pass" => "tZ33Mm7ghkhmvVn",
        "userids" => [
            "102" => "Bob",
            "103" => "Alice",
        ],
    ],
    "192.168.178.43" => [
        "user" => "admin",
        "pass" => "8kdaKIIS8P60cCJ",
        "userids" => [
            "102" => "Bob",
            "103" => "Alice",
        ],
    ],
];

/*************************************\
| CONFIGURATION for the Notifications |
\*************************************/
$notification = [
    "telegram" => [
        "enable" => true,
        "apikey" => "PUT:YOUR_API_KEY_HERE",
        "chatid" => "PUT_YOUR_CHAT_ID_HERE",
        "snapshotlocation" => "/mnt/ramdisk/snapshot.jpg",
    ],
];
