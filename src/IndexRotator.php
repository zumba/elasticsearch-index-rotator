<?php

namespace Zumba\ElasticsearchRotator;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Elasticsearch\Client;
use Zumba\ElasticsearchRotator\Common\PrimaryIndexStrategy;

class IndexRotator
{
	const SECONDARY_NAME_ONLY = 0;
	const SECONDARY_INCLUDE_ID = 1;
	const RETRY_TIME_COPY = 500000;
	const MAX_RETRY_COUNT = 5;
	const STRATEGY_CONFIGURATION = 'Zumba\ElasticsearchRotator\Strategy\ConfigurationStrategy';
	const STRATEGY_ALIAS = 'Zumba\ElasticsearchRotator\Strategy\AliasStrategy';
	const DEFAULT_PRIMARY_INDEX_STRATEGY = self::STRATEGY_CONFIGURATION;

	/**
	 * Elasticsearch client instance.
	 *
	 * @var \Elasticsearch\Client
	 */
	private $engine;

	/**
	 * Configuration index name for this index.
	 *
	 * @var \Zumba\ElasticsearchRotator\ConfigurationIndex
	 */
	private $configurationIndex;

	/**
	 * Strategy to employ when working on primary index.
	 *
	 * @var \Zumba\ElasticsearchRotator\Common\PrimaryIndexStrategy
	 */
	private $primaryIndexStrategy;

	/**
	 * Mapping for configuration index.
	 *
	 * @var array
	 */
	public static $elasticSearchConfigurationMapping = [
		'mappings' => [
			'configuration' => [
				'properties' => [
					'name' => ['type' => 'string', 'index' => 'not_analyzed'],
					'timestamp' => ['type' => 'date']
				]
			]
		]
	];
	/**
	 * Constructor.
	 *
	 * @param \Elasticsearch\Client $engine
	 * @param string $prefix Identifier for who's configuration this is intended.
	 * @param Psr\Log\LoggerInterface $logger
	 */
	public function __construct(\Elasticsearch\Client $engine, $prefix, LoggerInterface $logger = null)
	{
		$this->engine = $engine;
		$this->logger = $logger ?: new NullLogger();
		$this->configurationIndex = new ConfigurationIndex($this->engine, $this->logger, $prefix);
		$this->setPrimaryIndexStrategy($this->strategyFactory(static::DEFAULT_PRIMARY_INDEX_STRATEGY, [
			'configuration_index' => $this->configurationIndex
		]));
	}

	/**
	 * Instantiate a specific strategy.
	 *
	 * @param string $strategyClass Fully qualified class name for a strategy.
	 * @param array $options Options specific to the strategy
	 * @return \Zumba\ElasticsearchRotator\Common\PrimaryIndexStrategy
	 */
	public function strategyFactory($strategyClass, array $options = [])
	{
		return new $strategyClass($this->engine, $this->logger, $options);
	}

	/**
	 * Set the primary index strategy.
	 *
	 * @param \Zumba\ElasticsearchRotator\Common\PrimaryIndexStrategy $strategy
	 */
	public function setPrimaryIndexStrategy(PrimaryIndexStrategy $strategy)
	{
		$this->primaryIndexStrategy = $strategy;
	}

	/**
	 * Get the primary index name for this configuration.
	 *
	 * @return string
	 * @throws \ElasticsearchRotator\Exceptions\MissingPrimaryException
	 */
	public function getPrimaryIndex()
	{
		return $this->primaryIndexStrategy->getPrimaryIndex();
	}

	/**
	 * Sets the primary index for searches using this configuration.
	 *
	 * @param string $name Index name for the primary index to use.
	 * @return void
	 */
	public function setPrimaryIndex($name)
	{
		$this->primaryIndexStrategy->setPrimaryIndex($name);
	}

