<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Consumer;

use Haltuf\RabbitMQ\Connection\Connection;
use InvalidArgumentException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class Consumer
{
	/** @var callable */
	protected $callback;

	/** @var callable|null */
	protected $onMessage = null;

	protected int $messages = 0;

	protected int $acked = 0;

	protected int $nacked = 0;

	protected int $rejected = 0;

	protected ?int $maxMessages = null;

	public function __construct(
		protected readonly string $name,
		protected readonly Connection $connection,
		protected readonly string $queueName,
		callable $callback,
		protected readonly ?int $prefetchSize,
		protected readonly ?int $prefetchCount,
	) {
		$this->callback = $callback;
	}

	/** @param callable(AMQPMessage, int): void|null $observer called after every handled message with the callback result */
	public function setMessageObserver(?callable $observer): void
	{
		$this->onMessage = $observer;
	}

	public function getConsumedCount(): int
	{
		return $this->messages;
	}

	public function getAckedCount(): int
	{
		return $this->acked;
	}

	public function getNackedCount(): int
	{
		return $this->nacked;
	}

	public function getRejectedCount(): int
	{
		return $this->rejected;
	}

	public function consume(?int $maxSeconds = null, ?int $maxMessages = null): void
	{
		$this->maxMessages = $maxMessages;
		$this->resetCounters();
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
				$message = $this->createMessage($amqpMessage);
				$result = ($this->callback)($message);
				$this->handleResult($amqpMessage, $result);
			},
		);

		$stopTime = $maxSeconds !== null ? time() + $maxSeconds : null;

		while ($channel->is_consuming()) {
			if ($stopTime !== null) {
				$remaining = $stopTime - time();
				if ($remaining <= 0) {
					break;
				}
				try {
					$channel->wait(null, false, max(1, $remaining));
				} catch (AMQPTimeoutException) {
					break;
				}
			} else {
				try {
					$channel->wait(null, false, 30);
				} catch (AMQPTimeoutException) {
					continue;
				}
			}
		}
	}

	protected function createMessage(AMQPMessage $amqpMessage): Message
	{
		$headers = [];
		if ($amqpMessage->has('application_headers')) {
			$appHeaders = $amqpMessage->get('application_headers');
			if ($appHeaders instanceof AMQPTable) {
				$headers = $appHeaders->getNativeData();
			}
		}

		return new Message(
			content: $amqpMessage->getBody(),
			deliveryTag: (int) $amqpMessage->getDeliveryTag(),
			routingKey: $amqpMessage->getRoutingKey() ?? '',
			headers: $headers,
			redelivered: (bool) $amqpMessage->isRedelivered(),
			exchange: $amqpMessage->getExchange() ?? '',
			consumerTag: $amqpMessage->getConsumerTag() ?? '',
		);
	}

	protected function handleResult(AMQPMessage $amqpMessage, int $result): void
	{
		$channel = $this->connection->getChannel();
		$tag = $amqpMessage->getDeliveryTag();
		$consumerTag = (string) $amqpMessage->getConsumerTag();

		match ($result) {
			IConsumer::MESSAGE_ACK, IConsumer::MESSAGE_ACK_AND_TERMINATE => $channel->basic_ack($tag),
			IConsumer::MESSAGE_NACK => $channel->basic_nack($tag, false, true),
			IConsumer::MESSAGE_REJECT, IConsumer::MESSAGE_REJECT_AND_TERMINATE => $channel->basic_reject($tag, false),
			default => throw new InvalidArgumentException("Unknown return value of consumer [{$this->name}] user callback"),
		};

		if ($result === IConsumer::MESSAGE_ACK || $result === IConsumer::MESSAGE_ACK_AND_TERMINATE) {
			$this->acked++;
		} elseif ($result === IConsumer::MESSAGE_NACK) {
			$this->nacked++;
		} else {
			$this->rejected++;
		}

		if ($this->onMessage !== null) {
			($this->onMessage)($amqpMessage, $result);
		}

		if ($result === IConsumer::MESSAGE_REJECT_AND_TERMINATE || $result === IConsumer::MESSAGE_ACK_AND_TERMINATE) {
			$channel->basic_cancel($consumerTag);
		}

		if ($this->isMaxMessages()) {
			$channel->basic_cancel($consumerTag);
		}
	}

	protected function isMaxMessages(): bool
	{
		return $this->maxMessages !== null && $this->messages >= $this->maxMessages;
	}

	protected function resetCounters(): void
	{
		$this->messages = 0;
		$this->acked = 0;
		$this->nacked = 0;
		$this->rejected = 0;
	}
}
