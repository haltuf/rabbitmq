<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Producer;

use Haltuf\RabbitMQ\Connection\Connection;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

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

	/**
	 * Callback is invoked after a successful basic_publish (including after auto-reconnect retry).
	 * Exceptions thrown by the callback are swallowed and logged via error_log() — they must not
	 * abort publish() because the message has already been delivered to the broker.
	 *
	 * @param callable(string, array<string, mixed>, ?string): void $callback
	 */
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
			// Callback failure must not propagate — message has already been published.
			try {
				$callback($message, $headers, $routingKey);
			} catch (Throwable $e) {
				error_log('haltuf/rabbitmq: on-publish callback threw ' . $e::class . ': ' . $e->getMessage());
			}
		}
	}
}
