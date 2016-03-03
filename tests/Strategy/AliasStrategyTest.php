<?php

use \Zumba\ElasticsearchRotator\Strategy\AliasStrategy;
use \Zumba\PHPUnit\Extensions\ElasticSearch\Client\Connector;
use \Zumba\PHPUnit\Extensions\ElasticSearch\DataSet\DataSet;

class AliasStrategyTest extends \PHPUnit_Framework_TestCase
{
	use \Zumba\PHPUnit\Extensions\ElasticSearch\TestTrait;

	public function setUp() {
		$this->aliasStrategy = new AliasStrategy($this->getElasticSearchConnector()->getConnection(), null, [
			'alias_name' => 'some_alias',
			'index_pattern' => 'some_index_*'
		]);
	}

	public function getElasticSearchConnector()
	{
		if (empty($this->connection)) {
			$clientBuilder = \Elasticsearch\ClientBuilder::create();
			if (getenv('ES_TEST_HOST')) {
				$clientBuilder->setHosts([getenv('ES_TEST_HOST')]);
			}
			$this->connection = new Connector($clientBuilder->build());
		}
		return $this->connection;
	}

	public function getElasticSearchDataSet()
	{
		$dataSet = new DataSet($this->getElasticSearchConnector());
		$dataSet->setFixture([
			'some_index_1' => [],
			'some_index_2' => [],
		]);
		return $dataSet;
	}

	public function testGetPrimaryIndex()
	{
		// Assume an alias already exists.
		$this->getElasticSearchConnector()->getConnection()->indices()->updateAliases([
			'body' => [
				'actions' => [[
					'add' => [
						'index' => 'some_index_1',
						'alias' => 'some_alias'
					]
				]]
			]
		]);
		$this->assertEquals('some_index_1', $this->aliasStrategy->getPrimaryIndex());
	}

	/**
	 * @expectedException Zumba\ElasticsearchRotator\Exception\MissingPrimaryIndex
	 */
	public function testFailingToRetreivePrimaryIndex() {
		$this->aliasStrategy->getPrimaryIndex();
	}

	public function testSetPrimaryIndex()
	{
		$this->aliasStrategy->setPrimaryIndex('some_index_2');
		$this->assertEquals('some_index_2', $this->aliasStrategy->getPrimaryIndex());
	}
}
