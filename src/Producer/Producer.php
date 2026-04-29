<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Producer;

use Haltuf\RabbitMQ\Connection\Connection;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

final class Producer
{
	public const DELIVERY_MODE_NON_PERSISTENT = 1;
	public const DELIVERY_MODE_PERSISTENT = 2;

	/** @var list<callable(string, array<string, mixed>, ?string): void> */
	private array $onPublishCallbacks = [];

	public function __construct(
		private readonly Connection $connection,
		private readonly string $queueName,
		private readonly string $contentType = 'application/json',
		private readonly int $deliveryMode = self::DELIVERY_MODE_PERSISTENT,
	) {}

	/** @param callable(string, array<string, mixed>, ?string): void $callback */
	public function addOnPublishCallback(callable $callback): void
	{
		$this->onPublishCallbacks[] = $callback;
	}

	/** @param array<string, mixed> $headers */
	public function publish(string $message, array $headers = [], ?string $routingKey = null): void
	{
		$properties = [
			'content_type' => $this->contentType,
			'delivery_mode' => $this->deliveryMode,
		];

		if ($headers !== []) {
			$properties['application_headers'] = new AMQPTable($headers);
		}

		$amqpMessage = new AMQPMessage($message, $properties);
		$target = $routingKey ?? $this->queueName;

		try {
			$this->connection->getChannel()->basic_publish($amqpMessage, '', $target);
		} catch (AMQPConnectionClosedException | AMQPChannelClosedException | AMQPIOException) {
			$this->connection->reconnect();
			$this->connection->getChannel()->basic_publish($amqpMessage, '', $target);
		}

		foreach ($this->onPublishCallbacks as $callback) {
			$callback($message, $headers, $routingKey);
		}
	}
}