	/**
	 * Copy the primary index to a secondary index.
	 *
	 * @param integer $retryCount Recursive retry count for retrying the operation of this method.
	 * @return string ID of the newly created secondary entry.
	 * @throws \Zumba\ElasticsearchRotator\Exception\PrimaryIndexCopyFailure
	 */
	public function copyPrimaryIndexToSecondary($retryCount = 0)
	{
		$this->configurationIndex->createCurrentIndexConfiguration();
		try {
			$primaryName = $this->getPrimaryIndex();
		} catch (\Elasticsearch\Common\Exceptions\ServerErrorResponseException $e) {
			$this->logger->debug('Unable to get primary index.', json_decode($e->getMessage(), true));
			usleep(static::RETRY_TIME_COPY);
			if ($retryCount > static::MAX_RETRY_COUNT) {
				throw new Exception\PrimaryIndexCopyFailure('Unable to copy primary to secondary index.');
			}
			return $this->copyPrimaryIndexToSecondary($retryCount++);
		}
		$id = $this->engine->index([
			'index' => (string)$this->configurationIndex,
			'type' => ConfigurationIndex::TYPE_CONFIGURATION,
			'body' => [
				'name' => $primaryName,
				'timestamp' => time()
			]
		])['_id'];
		$this->logger->debug('Secondary entry created.', compact('id'));
		return $id;
	}

	/**
	 * Retrieve a list of all secondary indexes (rotated from) that are older than provided date (or ES date math)
	 *
	 * Note, if date is not provided, it will find all secondary indexes.
	 *
	 * @param string $olderThan
	 * @param integer $disposition Controls the return style (defaults to name only)
	 * @return array
	 */
	public function getSecondaryIndices(\DateTime $olderThan = null, $disposition = self::SECONDARY_NAME_ONLY)
	{
		if ($olderThan === null) {
			$olderThan = new \DateTime();
		}
		$params = [
			'index' => (string)$this->configurationIndex,
			'type' => ConfigurationIndex::TYPE_CONFIGURATION,
			'body' => [
				'query' => [
					'bool' => [
						'must_not' => [
							'term' => [
								'_id' => ConfigurationIndex::PRIMARY_ID
							]
						],
						'filter' => [
							'range' => [
								'timestamp' => [
									'lt' => $olderThan->format('U')
								]
							]
						]
					]
				],
				'sort' => ['_doc' => 'asc']
			]
		];
		// This section is to support deprecated feature set for ES 1.x.
		// It may be removed in future versions of this library when ES 1.x is sufficiently unsupported.
		if (!$this->doesSupportCombinedQueryFilter()) {
			$this->logger->notice('Using deprecated query format due to elasticsearch version <= 1.x.');
			unset($params['body']['query']['bool']['filter']);
			$params['body']['filter']['range']['timestamp']['lt'] = $olderThan->format('U');
		}
		$results = $this->engine->search($params);
		if ($results['hits']['total'] == 0) {
			return [];
		}
		$mapper = $disposition === static::SECONDARY_INCLUDE_ID ?
			function($entry) {
				return [
					'index' => $entry['_source']['name'],
					'configuration_id' => $entry['_id']
				];
			} :
			function($entry) {
				return $entry['_source']['name'];
			};
		return array_map($mapper, $results['hits']['hits']);
	}

	/**
	 * Remove any secondary index older that provided date.
	 *
	 * If no date is provided, will remove all secondary indices.
	 *
	 * @param \DateTime $olderThan
	 * @return array Results of the bulk operation.
	 */
	public function deleteSecondaryIndices(\DateTime $olderThan = null)
	{
		$results = [];
		foreach ($this->getSecondaryIndices($olderThan, static::SECONDARY_INCLUDE_ID) as $indexToDelete) {
			if ($this->engine->indices()->exists(['index' => $indexToDelete['index']])) {
				$results[$indexToDelete['index']] = [
					'index' => $this->engine->indices()->delete(['index' => $indexToDelete['index']]),
					'config' => $this->configurationIndex->deleteConfigurationEntry($indexToDelete['configuration_id'])
				];
				$this->logger->debug('Deleted secondary index.', compact('indexToDelete'));
			} else {
				$results[$indexToDelete] = [
					'index' => null,
					'config' => $this->configurationIndex->deleteConfigurationEntry($indexToDelete['configuration_id'])
				];
				$this->logger->debug('Index not found to delete.', compact('indexToDelete'));
			}
		}
		return $results;
	}

	/**
	 * Determines if the combined filter in query DSL is supported.
	 *
	 * @return boolean
	 */
	private function doesSupportCombinedQueryFilter()
	{
		return version_compare($this->engine->info()['version']['number'], '2.0.0', '>=');
	}
}
