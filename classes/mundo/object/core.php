<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Core functions for the Mundo objects, including data manipulation,
 * database updates and database searching.
 *
 * @package Mundo
 * @subpackage Mundo_Object
 * @author Tony Holdstock-Brown
 **/
class Mundo_Object_Core
{

	/**
	 * This is the name of the collection we're saving to in MongoDB.
	 *
	 * !! Note: This string becomes a MongoCollection instance once _init_db()
	 *          has been called (see Mundo_Core for this method).
	 *
	 * @var string
	 */
	protected $_collection;

	/**
	 * The name of the fields in our collection.
	 *
	 * @var array
	 */
	protected $_fields;

	/**
	 * Validation rules to run against data. The validation rules are ran when
	 * we save/update the collection or when we call the validate() method.
	 *
	 * @var array
	 */
	protected $_rules;

	/**
	 * An array of filters which are ran when setting data.
	 *
	 * @var array
	 */
	protected $_filters;

	/**
	 * Our Mongo class
	 *
	 * @var  Mongo
	 * @see  http://www.php.net/manual/en/class.mongo.php
	 */
	protected $_mongo;

	/**
	 * Our MongoDB class
	 *
	 * @var  MongoDB
	 * @see  http://www.php.net/manual/en/class.mongodb.php
	 */
	protected $_db;

	/**
	 * The "safe" parameter for DB queries
	 *
	 * @var  mixed
	 */
	protected $_safe;

	/**
	 * Initialise our data
	 *
	 * @param array $data 
	 */
	public function __construct($data = array())
	{
		if ( ! is_array($data))
		{
			// Only accept data in an array
			throw new Mundo_Exception("Arrays are the only accepted arguments when constructing Mundo Models");
		}

		// Set our data
		$this->set($data);
	}

	/**
	 * This is a container for the object's saved data
	 *
	 * @var array
	 */
	protected $_data = array();

	/**
	 * A container for changed data that needs saving to our collection
	 *
	 * @var array
	 */
	protected $_changed = array();

	/**
	 * Assigns field data by name. Assigned variables will first be added to
	 * the $_changed variable until the collection has been updated.
	 *
	 * @todo Allow setting of data through dot path notation (eg. comment.author)
	 *
	 * @param  mixed  field name or an array of field => values 
	 * @param  mixed  field value 
	 * @return $this
	 */
	public function set($values, $value = NULL)
	{
		if ($value)
		{
			// Normalise single field setting to multiple field setting
			$values = array($values => $value);
		}

		if ( ! $values)
			return $this;

		// Flatten our set data
		$values = Mundo::flatten($values);

		foreach ($values as $field => $value)
		{
			// Replace numerical keys with mongo's positional operator
			$field_positional = preg_replace('#\.[0-9]+#', '.$', $field);

			// $field_positional needs to be the whole array path or at least the first portion
			if ( ! in_array($field_positional, $this->_fields) AND ! preg_grep('/^'.str_replace(array('$', '.'), array('\$', '\.'), $field_positional).'/', $this->_fields))
			{
				throw new Mundo_Exception("Field ':field' does not exist", array(':field' => $field));
			}

			// Set our data
			Arr::set_path($this->_changed, $field, $value);
		}

		return $this;
	}

	/**
	 * Allow setting our field values by overloading, as in:
	 *
	 *    $model->field = $value;
	 *
	 * @param  string  $field   Field name
	 * @param  string  $value  Value
	 * @return void
	 */
	public function __set($field, $value)
	{
		$this->set($field, $value);
	}

	/**
	 * Allow retrieving field values via overloading.
	 *
	 * !! Note: Returns NULL if field does not exist.
	 *
	 * @param  string  $field  name of field to retrieve
	 * @return mixed
	 */
	public function __get($field)
	{
		return $this->get($field);
	}


	/**
	 * Checks if a field's value is set
	 *
	 * @param  string  $field field name
	 * @return bool
	 */
	public function __isset($field)
	{
		return ! ($this->get($field) === NULL);
	}

	/**
	 * Unset a field's data (ie. reset it's data to NULL)
	 *
	 * @param  string $field  Field name to unset
	 * @return void
	 */
	public function __unset($field)
	{
		// If there isn't any original data or changed data set, return
		if ( ! $this->original($field) AND ! $this->changed($field))
			return;

		// If the field has been changed and there is no original data, we need to remove it from our changed array
		$changed = $this->changed($field) AND ! $this->original($field);

		// Set our $field to NULL
		$this->set(array($field => NULL));

		if ($changed)
		{
			if (strpos($field, '.') !== FALSE)
			{
				// We're using dot notation to unset an embedded object, so separate our path string.
				$paths = explode('.', $field);

				// Pop the field name we are unsetting (the last element)
				$field = array_pop($paths);

				// Take the remaining keys as our parent path and array path
				$field_path = implode('.', $paths);

				// Get the embedded object we are removing values from 
				$changed = Arr::path($this->_changed, $field_path);

				// Unset our data from the embedded object
				unset($changed[$field]);

				// Reset our altered embedded object
				Arr::set_path($this->_changed, $field_path, $changed);
			}
			else
			{
				// Simple document field, just unset it
				unset($this->_changed[$field]);
			}
		}
	}

