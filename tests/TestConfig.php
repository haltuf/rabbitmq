<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Tests;

use Haltuf\RabbitMQ\Connection\Connection;

final class TestConfig
{
	public static function createConnection(bool $lazy = false): Connection
	{
		return new Connection(
			host: self::host(),
			port: self::port(),
			user: self::user(),
			password: self::password(),
			vhost: self::vhost(),
			heartbeat: 60,
			timeout: 5,
			lazy: $lazy,
		);
	}

	public static function host(): string
	{
		return getenv('RABBITMQ_HOST') ?: 'localhost';
	}

	public static function port(): int
	{
		return (int) (getenv('RABBITMQ_PORT') ?: 5672);
	}

	public static function user(): string
	{
		return getenv('RABBITMQ_USER') ?: 'guest';
	}

	public static function password(): string
	{
		return getenv('RABBITMQ_PASSWORD') ?: 'guest';
	}

	public static function vhost(): string
	{
		return getenv('RABBITMQ_VHOST') ?: '/';
	}
}
