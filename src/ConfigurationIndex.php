<?php

namespace Zumba\ElasticsearchRotator;

use Elasticsearch\Client;
use Psr\Log\LoggerInterface;

class ConfigurationIndex
{
	const INDEX_NAME_CONFIG = '.%s_configuration';
	const TYPE_CONFIGURATION = 'configuration';
	const PRIMARY_ID = 'primary';

	/**
	 * Mapping for configuration index.
	 *
	 * @var array
	 */
	public static $elasticSearchConfigurationMapping = [
		'mappings' => [
			'configuration' => [
				'properties' => [
					'name' => ['type' => 'keyword'],
					'timestamp' => ['type' => 'date']
				]
			]
		]
	];

	/**
	 * Constructor.
	 *
	 * @param \Elasticsearch\Client $engine [description]
	 * @param \Psr\Log\LoggerInterface $logger [description]
	 * @param string $prefix
	 */
	public function __construct(Client $engine, LoggerInterface $logger, $prefix)
	{
		$this->engine = $engine;
		$this->logger = $logger;
		$this->configurationIndexName = sprintf(ConfigurationIndex::INDEX_NAME_CONFIG, $prefix);
	}

	/**
	 * Determines if the configured configuration index is available.
	 *
	 * @return boolean
	 */
	public function isConfigurationIndexAvailable()
	{
		return $this->engine->indices()->exists(['index' => $this->configurationIndexName]);
	}

	/**
	 * Create the index needed to store the primary index name.
	 *
	 * @return void
	 */
	public function createCurrentIndexConfiguration()
	{
		if ($this->isConfigurationIndexAvailable()) {
			return;
		}
		$this->engine->indices()->create([
			'index' => $this->configurationIndexName,
			'body' => static::$elasticSearchConfigurationMapping
		]);
		$this->logger->debug('Configuration index created.', [
			'index' => $this->configurationIndexName
		]);
	}

	/**
	 * Delete an entry from the configuration index.
	 *
	 * @param string $id
	 * @return array
	 */
	public function deleteConfigurationEntry($id)
	{
		return $this->engine->delete([
			'index' => $this->configurationIndexName,
			'type' => static::TYPE_CONFIGURATION,
			'id' => $id
		]);
	}

	/**
	 * String representation of this class is the index name.
	 *
	 * @return string
	 */
	public function __toString()
	{
		return $this->configurationIndexName;
	}
}
