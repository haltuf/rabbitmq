<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Tests\Producer;

require __DIR__ . '/../bootstrap.php';

use Haltuf\RabbitMQ\Connection\Connection;
use Haltuf\RabbitMQ\Producer\Producer;
use Haltuf\RabbitMQ\Tests\TestConfig;
use Tester\Assert;
use Tester\TestCase;

class ProducerTest extends TestCase
{
	private Connection $connection;
	private string $testQueue;

	public function setUp(): void
	{
		$this->connection = TestConfig::createConnection();
		$this->testQueue = 'test_producer_' . uniqid();
		$this->connection->getChannel()->queue_declare($this->testQueue, false, false, false, true);
	}

	public function tearDown(): void
	{
		$this->connection->getChannel()->queue_delete($this->testQueue);
	}

	public function testPublishJsonMessage(): void
	{
		$producer = new Producer($this->connection, $this->testQueue);
		$producer->publish(json_encode(['key' => 'value']));

		$msg = $this->connection->getChannel()->basic_get($this->testQueue, true);
		Assert::notNull($msg);
		Assert::same('{"key":"value"}', $msg->getBody());
	}

	public function testPublishMultipleMessages(): void
	{
		$producer = new Producer($this->connection, $this->testQueue);
		for ($i = 0; $i < 3; $i++) {
			$producer->publish(json_encode(['index' => $i]));
		}

		for ($i = 0; $i < 3; $i++) {
			$msg = $this->connection->getChannel()->basic_get($this->testQueue, true);
			Assert::notNull($msg);
			Assert::same(json_encode(['index' => $i]), $msg->getBody());
		}
	}

	public function testPublishWithPersistentDeliveryMode(): void
	{
		$producer = new Producer($this->connection, $this->testQueue, 'application/json', Producer::DELIVERY_MODE_PERSISTENT);
		$producer->publish('test');

		$msg = $this->connection->getChannel()->basic_get($this->testQueue, true);
		Assert::notNull($msg);
		Assert::same(2, $msg->get('delivery_mode'));
	}

	public function testPublishWithCustomRoutingKey(): void
	{
		$otherQueue = $this->testQueue . '_other';
		$this->connection->getChannel()->queue_declare($otherQueue, false, false, false, true);

		$producer = new Producer($this->connection, $this->testQueue);
		$producer->publish('routed message', [], $otherQueue);

		$msg = $this->connection->getChannel()->basic_get($otherQueue, true);
		Assert::notNull($msg);
		Assert::same('routed message', $msg->getBody());

		$original = $this->connection->getChannel()->basic_get($this->testQueue, true);
		Assert::null($original);

		$this->connection->getChannel()->queue_delete($otherQueue);
	}

	public function testPublishWithHeaders(): void
	{
		$producer = new Producer($this->connection, $this->testQueue);
		$producer->publish('test', ['x-custom' => 'header-value']);

		$msg = $this->connection->getChannel()->basic_get($this->testQueue, true);
		Assert::notNull($msg);
		$headers = $msg->get('application_headers')->getNativeData();
		Assert::same('header-value', $headers['x-custom']);
	}

	public function testContentTypeIsApplicationJson(): void
	{
		$producer = new Producer($this->connection, $this->testQueue);
		$producer->publish('{}');

		$msg = $this->connection->getChannel()->basic_get($this->testQueue, true);
		Assert::same('application/json', $msg->get('content_type'));
	}

	public function testPublishReconnectsAfterDeadConnection(): void
	{
		$producer = new Producer($this->connection, $this->testQueue);

		$amqpConnection = $this->connection->getConnection();
		$ioRef = new \ReflectionProperty($amqpConnection, 'io');
		$io = $ioRef->getValue($amqpConnection);
		$io->close();

		$producer->publish('after-reconnect');

		$msg = $this->connection->getChannel()->basic_get($this->testQueue, true);
		Assert::notNull($msg);
		Assert::same('after-reconnect', $msg->getBody());
	}

	public function testOnPublishCallbackReceivesMessageHeadersAndRoutingKey(): void
	{
		$producer = new Producer($this->connection, $this->testQueue);

		/** @var list<array{string, array<string, mixed>, ?string}> $captured */
		$captured = [];
		$producer->addOnPublishCallback(
			function (string $message, array $headers, ?string $routingKey) use (&$captured): void {
				$captured[] = [$message, $headers, $routingKey];
			},
		);

		$producer->publish('payload', ['x-foo' => 'bar'], 'custom-key');
		$this->connection->getChannel()->queue_delete('custom-key');

		Assert::count(1, $captured);
		Assert::same('payload', $captured[0][0]);
		Assert::same(['x-foo' => 'bar'], $captured[0][1]);
		Assert::same('custom-key', $captured[0][2]);
	}

	public function testMultipleOnPublishCallbacksAreInvokedInOrder(): void
	{
		$producer = new Producer($this->connection, $this->testQueue);
		$calls = [];
		$producer->addOnPublishCallback(function () use (&$calls): void {
			$calls[] = 'first';
		});
		$producer->addOnPublishCallback(function () use (&$calls): void {
			$calls[] = 'second';
		});

		$producer->publish('a');

		Assert::same(['first', 'second'], $calls);
	}
}

(new ProducerTest())->run();
