<?php
//  WPPluginPattern Data Access Object
class WPPluginPatternDAO {
	
	//  Properties
	protected $plugin;
	protected $tableKey = '';
	protected $primaryKey = 'my_id';
	protected $fieldDefinitions = array();
	protected $row = false;
	
	//  Constructor
	public function __construct(&$plugin, $fields = array()) {
		
		//  Save the plugin by reference
		$this->plugin = $plugin;
		
		//  Set the field definitions
		$this->fieldDefinitions = $this->plugin->get_table_fields($this->tableKey);
		
		//  Save the actual table name using the key
		$this->table = $this->plugin->get_table($this->tableKey);
		
		//  If the passed ID is greater than zero, add it to the fields array
		if (!is_array($fields)) {
			$id = $fields;
			$fields = array();
			$fields[$this->_get_primary_key()] = $id;	
		}
		
		//  Try to get the database record based on fields to match against
		if (!empty($fields)) {
			$qry = 'SELECT * FROM ' . $this->_get_table() . ' WHERE ';
			foreach ($fields as $field=>$value) {
				$qry .= $field . " = '" . $value . "' AND ";	
			}
			$qry = substr($qry, 0, -5);
			$this->row = $this->plugin->db->get_row($qry, 'ARRAY_A');
			if (!is_array($this->row)) {
				$this->row = false;
			}
		}
		
	}
	
	//  Save an order
	public function save($fields = array()) {
		
		//  Only proceed if there were actually fields passed
		if (!empty($fields)) {
		
			//  Check the fields for errors
			$errors = $this->_validate_fields($fields);
			
			//  If there were no errors, proceed
			if (!$errors) {
				
				//  Sanitize the fields
				$fields = $this->_sanitize_fields($fields);
				
				//  Only proceed if there are still any fields to save after sanitation
				if (!empty($fields)) {
				
					//  If the row already exists, this is an update
					if ($this->row) {
						
						//  Update the row
						$where = array();
						$where[$this->_get_primary_key()] = $this->row[$this->_get_primary_key()];
						$this->plugin->db->update(
							$this->_get_table(),
							$fields,
							$where
						);
						
						//  Reload saved data
						$this->_reload_row();
						
					//  Otherwise this is an insert	
					} else {
					
						//  Insert the row
						$this->plugin->db->insert(
							$this->_get_table(),
							$fields
						);
						
						//  Reinitialize object
						$this->__construct($this->plugin, $this->plugin->db->insert_id);
							
					}
					
					return false;
				
				}
			
			//  Or else return the errors	
			} else {
				return $errors;
			}
		
		}
		
	}
	
	//  Create a new order
	public function create($fields = array()) {
		return $this->save($fields);
	}
	
	//  Update an order
	public function update($fields = array()) {
		return $this->save($fields);
	}
	
	//  Delete an order
	public function delete() {
		
	}
	
	//  Reload the row
	protected function _reload_row() {
		$this->row = $this->plugin->db->get_row('SELECT * FROM ' . $this->_get_table() . ' WHERE ' . $this->_get_primary_key() . ' = ' . $this->get_id(), 'ARRAY_A');	
	}
	
	//  Validate the fields -- By default does nothing, returns no errors
	protected function _validate_fields($fields = array()) { 
		return false;
	}
	
	//  Sanitize and pack up fields
	protected function _sanitize_fields($inputFields = array()) {
		
		//  Loop through field definitions
		$outputFields = array();
		foreach ($this->fieldDefinitions as $field=>$properties) {
			if (isset($inputFields[$field])) {
				
				//  If this is a serialized field ...
				if (isset($properties['serialize'])) {
					
					//  Loop through serialized fields
					$outputFields[$field] = array();
					foreach ($properties['serialize'] as $subfield) {
						$subfieldKey = $field . $this->plugin->get_key_delimiter() . $subfield;
						if (is_array($inputFields[$subfieldKey])) {
							$outputFields[$field][$subfield] = array();
							foreach ($inputFields[$subfieldKey] as $multiField) {
								array_push($outputFields[$field][$subfield], sanitize_text_field($multiField));
							}
						} else {
							$outputFields[$field][$subfield] = sanitize_text_field($inputFields[$subfieldKey]);
						}
					}
					$outputFields[$field] = serialize($outputFields[$field]);
					
				//  Or else just sanitize the field
				} else {
					$outputFields[$field] = sanitize_text_field($inputFields[$field]);
				}
				
			}
		}
		
		//  Return output fields
		return $outputFields;
		
	}
	
	//  Get the id
	public function get_id() {
		if ($this->row) {
			return $this->row[$this->_get_primary_key()];	
		} else {
			return 0;	
		}
	}
	
	//  Get table
	protected function _get_table() {
		return $this->table;	
	}
	
	//  Get the primary key
	protected function _get_primary_key() {
		return $this->primaryKey;	
	}
	
	//  Whether or not the passed ID exists
	public function exists() {
		return is_array($this->row);	
	}
	
	//  Get field
	public function get_field($key) {
		return $this->row[$key];	
	}
	
	//  Get all
	public function get_all($where = false, $order = false) {
		$qry = "SELECT * FROM " . $this->_get_table();
		if ($where) {
			$qry .= " WHERE ";
			foreach ($where as $field=>$value) {
				$qry .= $field . " = '" . $value . "' AND ";	
			}
			$qry = substr($qry, 0, -5);
		}
		if ($order) {
			$qry .= " ORDER BY ";
			foreach ($order as $field=>$value) {
				$qry .= $field . " " . $value . ", ";	
			}
			$qry = substr($qry, 0, -2);
		}
		$rows = $this->plugin->db->get_results($qry, 'ARRAY_A');
		$class = get_class($this);
		$daos = array();
		foreach ($rows as $row) {
			array_push($daos, new $class($this->plugin, $row[$this->_get_primary_key()]));
		}
		return $daos;
	}
	
}