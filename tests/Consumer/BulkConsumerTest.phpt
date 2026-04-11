<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Tests\Consumer;

require __DIR__ . '/../bootstrap.php';

use Haltuf\RabbitMQ\Connection\Connection;
use Haltuf\RabbitMQ\Consumer\BulkConsumer;
use Haltuf\RabbitMQ\Consumer\IConsumer;
use Haltuf\RabbitMQ\Consumer\Message;
use Haltuf\RabbitMQ\Producer\Producer;
use Haltuf\RabbitMQ\Tests\TestConfig;
use Tester\Assert;
use Tester\TestCase;

class BulkConsumerTest extends TestCase
{
	private Connection $connection;
	private string $testQueue;

	public function setUp(): void
	{
		$this->connection = TestConfig::createConnection();
		$this->testQueue = 'test_bulk_' . uniqid();
		$this->connection->getChannel()->queue_declare($this->testQueue, false, false, false, false);
	}

	public function tearDown(): void
	{
		try {
			$this->connection->reconnect();
			$this->connection->getChannel()->queue_delete($this->testQueue);
		} catch (\Throwable) {}
	}

	private function publishMessages(int $count): void
	{
		$producer = new Producer($this->connection, $this->testQueue);
		for ($i = 0; $i < $count; $i++) {
			$producer->publish(json_encode(['index' => $i]));
		}
	}

	private function createBulkConsumer(callable $callback, int $batchSize = 5, int $batchTimeout = 3): BulkConsumer
	{
		return new BulkConsumer(
			'test_bulk', $this->connection, $this->testQueue,
			$callback, 0, 10, $batchSize, $batchTimeout,
		);
	}

	public function testBulkConsumeProcessesAllMessages(): void
	{
		$this->publishMessages(5);

		$totalMessages = 0;
		$consumer = $this->createBulkConsumer(function (array $messages) use (&$totalMessages): array {
			$totalMessages += count($messages);
			$result = [];
			foreach ($messages as $msg) {
				$result[$msg->deliveryTag] = IConsumer::MESSAGE_ACK;
			}
			return $result;
		});

		$consumer->consume(maxSeconds: 5, maxMessages: 5);

		Assert::same(5, $totalMessages);
	}

	public function testBulkConsumeRespectsBatchSize(): void
	{
		$this->publishMessages(10);

		$batchSizes = [];
		$consumer = $this->createBulkConsumer(
			function (array $messages) use (&$batchSizes): array {
				$batchSizes[] = count($messages);
				$result = [];
				foreach ($messages as $msg) {
					$result[$msg->deliveryTag] = IConsumer::MESSAGE_ACK;
				}
				return $result;
			},
			batchSize: 5,
		);

		$consumer->consume(maxSeconds: 5, maxMessages: 10);

		Assert::true(count($batchSizes) >= 2);
		foreach ($batchSizes as $size) {
			Assert::true($size <= 5);
		}
		Assert::same(10, array_sum($batchSizes));
	}

	public function testBulkConsumeFlushesOnTimeout(): void
	{
		$this->publishMessages(2);

		$batches = [];
		$consumer = $this->createBulkConsumer(
			function (array $messages) use (&$batches): array {
				$batches[] = $messages;
				$result = [];
				foreach ($messages as $msg) {
					$result[$msg->deliveryTag] = IConsumer::MESSAGE_ACK;
				}
				return $result;
			},
			batchSize: 100,
			batchTimeout: 2,
		);

		$consumer->consume(maxSeconds: 5, maxMessages: 2);

		Assert::true(count($batches) >= 1);
		$total = 0;
		foreach ($batches as $batch) {
			$total += count($batch);
		}
		Assert::same(2, $total);
	}

	public function testBulkConsumeMessageDtoContents(): void
	{
		$this->publishMessages(1);

		$receivedMessages = [];
		$consumer = $this->createBulkConsumer(function (array $messages) use (&$receivedMessages): array {
			$receivedMessages = array_values($messages);
			$result = [];
			foreach ($messages as $msg) {
				$result[$msg->deliveryTag] = IConsumer::MESSAGE_ACK;
			}
			return $result;
		}, batchSize: 1);

		$consumer->consume(maxSeconds: 3, maxMessages: 1);

		Assert::count(1, $receivedMessages);
		$msg = $receivedMessages[0];
		Assert::type(Message::class, $msg);
		Assert::same('{"index":0}', $msg->content);
		Assert::type('int', $msg->deliveryTag);
	}

	public function testBulkConsumeWithMixedResults(): void
	{
		$this->publishMessages(3);

		$consumer = $this->createBulkConsumer(function (array $messages): array {
			$result = [];
			foreach ($messages as $msg) {
				$data = json_decode($msg->content, true);
				$result[$msg->deliveryTag] = $data['index'] === 1
					? IConsumer::MESSAGE_REJECT
					: IConsumer::MESSAGE_ACK;
			}
			return $result;
		}, batchSize: 3);

		$consumer->consume(maxSeconds: 5, maxMessages: 3);

		$remaining = $this->connection->getChannel()->basic_get($this->testQueue, true);
		Assert::null($remaining);
	}
}

(new BulkConsumerTest())->run();
