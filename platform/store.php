<?php

/* Eregansu: Complex object store
 *
 * Copyright 2010 Mo McRoberts.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 * 1. Redistributions of source code must retain the above copyright
 *    notice, this list of conditions and the following disclaimer.
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 * 3. The names of the author(s) of this software may not be used to endorse
 *    or promote products derived from this software without specific prior
 *    written permission.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES, 
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY 
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.  IN NO EVENT SHALL
 * AUTHORS OF THIS SOFTWARE BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED
 * TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR
 * PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF 
 * LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING 
 * NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS 
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

/**
 * @framework Eregansu
 */

uses('model', 'uuid');

class Storable implements ArrayAccess
{
	protected static $models = array();
	protected static $refs = array();
	
	public static function objectForData($data, $model, $className = null)
	{
		if(!$className)
		{
			$className = 'Storable';
		}
		if(!isset(self::$models[$className]))
		{
			self::$models[$className] = $model;
		}
		return new $className($data);
	}
	
	protected function __construct($data)
	{
		if(!is_array($data))
		{
			throw new Exception(gettype($data) . ' passed to Storable::__construct(), array expected');
		}
		foreach($data as $k => $v)
		{
			$this->$k = $v;
		}
		$this->loaded();
	}
	
	public function store()
	{
		if(!($data = self::$models[get_class($this)]->setData($this)))
		{
			return null;
		}
		$this->reload($data);
		return $this->uuid;
	}
	
	public function reload($data = null)
	{
		static $uuid = null;
		
		if(!$uuid && isset($this->uuid))
		{
			$uuid = $this->uuid;
		}
		$keys = array_keys(get_object_vars($this));
		foreach($keys as $k)
		{
			unset($this->$k);
		}
		if(!$data)
		{
			$data = self::$models[get_class($this)]->dataForUUID($uuid);
		}
		if($data)
		{		
			foreach($data as $k => $v)
			{
				$this->$k = $v;
			}
			$this->loaded(true);
			$uuid = $this->uuid;
		}
		return $uuid;
	}
	
	protected function loaded($reloaded = false)
	{
	}
	
	public function offsetExists($name)
	{
		return isset($this->$name);
	}
	
	public function offsetGet($name)
	{
		if(isset($this->$name) && isset($this->_refs) && in_array($name, $this->_refs))
		{
			return $this->referencedObject($this->$name);
		}
		return $this->$name;
	}
	
	public function offsetSet($name, $value)
	{
		$this->$name = $value;
	}
	
	public function offsetUnset($name)
	{
		unset($this->$name);
	}
	
	protected function referencedObject($id)
	{
		$className = get_class($this);
		if(!isset(self::$refs[$className])) self::$refs[$className] = array();
		if(!isset(self::$refs[$className][$id]))
		{
			self::$refs[$className][$id] = self::$models[$className]->objectForId($id);
		}
		return self::$refs[$className][$id];
	}
}

abstract class StorableSet implements DataSet
{
	protected $model;
	public $EOF = true;
	
	public function __construct($model, $args)
	{
		$this->model = $model;	
	}
	
	public function rewind()
	{
	}
	
	public function valid()
	{
		$valid = !$this->EOF;
		return $valid;
	}

}

class StaticStorableSet extends StorableSet
{
	protected $list;
	protected $keys;
	protected $storableClass = 'Storable';
	protected $count;
	protected $current;
	
	public function __construct($model, $args)
	{
		parent::__construct($model, $args);
		if(isset($args['storableClass']))
		{
			$this->storableClass = $args['storableClass'];
		}
		$this->list = $args['list'];
		$this->rewind();
	}
	
	public function next()
	{
		if(!$this->EOF)
		{
			if(null !== ($k = array_shift($this->keys)))
			{
				$this->current = $this->storableForEntry($this->list[$k]);
				$this->count++;
				if(!count($this->keys))
				{
					$this->EOF = true;
				}
				return $this->current;
			}
		}
		$this->current = null;
		$this->EOF = true;
		return null;
	}
	
