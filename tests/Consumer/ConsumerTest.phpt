<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Tests\Consumer;

require __DIR__ . '/../bootstrap.php';

use Haltuf\RabbitMQ\Connection\Connection;
use Haltuf\RabbitMQ\Consumer\Consumer;
use Haltuf\RabbitMQ\Consumer\IConsumer;
use Haltuf\RabbitMQ\Consumer\Message;
use Haltuf\RabbitMQ\Producer\Producer;
use Haltuf\RabbitMQ\Tests\TestConfig;
use PhpAmqpLib\Message\AMQPMessage;
use Tester\Assert;
use Tester\TestCase;

class ConsumerTest extends TestCase
{
	private Connection $connection;
	private string $testQueue;

	public function setUp(): void
	{
		$this->connection = TestConfig::createConnection();
		$this->testQueue = 'test_consumer_' . uniqid();
		$this->connection->getChannel()->queue_declare($this->testQueue, false, false, false, false);
	}

	public function tearDown(): void
	{
		try {
			$this->connection->reconnect();
			$this->connection->getChannel()->queue_delete($this->testQueue);
		} catch (\Throwable) {}
	}

	private function publishMessage(string $body): void
	{
		$producer = new Producer($this->connection, $this->testQueue);
		$producer->publish($body);
	}

	private function createConsumer(callable $callback, ?int $prefetchCount = 1): Consumer
	{
		return new Consumer('test', $this->connection, $this->testQueue, $callback, 0, $prefetchCount);
	}

	public function testConsumeWithAck(): void
	{
		$this->publishMessage('{"test": "ack"}');

		$received = [];
		$consumer = $this->createConsumer(function (Message $msg) use (&$received): int {
			$received[] = $msg;
			return IConsumer::MESSAGE_ACK;
		});

		$consumer->consume(maxSeconds: 3, maxMessages: 1);

		Assert::count(1, $received);
		Assert::same('{"test": "ack"}', $received[0]->content);
		Assert::type('int', $received[0]->deliveryTag);
		Assert::type('string', $received[0]->routingKey);

		$this->connection->reconnect();
		$msg = $this->connection->getChannel()->basic_get($this->testQueue, true);
		Assert::null($msg);
	}

	public function testConsumeWithReject(): void
	{
		$this->publishMessage('bad message');

		$consumer = $this->createConsumer(function (Message $msg): int {
			return IConsumer::MESSAGE_REJECT;
		});

		$consumer->consume(maxSeconds: 3, maxMessages: 1);

		$msg = $this->connection->getChannel()->basic_get($this->testQueue, true);
		Assert::null($msg);
	}

	public function testConsumeWithNackRequeues(): void
	{
		$this->publishMessage('retry me');

		$attempts = 0;
		$consumer = $this->createConsumer(function (Message $msg) use (&$attempts): int {
			$attempts++;
			if ($attempts === 1) {
				return IConsumer::MESSAGE_NACK;
			}
			return IConsumer::MESSAGE_ACK;
		});

		$consumer->consume(maxSeconds: 3, maxMessages: 2);

		Assert::same(2, $attempts);
	}

	public function testConsumeMultipleMessages(): void
	{
		for ($i = 0; $i < 3; $i++) {
			$this->publishMessage(json_encode(['i' => $i]));
		}

		$received = [];
		$consumer = $this->createConsumer(function (Message $msg) use (&$received): int {
			$received[] = json_decode($msg->content, true)['i'];
			return IConsumer::MESSAGE_ACK;
		}, prefetchCount: 3);

		$consumer->consume(maxSeconds: 5, maxMessages: 3);

		Assert::same([0, 1, 2], $received);
	}

	public function testConsumeRespectsMaxSeconds(): void
	{
		$start = time();
		$consumer = $this->createConsumer(fn (Message $msg) => IConsumer::MESSAGE_ACK);
		$consumer->consume(maxSeconds: 2);
		$elapsed = time() - $start;

		Assert::true($elapsed >= 2 && $elapsed <= 5);
	}

	public function testAckAndTerminate(): void
	{
		for ($i = 0; $i < 3; $i++) {
			$this->publishMessage(json_encode(['i' => $i]));
		}

		$received = [];
		$consumer = $this->createConsumer(function (Message $msg) use (&$received): int {
			$received[] = $msg->content;
			return IConsumer::MESSAGE_ACK_AND_TERMINATE;
		});

		$consumer->consume(maxSeconds: 5);

		Assert::count(1, $received);
	}

	public function testRejectAndTerminate(): void
	{
		$this->publishMessage('terminate');

		$called = false;
		$consumer = $this->createConsumer(function (Message $msg) use (&$called): int {
			$called = true;
			return IConsumer::MESSAGE_REJECT_AND_TERMINATE;
		});

		$consumer->consume(maxSeconds: 3);

		Assert::true($called);
	}

	public function testMessageDto(): void
	{
		$this->publishMessage('dto test');

		$receivedMsg = null;
		$consumer = $this->createConsumer(function (Message $msg) use (&$receivedMsg): int {
			$receivedMsg = $msg;
			return IConsumer::MESSAGE_ACK;
		});

		$consumer->consume(maxSeconds: 3, maxMessages: 1);

		Assert::notNull($receivedMsg);
		Assert::same('dto test', $receivedMsg->content);
		Assert::type('int', $receivedMsg->deliveryTag);
		Assert::type('string', $receivedMsg->routingKey);
		Assert::type('array', $receivedMsg->headers);
		Assert::type('bool', $receivedMsg->redelivered);
		Assert::type('string', $receivedMsg->exchange);
		Assert::type('string', $receivedMsg->consumerTag);
	}

	public function testCountersTrackResults(): void
	{
		$this->publishMessage('one');
		$this->publishMessage('two');
		$this->publishMessage('three');

		$calls = 0;
		$consumer = $this->createConsumer(function (Message $msg) use (&$calls): int {
			$calls++;
			return $calls === 2 ? IConsumer::MESSAGE_REJECT : IConsumer::MESSAGE_ACK;
		});

		$consumer->consume(maxSeconds: 3, maxMessages: 3);

		Assert::same(3, $consumer->getConsumedCount());
		Assert::same(2, $consumer->getAckedCount());
		Assert::same(1, $consumer->getRejectedCount());
		Assert::same(0, $consumer->getNackedCount());

		// a fresh consume() run starts counting from zero again
		$consumer->consume(maxSeconds: 1);
		Assert::same(0, $consumer->getConsumedCount());
		Assert::same(0, $consumer->getAckedCount());
		Assert::same(0, $consumer->getRejectedCount());
	}

	public function testMessageObserverReceivesEveryHandledMessage(): void
	{
		$this->publishMessage('observed ack');
		$this->publishMessage('observed reject');

		$calls = 0;
		$consumer = $this->createConsumer(function (Message $msg) use (&$calls): int {
			$calls++;
			return $calls === 2 ? IConsumer::MESSAGE_REJECT : IConsumer::MESSAGE_ACK;
		});

		$observed = [];
		$consumer->setMessageObserver(function (AMQPMessage $message, int $result) use (&$observed): void {
			$observed[] = [$message->getBody(), $result];
		});

		$consumer->consume(maxSeconds: 3, maxMessages: 2);

		Assert::equal(
			[
				['observed ack', IConsumer::MESSAGE_ACK],
				['observed reject', IConsumer::MESSAGE_REJECT],
			],
			$observed,
		);
	}
}

(new ConsumerTest())->run();
