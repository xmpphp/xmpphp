<?php

class XMLObj {
	var $name;
	var $ns;
	var $attrs = array();
	var $subs = array();
	var $data = '';

	function XMLObj($name, $ns='', $attrs=array(), $data='') {
		$this->name = strtolower($name);
		$this->ns  = $ns;
		if(is_array($attrs)) {
			foreach($attrs as $key => $value) {
				$this->attrs[strtolower($key)] = $value;
			}
		}
		$this->data = $data;
	}

	function printobj($depth=0) {
		print str_repeat("\t", $depth) . $this->name . " " . $this->ns . ' ' . $this->data;
		print "\n";
		foreach($this->subs as $sub) {
			$sub->printobj($depth + 1);
		}
	}

	function hassub($name) {
		foreach($this->subs as $sub) {
			if($sub->name == $name) return True;
		}
		return False;
	}

	function sub($name, $attrs=Null, $ns=Null) {
		foreach($this->subs as $sub) {
			if($sub->name == $name) return $sub;
		}
	}
}

class XMLStream {
	var $socket;
	var $parser;
	var $buffer;
	var $xml_depth = 0;
	var $host;
	var $port;
	var $stream_start = '<stream>';
	var $disconnect = false;
	var $ns_map = array();
	var $current_ns;
	var $xmlobj = Null;
	var $nshandlers = array();
	var $idhandlers = array();
	var $lastid = 0;

	function XMLStream($host, $port) {
		$this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		$this->host = $host;
		$this->port = $port;
		#set up the parser
		$this->setupParser();
	}

	function getId() {
		$this->lastid++;
		return $this->lastid;
	}

	function addIdHandler($id, $pointer, $obj=Null) {
		$this->idhandlers[$id] = array($pointer, $obj);
	}

	function addHandler($name, $ns, $pointer, $obj=Null) {
		$this->nshandlers[] = array($name,$ns,$pointer,$obj);
	}

	function connect() {
		if(socket_connect($this->socket, $this->host, $this->port)) {
			socket_write($this->socket, $this->stream_start);
		}
	}

	function process() {
		while(!$this->disconnect) {
			$buff = socket_read($this->socket, 1024);
			#print $buff;
			#print "\n*****\n";
			xml_parse($this->parser, $buff, False);
			# parse whatever we get out of the socket
		}
	}

	function startXML($parser, $name, $attr) {
		$this->xml_depth++;
		if(array_key_exists('XMLNS', $attr)) {
			$this->current_ns = $attr['XMLNS'];
		}
		$ns = $this->current_ns;
		foreach($attr as $key => $value) {
			if(strstr($key, ":")) {
				$key = explode(':', $key);
				$key = $key[1];
				$this->ns_map[$key] = $value;
			}
		}
		if(!strstr($name, ":") === False)
		{
			$name = explode(':', $name);
			$ns = $this->ns_map[$name[0]];
			$name = $name[1];
		}
		#print $this->xml_depth . ' ' . $name . ' ' . $ns . ' '  . $attr . "\n";
		$obj = new XMLObj($name, $ns, $attr);
		if($this->xml_depth > 1)
			$this->xmlobj[$this->xml_depth - 1]->subs[] = $obj;
		$this->xmlobj[$this->xml_depth] = $obj;
	}

	function endXML($parser, $name) {
		$this->xml_depth--;
		if($this->xml_depth == 1) {
			#$this->xmlobj[2]->printobj();
			#clean-up old objects
			$found = False;
			foreach($this->nshandlers as $handler) {
				if($this->xmlobj[2]->name == $handler[0] and $this->xmlobj[2]->ns == $handler[1]) {
					if($handler[3] === Null) $handler[3] = $this;
					call_user_method($handler[2], $handler[3], $this->xmlobj[2]);
					break;
				}
			}
			foreach($this->idhandlers as $id => $handler) {
				if($this->xmlobj[2]->attrs['id'] == $id) {
					if($handler[1] === Null) $handler[1] = $this;
					call_user_method($handler[0], $handler[1], $this->xmlobj[2]);
					#id handlers are only used once
					unset($this->idhandlers[$id]);
					break;
				}
			}
			if(is_array($this->xmlobj)) {
				$this->xmlobj = array_slice($this->xmlobj, 0, 1);
				$this->xmlobj[0]->subs = Null;
			}
		}
	}
	function charXML($parser, $data) {
		$this->xmlobj[$this->xml_depth]->data = $data;
	}

	function send($msg) {
		socket_write($this->socket, $msg);
	}

	function reset() {
		$this->xml_depth = 0;
		$this->xmlobj = Null;
		$this->setupParser();
		$this->send($this->stream_start);
	}

	function setupParser() {
		$this->parser = xml_parser_create();
		xml_set_object($this->parser, $this);
		xml_set_element_handler($this->parser, 'startXML', 'endXML');
		xml_set_character_data_handler($this->parser, 'charXML');
	}
}

class XMPP extends XMLStream {
	var $server;
	var $user;
	var $password;
	var $resource;

	function XMPP($host, $port, $user, $password, $resource) {
		$this->XMLStream($host, $port);
		$this->user = $user;
		$this->password = $password;
		$this->resource = $resource;
		$this->stream_start = '<stream:stream to="' . $host . '" xmlns:stream="http://etherx.jabber.org/streams" xmlns="jabber:client" version="1.0">\n';
		$this->addHandler('features', 'http://etherx.jabber.org/streams', 'features_handler');
		$this->addHandler('success', 'urn:ietf:params:xml:ns:xmpp-sasl', 'sasl_success_handler');
		$this->addHandler('failure', 'urn:ietf:params:xml:ns:xmpp-sasl', 'sasl_failure_handler');
	}

	function features_handler($xml) {
		if($xml->hassub('bind')) {
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
	}
}