	protected function storableForEntry($entry, $rowData = null)
	{
		if(is_array($rowData))
		{
			$entry['uuid'] = $rowData['uuid'];
			$entry['created'] = $rowData['created'];
			if(strlen($rowData['creator_uuid']))
			{
				$entry['creator'] = array('scheme' => $rowData['creator_scheme'], 'uuid' => $rowData['creator_uuid']);
			}
			$entry['modified'] = $rowData['modified'];
			if(strlen($rowData['modifier_uuid']))
			{
				$entry['modifier'] = array('scheme' => $rowData['modifier_scheme'], 'uuid' => $rowData['modifier_uuid']);
			}
			$entry['owner'] = $rowData['owner'];		
		}
		return call_user_func(array($this->storableClass, 'objectForData'), $entry, $this->model, $this->storableClass);	
	}
	
	public function key()
	{
		return ($this->EOF ? null : $this->count);
	}
	
	public function current()
	{
		if($this->current === null)
		{
			$this->next();
		}
		return $this->current;
	}
	
	public function rewind()
	{
		$this->count = 0;
		$this->current = null;
		if(count($this->list))
		{
			$this->EOF = false;
			$this->keys = array_keys($this->list);
		}
		else
		{
			$this->EOF = true;
			$this->keys = array();
		}
	}
}

class DBStorableSet extends StaticStorableSet
{
	protected $rs;
	protected $current;
	protected $key;
	public $offset;
	public $limit;
	public $total;
	
	public function __construct($model, $args)
	{
		$this->rs = $args['recordSet'];
		if(isset($args['storableClass']))
		{
			$this->storableClass = $args['storableClass'];
		}		
		$this->total = $this->rs->total;
		if(isset($args['offset'])) $this->offset = $args['offset'];
		if(isset($args['limit'])) $this->limit = $args['limit'];
		$this->rewind();
	}
	
	public function key()
	{
		return $this->key;
	}
		
	public function next()
	{
		$this->rs->next();
		if(($this->data = $this->rs->fields))
		{
			$this->count++;
			$this->key = $this->data['uuid'];
			$data = json_decode($this->data['data'], true);
			$this->current = $this->storableForEntry($data, $this->data);
		}
		else
		{
			$this->key = $this->current = null;
		}
		$this->EOF = $this->rs->EOF;
		return $this->current;
	}
	
	public function rewind()
	{	
		$this->count = 0;
		$this->rs->rewind();
		if(($this->data = $this->rs->fields))
		{
			$this->key = $this->data['uuid'];
			$data = json_decode($this->data['data'], true);
			$this->current = $this->storableForEntry($data, $this->data);			
		}
		else
		{
			$this->key = $this->current = null;
		}
		$this->EOF = $this->rs->EOF;
	}	
}

class Store extends Model
{
	protected $storableClass = 'Storable';
	
	/* The name of the 'objects' table */
	protected $objects = 'objects';
	
	public static function getInstance($args = null, $className = null, $defaultDbIri = null)
	{
		return parent::getInstance($args, ($className ? $className : 'Store'), $defaultDbIri);
	}
	
	public function objectForUUID($uuid)
	{
		if(!($data = $this->dataForUUID($uuid)))
		{
			return null;
		}
		$class = $this->storableClass;
		return call_user_func(array($this->storableClass, 'objectForData'), $data, $this, $this->storableClass);
	}
	
	public function dataForUUID($uuid)
	{
		if(!($row = $this->db->row('SELECT * FROM {' . $this->objects . '} WHERE "uuid" = ?', $uuid)))
		{
			return null;
		}
		$data = json_decode($row['data'], true);
		/* Ensure these are set from the outset */
		$this->retrievedMeta($data, $row);
		return $data;
	}
		
