<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Consumer;

use Haltuf\RabbitMQ\Connection\Connection;
use InvalidArgumentException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;
use UnexpectedValueException;

class BulkConsumer extends Consumer
{
	/** @var AMQPMessage[] */
	private array $buffer = [];

	private ?int $stopTime = null;

	public function __construct(
		string $name,
		Connection $connection,
		string $queueName,
		callable $callback,
		?int $prefetchSize,
		?int $prefetchCount,
		private readonly int $bulkSize,
		private readonly int $bulkTime,
	) {
		parent::__construct($name, $connection, $queueName, $callback, $prefetchSize, $prefetchCount);

		if ($bulkSize <= 0 || $bulkTime <= 0) {
			throw new InvalidArgumentException('Configuration values bulkSize and bulkTime must have value greater than zero');
		}
	}

	public function consume(?int $maxSeconds = null, ?int $maxMessages = null): void
	{
		$this->maxMessages = $maxMessages;
		$this->resetCounters();
		$this->buffer = [];
		$this->stopTime = ($maxSeconds !== null && $maxSeconds > 0) ? time() + $maxSeconds : null;

		$channel = $this->connection->getChannel();

		if ($this->prefetchSize !== null || $this->prefetchCount !== null) {
			$channel->basic_qos(
				$this->prefetchSize ?? 0,
				$this->prefetchCount ?? 0,
				false,
			);
		}

		$channel->basic_consume(
			$this->queueName,
			'',
			false,
			false,
			false,
			false,
			function (AMQPMessage $amqpMessage): void {
				$this->messages++;
				$this->buffer[] = $amqpMessage;
			},
		);

		do {
			try {
				$channel->wait(null, false, $this->getTtl());
			} catch (AMQPTimeoutException) {
			}

			if (count($this->buffer) >= $this->bulkSize || $this->isStopTime() || $this->isMaxMessages()) {
				$this->processBuffer();
			}
		} while (!$this->isStopTime() && !$this->isMaxMessages());

		$this->processBuffer();
	}

	private function processBuffer(): void
	{
		if ($this->buffer === []) {
			return;
		}

		$messages = [];
		foreach ($this->buffer as $amqpMessage) {
			$message = $this->createMessage($amqpMessage);
			$messages[$message->deliveryTag] = $message;
		}

		try {
			$result = ($this->callback)($messages);
		} catch (Throwable) {
			$result = array_map(static fn() => IConsumer::MESSAGE_NACK, $messages);
		}

		if (!is_array($result)) {
			$nackResult = array_map(static fn() => IConsumer::MESSAGE_NACK, $messages);
			$this->sendResults($nackResult);
			$this->buffer = [];
			throw new UnexpectedValueException(
				'Unexpected result from consumer. Expected array(delivery_tag => MESSAGE_STATUS) but got ' . gettype($result),
			);
		}

		$result = array_map('intval', $result);
		$this->sendResults($result);
		$this->buffer = [];
	}

	/** @param array<int, int> $result */
	private function sendResults(array $result): void
	{
		foreach ($this->buffer as $amqpMessage) {
			$deliveryTag = (int) $amqpMessage->getDeliveryTag();
			$status = $result[$deliveryTag] ?? IConsumer::MESSAGE_NACK;
			$this->handleResult($amqpMessage, $status);
		}
	}

	private function isStopTime(): bool
	{
		return $this->stopTime !== null && $this->stopTime < time();
	}

	private function getTtl(): int
	{
		if ($this->stopTime !== null && $this->stopTime > 0) {
			return max(1, min($this->bulkTime, $this->stopTime - time()));
		}

		return $this->bulkTime;
	}
}
