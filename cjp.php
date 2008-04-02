<?php
require_once("xmlobj.php");

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
	var $current_ns = array();
	var $xmlobj = Null;
	var $nshandlers = array();
	var $idhandlers = array();
	var $eventhandlers = array();
	var $lastid = 0;
	var $default_ns;
	var $until;
	var $until_happened = False;
	var $until_payload = array();

	function XMLStream($host, $port) {
		#$this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
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

	function addEventHandler($name, $pointer, $obj) {
		$this->eventhanders[] = array($name, $pointer, $obj);
	}

	function connect($persistent=False) {
		#if(socket_connect($this->socket, $this->host, $this->port)) {
		#	socket_write($this->socket, $this->stream_start);
		#}
		if($persistent) {
			$conflag = STREAM_CLIENT_PERSISTENT;
		} else {
			$conflag = STREAM_CLIENT_CONNECT;
		}
		print "connecting to tcp://{$this->host}:{$this->port}\n";
		$this->socket = stream_socket_client("tcp://{$this->host}:{$this->port}", $flags=$conflag);
		$this->send($this->stream_start);
	}

	function process() {
		while(!$this->disconnect) {
			#$buff = socket_read($this->socket, 1024);
			$buff = fread($this->socket, 1024);
			print "RECV: $buff\n";
			xml_parse($this->parser, $buff, False);
			# parse whatever we get out of the socket
		}
	}

	function processTime($timeout=-1) {
		$start = time();
		$updated = '';
		while($timeout == -1 or time() - $start < $timeout) {
			$timeleft = $timeout - (time() - $start);
			$read = array($this->socket);
			$write = NULL;
			$except = NULL;
			$updated = stream_select($read, $write, $except, intval($timeleft));
			if ($updated > 0) {
				$buff = fread($this->socket, 1024);
				print "RECV: $buff\n";
				xml_parse($this->parser, $buff, False);
			}
		}
	}

	function processUntil($event, $timeout=-1) {
		$start = time();
		if(!is_array($event)) $event = array($event);
		$this->until = $event;
		$this->until_happened = False;
		$updated = '';
		while(!$this->until_happened and (time() - $start < $timeout or $timeout == -1)) {
			$read = array($this->socket);
			$write = NULL;
			$except = NULL;
			$updated = stream_select($read, $write, $except, 1);
			if ($updated > 0) {
				$buff = fread($this->socket, 1024);
				print "RECV: $buff\n";
				xml_parse($this->parser, $buff, False);
			}
		}
		$payload = $this->until_payload;
		$this->until_payload = array();
		return $payload;
	}

	function startXML($parser, $name, $attr) {
		$this->xml_depth++;
		if(array_key_exists('XMLNS', $attr)) {
			$this->current_ns[$this->xml_depth] = $attr['XMLNS'];
		} else {
			$this->current_ns[$this->xml_depth] = $this->current_ns[$this->xml_depth - 1];
			if(!$this->current_ns[$this->xml_depth]) $this->current_ns[$this->xml_depth] = $this->default_ns;
		}
		$ns = $this->current_ns[$this->xml_depth];
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
		$obj = new XMLObj($name, $ns, $attr);
		if($this->xml_depth > 1)
			$this->xmlobj[$this->xml_depth - 1]->subs[] = $obj;
		$this->xmlobj[$this->xml_depth] = $obj;
	}

	function endXML($parser, $name) {
		$this->xml_depth--;
		print "{$this->xml_depth}: $name\n";
		if($this->xml_depth == 1) {
			#clean-up old objects
			$found = False;
			foreach($this->nshandlers as $handler) {
				print $this->xml_depth;
				print "::::{$this->xmlobj[2]->name}:{$this->xmlobj[2]->ns}\n";
				if($this->xmlobj[2]->name == $handler[0] and ($this->xmlobj[2]->ns == $handler[1] or (!$handler[1] and $this->xmlobj[2]->ns == $this->default_ns))) {
					if($handler[3] === Null) $handler[3] = $this;
					call_user_method($handler[2], $handler[3], $this->xmlobj[2]);
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

	function event($name, $payload=Null) {
		print "EVENT: $name\n";
		foreach($this->eventhandlers as $handler) {
			print "$name {$handler[0]}\n";
			if($name == $handler[0]) {
				if($handler[2] === Null) $handler[2] = $this;
				call_user_method($handler[1], $handler[2], $payload);
				print "Called {$handler[1]}\n";
			}
		}
		if(in_array($name, $this->until)) {
			$this->until_happened = True;
			$this->until_payload[] = array($name, $payload);
		}
	}

	function charXML($parser, $data) {
		$this->xmlobj[$this->xml_depth]->data = $data;
	}

	function send($msg) {
		#socket_write($this->socket, $msg);
		print "SENT: $msg \n";
		fwrite($this->socket, $msg);
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