	public function setData($data, $user = null, $lazy = false)
	{
		if(is_object($data))
		{
			$data = get_object_vars($data);
		}
		if(isset($data['uuid']) && strlen($data['uuid']) == 36)
		{
			$uuid = $data['uuid'];
		}
		else
		{
			$uuid = UUID::generate();
		}
		$user_scheme = $user_uuid = null;
		$uuid = strtolower($uuid);
		unset($data['uuid']);
		unset($data['created']);
		unset($data['modified']);
		unset($data['creator']);
		unset($data['modifier']);
		unset($data['dirty']);
		unset($data['owner']);
		$json = json_encode($data);
		do
		{
			$this->db->begin();
			$entry = $this->db->row('SELECT "uuid" FROM {' . $this->objects . '} WHERE "uuid" = ?', $uuid);
			if($entry)
			{
				$this->db->exec('UPDATE {' . $this->objects . '} SET "data" = ?, "dirty" = ?, "modified" = ' . $this->db->now () . ', "modifier_scheme" = ?, "modifier_uuid" = ? WHERE "uuid" = ?', $json, 'Y', $user_scheme, $user_uuid, $uuid);
			}
			else
			{
				$this->db->insert($this->objects, array(
					'uuid' => $uuid,
					'data' => $json,
					'@created' => $this->db->now(),
					'creator_scheme' => $user_scheme,
					'creator_uuid' => $user_uuid,
					'@modified' => $this->db->now(),
					'modifier_scheme' => $user_scheme,
					'modifier_uuid' => $user_uuid,
					'dirty' => 'Y',
				));
			}
		}
		while(!$this->db->commit());
		$row = $this->db->row('SELECT "uuid", "created", "creator_scheme", "creator_uuid", "modified", "modifier_scheme", "modifier_uuid", "owner" FROM {' . $this->objects . '} WHERE "uuid" = ?', $uuid);
		$this->retrievedMeta($data, $row);
		$this->stored($data, $json, $lazy);
		return $data;
	}
	
	public function updateObjectWithUUID($uuid)
	{
		if(!($row = $this->db->row('SELECT * FROM {' . $this->objects . '} WHERE "uuid" = ?', $uuid)))
		{
			return false;
		}
		$data = json_decode($row['data'], true);
		$this->retrievedMeta($data, $row);
		$this->stored($data, false);
		return true;
	}
	
	protected function retrievedMeta(&$data, $row)
	{
		$data['uuid'] = $row['uuid'];
		$data['created'] = $row['created'];
		if(strlen($row['creator_uuid']))
		{
			$data['creator'] = array('scheme' => $row['creator_scheme'], 'uuid' => $row['creator_uuid']);
		}
		$data['modified'] = $row['modified'];
		if(strlen($row['modifier_uuid']))
		{
			$data['modifier'] = array('scheme' => $row['modifier_scheme'], 'uuid' => $row['modifier_uuid']);
		}
		$data['owner'] = $row['owner'];
	}
	
	protected function stored($data, $json = null, $lazy = false)
	{
		if(!isset($data['kind']) || !strlen($data['kind']) || !isset($data['uuid']))
		{
			return false;
		}
		$uuid = strtolower(trim($data['uuid']));
		if(!strlen($uuid))
		{
			return false;
		}
		if($lazy)
		{
			return true;
		}
		if(defined('OBJECT_CACHE_ROOT'))
		{
			try
			{
				if(!file_exists(OBJECT_CACHE_ROOT))
				{
					mkdir(OBJECT_CACHE_ROOT, 0777, true);
				}
				$dir = OBJECT_CACHE_ROOT . $data['kind'] . '/' . substr($uuid, 0, 2) . '/';
				if(null == $json)
				{
					$json = json_encode($data);
				}
				if(!file_exists($dir))
				{
					mkdir($dir, 0777, true);
				}
				$f = fopen($dir . $uuid . '.json', 'w');
				fwrite($f, $json);
				fclose($f);
				try
				{
					chmod($dir . $uuid . '.json', 0666);
				}
				catch (Exception $e)
				{
				}
			}
			catch(Exception $e)
			{
				if(php_sapi_name() == 'cli')
				{
					echo str_repeat('=', 79) . "\n" . $e . "\n" . str_repeat('=', 79) . "\n";
				}				
			}
		}
		$this->db->query('UPDATE {' . $this->objects . '} SET "dirty" = ? WHERE "uuid" = ?', 'N', $uuid);
		return true;
	}
}
