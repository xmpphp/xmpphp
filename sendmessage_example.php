<?php

// activate full error reporting
error_reporting(E_ALL & E_STRICT);

include("XMPPHP/XMPP.php");

$conn = new XMPP('talk.google.com', 5222, 'username', 'password', 'xmpphp', 'gmail.com', $printlog=false, $loglevel=XMPPHP_Log::LEVEL_INFO);
$conn->connect();
$conn->processUntil('session_start');
$conn->presence();
$conn->message('stephan@jabber.wentz.it', 'This is a test message!');
$conn->disconnect();
