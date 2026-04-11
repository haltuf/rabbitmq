<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Connection;

use InvalidArgumentException;

final class ConnectionFactory
{
	/** @var array<string, Connection> */
	private array $connections = [];

	/** @param array<string, array<string, mixed>> $config */
	public function __construct(
		private readonly array $config,
	) {}

	public function getConnection(string $name): Connection
	{
		if (!isset($this->connections[$name])) {
			$this->connections[$name] = $this->create($name);
		}

		return $this->connections[$name];
	}

	private function create(string $name): Connection
	{
		if (!isset($this->config[$name])) {
			throw new InvalidArgumentException("Connection [$name] does not exist");
		}

		$data = $this->config[$name];

		return new Connection(
			$data['host'],
			(int) $data['port'],
			$data['user'],
			$data['password'],
			$data['vhost'],
			(int) ($data['heartbeat'] ?? 60),
			(float) ($data['timeout'] ?? 1),
			(bool) ($data['lazy'] ?? false),
		);
	}
}
