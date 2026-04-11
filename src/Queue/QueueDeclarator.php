<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Queue;

use Haltuf\RabbitMQ\Connection\ConnectionFactory;
use InvalidArgumentException;
use PhpAmqpLib\Wire\AMQPTable;

final class QueueDeclarator
{
	/** @param array<string, array<string, mixed>> $queuesConfig */
	public function __construct(
		private readonly ConnectionFactory $connectionFactory,
		private readonly array $queuesConfig,
	) {}

	public function declareQueue(string $name): void
	{
		if (!isset($this->queuesConfig[$name])) {
			throw new InvalidArgumentException("Queue [$name] does not exist");
		}

		$data = $this->queuesConfig[$name];
		$connection = $this->connectionFactory->getConnection($data['connection']);

		$connection->getChannel()->queue_declare(
			$name,
			$data['passive'] ?? false,
			$data['durable'] ?? true,
			$data['exclusive'] ?? false,
			$data['autoDelete'] ?? false,
			$data['noWait'] ?? false,
			new AMQPTable($data['arguments'] ?? []),
		);
	}

	/** @return string[] */
	public function getQueueNames(): array
	{
		return array_keys($this->queuesConfig);
	}
}
