<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Consumer;

final readonly class Message
{
	/**
	 * @param array<string, mixed> $headers
	 */
	public function __construct(
		public string $content,
		public int $deliveryTag,
		public string $routingKey = '',
		public array $headers = [],
		public bool $redelivered = false,
		public string $exchange = '',
		public string $consumerTag = '',
	) {}
}
