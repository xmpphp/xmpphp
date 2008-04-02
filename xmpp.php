<?php
require_once("xmlobj.php");
require_once("xmlstream.php");

class XMPP extends XMLStream {
	var $server;
	var $user;
	var $password;
	var $resource;

	function XMPP($host, $port, $user, $password, $resource, $server=Null) {
		$this->XMLStream($host, $port);
		$this->user = $user;
		$this->password = $password;
		$this->resource = $resource;
		if(!$server) $server = $host;
		$this->stream_start = '<stream:stream to="' . $server . '" xmlns:stream="http://etherx.jabber.org/streams" xmlns="jabber:client" version="1.0">\n';
		$this->addHandler('features', 'http://etherx.jabber.org/streams', 'features_handler');
		$this->addHandler('success', 'urn:ietf:params:xml:ns:xmpp-sasl', 'sasl_success_handler');
		$this->addHandler('failure', 'urn:ietf:params:xml:ns:xmpp-sasl', 'sasl_failure_handler');
		$this->addHandler('proceed', 'urn:ietf:params:xml:ns:xmpp-tls', 'tls_proceed_handler');
		$this->default_ns = 'jabber:client';
		$this->addHandler('message', 'jabber:client', 'message_handler');
		$this->addHandler('presence', 'jabber:client', 'presence_handler');
	}

	function message_handler($xml) {
		print "Message: {$xml->sub('body')->data}\n";
	}

	function presence_handler($xml) {
	}

	function features_handler($xml) {
		if($xml->hassub('starttls')) {
			$this->send("<starttls xmlns='urn:ietf:params:xml:ns:xmpp-tls'><required /></starttls>");
		} elseif($xml->hassub('bind')) {
			$id = $this->getId();
			print "ok, we can bind $id\n";
			$this->addIdHandler($id, 'resource_bind_handler');
			$this->send("<iq xmlns=\"jabber:client\" type=\"set\" id=\"$id\"><bind xmlns=\"urn:ietf:params:xml:ns:xmpp-bind\"><resource>{$this->resource}</resource></bind></iq>");
		} else {
			print "Attempting Auth...\n";
			$this->send("<auth xmlns='urn:ietf:params:xml:ns:xmpp-sasl' mechanism='PLAIN'>" . base64_encode("\x00" . $this->user . "\x00" . $this->password) . "</auth>");
		}
	}

	function sasl_success_handler($xml) {
		print "Auth success!\n";
		$this->reset();
	}
	
	function sasl_failure_handler($xml) {
		print "Auth failed!\n";
	}

	function resource_bind_handler($xml) {
		if($xml->attrs['type'] == 'result') print "Bound to " . $xml->sub('bind')->sub('jid')->data . "\n";
		$id = $this->getId();
		$this->addIdHandler($id, 'session_start_handler');
		$this->send("<iq xmlns='jabber:client' type='set' id='$id'><session xmlns='urn:ietf:params:xml:ns:xmpp-session' /></iq>");
	}

	function session_start_handler($xml) {
		print "session started\n";
		$this->event('session_start');
	}

	function tls_proceed_handler($xml) {
		print "Starting TLS connection\n";
		stream_socket_enable_crypto($this->socket, True, STREAM_CRYPTO_METHOD_TLS_CLIENT);
		print stream_socket_get_name($this->socket, True) . "\n";
		$this->reset();
	}
}
?>
