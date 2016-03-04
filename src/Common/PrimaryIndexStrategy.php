<?php

namespace Zumba\ElasticsearchRotator\Common;

use Elasticsearch\Client;
use Psr\Log\LoggerInterface;

interface PrimaryIndexStrategy
{
	/**
	 * Constructor.
	 *
	 * @param \Elasticsearch\Client $engine
	 * @param \Psr\Log\LoggerInterface $logger
	 * @param array $options Options specific to this strategy
	 */
	public function __construct(Client $engine, LoggerInterface $logger, array $options = []);

	/**
	 * Get the primary index name for this configuration.
	 *
	 * @return string
	 * @throws \ElasticsearchRotator\Exceptions\MissingPrimaryException
	 */
	public function getPrimaryIndex();

	/**
	 * Get the primary index name for this configuration.
	 *
	 * @return string
	 * @throws \ElasticsearchRotator\Exceptions\MissingPrimaryException
	 */
	public function setPrimaryIndex($name);
}
