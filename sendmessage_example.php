<?php

// activate full error reporting
error_reporting(E_ALL & E_STRICT);

include("XMPPHP/XMPP.php");

//$conn = new XMPP('talk.google.com', 5222, 'username', 'password', 'xmpphp', 'gmail.com', $printlog=false, $loglevel=Logging::LEVEL_INFO);
$conn = new XMPPHP_XMPP('jabber.wentz.it', 5222, 'dev', 'd3vd3v', 'xmpphp', 'jabber.wentz.it', $printlog=true, $loglevel=XMPPHP_Log::LEVEL_VERBOSE);
$conn->connect();
$conn->processUntil('session_start');
$conn->presence();
$conn->message('stephan@jabber.wentz.it', 'This is a test message!');
$conn->disconnect();
