<?php

namespace Zumba\ElasticsearchRotator\Strategy;

use Zumba\ElasticsearchRotator\Common\PrimaryIndexStrategy;
use Zumba\ElasticsearchRotator\IndexRotator;
use Zumba\ElasticsearchRotator\ConfigurationIndex;
use Zumba\ElasticsearchRotator\Exception;
use Elasticsearch\Client;
use Psr\Log\LoggerInterface;

class ConfigurationStrategy implements PrimaryIndexStrategy
{

	/**
	 * @var \Elasticsearch\Client
	 */
	private $engine;

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	private $logger;

	/**
	 * @var array
	 */
	private $options;

	/**
	 * Constructor.
	 *
	 * @param \Elasticsearch\Client $engine
	 * @param \Psr\Log\LoggerInterface $logger
	 * @param array $options
	 * @throws \DomainException
	 */
	public function __construct(Client $engine, LoggerInterface $logger = null, array $options = [])
	{
		if (!empty($options['configuration_index']) && !$options['configuration_index'] instanceof ConfigurationIndex) {
			throw new \DomainException('Configuration index must be provided.');
		}
		$this->engine = $engine;
		$this->logger = $logger;
		$this->options = $options;
	}

	/**
	 * Get the primary index name for this configuration.
	 *
	 * @return string
	 * @throws \ElasticsearchRotator\Exceptions\MissingPrimaryException
	 */
	public function getPrimaryIndex()
	{
		if (!$this->options['configuration_index']->isConfigurationIndexAvailable()) {
			$this->logger->error('Primary index configuration index not available.');
			throw new Exception\MissingPrimaryIndex('Primary index configuration index not available.');
		}
		$primaryPayload = [
			'index' => (string)$this->options['configuration_index'],
			'type' => ConfigurationIndex::TYPE_CONFIGURATION,
			'id' => ConfigurationIndex::PRIMARY_ID,
			'preference' => '_primary'
		];
		try {
			$primary = $this->engine->get($primaryPayload);
		} catch (\Elasticsearch\Common\Exceptions\Missing404Exception $e) {
			$this->logger->error('Primary index does not exist.');
			throw new Exception\MissingPrimaryIndex('Primary index not available.');
		}
		return $primary['_source']['name'];
	}

	/**
	 * Sets the primary index for searches using this configuration.
	 *
	 * @param string $name Index name for the primary index to use.
	 * @return void
	 */
	public function setPrimaryIndex($name)
	{
		$this->options['configuration_index']->createCurrentIndexConfiguration();
		$this->engine->index([
			'index' => (string)$this->options['configuration_index'],
			'type' => ConfigurationIndex::TYPE_CONFIGURATION,
			'id' => ConfigurationIndex::PRIMARY_ID,
			'body' => [
				'name' => $name,
				'timestamp' => time()
			]
		]);
		$this->logger->debug('Primary index set.', compact('name'));
	}
}
