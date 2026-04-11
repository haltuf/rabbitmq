<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Tests\Connection;

require __DIR__ . '/../bootstrap.php';

use Haltuf\RabbitMQ\Connection\Connection;
use Haltuf\RabbitMQ\Tests\TestConfig;
use Tester\Assert;
use Tester\TestCase;

class ConnectionTest extends TestCase
{
	public function testLazyConnectionDoesNotConnectImmediately(): void
	{
		$conn = TestConfig::createConnection(lazy: true);
		Assert::type(Connection::class, $conn);
	}

	public function testGetChannelOpensConnection(): void
	{
		$conn = TestConfig::createConnection();
		$channel = $conn->getChannel();
		Assert::true($channel->is_open());
	}

	public function testGetConnectionReturnsConnectedInstance(): void
	{
		$conn = TestConfig::createConnection();
		$conn->getChannel();
		Assert::true($conn->getConnection()->isConnected());
	}

	public function testReconnectEstablishesNewConnection(): void
	{
		$conn = TestConfig::createConnection();
		$conn->getChannel();
		$conn->reconnect();
		Assert::true($conn->getConnection()->isConnected());
		Assert::true($conn->getChannel()->is_open());
	}

	public function testEagerConnectionConnectsImmediately(): void
	{
		$conn = TestConfig::createConnection(lazy: false);
		Assert::true($conn->getConnection()->isConnected());
	}

	public function testInvalidHostThrows(): void
	{
		$conn = new Connection(
			host: 'nonexistent-host',
			port: 5672,
			user: 'test',
			password: 'test',
			vhost: '/',
			heartbeat: 60,
			timeout: 2,
			lazy: true,
		);
		Assert::exception(
			fn () => $conn->getChannel(),
			\Throwable::class,
		);
	}
}

(new ConnectionTest())->run();
