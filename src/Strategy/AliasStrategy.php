<?php

namespace Zumba\ElasticsearchRotator\Strategy;

use Zumba\ElasticsearchRotator\Common\PrimaryIndexStrategy;
use Zumba\ElasticsearchRotator\Exception;
use Elasticsearch\Client;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class AliasStrategy implements PrimaryIndexStrategy
{
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
		$this->engine = $engine;
		$this->logger = $logger ?: new NullLogger();
		if (empty($options['alias_name'])) {
			throw new \DomainException('Alias name must be specified.');
		}
		if (empty($options['index_pattern'])) {
			throw new \DomainException('Index pattern must be specified.');
		}
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
		try {
			$indexMeta = $this->engine->indices()->get([
				'index' => $this->options['alias_name']
			]);
		} catch (\Elasticsearch\Common\Exceptions\Missing404Exception $e) {
			throw new Exception\MissingPrimaryIndex('Primary index configuration index not available.');
		}
		return key($indexMeta);
	}

	/**
	 * Sets the primary index for searches using this configuration.
	 *
	 * @param string $name Index name for the primary index to use.
	 * @return void
	 */
	public function setPrimaryIndex($name)
	{
		$this->logger->debug(sprintf('Setting primary index to %s.', $name));
		$params = [
			'body' => [
				'actions' => [
					[
						'remove' => [
							'index' => $this->options['index_pattern'],
							'alias' => $this->options['alias_name']
						],
					],
					[
						'add' => [
							'index' => $name,
							'alias' => $this->options['alias_name']
						]
					]
				]
			]
		];
		try {
			$this->engine->indices()->updateAliases($params);
		} catch (\Elasticsearch\Common\Exceptions\Missing404Exception $e) {
			$this->logger->debug('No aliases matched the pattern. Retrying without the removal of old indices.');
			array_shift($params['body']['actions']);
			$this->engine->indices()->updateAliases($params);
		}

	}
}
