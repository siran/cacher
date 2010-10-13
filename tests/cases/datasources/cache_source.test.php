<?php

App::import('Model', 'App');
if (!class_exists('Cache')) {
	require LIBS . 'cache.php';
}

class CacheSourceTestCase extends CakeTestCase {

	var $fixtures = array('plugin.cacher.cache_data');

	function startTest() {
		$this->_cacheDisable = Configure::read('Cache.disable');		
		Configure::write('Cache.disable', false);
		$this->CacheData =& ClassRegistry::init('CacheData');
		if (!in_array('cache', ConnectionManager::sourceList())) {
			 ConnectionManager::create('cache', array(
				'original' => $this->CacheData->useDbConfig,
				'datasource' => 'Cacher.cache'
			));
		}
		$this->dataSource =& ConnectionManager::getDataSource('cache');
		Cache::clear(false, $this->dataSource->cacheConfig);
	}

	function endTest() {
		Configure::write('Cache.disable', $this->_cacheDisable);
		unset($this->CacheData);
		unset($this->dataSource);
		ClassRegistry::flush();
	}

	function testUseExistingConfig() {
		Cache::config('cacheTest', array(
			'engine' => 'File',
			'duration' => '+1 days'
		));

		ConnectionManager::create('newCache', array(
			'config' => 'cacheTest',
			'original' => $this->CacheData->useDbConfig,
			'datasource' => 'Cacher.cache'
		));
		$this->dataSource =& ConnectionManager::getDataSource('newCache');

		$key = $this->dataSource->_key($this->CacheData, array());
		$this->dataSource->read($this->CacheData, array());

		$result = Cache::config($this->dataSource->cacheConfig);
		$result = $result['settings']['duration'];
		$expected = strtotime('+1 days') - strtotime('now');
		$this->assertEqual($result, $expected);

		$settings = Cache::config($this->dataSource->cacheConfig);
		$results = glob($settings['settings']['path'].$settings['settings']['prefix'].'*');
		$this->assertEqual(count($results), 1);

		$results = Cache::read($key, $this->dataSource->cacheConfig);
		$results = Set::extract('/CacheData/name', $results);
		$expected = array(
			'A Cached Thing',
			'Cache behavior'
		);
		$this->assertEqual($results, $expected);

		Cache::clear(false, $this->dataSource->cacheConfig);
	}

	function testRead() {
		$conditions = array(
			'conditions' => array(
				'CacheData.id' => 1
			)
		);
		$key = $this->dataSource->_key($this->CacheData, $conditions);
		
		$results = $this->dataSource->read($this->CacheData, $conditions);
		// test that we get the correct data
		$this->assertEqual(Set::extract('/CacheData/name', $results), array('A Cached Thing'));
		// test that we wrote to the cache
		$this->assertTrue(Cache::read($key, $this->dataSource->cacheConfig));
		// test that the cache results match the results
		$this->assertEqual(Cache::read($key, $this->dataSource->cacheConfig), $results);
		
		// test multiple cached results
		$moreConditions = array(
			'conditions' => array(
				'CacheData.name' => 'non-existent'
			)
		);
		$results = $this->dataSource->read($this->CacheData, $moreConditions);
		$this->assertEqual($results, array());

		// delete from the db and make sure we read from the cache
		$this->CacheData->delete(1);
		$results = $this->dataSource->read($this->CacheData, $conditions);
		$this->assertEqual(Set::extract('/CacheData/name', $results), array('A Cached Thing'));

		$settings = Cache::config($this->dataSource->cacheConfig);
		$results = glob($settings['settings']['path'].$settings['settings']['prefix'].'*');
		$this->assertEqual(count($results), 2);

		Cache::clear(false, $this->dataSource->cacheConfig);
	}

	function testClearModelCache() {
		$conditions = array(
			'conditions' => array(
				'CacheData.id' => 1
			)
		);
		$results = $this->dataSource->read($this->CacheData, $conditions);

		$moreConditions = array(
			'conditions' => array(
				'CacheData.name' => 'non-existent'
			)
		);
		$results = $this->dataSource->read($this->CacheData, $moreConditions);

		$settings = Cache::config($this->dataSource->cacheConfig);
		$results = glob($settings['settings']['path'].$settings['settings']['prefix'].'*');
		$this->assertEqual(count($results), 2);

		// test that clearing only clears this model's data
		$this->CacheData->alias = 'DifferentModel';
		$this->dataSource->clearModelCache($this->CacheData);
		$settings = Cache::config($this->dataSource->cacheConfig);
		$results = glob($settings['settings']['path'].$settings['settings']['prefix'].'*');
		$this->assertEqual(count($results), 2);

		$this->CacheData->alias = 'CacheData';
		$this->dataSource->clearModelCache($this->CacheData);
		$settings = Cache::config($this->dataSource->cacheConfig);
		$results = glob($settings['settings']['path'].$settings['settings']['prefix'].'*');
		$this->assertEqual(count($results), 0);
	}

	function testHash() {
		$query = array(
			'conditions' => array(
				'SomeModel.name' => 'CakePHP'
			)
		);
		$this->assertTrue(is_string($this->dataSource->_key($this->CacheData, $query)));

		$anotherQuery = array(
			'conditions' => array(
				'SomeModel.name' => 'CakePHP'
			),
			'order' => 'SomeModel.name'
		);
		$this->assertNotEqual($this->dataSource->_key($this->CacheData, $anotherQuery), $this->dataSource->_key($this->CacheData, $query));
	}
}

?>