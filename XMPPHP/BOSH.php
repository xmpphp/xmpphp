<?php
/**
 * XMPPHP: The PHP XMPP Library
 * Copyright (C) 2008  Nathanael C. Fritz
 * This file is part of SleekXMPP.
 * 
 * XMPPHP is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 * 
 * XMPPHP is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with XMPPHP; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category   xmpphp 
 * @package	XMPPHP
 * @author	 Nathanael C. Fritz <JID: fritzy@netflint.net>
 * @author	 Stephan Wentz <JID: stephan@jabber.wentz.it>
 * @copyright  2008 Nathanael C. Fritz
 */

/** XMPPHP_XMLStream */
require_once "XMPP.php";

/**
 * XMPPHP Main Class
 * 
 * @category   xmpphp 
 * @package	XMPPHP
 * @author	 Nathanael C. Fritz <JID: fritzy@netflint.net>
 * @author	 Stephan Wentz <JID: stephan@jabber.wentz.it>
 * @copyright  2008 Nathanael C. Fritz
 * @version	$Id$
 */
class XMPPHP_BOSH extends XMPPHP_XMPP {

		protected $rid;
		protected $sid;
		protected $http_server;
		protected $http_buffer = Array();
		protected $session = false;

		public function connect($server, $wait='1', $session=false) {
			$this->http_server = $server;
			$this->use_encryption = false;
			$this->session = $session;

			$this->rid = 0;
			$this->sid = null;
			if($session)
			{
				$this->loadSession();
			}
			if(!$this->sid) {
				$body = $this->__buildBody();
				$body->addAttribute('hold','1');
				$body->addAttribute('to', $this->host);
				$body->addAttribute('route', "xmpp:{$this->host}:{$this->port}");
				$body->addAttribute('secure','true');
				$body->addAttribute('version','1.6');
				$body->addAttribute('wait', strval($wait));
				$body->addAttribute('ack','1');
				$body->addAttribute('xmlns:xmpp','urn:xmpp:xbosh');
				$buff = "<stream:stream xmlns='jabber:client' xmlns:stream='http://etherx.jabber.org/streams'>";
				xml_parse($this->parser, $buff, false);
				$response = $this->__sendBody($body);
				$rxml = new SimpleXMLElement($response);
				$this->sid = $rxml['sid'];

			} else {
				$buff = "<stream:stream xmlns='jabber:client' xmlns:stream='http://etherx.jabber.org/streams'>";
				xml_parse($this->parser, $buff, false);
			}
		}

		public function __sendBody($body=null, $recv=true) {
			if(!$body) {
				$body = $this->__buildBody();
			}
			$ch = curl_init($this->http_server);
			curl_setopt($ch, CURLOPT_HEADER, 0);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt ($ch, CURLOPT_HTTPHEADER, Array("Content-Type: text/xml"));
			curl_setopt ($ch, CURLOPT_POSTFIELDS, Array('upfile' => $body->asXML()));
			$output = '';
			if($recv) {
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				$output = curl_exec($ch);
				$this->http_buffer[] = $output;
			}
			curl_close($ch);
			return $output;
		}

		public function __buildBody($sub=null) {
			$xml = new SimpleXMLElement("<body xmlns='http://jabber.org/protocol/httpbind' xmlns:xmpp='urn:xmpp:xbosh' />");
			$xml->addAttribute('content', 'text/xml; charset=utf-8');
			$xml->addAttribute('rid', $this->rid);
			$this->rid += 1;
			if($this->sid) $xml->addAttribute('sid', $this->sid);
			#if($this->sid) $xml->addAttribute('xmlns', 'http://jabber.org/protocol/httpbind');
			$xml->addAttribute('xml:lang', 'en');
			if($sub) { // ok, so simplexml is lame
				$p = dom_import_simplexml($xml);
				$c = dom_import_simplexml($sub);
				$cn = $p->ownerDocument->importNode($c, true);
				$p->appendChild($cn);
				$xml = simplexml_import_dom($p);
			}
			return $xml;
		}

		public function __process() {
			if($this->http_buffer) {
				$this->__parseBuffer();
			} else {
				$this->__sendBody();
				$this->__parseBuffer();
			}
		}

		public function __parseBuffer() {
			while ($this->http_buffer) {
				$idx = key($this->http_buffer);
				$buffer = $this->http_buffer[$idx];
				unset($this->http_buffer[$idx]);
				$xml = new SimpleXMLElement($buffer);
				$children = $xml->xpath('child::node()');
				foreach ($children as $child) {
					$buff = $child->asXML();
					$this->log->log("RECV: $buff",  XMPPHP_Log::LEVEL_VERBOSE);
					xml_parse($this->parser, $buff, false);
				}
			}
		}

		public function send($msg) {
			$this->log->log("SEND: $msg",  XMPPHP_Log::LEVEL_VERBOSE);
			$msg = new SimpleXMLElement($msg);
			#$msg->addAttribute('xmlns', 'jabber:client');
			$this->__sendBody($this->__buildBody($msg), true);
			#$this->__parseBuffer();
		}

		public function reset() {
			$this->xml_depth = 0;
			unset($this->xmlobj);
			$this->xmlobj = array();
			$this->setupParser();
			#$this->send($this->stream_start);
			$body = $this->__buildBody();
			$body->addAttribute('to', $this->host);
			$body->addAttribute('xmpp:restart', 'true', 'urn:xmpp:xbosh');
			$buff = "<stream:stream xmlns='jabber:client' xmlns:stream='http://etherx.jabber.org/streams'>";
			$response = $this->__sendBody($body);
			$this->been_reset = true;
			xml_parse($this->parser, $buff, false);
		}

		public function loadSession() {
			if(is_array($_SESSION['XMPPHP_BOSH'])) {
				foreach($_SESSION['XMPPHP_BOSH'] as $key => $value) {
					#print "Loading $key as $value<br/>";
					$this->$key = $value;
				}
			}
		}

		public function saveSession() {
			$_SESSION['XMPPHP_BOSH'] = Array();
			#$variables = Array('server', 'user', 'password', 'resource', 'fulljid', 'basejid', 'authed', 'session_started', 'auto_subscribe', 'use_encryption', 'host', 'port', 'stream_start', 'stream_end', 'disconnected', 'sent_disconnected', 'ns_map', 'current_ns', 'lastid', 'default_ns',  'been_reset', 'last_send', 'use_ssl', 'rid', 'sid', 'http_server','http_buffer', 'xml_depth');
			$variables = Array('rid', 'sid');
			foreach ($variables as $key) {
				#print "Saving $key as {$this->$key}<br/>";
				flush();
				$_SESSION['XMPPHP_BOSH'][$key] = (string) $this->$key;
			}
		}
}
