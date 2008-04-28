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


class Logging {
    
    const LOG_ERROR   = 0;
    const LOG_WARNING = 1;
    const LOG_INFO    = 2;
    const LOG_DEBUG   = 3;
    const LOG_VERBOSE = 4;
    
	protected $data = array();
	protected $names = array('ERROR', 'WARNING', 'INFO', 'DEBUG', 'VERBOSE');
	protected $runlevel;
	protected $printout;

	/**
	 * Constructor
	 *
	 * @param boolean $printout
	 * @param string  $runlevel
	 */
	public function __construct($printout = false, $runlevel = self::LOG_INFO) {
		$this->runlevel = $runlevel;
		$this->printout = $printout;
	}

	public function log($msg, $runlevel = null) {
		if($runlevel === null) $runlevel = self::LOG_INFO;
		$this->data[] = array($this->runlevel, $msg);
		if($this->printout and $runlevel <= $this->runlevel) echo "{$this->names[$runlevel]}: $msg\n";
	}

	public function printout($clear = true, $runlevel = null) {
		if($runlevel === null) $runlevel = $this->runlevel;
		foreach($this->data as $data) {
			if($runlevel <= $data[0]) echo "{$this->names[$runlevel]}: $data[1]\n";
		}
		if($clear) $this->data = array();
	}
}

?>
