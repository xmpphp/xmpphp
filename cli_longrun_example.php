<?php
include("xmpp.php");
$conn = new XMPP('talk.google.com', 5222, 'user', 'password', 'xmpphp', 'gmail.com', $printlog=True, $loglevel=LOGGING_INFO);
$conn->connect();
while(!$conn->disconnected) {
	$payloads = $conn->processUntil(array('message', 'presence', 'end_stream', 'session_start'));
	foreach($payloads as $event) {
		$pl = $event[1];
		switch($event[0]) {
			case 'message': 
				print "---------------------------------------------------------------------------------\n";
				print "Message from: {$pl['from']}\n";
				if($pl['subject']) print "Subject: {$pl['subject']}\n";
				print $pl['body'] . "\n";
				print "---------------------------------------------------------------------------------\n";
				$conn->message($pl['from'], $body="Thanks for sending me \"{$pl['body']}\".", $type=$pl['type']);
				if($pl['body'] == 'quit') $conn->disconnect();
				if($pl['body'] == 'break') $conn->send("</end>");
			break;
			case 'presence':
				print "Presence: {$pl['from']} [{$pl['show']}] {$pl['status']}\n";
			break;
			case 'session_start':
				$conn->presence($status="Cheese!");
			break;
		}
	}
}

?>
