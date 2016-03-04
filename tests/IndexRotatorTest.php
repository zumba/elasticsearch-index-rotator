<?php

use \Zumba\ElasticsearchRotator\IndexRotator;
use \Zumba\ElasticsearchRotator\ConfigurationIndex;
use \Zumba\PHPUnit\Extensions\ElasticSearch\Client\Connector;
use \Zumba\PHPUnit\Extensions\ElasticSearch\DataSet\DataSet;

class IndexRotatorTest extends \PHPUnit_Framework_TestCase
{
	use \Zumba\PHPUnit\Extensions\ElasticSearch\TestTrait;

	public function setUp() {
		$this->indexRotator = new IndexRotator($this->getElasticSearchConnector()->getConnection(), 'config_test');
		parent::setUp();
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
			'.config_test_configuration' => [
				'configuration' => [
					[
						'id' => 'primary',
						'name' => 'some_index_1',
						'timestamp' => time()
					],
					[
						'id' => 'somesecondary2',
						'name' => 'some_index_3',
						'timestamp' => (new \DateTime('2015-02-01'))->format('U')
					],
					[
						'id' => 'somesecondary1',
						'name' => 'some_index_2',
						'timestamp' => (new \DateTime('2015-01-15'))->format('U')
					],
				]
			],
			'some_index_1' => [],
			'some_index_2' => [],
			'some_index_3' => [],
		]);
		$dataSet->setMappings([
			'.config_test_configuration' => ConfigurationIndex::$elasticSearchConfigurationMapping['mappings']
		]);
		return $dataSet;
	}

	public function testGetPrimaryIndex()
	{
		$this->assertEquals('some_index_1', $this->indexRotator->getPrimaryIndex());
	}

	/**
	 * @expectedException Zumba\ElasticsearchRotator\Exception\MissingPrimaryIndex
	 */
	public function testFailingToRetreivePrimaryIndex() {
		// Remove the fixtured primary index.
		$this->elasticSearchTearDown();
		$this->indexRotator->getPrimaryIndex();
	}

	public function testSetPrimaryIndex()
	{
		$this->indexRotator->setPrimaryIndex('some_index_2');
		$this->assertEquals('some_index_2', $this->indexRotator->getPrimaryIndex());
	}

	public function testCopyPrimaryIndexToSecondary()
	{
		$id = $this->indexRotator->copyPrimaryIndexToSecondary();
		$this->assertNotEquals('primary', $id);
		$results = $this->getElasticSearchConnector()->getConnection()->get([
			'index' => '.config_test_configuration',
			'type' => 'configuration',
			'id' => $id
		]);
		$this->assertEquals('some_index_1', $results['_source']['name']);
	}

	/**
	 * @dataProvider secondaryIndexConditionProvider
	 */
	public function testGetSecondaryIndices($olderThan, $expectedIndices)
	{
		$results = $this->indexRotator->getSecondaryIndices($olderThan);
		$this->assertEmpty(array_diff($results, $expectedIndices));
	}

	/**
	 * @dataProvider secondaryIndexConditionProvider
	 */
	public function testGetSecondaryIndicesIncludeIds($olderThan, $expectedIndices, $expectedConfigurationIds)
	{
		$results = $this->indexRotator->getSecondaryIndices($olderThan, IndexRotator::SECONDARY_INCLUDE_ID);
		$this->assertEmpty(array_diff(array_column($results, 'index'), $expectedIndices));
		$this->assertEmpty(array_diff(array_column($results, 'configuration_id'), $expectedConfigurationIds));
	}

	/**
	 * @dataProvider secondaryIndexConditionProvider
	 */
	public function testDeleteSecondaryIndices($olderThan, $expectedToDelete)
	{
		$results = $this->indexRotator->deleteSecondaryIndices($olderThan);
		$this->assertEmpty(array_diff(array_keys($results), $expectedToDelete));
		foreach ($results as $result) {
			$this->assertEquals(['acknowledged' => true], $result['index']);
			$this->assertTrue($result['config']['found']);
		}
	}

	public function secondaryIndexConditionProvider()
	{
		return [
			'all' => [null, ['some_index_3', 'some_index_2'], ['somesecondary2', 'somesecondary1']],
			'older than 2015-02-01' => [new \DateTime('2015-02-01'), ['some_index_2'], ['somesecondary1']],
			'older than 2015-01-01' => [new \DateTime('2015-01-01'), [], []]
		];
	}
}
