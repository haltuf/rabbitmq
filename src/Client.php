<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ;

use Haltuf\RabbitMQ\Producer\Producer;
use InvalidArgumentException;

final class Client
{
	/** @param array<string, Producer> $producers */
	public function __construct(
		private readonly array $producers,
	) {}

	public function getProducer(string $name): Producer
	{
		if (!isset($this->producers[$name])) {
			throw new InvalidArgumentException("Producer [$name] does not exist");
		}

		return $this->producers[$name];
	}
}
