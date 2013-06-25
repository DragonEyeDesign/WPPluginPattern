<?php
//  WPPluginPatternController
abstract class WPPluginPatternController {
	
	//  Properties
	protected $widgets = array();
	protected $parentKey;
	protected $plugin;
	
	//  Construct
	public function __construct(&$plugin) {

		//  Save a reference to the plugin
		$this->plugin = &$plugin;
		
		if (is_admin()) {
			
			//  Generate the parent page key
			$this->parentKey = $this->plugin->add_namespace('home');
			
			//  Add the plugin admin menu
			add_action('admin_menu', array($this, 'add_menu'));
			
			//  Initialize admin
			add_action('admin_init', array($this, 'run'));
			
		} else {

			//  Initialize
			add_action('init', array($this, 'run'));
		
		}
		
	}
	
	//  Run the controller
	public function init() {}
	
	//  Add admin menu items
	public function add_menu() {
		
		//  Add the main menu item (NOTE: We are hiding the duplicate submenu item with CSS)
		add_menu_page($this->plugin->get_menu_title(), $this->plugin->get_menu_title(), 'manage_options', $this->parentKey, array($this, 'display_admin_page'), 'div', 3);
		
		//  Initialize tabs array
		$this->tabs = array();
		
		//  Loop through the options sections and add a submenu item for each
		foreach ($this->plugin->get_options() as $group) {
			add_submenu_page($this->parentKey, $group['title'], $group['title'], 'manage_options', $group['key'], array($this, 'display_admin_page'));
			$this->tabs[$group['key']] = $group['title'];
		}
		
		//  Loop through the pages and add a submenu item for each
		foreach ($this->plugin->get_admin_pages() as $page=>$title) {
			$page = $this->plugin->add_namespace($page);
			add_submenu_page($this->parentKey, $title, $title, 'read', $page, array($this, 'display_admin_page'));	
			$this->tabs[$page] = $title;
		}
		
	}
	
	//  Get admin tabs
	protected function _get_admin_tabs() {
		return $this->tabs;
	}
	
	//  Initialize the admin controller
	public function run() {
		
		//  Add the admin CSS & JS
		wp_enqueue_style($this->plugin->add_namespace('admin'), $this->plugin->get_url('/css/admin.css'));
		wp_enqueue_script($this->plugin->add_namespace('admin'), $this->plugin->get_url('/js/admin.js'), array('jquery'), false, true);
		
		//  Register the settings
		$this->_register_options();
		
	}
	
	//  Whether or not the current page is a plugin admin page
	public function is_plugin_admin() {
		return (is_admin() && isset($_GET['page']) && (substr($_GET['page'], 0, strlen($this->plugin->get_namespace())) == $this->plugin->get_namespace()));
	}
	
	//  Display admin page
	public function display_admin_page() {
		require_once($this->plugin->get_path('/views/admin/page.php'));	
	}
	
	//  Register setting sections
	protected function _register_options() {
		
		//  Loop through option sections and register settings fields
		foreach ($this->plugin->get_options() as $key=>$group) {
			
			//  Get the section options
			$groupOptions = get_option($group['key']);
			
			//  Add the settings section
			add_settings_section($group['key'], null, array($this, 'display_options_intro'), $group['key']);
			
			//  Loop through the section's fields
			foreach ($group['fields'] as $option=>$field) {
				
				//  Only add the field if it will be displayed
				if (!array_key_exists('display', $field) || $field['display']) {
				
					//  Set the option keys
					$field['key'] = $option;
					$field['optionsKey'] = $group['key'];
					$field['groupKey'] = $key;
					
					//  If there is already a saved value for the field ...
					if (is_array($groupOptions) && array_key_exists($field['key'], $groupOptions)) {
						
						//  Use the saved option
						$field['value'] = $groupOptions[$field['key']];
						
						//  If the value is numeric, convert it to integer or float
						if (is_numeric($field['value'])) {
							if (intval($field['value']) == floatval($field['value'])) {
								$field['value'] = intval($field['value']);
							} else {
								$field['value'] = floatval($field['value']);	
							}
						}
						
					//  Or else just default to blank	
					} else {
						$field['value'] = '';
					}
					
					//  Figure out the display method. **NOTE: If this dynamtically generated method does not exist, we'll overload the magic method __call to route it to the generic field display method
					add_settings_field($field['key'], $field['label'], array($this, 'display_option_field'), $group['key'], $group['key'], $field);
				
				}
				
			}
			
			//  Register the setting
			register_setting($group['key'], $group['key'], array($this, 'validate_option_fields'));
			
		}
		
	}
	