	/**
	 * Gets data from the object. Note that this merges the original data 
	 * ($_data) and the changed data ($_changed) before returning.
	 *
	 * @param  string  Path of the field to return (eg. comments.0.author)
	 * @return mixed   Array of data if no path was supplied, or the value of the field/null if no field was found
	 **/
	public function get($path = NULL)
	{
		if ( ! $path)
		{
			return $this->_merge(); 
		}

		return Arr::path($this->_merge(), $path);
	}

	/**
	 * Gets changed data for a given field. If no field is supplied, this
	 * returns all changed data.
	 *
	 * @return mixed
	 */
	public function changed($path = NULL)
	{
		if ( ! $path)
		{
			return $this->_changed; 
		}

		return Arr::path($this->_changed, $path);
	}

	/**
	 * Gets original (saved) data for a given field. If no field is supplied, 
	 * this returns all original data.
	 *
	 * @return mixed
	 */
	public function original($path = NULL)
	{
		if ( ! $path)
		{
			return $this->_data; 
		}

		return Arr::path($this->_data, $path);
	}

	/**
	 * Convenience function for merging saved and changed data
	 *
	 * @return array
	 */
	protected function _merge()
	{
		return Arr::merge($this->_data, $this->_changed);
	}

	/**
	 * Loads a Validation object with the rules defined in the model and 
	 * either the set model data or the data passed as an argument.
	 *
	 * Note: This function returns a Validation object and does not validate
	 * data itself. Just run check() on this function's return value. This is
	 * because it makes it easier to grab error messages and use the normal
	 * Validation library.
	 *
	 * @param   string $data 
	 * @return  Validation
	 */
	public function validate($data = NULL)
	{
		if ( ! $data)
		{
			// Use already set data if none is given
			$data = $this->_merge();
		}

		$flat_data = Mundo::flatten($data);

		$validation = Validation::factory($flat_data);

		if ($this->_rules)
		{
			// Get our rules
			$rules = $this->_extract_rules($data);

			foreach ($rules as $field => $rule)
			{
				// Assign the rules for each field
				$validation->rules($field, $rule);
			}
		}

		return $validation;
	}

	/**
	 * Helper function used to validate current data when querying database
	 *
	 * @throws Validation_Exception
	 * @return boolean
	 */
	protected function _validate()
	{

		$validate = $this->validate();

		if ( ! $validate->check())
		{
			throw new Validation_Exception($validate);
			return FALSE;
		}

		return TRUE;
	}
	/**
	 * This extracts rules form $_rules in the format required for the
	 * Validation library
	 *
	 * @param string $rules 
	 * @return void
	 */
	protected function _extract_rules($data, $rules = NULL, $path = NULL)
	{

		if ( ! $rules)
		{
			// We have to manually set them with recusivity
			$rules = $this->_rules;
		}

		foreach ($rules as $field => $rule)
		{
			
			if ($field == '$')
			{
				// If this is an embedded collection, we need to work out how many collections we're accounting for.
				// This is to assign validation rules to each collection member we have.
				$collection_number = count(Arr::path($data, $path)) - 1;

				if ($collection_number < 0)
				{
					// We have no embedded objects, so don't validate
					continue;
				}
			}
			else
			{
				// Add dots to our path (not necessary on the first traversal)
				$dotted_path = $path ? $path.'.'.$field : $field;

				// Hack to loop assignments once without collecitons
				$collection_number = 1;
			}

			do
			{
				if ($field == '$')
				{
					// Add our collection number to our path (if we need to).
					$dotted_path = $path ? $path.'.'.$collection_number : $collection_number;
				}

				if (Arr::is_assoc($rule))
				{
					// If $rule is an associative array this is an embedded object/coll. Run this again.
					if ($embedded_rules = $this->_extract_rules($data, $rule, $dotted_path))
					{
						// Make sure we return it
						$ruleset = isset($ruleset) ? Arr::merge($ruleset, $embedded_rules) : $embedded_rules;
					}
				}
				else
				{
					// Assign our rule
					$ruleset[$dotted_path] = $rule;
				}
			}
			while($collection_number--);
		}

		// Return our rules
		return isset($ruleset) ? $ruleset : FALSE;
	}

	/**
	 * Whether we have a document loaded from the database
	 *
	 * @var boolean
	 */
	protected $_loaded = FALSE;

	/**
	 * Returns the $_loaded value which indicates whether a document has been
	 * loaded from the database
	 *
	 * @return boolean
	 */
	public function loaded()
	{
		return $this->_loaded;
	}

