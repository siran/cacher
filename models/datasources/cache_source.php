<?php
/**
 * Cache data source class.
 *
 * @copyright     Copyright 2010, Jeremy Harris
 * @link          http://42pixels.com
 * @package       cacher
 * @subpackage    cacher.models.behaviors
 */

/**
 * Includes
 */
App::import('Lib', 'Folder');

/**
 * CacheSource datasource
 *
 * Gets find results from cache instead of the original datasource. The cache
 * is stored under CACHE/cacher.
 *
 * @package       cacher
 * @subpackage    cacher.models.datasources
 */
class CacheSource extends DataSource {

/**
 * Stored original datasource for fallback methods
 *
 * @var DataSource
 */
	var $source = null;

/**
 * Constructor
 *
 * Sets default options if none are passed when the datasource is created and
 * creates the cache configuration. If a `config` is passed and is a valid
 * Cache configuration, CacheSource uses its settings
 *
 * ### Extra config settings
 * - `original` The name of the original datasource, i.e., 'default' (required)
 * - `config` The name of the Cache configuration to use. Uses 'default' by default
 * - other settings required by DataSource...
 *
 * @param array $config Configure options
 */
	function __construct($config = array()) {
		$config = array_merge(array('config' => 'default'), $config);
		parent::__construct($config);
		if (!isset($this->config['original'])) {
			trigger_error('Cacher.CacheSource::__construct() :: Missing name of original datasource', E_USER_WARNING);
		}
		if (!Cache::isInitialized($this->config['config'])) {
			trigger_error('Cacher.CacheSource::__construct() :: Cache config '.$this->config['config'].' not configured.', E_USER_WARNING);
		}

		$this->source =& ConnectionManager::getDataSource($this->config['original']);
	}

/**
 * Reads from cache if it exists. If not, it falls back to the original
 * datasource to retrieve the data and cache it for later
 *
 * @param Model $Model
 * @param array $queryData
 * @return array Results
 * @see DataSource::read()
 */
	function read($Model, $queryData = array()) {
		$this->_resetSource($Model);
		$key = $this->_key($Model, $queryData);
		$results = Cache::read($key, $this->config['config']);
		if ($results === false) {
			$results = $this->source->read($Model, $queryData);
			Cache::write($key, $results, $this->config['config']);
			$this->_map($Model, $key);
		}		
		return $results;
	}

/*
 * Clears the cache for a specific model and rewrites the map. Pass query to
 * clear a specific query's cached results
 *
 * @param array $query If null, clears all for this model
 * @param Model $Model The model to clear the cache for
 */
	function clearModelCache($Model, $query = null) {
		$map = Cache::read('map', $this->config['config']);
		
		$keys = array();
		if ($query !== null) {
			$keys = array($this->_key($Model, $query));
		} else{
			if (!empty($map[$this->source->configKeyName]) && !empty($map[$this->source->configKeyName][$Model->alias])) {
				$keys = $map[$this->source->configKeyName][$Model->alias];
			}
		}
		if (empty($keys)) {
			return;
		}
		$map[$this->source->configKeyName][$Model->alias] = array_flip($map[$this->source->configKeyName][$Model->alias]);
		foreach ($keys as $cacheKey) {
			Cache::delete($cacheKey, $this->config['config']);
			unset($map[$this->source->configKeyName][$Model->alias][$cacheKey]);
		}
		$map[$this->source->configKeyName][$Model->alias] = array_values(array_flip($map[$this->source->configKeyName][$Model->alias]));
		Cache::write('map', $map, $this->config['config']);
	}

/**
 * Hashes a query into a unique string and creates a cache key
 *
 * @param Model $Model The model
 * @param array $query The query
 * @return string
 * @access protected
 */
	function _key($Model, $query) {
		$query = array_merge(
			array(
				'conditions' => null, 'fields' => null, 'joins' => array(), 'limit' => null,
				'offset' => null, 'order' => null, 'page' => null, 'group' => null, 'callbacks' => true
			),
			(array)$query
		);
		$queryHash = md5(serialize($query));
		$sourceName = $this->source->configKeyName;
		return Inflector::underscore($sourceName).'_'.Inflector::underscore($Model->alias).'_'.$queryHash;
	}
	
/**
 * Creates a cache map (used for deleting cache keys or groups)
 * 
 * @param Model $Model
 * @param string $key 
 */
	function _map($Model, $key) {
		$map = Cache::read('map', $this->config['config']);
		if ($map === false) {
			$map = array();
		}
		$map = Set::merge($map, array(
			$this->source->configKeyName => array(
				$Model->alias => array(
					$key
				)
			)
		));
		Cache::write('map', $map, $this->config['config']);
	}

/**
 * Resets the model's datasource to the original
 *
 * @param Model $Model The model
 * @return boolean
 */
	function _resetSource($Model) {
		if (isset($Model->_useDbConfig)) {
			$this->source =& ConnectionManager::getDataSource($Model->_useDbConfig);
		}
		return $Model->setDataSource(ConnectionManager::getSourceName($this->source));
	}

}

?>