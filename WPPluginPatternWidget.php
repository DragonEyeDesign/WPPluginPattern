<?php
//  Plugin widget parent class
abstract class WPPluginPatternWidget {

	//  Properties
	protected $key = '';
	protected $shortCode = true;
	protected $showOnce = false;
	protected $alreadyShown = false;
	protected $widgetPath = '';
	protected $plugin;
	
	//  Constructor
	public function __construct(&$plugin) {
	
		//  Save the plugin by reference
		$this->plugin = &$plugin;
		
		//  Register the shortcode
		if ($this->shortCode) {
			add_shortcode($this->plugin->add_namespace($this->key), array($this, 'run'));
		}
		
		//  Set the widget path
		$reflector = new ReflectionClass(get_class($this));
		$this->widgetPath = dirname($reflector->getFileName());
		$this->widgetUrl = substr($this->get_path(''), strlen(ABSPATH)-1);
	
	}
	
	//  Run the widget code
	public function run() {
		$this->alreadyShown = true;
	}
	
	//  Get the parsed content of a file include
	protected function get_include_contents($filename, $params = array()) {
    	if (is_file($filename)) {
			ob_start();
        	include($filename);
        	$contents = ob_get_contents();
        	ob_end_clean();
        	return $contents;
    	} else {
    		return false;
		}
	}
	
	//  Get the widget path
	public function get_path($path) {
		return $this->widgetPath.$path;	
	}
	
	//  Get the widget URL
	protected function _get_url($path) {
		return $this->widgetUrl.$path;	
	}
	
	//  Set options
	protected function _set_options() {
		$this->options = $this->plugin->get_options_group($this->key);
	}
	
	//  Get an option
	public function get_option($key) {
		if (!property_exists($this, 'options')) {
			$this->_set_options();
		}	
		if (isset($this->options[$key])) {
			return $this->options[$key];
		} else {
			return false;	
		}
	}
	
	//  Add a namespace to the passed arguments
	public function add_namespace($parts, $delimiter = false) {
		if (empty($parts)) {
			$parts = array();
		} else if (!is_array($parts)) {
			$parts = array($parts);
		}
		array_unshift($parts, $this->key);	
		return $this->plugin->add_namespace($parts, $delimiter);
	}
	
	//  Strip out the namespace from a string
	public function strip_namespace($str, $delimiter = false) {
		return substr($str, strlen($this->add_namespace('', $delimiter)) + 1);	
	}

}