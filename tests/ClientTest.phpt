<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Tests;

require __DIR__ . '/bootstrap.php';

use Haltuf\RabbitMQ\Client;
use Haltuf\RabbitMQ\Connection\Connection;
use Haltuf\RabbitMQ\Consumer\Consumer;
use Haltuf\RabbitMQ\Consumer\IConsumer;
use Haltuf\RabbitMQ\Producer\Producer;
use Tester\Assert;
use Tester\TestCase;

class ClientTest extends TestCase
{
	private Connection $connection;
	private string $testQueue;

	public function setUp(): void
	{
		$this->connection = TestConfig::createConnection();
		$this->testQueue = 'test_client_' . uniqid();
		$this->connection->getChannel()->queue_declare($this->testQueue, false, false, false, true);
	}

	public function tearDown(): void
	{
		$this->connection->getChannel()->queue_delete($this->testQueue);
	}

	public function testGetProducerReturnsProducer(): void
	{
		$producer = new Producer($this->connection, $this->testQueue);
		$client = new Client(['myProducer' => $producer]);

		Assert::same($producer, $client->getProducer('myProducer'));
	}

	public function testGetProducerThrowsForUnknown(): void
	{
		$client = new Client([]);

		Assert::exception(
			fn () => $client->getProducer('nonExistent'),
			\InvalidArgumentException::class,
			'Producer [nonExistent] does not exist',
		);
	}

	public function testPublishViaClient(): void
	{
		$producer = new Producer($this->connection, $this->testQueue);
		$client = new Client(['testProducer' => $producer]);

		$client->getProducer('testProducer')->publish('{"via":"client"}');

		$msg = $this->connection->getChannel()->basic_get($this->testQueue, true);
		Assert::notNull($msg);
		Assert::same('{"via":"client"}', $msg->getBody());
	}

	public function testGetConsumerReturnsConsumer(): void
	{
		$consumer = new Consumer(
			'myConsumer',
			$this->connection,
			$this->testQueue,
			static fn (): int => IConsumer::MESSAGE_ACK,
			null,
			null,
		);
		$client = new Client([], ['myConsumer' => $consumer]);

		Assert::same($consumer, $client->getConsumer('myConsumer'));
	}

	public function testGetConsumerThrowsForUnknown(): void
	{
		$client = new Client([]);

		Assert::exception(
			fn () => $client->getConsumer('nonExistent'),
			\InvalidArgumentException::class,
			'Consumer [nonExistent] does not exist',
		);
	}

	public function testMultipleProducers(): void
	{
		$queue2 = $this->testQueue . '_2';
		$this->connection->getChannel()->queue_declare($queue2, false, false, false, true);

		$p1 = new Producer($this->connection, $this->testQueue);
		$p2 = new Producer($this->connection, $queue2);
		$client = new Client(['p1' => $p1, 'p2' => $p2]);

		$client->getProducer('p1')->publish('msg1');
		$client->getProducer('p2')->publish('msg2');

		$m1 = $this->connection->getChannel()->basic_get($this->testQueue, true);
		$m2 = $this->connection->getChannel()->basic_get($queue2, true);
		Assert::same('msg1', $m1->getBody());
		Assert::same('msg2', $m2->getBody());

		$this->connection->getChannel()->queue_delete($queue2);
	}
}

(new ClientTest())->run();
