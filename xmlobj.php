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
?>
