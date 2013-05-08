<?php

/* Eregansu: Data models
 *
 * Copyright 2009-2012 Mo McRoberts.
 *
 *  Licensed under the Apache License, Version 2.0 (the "License");
 *  you may not use this file except in compliance with the License.
 *  You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 *  Unless required by applicable law or agreed to in writing, software
 *  distributed under the License is distributed on an "AS IS" BASIS,
 *  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *  See the License for the specific language governing permissions and
 *  limitations under the License.
 */

/**
 * @year 2009
 * @include uses('model');
 * @since Available in Eregansu 1.0 and later. 
 */

uses('db');

/**
 * Base class for data models.
 *
 * The \class{Model} class is intended to be used as a base for classes which
 * provide interfaces to persistent storage, such as relational databases.
 */
class Model
{
	protected static $instances = array();
	protected $databases = array('db');
	public $db;
	
	/**
	 * Obtains an instance of one of \class{Model}'s descendants.
	 *
	 * If \p{$args['class']} is not set, \m{getInstance} will immediately
	 * return \c{null}.
	 *
	 * Otherwise, an instance of the named class will be obtained, and its
	 * \m{__construct|constructor} will be invoked, passing \p{$args}.
	 *
	 * Descendants should override \m{getInstance} to set \p{$args['class']} to
	 * the name of the class if it's not set.
	 *
	 * Descendants should, if possible, ensure that \p{$args['db']} is set to
	 * a database connection URI which can be passed to \m{DBCore::connect}.
	 *
	 * The combination of \p{$args['class']} and \p{$args['db']} are used to
	 * construct a key into the shared instance list. When a new instance is
	 * constructed, it is stored with this key in the list. If an entry with
	 * the key is already present, it will be returned and no new instance
	 * will be created.
	 *
	 * @type Model
	 * @param[in,optional] array $args Initialisation parameter array.
	 * @return On success, returns an instance of a descendant of \class{Model}.
	 */
	public static function getInstance($args = null)
	{
		if(!isset($args['class'])) return null;
		if(!isset($args['db'])) $args['db'] = null;
		if(!isset($args['instanceKey']))
		{
			$args['instanceKey'] = $args['db'];
		}
		$key = $args['class'] . (isset($args['instanceKey']) ? (':' . $args['instanceKey']) : '');
		$className = $args['class'];
		if(!isset(self::$instances[$key]))
		{
			self::$instances[$key] = new $className($args);
		}
		return self::$instances[$key];
	}

	/**
	 * Construct an instance of \class{Model}.
	 *
	 * @param[in] array $args Initialisation parameters.
	 */
	public function __construct($args)
	{
		foreach($this->databases as $key)
		{
			if(isset($args[$key]) && strlen($args[$key]) && !isset($this->{$key}))
			{
				$this->{$key} = Database::connect($args[$key]);
			}
		}
	}
}

/* ObjectSet encapsulates an array (or another IDataset) */
abstract class ObjectSet implements IDataset, ISerialisable
{
	protected $list;
	protected $model;
	protected $instanceClass;
	protected $current;
	protected $serialiseJsonAsObject = false;

	public $limit = 0;
	public $offset = 0;
	public $total = 0;
	
	public function __construct($list = null, $model = null, $offset = 0, $limit = 0)
	{
		$this->list = $list;
		$this->model = $model;
		$this->limit = $limit;
		$this->offset = $offset;
		if(is_object($list) && isset($list->total))
		{
			$this->total = $list->total;
		}
	}
		
	public function current()
	{
		return $this->current;
	}
	
	protected function obtainCurrent()
	{
		if($this->list instanceof Iterator)
		{
			$this->current = $this->list->current();
		}
		else
		{
			$this->current = current($this->list);
		}
		if($this->current !== null && isset($this->instanceClass))
		{
			$class = $this->instanceClass;
			$this->current = new $class($this->current, $this->model);
		}
	}
	
