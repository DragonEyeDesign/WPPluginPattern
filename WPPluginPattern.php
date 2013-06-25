<?php
//  Include dependencies
$path = dirname(__FILE__);
require_once($path.'/WPPluginPatternController.php');
require_once($path.'/WPPluginPatternWidget.php');
require_once($path.'/WPPluginPatternDAO.php');

//  WPPluginPattern
abstract class WPPluginPattern {
	
	//  Properties
	protected $namespace = "myplugin";
	protected $menuTitle = 'My Plugin';
	protected $keyDelimiter = "_";
	public $db = false;
	protected $path = false;
	protected $url = false;
	public $debug = false;
	protected $config = array();
	protected $options = array();
	protected $optionValues = array();
	protected $transferableSubfieldProperties = array(
		'required',
		'max_length',
		'default',
		'hint'
	);
	protected $tableMaps = array();
	protected $tables = array();
	protected $jsEnabled = false;
	protected $controller;
	protected $plugin;
	protected $logTypes = array(
		'error'		=>	E_USER_ERROR,
		'warning'	=>	E_USER_WARNING,
		'notice'	=>	E_USER_NOTICE
	);
	
	//  Constructor
	public function __construct($config, &$db, $debug = false) {
		
		//  Save all the config
		$this->config = $config;
		
		//  Save handle to the database
		$this->db = &$db;
		
		//  Save the debug flag
		$this->debug = $debug;
		
		//  Save the namespace
		if (array_key_exists('plugin', $this->config) && array_key_exists('namespace', $this->config['plugin'])) {
			$this->namespace = $this->config['plugin']['namespace'];
		}
		
		//  Set table names
		foreach ($this->tables as $key=>$fields) {
			$this->tableMaps[$key] = $this->db->prefix . $this->add_namespace($key);	
		}
		
		//  Set up the plugin location
		if (array_key_exists('plugin', $this->config) && array_key_exists('path', $this->config['plugin'])) {
			$this->path = $this->config['plugin']['path'];	
		} else {
			$dir = dirname(__FILE__);
			$this->path = substr($dir, 0, strrpos($dir, "/"));
		}
		$this->url = substr($this->get_path(), strlen(ABSPATH)-1);
		
		//  Save the menu title
		if (array_key_exists('plugin', $this->config) && array_key_exists('menu_title', $this->config['plugin'])) {
			$this->menuTitle = $this->config['plugin']['menu_title'];
		}
		
		//  Initialize plugin translations
		load_plugin_textdomain($this->get_namespace(), false, dirname(plugin_basename(__FILE__)) . '/../languages/');
		
		//  If running in the admin, initialize plugin options in the admin
		if (is_admin()) {
			$this->_init_admin_options();
		}
			
	}
	
	//  Install the plugin
	public function activate() {
		
		//  Add options
		$this->_add_options();
		
		//  Create the database tables the plugin needs if necessary
		$this->_create_tables();
		
		//  If we need to migrate data, do it now
		$this->_migrate_data();
		
	}
	
	//  Uninstall the plugin -- Let's not delete anything
	public function deactivate() {
		
		//  Remove options
		//$this->_remove_options();
		
		//  Drop tables
		//$this->_drop_tables();
		
	}
	
	//  Add options
	protected function _add_options() {
		
		//  Loop through options and add them if the don't exist already
		foreach ($this->options as $group=>$properties) {
			
			//  Only proceed if the option doesn't exist already
			$optionExists = get_option($properties['key']);
			if (!$optionExists) {
			
				//  Add the option
				add_option($properties['key']);
				
				//  Loop through fields and update any defaults
				$values = array();
				foreach ($properties["fields"] as $option=>$field) {
					
					//  If there is a default, use it
					if (array_key_exists('default', $field)) {
						$values[$option] = $field['default'];
					
					//  Or else default to an empty string
					} else {
						$values[$option] = '';
					}
					
				}
				
				//  Update the option with initial values
				update_option($properties['key'], $values);
				
			}
		
		}
		
	}
	
	//  Remove all options
	protected function _remove_options() {
		foreach ($this->options as $group) {
			delete_option($group['key']);
		}
	}
	
