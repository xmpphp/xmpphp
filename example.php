<?php

include "cjp.php";
$client = new XMPP('talk.google.com', 5222, 'username', 'password', 'ChicXMPP', 'gmail.com');
$client->connect();
$client->process();

?>
