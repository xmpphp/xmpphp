<?php

define('LOGGING_ERROR', 0);
define('LOGGING_WARNING', 1);
define('LOGGING_INFO', 2);
define('LOGGING_DEBUG', 3);
define('LOGGING_VERBOSE', 4);

class Logging {
	var $data = array();
	var $names = array();
	var $runlevel;
	var $printout;

	function Logging($printout = False, $runlevel=LOGGING_INFO) {
		$this->names = array('ERROR  ', 'WARNING', 'INFO   ', 'DEBUG  ', 'VERBOSE');
		$this->runlevel = $runlevel;
		$this->printout = $printout;
	}

	function log($msg, $runlevel=Null) {
		if(!$runlevel) $runlevel = LOGGING_INFO;
		$data[] = array($this->runlevel, $msg);
		if($this->printout and $runlevel <= $this->runlevel) print "{$this->names[$runlevel]}: $msg\n";
	}

	function printout($clear=True, $runlevel=Null) {
		if(!$runlevel) $runlevel = $this->runlevel;
		foreach($this->data as $data) {
			if($runlevel <= $data[0]) print "{$this->names[$runlevel]}: $data[1]\n";
		}
		if($clear) $this->data = array();
	}
}

?>
