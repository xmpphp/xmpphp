<?php

// activate full error reporting
error_reporting(E_ALL & E_STRICT);

include("xmpp.php");
$conn = new XMPP('talk.google.com', 5222, 'username', 'password', 'xmpphp', 'gmail.com', $printlog=False, $loglevel=Logging::LOG_INFO);
$conn->connect();
$conn->processUntil('session_start');
$conn->message('someguy@someserver.net', 'This is a test message!');
$conn->disconnect();

?>