	public function key()
	{
		if(isset($this->list))
		{
			if($this->list instanceof Iterator)
			{
				$k = $this->list->key();				
			}
			else
			{
				$k = key($this->list);
			}
			return $k;
		}
		return null;
	}
		
	public function rewind()
	{
		$this->current = null;
		if(isset($this->list))
		{		
			reset($this->list);
			$this->obtainCurrent();
		}
		return false;
	}
	
	public function next()
	{
		$this->current = null;
		if(isset($this->list))
		{
			if($this->list instanceof Iterator)
			{
				$r = $this->list->next();
			}
			else
			{
				$r = next($this->list);
			}
		}
		else
		{
			$r = false;
		}
		$this->obtainCurrent();
		return $r;
	}
	
	public function valid()
	{
		if(isset($this->list))
		{
			if($this->list instanceof Iterator)
			{
				return $this->list->valid();
			}
			return key($this->list) === null ? false : true;
		}
		return false;
	}
	
	public function count()
	{
		if(isset($this->list))
		{
			return count($this->list);
		}
		return 0;
	}
	
	public function serialisations()
	{
		return array('application/json');
	}
	
	public function serialise(&$mimeType, $returnBuffer = false, $request = null, $sendHeaders = null /* true if (!$returnBuffer && $request) */)
	{
		if($returnBuffer)
		{
			ob_start();
		}
		$r = false;
		if($mimeType == 'application/json' || $mimeType == 'text/javascript')
		{
			if($request !== null && $sendHeaders)
			{
				$request->header('Content-type', $mimeType);
			}
			echo $this->serialiseJsonAsObject ? '{' : '[';
			$i = 0;
			foreach($this as $k => $v)
			{
				if($this->serialiseJsonAsObject)
				{
					echo ($i ? ',' : '') . '"' . addslashes($k) . '":';
				}
				else if($i)
				{
					echo ',';
				}
				$t = $mimeType;
				if(!($v instanceof ISerialisable) || $v->serialise($t) === false)
				{
					echo json_encode($v);
				}
				$i++;
			}
			echo $this->serialiseJsonAsObject ? '}' : ']';
			if($returnBuffer)
			{
				return ob_get_clean();
			}
			return true;
		}
		if($returnBuffer)
		{
			ob_end_clean();
		}
		return false;
	}
}

/* ObjectInstance provides a base class for the encapsulation of data rows */
abstract class ObjectInstance implements ArrayAccess, Countable, Iterator, ISerialisable
{
	protected $model;
	protected $data;
	
	public function __construct($data = null, $model = null)
	{
		$this->data = $data;
		$this->model = $model;
	}
		
	/* ArrayAccess */
	public function offsetGet($k)
	{
		$nothing = null;
		if(isset($this->data[$k]))
		{
			return $this->data[$k];
		}
		return $nothing;
	}
	
	public function offsetExists($k)
	{
		return isset($this->data[$k]);
	}
	
	public function offsetUnset($k)
	{
		unset($this->data[$k]);
	}
	
	public function offsetSet($k, $v)
	{
		$this->data[$k] = $v;
	}
	
	/* Countable */
	public function count()
	{
		return count($this->data);
	}
	
	/* Iterator */
	public function current()
	{
		return current($this->data);
	}
	
	public function key()
	{
		return key($this->data);
	}
	
	public function next()
	{
		return next($this->data);
	}
	
	public function rewind()	
	{
		return reset($this->data);
	}
	
	public function valid()
	{
		return key($this->data) === null ? false : true;
	}
	
	/* ISerialisable */
	public function serialisations()
	{
		return array('application/json');
	}
	
	public function serialise(&$mimeType, $returnBuffer = false, $request = null, $sendHeaders = null /* true if (!$returnBuffer && $request) */)
	{
		if($mimeType == 'application/json' || $mimeType == 'text/javascript')
		{
			if($request !== null && $sendHeaders)
			{
				$request->header('Content-type', $mimeType);
			}
			if($returnBuffer)
			{
				return json_encode($this->data);
			}
			echo json_encode($this->data);
			return true;
		}
		return false;
	}
}
