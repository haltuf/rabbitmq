<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ;

use Haltuf\RabbitMQ\Consumer\Consumer;
use Haltuf\RabbitMQ\Producer\Producer;
use InvalidArgumentException;

final class Client
{
	/**
	 * @param array<string, Producer> $producers
	 * @param array<string, Consumer> $consumers
	 */
	public function __construct(
		private readonly array $producers,
		private readonly array $consumers = [],
	) {}

	public function getProducer(string $name): Producer
	{
		if (!isset($this->producers[$name])) {
			throw new InvalidArgumentException("Producer [$name] does not exist");
		}

		return $this->producers[$name];
	}

	/** @return array<string, Producer> */
	public function getProducers(): array
	{
		return $this->producers;
	}

	public function getConsumer(string $name): Consumer
	{
		if (!isset($this->consumers[$name])) {
			throw new InvalidArgumentException("Consumer [$name] does not exist");
		}

		return $this->consumers[$name];
	}
}
