<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Connection;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use RuntimeException;
use Throwable;

final class Connection
{
	private ?AMQPStreamConnection $connection = null;

	private ?AMQPChannel $channel = null;

	public function __construct(
		private readonly string $host,
		private readonly int $port,
		private readonly string $user,
		private readonly string $password,
		private readonly string $vhost,
		private readonly int $heartbeat,
		private readonly float $timeout,
		bool $lazy = false,
	) {
		if (!$lazy) {
			$this->connect();
		}
	}

	public function __destruct()
	{
		$this->disconnect();
	}

	public function getChannel(): AMQPChannel
	{
		$this->connectIfNeeded();

		if ($this->connection === null) {
			throw new RuntimeException('RabbitMQ connection could not be established');
		}

		if ($this->channel === null || !$this->channel->is_open()) {
			$this->channel = $this->connection->channel();
		}

		return $this->channel;
	}

	public function getConnection(): AMQPStreamConnection
	{
		$this->connectIfNeeded();

		if ($this->connection === null) {
			throw new RuntimeException('RabbitMQ connection could not be established');
		}

		return $this->connection;
	}

	public function reconnect(): void
	{
		$this->disconnect();
		$this->connect();
	}

	private function connect(): void
	{
		$this->connection = new AMQPStreamConnection(
			$this->host,
			$this->port,
			$this->user,
			$this->password,
			$this->vhost,
			false,
			'AMQPLAIN',
			null,
			'en_US',
			$this->timeout,
			$this->timeout,
			null,
			false,
			$this->heartbeat,
		);
	}

	private function connectIfNeeded(): void
	{
		if ($this->connection === null || !$this->connection->isConnected()) {
			$this->connect();
		}
	}

	private function disconnect(): void
	{
		try {
			if ($this->channel !== null && $this->channel->is_open()) {
				$this->channel->close();
			}
		} catch (Throwable) {
		}

		try {
			if ($this->connection !== null && $this->connection->isConnected()) {
				$this->connection->close();
			}
		} catch (Throwable) {
		}

		$this->channel = null;
		$this->connection = null;
	}
}
