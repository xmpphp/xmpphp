<?php

class XMLStream {
	var $socket;
	var $parser;
	var $buffer;
	var $xml_depth;
	var $host;
	var $port;
	var $stream_start = '<stream>';
	var $disconnect = false;

	function XMLStream($host, $port) {
		$this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		$this->host = $host;
		$this->port = $port;
		#set up the parser
		$this->parser = xml_parser_create();
		xml_set_object($this->parser, $this);
		xml_set_element_handler($this->parser, 'startXML', 'endXML');
		xml_set_character_data_handler($this->parser, 'charXML');
	}

	function connect() {
		if(socket_connect($this->socket, $this->host, $this->port)) {
			socket_write($this->socket, $this->stream_start);
		}
	}

	function process() {
		while(!$this->disconnect) {
			xml_parse($this->parser, socket_read($this->socket, 1024), False);
			# parse whatever we get out of the socket
		}
	}

	function startXML($parser, $name, $attr) {
		print "Start:";
		print $name;
		print "\n";
		print $attr;
		print "\n";
		print "----";
	}

	function endXML($parser, $name) {
		print "End: " . $name;
		print "\n";
	}
	function charXML($parser, $data) {
		print "Data: " . $data;
		print "\n";
	}
}

$conn = new XMLStream('netflint.net', 5222);
$conn->stream_start = '<stream:stream to="netflint.net" xmlns:stream="http://etherx.jabber.org/streams" xmlns="jabber:client" version="1.0">\n';
$conn->connect();
$conn->process();