	/**
	 * Creates a new document in our collection
	 *
	 * @return  mixed  $this
	 * @throws  mixed  Validation_Exception, Mundo_Exception
	 */
	public function create()
	{
		if ($this->get('_id'))
		{
			// Running the load() method alters $_loaded, so we need to duplicate our class

			// Get the model class name (PHP => 5.3.X )
			$class = get_called_class();

			// Create a duplicate class; 
			$object = new $class;

			// Assign our ID
			$object->set('_id', $this->get('_id'));

			// See if an object with this ID exists
			if($object->load($this->get('_id'))->loaded())
			{
				// We cannot create a document with a duplicate ID
				Throw new Mundo_Exception("Creating failed: a document with ObjectId ':object_id' exists already.", array(":object_id" => $this->get('_id')));
			}

			// Garbage collection
			unset($object, $class);
		}

		// Ensure our data is valid
		$this->_validate();

		// Intiialise our database
		$this->_init_db();

		// Merge our existing data and changed data
		$data = $this->_merge();

		// Insert our data
		$this->_collection->insert($data, array('safe' => $this->_safe));

		// Reset our $_changed to empty after our save
		$this->_changed = array();

		// Update our saved data variable
		$this->_data = $data;

		// We're now loaded
		$this->_loaded = TRUE;

		return $this;
	}

	/**
	 * Saves model data using the MongoCollection::save driver method
	 *
	 * @return $this
	 **/
	public function save()
	{
		// Validate our data
		$this->_validate();

		// If we have no changed data why bother?
		if ( ! $this->changed())
			return $this;

		// Get our original data so we can merge changes
		$data = $this->original();

		// Flatten our changed data for set_path calls
		$changed = Mundo::flatten($this->changed());

		// For each piece of changed data merge it in.
		foreach($changed as $field => $value)
		{
			Arr::set_path($data, $field, $value);
		}

		// Connect to and query the collection
		$this->_init_db();
		$this->_collection->save($data, array('safe' => $this->_safe));

		// Reset our changed array
		$this->_changed = array();

		// Replace our data just in case an upsert created an ID
		$this->_data = $data;

		// Ensure we're loaded if that was an upsert
		$this->_loaded = TRUE;

		return $this;
	}

	/**
	 * Atomically updates the document according to data in the changed
	 * property.
	 *
	 * @returns $this
	 */
	public function update()
	{
		// If this isn't loaded fail
		if ( ! $this->loaded())
		{
			throw new Mundo_Exception("Cannot atomically update the document because the model has not yet been loaded");
		}

		// Do no work if possible.
		if ( ! $this->changed())
			return $this;

		// Initialise an empty driver query
		$query = array();

		// Take our changed and original and flatten them for comparison
		$changed = Mundo::flatten($this->changed());
		$original = Mundo::flatten($this->original());

		echo Debug::vars($changed, $original);

		foreach ($changed as $field => $value)
		{
		}
	}


	/**
	 * Stores an array containing the last update() query sent to the driver
	 * Mongo PHP driver
	 *
	 * @var array
	 **/
	protected $_last_update;

	/**
	 * Displays the last atomic operation as it would have been sent to the
	 * Mongo PHP driver
	 *
	 * @return array
	 */
	public function last_update()
	{
		return $this->_last_update;
	}

	/**
	 * Connect to Mongo for queries
	 *
	 * @return self 
	 */
	protected function _init_db()
	{

		if ($this->_mongo instanceof Mongo AND $this->_db instanceof MongoDB AND $this->_collection instanceof MongoCollection)
		{
			// Our database is already initialised
			return $this;
		}

		// Get our configuration information
		$config = Kohana::$config->load("mundo");

		// Load and connect to mongo
		$this->_mongo = new Mongo();

		// Select our database
		$this->_db = $this->_mongo->{$config->database};

		// Set our safety settings
		$this->_safe = $config->mongo_safe;

		// Load our selected collection using the same variable as our collection name.
		$this->_collection = $this->_db->{$this->_collection};

		return $this;
	}

	/**
	 * Loads a single document from the database using the object's
	 * current data
	 *
	 * @param   MongoId   Object ID if you want to load from a specific ID without other model data
	 * @return  $this
	 */
	public function load($object_id = NULL)
	{
		$query = array();

		/**
		 * @todo Assess the below: should we just attempt to check for
		 * an object_id argument, use the current $_id from merged and
		 * then use all data instead of this?
		 */

		if ($object_id)
		{
			// Load from the given ObjectId
			$query = array('_id' => $object_id);
		}
		elseif ( ! $this->changed() AND ! $this->loaded())
		{
			// No data to query with
			throw new Mundo_Exception("No model data supplied");
		}
		elseif ( ! $this->changed())
		{
			// No changed data, so assume we are reloading our object. Use the current ObjectId.
			$query = array('_id' => $this->get('_id'));
		}
		else
		{
			// Use all recent data as our query
			$query = $this->get();
		}

		// Initialise our database
		$this->_init_db();

		if ($result = $this->_collection->findOne($query))
		{
			// Assign our returned data
			$this->_data = $result;

			// Set our loaded flag
			$this->_loaded = TRUE;

			// Reset our changed array
			$this->_changed = array();
		}

		return $this;
	}

} // End Mundo_Object_Core