	//  Display the options
	public function display_options_intro($group) {
		
		//  Set the intro
		$key = str_replace($this->plugin->add_namespace(''), '', str_replace('_options', '', $group['id']));
		$intro = $this->plugin->get_options_intro($key);
		
		//  Include the intro template		
		require_once($this->plugin->get_path('/views/admin/options_intro.php'));	
		
	}
	
	//  Display an option field
	public function display_option_field($field) {
		
		//  Only proceed if the field isn't flagged as invisible
		if (!array_key_exists('display', $field) || $field['display']) {
			
			//  If this is a custom option field, include the custom display file
			if ($field['type'] == 'custom') {
				$includePath = $this->plugin->get_path('/views/admin/option_field_'.$field['key'].'.php');
				if (file_exists($includePath)) {
					include($includePath);	
				}
			
			//  Otherwise proceed with a generic input
			} else {
			
				//  If a value was posted, use that for the value
				if (array_key_exists($field['key'], $_POST)) {
					$currentValue = $_POST[$field['key']];	
				
				//  Or else if there is a saved value, use that
				} else {
					$currentValue = $field['value'];	
				}
				
				//  Figure out the input type
				if (in_array($field['type'], array('text', 'email', 'url', 'password'))) {
					$input = "text";	
				} else {
					$input = $field['type'];
				}
				
				//  Default to not required
				if (!array_key_exists('required', $field)) {
					$field['required'] = false;	
				}
				
				//  Include the appropriate field template
				include($this->plugin->get_path('/views/admin/option_field_'.$input.'.php'));
			
			}
		
		}
	
	}
	
	//  Validate option fields
	public function validate_option_fields($input) {
		
		//  Figure out the section we're dealing with
		$prefixLength = strlen($this->plugin->add_namespace(''));
		$group = substr($_POST['option_page'], $prefixLength, strlen($_POST['option_page']) - $prefixLength - 8); //  8 is the length of '_options' suffix
		
		//  Retrieve the saved values for this section
		$savedValues = get_option($_POST['option_page']);
		
		//  Loop through submitted fields
		$output = array();
		foreach ($input as $option=>$value) {
			
			//  Only allow legitimate options through
			if ($this->plugin->option_exists($option, $group)) {
				
				//  Get the saved value first
				if (isset($savedValues[$option])) {
					$output[$option] = $savedValues[$option];
				} else {
					$output[$option] = '';	
				}
				
				//  Save the field properties
				$field = $this->plugin->get_option_field($option, $group);
				
				//  Sanitize the option field
				$value = $this->_sanitize_option_field($value, $field);
				
				//  Assume no errors
				$error = false;
				
				//  If the option is required, throw an error if empty
				if (array_key_exists('required', $field) && $field['required'] && empty($value)) {
					add_settings_error($option, $option . $this->plugin->get_key_delimiter() . 'required', $field['label'] . __(' field is required.', 'administrate'));
					$error = true;
				
				//  If the option is email, validate using WP built in function
				} else if (!empty($value) && ($field['type'] == 'email') && !is_email($value)) {
					add_settings_error($option, $option . $this->plugin->get_key_delimiter() . 'email', $field['label'] . __(' field must be a valid email address.', 'administrate'));
					$error = true;
				}
				
				//  Only add to sanitized output if there were no errors
				if (!$error) {
					$output[$option] = $value;	
				}
				
			}
			
		}
	   
	   	//  Return the sanitized fields
		return $output;
		
	}
	
	//  Sanitize an option field
	protected function _sanitize_option_field($value, $field) {
	
		//  If the value is an array, sanitize and serialize it
		if (is_array($value)) {
			foreach ($value as $key=>$val) {
				$value[$key] = $this->_sanitize_option_field($val, $field);	
			}
			$value = serialize($value);
		
		//  If the field type is checkbox, sanitize to 1 or 0
		} else if ($field['type'] == 'checkbox') {
			if (intval($value) == 1) {
				$value = 1;
			} else {
				$value = 0;	
			}
		
		//  If the field's default is numeric, make sure the value is numeric
		} else if (array_key_exists('default', $field) && is_numeric($field['default'])) {
			if (intval($value) == floatval($value)) {
				$value = intval($value);	
			} else {
				$value = floatval($value);
			}
		
		//  Or if the field type is email, sanitize
		//} else if ($field['type'] == 'email') {
			//$value = sanitize_email($value);
		
		//  Or else at least escape special characters and malicious stuff
		} else if (!isset($field['allow_html']) || !$field['allow_html']) {
			
			$value = str_replace("\n",'|lb|', $value);
			$value = sanitize_text_field($value);	
			$value = str_replace('|lb|', "\n", $value);
			
		}
	
		//  Return the sanitized value
		return $value;
		
	}
	
	//  Display a widget
	public function display_widget($key, $params = array()) {
		$this->widgets[$key]->run($params);	
	}
	
}
