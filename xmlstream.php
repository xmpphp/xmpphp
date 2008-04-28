<?php
/*
XMPPHP: The PHP XMPP Library
Copyright (C) 2008  Nathanael C. Fritz
This file is part of SleekXMPP.

XMPPHP is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

XMPPHP is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with XMPPHP; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
require_once("xmlobj.php");
require_once("logging.php");

class XMLStream {
	protected $socket;
	protected $parser;
	protected $buffer;
	protected $xml_depth = 0;
	protected $host;
	protected $port;
	protected $stream_start = '<stream>';
	protected $stream_end = '</stream>';
	public $disconnected = false;
	protected $sent_disconnect = false;
	protected $ns_map = array();
	protected $current_ns = array();
	protected $xmlobj = null;
	protected $nshandlers = array();
	protected $idhandlers = array();
	protected $eventhandlers = array();
	protected $lastid = 0;
	protected $default_ns;
	protected $until = '';
	protected $until_happened = false;
	protected $until_payload = array();
	protected $log;
	protected $reconnect = true;
	protected $been_reset = false;
	protected $is_server;

	/**
	 * Constructor
	 *
	 * @param string  $host
	 * @param string  $port
	 * @param boolean $log
	 * @param string  $loglevel
	 * @param boolean $is_server
	 */
	public function __construct($host = null, $port = null, $log = false, $loglevel = null, $is_server = false) {
		$this->reconnect = !$is_server;
		$this->is_server = $is_server;
		$this->host = $host;
		$this->port = $port;
		$this->setupParser();
		$this->log = new Logging($log, $loglevel);
	}

	public function getId() {
		$this->lastid++;
		return $this->lastid;
	}

	public function addIdHandler($id, $pointer, $obj = null) {
		$this->idhandlers[$id] = array($pointer, $obj);
	}

	public function addHandler($name, $ns, $pointer, $obj = null, $depth = 1) {
		$this->nshandlers[] = array($name,$ns,$pointer,$obj, $depth);
	}

	public function addEventHandler($name, $pointer, $obj) {
		$this->eventhanders[] = array($name, $pointer, $obj);
	}

	public function connect($persistent = false, $sendinit = true) {
		$this->disconnected = false;
		$this->sent_disconnect = false;
		if($persistent) {
			$conflag = STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT;
		} else {
			$conflag = STREAM_CLIENT_CONNECT;
		}
		$this->log->log("Connecting to tcp://{$this->host}:{$this->port}");
		$this->socket = stream_socket_client("tcp://{$this->host}:{$this->port}", $conflag);
		if(!$this->socket) {
			$this->log->log("Could not connect.", Logging::LOG_ERROR);
			$this->disconnected = true;
		}
		stream_set_blocking($this->socket, 1);
		if($sendinit) $this->send($this->stream_start);
	}

	public function apply_socket($socket) {
		$this->socket = $socket;
	}

	public function process() {
		$updated = '';
		while(!$this->disconnect) {
			$read = array($this->socket);
			$write = null;
			$except = null;
			$updated = stream_select($read, $write, $except, 1);
			if ($updated > 0) {
				$buff = @fread($this->socket, 1024);
				if(!$buff) { 
					if($this->reconnect) {
						$this->doReconnect();
					} else {
						fclose($this->socket);
						return false;
					}
				}
				$this->log->log("RECV: $buff", Logging::LOG_VERBOSE);
				xml_parse($this->parser, $buff, false);
			}
		}
	}

	public function read() {
		$buff = @fread($this->socket, 1024);
		if(!$buff) { 
			if($this->reconnect) {
				$this->doReconnect();
			} else {
				fclose($this->socket);
				return false;
			}
		}
		$this->log->log("RECV: $buff", Logging::LOG_VERBOSE);
		xml_parse($this->parser, $buff, false);
	}

	public function processTime($timeout = -1) {
		$start = time();
		$updated = '';
		while(!$this->disconnected and ($timeout == -1 or time() - $start < $timeout)) {
			$read = array($this->socket);
			$write = null;
			$except = null;
			$updated = stream_select($read, $write, $except, 1);
			if ($updated > 0) {
				$buff = @fread($this->socket, 1024);
				if(!$buff) { 
					if($this->reconnect) {
						$this->doReconnect();
					} else {
						fclose($this->socket);
						return false;
					}
				}
				$this->log->log("RECV: $buff", Logging::LOG_VERBOSE);
				xml_parse($this->parser, $buff, false);
			}
		}
	}

	public function processUntil($event, $timeout=-1) {
		$start = time();
		if(!is_array($event)) $event = array($event);
		$this->until[] = $event;
		end($this->until);
		$event_key = key($this->until);
		reset($this->until);
		$updated = '';
		while(!$this->disconnected and $this->until[$event_key] and (time() - $start < $timeout or $timeout == -1)) {
			$read = array($this->socket);
			$write = null;
			$except = null;
			$updated = stream_select($read, $write, $except, 1);
			if ($updated > 0) {
				$buff = @fread($this->socket, 1024);
				if(!$buff) { 
					if($this->reconnect) {
						$this->doReconnect();
					} else {
						fclose($this->socket);
						return false;
					}
				}
				$this->log->log("RECV: $buff", Logging::LOG_VERBOSE);
				xml_parse($this->parser, $buff, false);
			}
		}
		if(array_key_exists($event_key, $this->until_payload)) {
			$payload = $this->until_payload[$event_key];
		} else {
			$payload = array();
		}
		unset($this->until_payload[$event_key]);
		return $payload;
	}

	public function startXML($parser, $name, $attr) {
		if($this->been_reset) {
			$this->been_reset = false;
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
		if(!strstr($name, ":") === false)
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

	public function endXML($parser, $name) {
		#$this->log->log("Ending $name", Logging::LOG_DEBUG);
		#print "$name\n";
		if($this->been_reset) {
			$this->been_reset = false;
			$this->xml_depth = 0;
		}
		$this->xml_depth--;
		if($this->xml_depth == 1) {
			#clean-up old objects
			$found = false;
			foreach($this->nshandlers as $handler) {
				if($handler[4] != 1 and $this->xmlobj[2]->hassub($handler[0])) {
					$searchxml = $this->xmlobj[2]->sub($handler[0]);
				} elseif(is_array($this->xmlobj) and array_key_exists(2, $this->xmlobj)) {
					$searchxml = $this->xmlobj[2];
				}
				if($searchxml !== null and $searchxml->name == $handler[0] and ($searchxml->ns == $handler[1] or (!$handler[1] and $searchxml->ns == $this->default_ns))) {
					if($handler[3] === null) $handler[3] = $this;
					$this->log->log("Calling {$handler[2]}", Logging::LOG_DEBUG);
					call_user_func(array($handler[3], $handler[2]), $this->xmlobj[2]);
				}
			}
			foreach($this->idhandlers as $id => $handler) {
				if(array_key_exists('id', $this->xmlobj[2]->attrs) and $this->xmlobj[2]->attrs['id'] == $id) {
					if($handler[1] === null) $handler[1] = $this;
					call_user_func(array($handler[1], $handler[0]), $this->xmlobj[2]);
					#id handlers are only used once
					unset($this->idhandlers[$id]);
					break;
				}
			}
			if(is_array($this->xmlobj)) {
				$this->xmlobj = array_slice($this->xmlobj, 0, 1);
				if(isset($this->xmlobj[0]) && $this->xmlobj[0] instanceof XMLObj) {
				    $this->xmlobj[0]->subs = null;
				}
			}
			unset($this->xmlobj[2]);
		}
		if($this->xml_depth == 0 and !$this->been_reset) {
			if(!$this->disconnected) {
				if(!$this->sent_disconnect) {
					$this->send($this->stream_end);
				}
				$this->disconnected = true;
				$this->sent_disconnect = true;
				fclose($this->socket);
				if($this->reconnect) {
					$this->doReconnect();
				}
			}
			$this->event('end_stream');
		}
	}

	public function doReconnect() {
		if(!$this->is_server) {
			$this->log->log("Reconnecting...", Logging::LOG_WARNING);
			$this->connect(false, false);
			$this->reset();
		}
	}

	public function disconnect() {
		$this->reconnect = false;
		$this->send($this->stream_end);
		$this->sent_disconnect = true;
		$this->processUntil('end_stream', 5);
		$this->disconnected = true;
	}

	public function event($name, $payload = null) {
		$this->log->log("EVENT: $name", Logging::LOG_DEBUG);
		foreach($this->eventhandlers as $handler) {
			if($name == $handler[0]) {
				if($handler[2] === null) $handler[2] = $this;
				call_user_method($handler[1], $handler[2], $payload);
			}
		}
		foreach($this->until as $key => $until) {
			if(is_array($until)) {
				if(in_array($name, $until)) {
					$this->until_payload[$key][] = array($name, $payload);
					$this->until[$key] = false;
				}
			}
		}
	}

	public function charXML($parser, $data) {
		if(array_key_exists($this->xml_depth, $this->xmlobj))
			$this->xmlobj[$this->xml_depth]->data .= $data;
	}

	public function send($msg) {
		#socket_write($this->socket, $msg);
		$this->log->log("SENT: $msg", Logging::LOG_VERBOSE);
		@fwrite($this->socket, $msg);
	}

	public function reset() {
		$this->xml_depth = 0;
		unset($this->xmlobj);
		$this->xmlobj = array();
		$this->setupParser();
		if(!$this->is_server) {
			$this->send($this->stream_start);
		}
		$this->been_reset = true;
	}

	public function setupParser() {
		$this->parser = xml_parser_create('UTF-8');
		xml_parser_set_option($this->parser,XML_OPTION_SKIP_WHITE,1);
		xml_parser_set_option($this->parser,XML_OPTION_TARGET_ENCODING, "UTF-8");
		xml_set_object($this->parser, $this);
		xml_set_element_handler($this->parser, 'startXML', 'endXML');
		xml_set_character_data_handler($this->parser, 'charXML');
	}
}