	//  Create database tables if necessary
	protected function _create_tables() {
	
		//  Loop through tables and create them if necessary
		foreach ($this->tables as $table=>$fields) {
			
			//  Set the table name
			$tableName = $this->get_table($table);
			
			//  If the table doesn't exist, create it
			$tableExists = $this->db->query("SHOW TABLES LIKE '" . $tableName . "'");
			if (!$tableExists) {
				
				//  Start the SQL string
				$createSql = 'CREATE TABLE ' . $tableName . ' (';
				$primaryKey = false;
				
				//  Loop through defined fields
				foreach ($fields as $field=>$properties) {
					
					//  Add the field & type
					$createSql .= $field . ' ' . $properties['type'];
					
					//  Include the length if provided
					if (isset($properties['length'])) {
						$createSql .= '(' . $properties['length'] . ')';	
					
					//  Otherwise if the field has predetermined options (e.g., ENUM), define those
					} else if (isset($properties['options'])) {
						$createSql .= '(';
						foreach ($properties['options'] as $option) {
							$createSql .= '\'' . $option . '\',';	
						}
						$createSql = substr($createSql, 0, -1) . ')';
					}
					
					//  Add the NOT NULL flag if set
					if (isset($properties['null']) && !$properties['null']) {
						$createSql .= ' NOT NULL';
					} else if (!isset($properties['default'])) {
						$properties['default'] = 'NULL';	
					}
					
					//  Add the default if set
					if (isset($properties['default'])) {
						$createSql .= ' DEFAULT ';
						if ($properties['default'] == 'NULL') {
							$createSql .= $properties['default'];
						} else {
							$createSql .= '\'' . $properties['default'] . '\'';	
						}
					}
					
					//  If this is the first field, assume auto increment and primary key
					if (!$primaryKey) {
						$createSql .= ' AUTO_INCREMENT';
						$primaryKey = $field;
					}
					
					$createSql .= ', ';
				
				}
				
				//  Add the primary key (assumed to be first defined field)
				$createSql .= 'PRIMARY KEY (' . $primaryKey . ')) CHARACTER SET utf8 COLLATE utf8_general_ci';
				
				//  Make the query
				$this->db->query($createSql);
			
			}
			
		}	
		
	}
	
	//  Drop tables
	protected function _drop_tables() {
		foreach ($this->tables as $table=>$fields) {
			$this->db->query('DROP TABLE ' . $this->get_table($table));	
		}	
	}
	
	//  Migrate data on activation
	protected function _migrate_data() {}
	
	//  Initialize admin options
	protected function _init_admin_options() {
		
		//  Save the option fields
		$this->options = $this->get_config('options');
	
		//  Loop through options
		foreach ($this->options as $group=>$properties) {
			
			//  Add the section key as a property for later reference
			$this->options[$group]['key'] = $this->get_options_group_key($group);
		
			//  Loop through options
			foreach ($properties['fields'] as $option=>$field) {
				
				//  If the field has subfields, generate them programmatically
				if (array_key_exists('fields', $field)) {
					
					//  Loop through subfields
					foreach ($field['fields'] as $suboption=>$subfield) {
						
						//  If the subfield is not an array, use the value as the label and default
						if (!is_array($subfield)) {
							$subfield = array(
								'label'		=>	$subfield,
								'default'	=>	$subfield
							);	
						}
						
						//  Loop through transferrable subfield properties and transfer them
						foreach ($this->transferableSubfieldProperties as $property) {
							if ((!array_key_exists($property, $subfield)) && array_key_exists($property, $field)) {
								$subfield[$property] = $field[$property]; 							
							}
						}
						
						//  Set flag to not show subfield because parent field will do it
						$subfield['display'] = false;
						
						//  If a type is not set, default to text
						if (!array_key_exists('type', $subfield)) {
							if (array_key_exists('child_type', $field)) {
								$subfield['type'] = $field['child_type'];
							} else {
								$subfield['type'] = 'text';	
							}
						}
						
						//  Add the new field
						$this->options[$group]['fields'][$option . $this->get_key_delimiter() . $suboption] = $subfield;
						
					}
					
				}
			
			}
		
		}
		
	}
	
	//  Display error messages
	public function display_errors($errors = array()) {
		if (!empty($errors)) {
			if (!is_array($errors)) {
				$errors = array($errors);
			}
			include($this->get_path('/views/public/errors.php'));
		}
	}
	
	//  Get the namespace
	public function get_namespace() {
		return $this->namespace;	
	}
	
	//  Add the plugin prefix
	public function add_namespace($str, $delimiter = false) {
		if (!is_array($str)) {
			$str = array($str);	
		}
		if (!$delimiter) {
			$delimiter = $this->get_key_delimiter();	
		}
		$newStr = $this->get_namespace();
		foreach ($str as $tmpStr) {
			$newStr .= $delimiter . $tmpStr;	
		}
		if ($delimiter) {
			$newStr = str_replace($this->get_key_delimiter(), $delimiter, $newStr);	
		}
		return $newStr;
	}
	
	//  Strip out the namespace from a string
	public function strip_namespace($str, $delimiter = false) {
		return substr($str, strlen($this->add_namespace('', $delimiter)));	
	}
	
	//  Get the key delimiter
	public function get_key_delimiter() {
		return $this->keyDelimiter;	
	}
	
	//  Get a config array
	public function get_config($key) {
		return $this->config[$key];	
	}
	
	//  Get a data label
	public function get_data_label($table, $field, $serializedField = false) {
		if ($serializedField) {
			return $this->config['data_labels'][$table][$field][$serializedField];	
		} else {
			return $this->config['data_labels'][$table][$field];
		}
	}
	
