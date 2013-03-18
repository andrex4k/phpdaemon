<?php

/**
 * Pool of connections
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class ConnectionPool extends ObjectStorage {

	public $allowedClients  = null;
	public $connectionClass;
	public $name;
	public $config;
	protected static $instances = [];
	public $maxConcurrency = 0;
	protected $finished = false;
	protected $bound;
	protected $enabled = false;
	protected $overload = false;
	
	public function __construct($config = []) {
		$this->bound = new ObjectStorage;
		$this->config = $config;
		$this->onConfigUpdated();
		if ($this->connectionClass === null) {
			$this->connectionClass = get_class($this) . 'Connection';
		}
		if (isset($this->config->listen)) {
			$this->bindSockets($this->config->listen->value);
		}
		$this->init();
	}
	
	protected function init() {}
	
	/**
	 * Called when the worker is ready to go.
	 * @return void
	*/
	public function onReady() {
		$this->enable();
	}	
	
	/**
	 * Called when worker is going to update configuration.
	 * @return void
	 */
	public function onConfigUpdated() {
		if (Daemon::$process instanceof Daemon_WorkerProcess) {
			if ($this->config === null) {
				$this->disable();
			} else {
				$this->enable();
			}
		}
		if ($defaults = $this->getConfigDefaults()) {
			$this->processDefaultConfig($defaults);
		}
		$this->applyConfig();
	}
	
	protected function applyConfig() {
		foreach ($this->config as $k => $v) {
			if (is_object($v) && $v instanceof Daemon_ConfigEntry) {
				$v = $v->value;
			}
			$k = strtolower($k);
			if ($k === 'connectionclass') {
				$this->connectionClass = $v;
			}
			elseif ($k === 'name') {
				$this->name = $v;
			}
			elseif ($k === 'allowedclients') {
				$this->allowedClients = $v;
			}
			elseif ($k === 'maxallowedpacket') {
				$this->maxAllowedPacket = (int) $v;
			}
			elseif ($k === 'maxconcurrency') {
				$this->maxConcurrency = (int) $v;
			}
		}
	}
	/**
	 * Setting default config options
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return false;
	}
	
	public static function getInstance($arg = '', $spawn = true) {
		if ($arg === 'default') {
			$arg = '';
		}
		$class = get_called_class();
		if (is_string($arg)) {
			$key = $class . ':' . $arg;
			if (isset(self::$instances[$key])) {
				return self::$instances[$key];
			}
			elseif (!$spawn) {
				return false;
			}
			$k = 'Pool:' . $class . ($arg !== '' ? ':' . $arg : '' );
			
			$config = (isset(Daemon::$config->{$k}) && Daemon::$config->{$k} instanceof Daemon_ConfigSection) ? Daemon::$config->{$k}: new Daemon_ConfigSection;			
			$obj = self::$instances[$key] = new $class($config);
			$obj->name = $arg;
			return $obj;
		} elseif ($arg instanceof Daemon_ConfigSection) {
			return new static($arg);

		} else {
			return new static(new Daemon_ConfigSection($arg));
		}
	}
	
	
 	/**
	 * Process default config
	 * @todo move it to Daemon_Config class
	 * @param ["setting" => "value", ...]
	 * @return void
	 */
	public function processDefaultConfig($settings = []) {
		foreach ($settings as $k => $v) {
			$k = strtolower(str_replace('-', '', $k));

			if (!isset($this->config->{$k})) {
			  if (is_scalar($v))	{
					$this->config->{$k} = new Daemon_ConfigEntry($v);
				} else {
					$this->config->{$k} = $v;
				}
			} elseif ($v instanceof Daemon_ConfigSection) {
			// @todo
			}	else {
				$current = $this->config->{$k};
			  if (is_scalar($v))	{
					$this->config->{$k} = new Daemon_ConfigEntry($v);
				} else {
					$this->config->{$k} = $v;
				}
				
				$this->config->{$k}->setHumanValue($current->value);
				$this->config->{$k}->source = $current->source;
				$this->config->{$k}->revision = $current->revision;
			}
		}
	}
	
	public function setConnectionClass($class) {
		$this->connectionClass = $class;
	}
	
	/**
	 * Enable socket events
	 * @return void
	*/
	public function enable() {
		if ($this->enabled) {
			return;
		}
		$this->enabled = true;
		if ($this->bound) {
			$this->bound->each('enable');
		}
	}
	
	/**
	 * Disable all events of sockets
	 * @return void
	 */
	public function disable() {
		if (!$this->enabled) {
			return;
		}
		$this->enabled = false;
		if ($this->bound) {
			$this->bound->each('disable');
		}
	}

	/**
	 * Called when application instance is going to shutdown
	 * @return boolean Ready to shutdown?
	 */
	public function onShutdown($graceful = false) {
		return $this->finish();
	}

	public function onFinish() {}

	/**
	 * Close each of binded sockets.
	 * @return void
	 */
	public function closeBound() {
		$this->bound->each('close');
	}


	public function finish() {
		$this->disable(); 
		$this->closeBound();
		
		$result = true;
	
		foreach ($this as $k => $conn) {
			if (!$conn->gracefulShutdown()) {
				$result = false;
			}
		}
		if ($result && !$this->finished) {
			$this->finished = true;
			$this->onFinish();
		}
		return $result;
	}

	public function attachBound($bound, $inf = null) {
		$this->bound->attach($bound, $inf);
	}

	public function detachBound($bound) {
		$this->bound->detach($bound);
	}

	public function attach($conn, $inf = null) {
		parent::attach($conn, $inf);
		if ($this->maxConcurrency && !$this->overload) {
			if ($this->count() >= $this->maxConcurrency) {
				$this->overload = true;
				$this->disable();
				return;
			}
		}
	}

	public function detach($conn) {
		parent::detach($conn);
		if ($this->overload) {
			if (!$this->maxConcurrency || ($this->count() < $this->maxConcurrency)) {
				$this->overload = false;
				$this->enable();
			}
		}
	}
	
	/**
	 * Bind given sockets
	 * @param mixed Addresses to bind
	 * @return integer Number of bound.
	 */
	public function bindSockets($addrs = [], $max = 0) {
		if (is_string($addrs)) { // @TODO: remove in 1.0
			$addrs = array_map('trim', explode(',', $addrs));
		}
		$n = 0;
		foreach ($addrs as $addr) {
			if ($this->bindSocket($addr)) {
				++$n;
			}
			if ($max > 0 && ($n >= $max)) {
				return $n;
			}
		}
		return $n;
	}

	public function bindSocket($uri) {
		$u = Daemon_Config::parseSocketUri($uri);
		$scheme = $u['scheme'];
		if ($scheme === 'unix') {
			$socket = new BoundUNIXSocket($u);
				
		} elseif ($scheme === 'udp') {
			$socket = new BoundUDPSocket($u);
			if (isset($this->config->port->value)) {
				$socket->setDefaultPort($this->config->port->value);
			}
		} elseif ($scheme === 'tcp') {
			$socket = new BoundTCPSocket($u);
			if (isset($this->config->port->value)) {
				$socket->setDefaultPort($this->config->port->value);
			}
		}
		else {
		 	Daemon::log(get_class($this).': enable to bind \''.$uri.'\': scheme \''.$scheme.'\' is not supported');
		 	return false;
		}
		$socket->attachTo($this);
		if ($socket->bindSocket()) {
			if ($this->enabled) {
				$socket->enable();
			}
			return true;
		}
		return false;
	}
	/**
	 * Establish a connection with remote peer
	 * @param string URL
	 * @param callback Optional. Callback.
	 * @param string Optional. Connection class name.
	 * @return integer Connection's ID. Boolean false when failed.
	 */
	public function connect($url, $cb, $class = null) {
		if ($class === null) {
			$class = $this->connectionClass;
		}
		$conn = new $class(null, $this);
		$conn->connect($url, $cb);
		return $conn;
	}


	/**
	 * Establish a connection with remote peer
	 * @param string Address
	 * @param string Optional. Default port
	 * @param callback Optional. Callback.
	 * @param string Optional. Connection class name.
	 * @return integer Connection's ID. Boolean false when failed.
	 */
	public function connectTo($addr, $port = 0, $cb = null, $class = null) {
		if ($class === null) {
			$class = $this->connectionClass;
		}
		$conn = new $class(null, $this);
		$conn->connectTo($addr, $port);
		if ($cb !== null) {
			$conn->onConnected($cb);
		}
		return $conn;
	}
}
