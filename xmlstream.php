<?php
require_once("xmlobj.php");
require_once("logging.php");

class XMLStream {
	var $socket;
	var $parser;
	var $buffer;
	var $xml_depth = 0;
	var $host;
	var $port;
	var $stream_start = '<stream>';
	var $stream_end = '</stream';
	var $disconnected = false;
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
	var $log;
	var $reconnect = True;
	var $been_reset = False;

	function XMLStream($host, $port, $log=False, $loglevel=Null) {
		#$this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		$this->reconnect = True;
		$this->host = $host;
		$this->port = $port;
		#set up the parser
		$this->setupParser();
		#set up logger
		$this->log = new Logging($log, $loglevel);
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

	function connect($persistent=False, $sendinit=True) {
		$this->disconnected = False;
		if($persistent) {
			$conflag = STREAM_CLIENT_PERSISTENT;
		} else {
			$conflag = STREAM_CLIENT_CONNECT;
		}
		$this->log->log("Connecting to tcp://{$this->host}:{$this->port}");
		$this->socket = stream_socket_client("tcp://{$this->host}:{$this->port}", $flags=$conflag);
		if($sendinit) $this->send($this->stream_start);
	}

	function apply_socket($socket) {
		$this->socket = $socket;
	}

	function process() {
		$updated = '';
		while(!$this->disconnect) {
			$read = array($this->socket);
			$write = NULL;
			$except = NULL;
			$updated = stream_select($read, $write, $except, 1);
			if ($updated > 0) {
				$buff = fread($this->socket, 1024);
				if(!$buff and $this->reconnect) $this->doReconnect();
				$this->log->log("RECV: $buff", LOGGING_VERBOSE);
				xml_parse($this->parser, $buff, False);
			}
		}
	}

	function processTime($timeout=-1) {
		$start = time();
		$updated = '';
		while(!$this->disconnected and ($timeout == -1 or time() - $start < $timeout)) {
			$read = array($this->socket);
			$write = NULL;
			$except = NULL;
			$updated = stream_select($read, $write, $except, 1);
			if ($updated > 0) {
				$buff = fread($this->socket, 1024);
				if(!$buff and $this->reconnect) $this->doReconnect();
				$this->log->log("RECV: $buff", LOGGING_VERBOSE);
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
		while(!$this->disconnected and !$this->until_happened and (time() - $start < $timeout or $timeout == -1)) {
			$read = array($this->socket);
			$write = NULL;
			$except = NULL;
			$updated = stream_select($read, $write, $except, 1);
			if ($updated > 0) {
				$buff = fread($this->socket, 1024);
				if(!$buff and $this->reconnect) $this->doReconnect();
				$this->log->log("RECV: $buff", LOGGING_VERBOSE);
				xml_parse($this->parser, $buff, False);
			}
		}
		$payload = $this->until_payload;
		$this->until_payload = array();
		return $payload;
	}

	function startXML($parser, $name, $attr) {
		if($this->been_reset) {
			$this->been_reset = False;
			$this->xml_depth = 0;
		}
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
		if($this->been_reset) {
			$this->been_reset = False;
			$this->xml_depth = 0;
		}
		$this->xml_depth--;
		if($this->xml_depth == 1) {
			#clean-up old objects
			$found = False;
			foreach($this->nshandlers as $handler) {
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
		if($this->xml_depth == 0 and !$this->been_reset) {
			if(!$this->disconnected) {
				$this->send($this->stream_end);
				$this->disconnected = True;
				fclose($this->socket);
				if($this->reconnect) {
					$this->doReconnect();
				}
			}
			$this->event('end_stream');
		}
	}

	function doReconnect() {
		$this->connect(False, False);
		$this->reset();
	}

	function disconnect() {
		$this->reconnect = False;
		$this->send($this->stream_end);
		$this->processUntil('end_stream', 5);
		$this->disconnected = True;
	}

	function event($name, $payload=Null) {
		$this->log->log("EVENT: $name", LOGGING_DEBUG);
		foreach($this->eventhandlers as $handler) {
			if($name == $handler[0]) {
				if($handler[2] === Null) $handler[2] = $this;
				call_user_method($handler[1], $handler[2], $payload);
			}
		}
		if(in_array($name, $this->until)) {
			$this->until_happened = True;
			$this->until_payload[] = array($name, $payload);
		}
	}

	function charXML($parser, $data) {
		$this->xmlobj[$this->xml_depth]->data .= $data;
	}

	function send($msg) {
		#socket_write($this->socket, $msg);
		$this->log->log("SENT: $msg", LOGGING_VERBOSE);
		fwrite($this->socket, $msg);
	}

	function reset() {
		$this->xml_depth = 0;
		$this->xmlobj = Null;
		$this->setupParser();
		$this->send($this->stream_start);
		$this->been_reset = True;
	}

	function setupParser() {
		$this->parser = xml_parser_create();
		xml_set_object($this->parser, $this);
		xml_set_element_handler($this->parser, 'startXML', 'endXML');
		xml_set_character_data_handler($this->parser, 'charXML');
	}
}