	//  Get option
	public function get_option($option, $group) {
		if (!isset($this->optionValues[$group])) {
			$this->optionValues[$group] = $this->get_options_group($group);	
		}
		if (isset($this->optionValues[$group][$option])) {
			return $this->optionValues[$group][$option];	
		} else {
			return '';	
		}
	}
	
	//  Update an option
	public function update_option($option, $group, $value) {
		$values = $this->get_options_group($group);
		$values[$option] = $value;
		return update_option($this->get_options_group_key($group), $values);
	}
	
	//  Get options group key
	public function get_options_group_key($group) {
		return $this->add_namespace(array($group, 'options'));	
	}
	
	//  Get all options
	public function get_options() {
		return $this->options;	
	}
	
	//  Get a specific option field definition
	public function get_option_field($field, $group) {
		return $this->options[$group]['fields'][$field];
	}
	
	//  Whether or not an option exists
	public function option_exists($option, $group) {
		return (array_key_exists($group, $this->options) && array_key_exists($option, $this->options[$group]['fields']));
	}
	
	//  Get a section's option intro
	public function get_options_intro($group) {
		return $this->options[$group]['intro'];	
	}
	
	//  Get an entire section of options
	public function get_options_group($group) {
		return get_option($this->get_options_group_key($group));	
	}
	
	//  Get the admin pages
	public function get_admin_pages() {
		if (isset($this->config['pages'])) {
			return $this->config['pages'];	
		} else {
			return array();	
		}
	}
	
	//  Display the widget
	public function display_widget($key, $params = array()) {
		$this->controller->display_widget($key, $params);	
	}
	
	//  Get a table
	public function get_table($key) {
		return $this->tableMaps[$key];
	}
	
	//  Get the table field definitions
	public function get_table_fields($key) {
		return $this->tables[$key];	
	}
	
	//  Get the plugin path
	public function get_path($path = "") {
		return $this->path . $path;	
	}
	
	//  Get the plugin URL
	public function get_url($path = "") {
		return $this->url . $path;	
	}
	
	//  Get the menu title
	public function get_menu_title() {
		return $this->menuTitle;	
	}
	
	//  Whether or not Javascript is enabled
	public function is_js_enabled() {
		return $this->jsEnabled;	
	}
	
	//  Convert a string to a key-compatible string
	public function str_to_key($str) {
		return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($str)));	
	}
	
	//  Log an event
	public function log($msg, $type = 'notice') {
		if ($this->debug) {
			trigger_error('Administrate Plugin: ' . $msg, self::$logTypes[$type]);
		}
		if ($type == 'error') {
			exit;	
		}
	}
	
	//  Parse the plugin readme
	public function parse_readme($path) {
		
		//  Get the file contents
		$lines = file($path);
		
		//  Initialize the array
		$result = array();
		
		//  Loop through lines
		$i = -1;
		foreach ($lines as $line) {
			
			//  Skip empty lines
			
			
			if (!empty($line)) {
				
				$line = trim($line);
				
				//  If the line starts with
				if (substr($line, 0, 4) == '=== ') {
				
					$result[++$i] = array();
					$result[$i]['title'] = substr($line, 4, strpos($line, ' ===', 4) - 4);
					$result[$i]['items'] = array();
					$top = true;
					$sub = false;
					
				} else if (substr($line, 0, 3) == '== ') {
					
					$n = count($result[$i]['items']);
					$result[$i]['items'][$n] = array();
					$result[$i]['items'][$n]['title'] = substr($line, 3, strpos($line, ' ==', 3) - 3);
					$result[$i]['items'][$n]['items'] = array();
					$top = false;
					$sub = true;
					
				} else if (substr($line, 0, 2) == '= ') {
					
					$d = count($result[$i]['items'][$n]['items']);
					$result[$i]['items'][$n]['items'][$d] = array();
					$result[$i]['items'][$n]['items'][$d]['title'] = substr($line, 2, strpos($line, ' ==', 2) - 2);
					$result[$i]['items'][$n]['items'][$d]['items'] = array();
					$top = false;
					$sub = false;
				
				//  Or else add a line to the current item
				} else {
					if ($top) {
						array_push($result[$i]['items'], $line);
					} else if ($sub) {
						array_push($result[$i]['items'][$n]['items'], $line);
					} else {
						array_push($result[$i]['items'][$n]['items'][$d]['items'], $line);
					}
				}
				
			}
			
		}
		
		//  Return the result
		return $result;
		
	}
	
	//  Convert to boolean
	public function to_boolean($str) {
		return (intval($str) === 1);
	}
	
	//  Pluralize a word
	public function pluralize_str($num, $singular, $plural = false) {
		if ($num == 1) {
			return $singular;	
		} else {
			if ($plural) {
				return $plural;
			} else {
				if (substr($singular, -1) == 'y') {
					return $substr($singular, 0, -1) . 'ies';
				} else if (substr($singular, -1) == 'x') {
					return $singular . 'es';
				} else {
					return $singular . 's';
				}
			}
		}
	}
	
}
