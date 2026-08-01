<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Console;

use Haltuf\RabbitMQ\Consumer\Consumer;
use Haltuf\RabbitMQ\Consumer\IConsumer;
use Haltuf\RabbitMQ\Consumer\Message;
use InvalidArgumentException;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class BaseConsumerCommand extends Command
{
	private const RESULT_LABELS = [
		IConsumer::MESSAGE_ACK => 'ack',
		IConsumer::MESSAGE_NACK => 'nack',
		IConsumer::MESSAGE_REJECT => 'reject',
		IConsumer::MESSAGE_ACK_AND_TERMINATE => 'ack+terminate',
		IConsumer::MESSAGE_REJECT_AND_TERMINATE => 'reject+terminate',
	];

	/** @param array<string, Consumer> $consumers */
	public function __construct(
		protected readonly array $consumers,
	) {
		parent::__construct();
	}

	protected function validateConsumer(string $name): void
	{
		if (!isset($this->consumers[$name])) {
			throw new InvalidArgumentException(
				"Consumer [$name] does not exist\n\n Available consumers: "
				. implode('', array_map(static fn($s) => "\n\t- [{$s}]", array_keys($this->consumers))),
			);
		}
	}

	/**
	 * Runs the consume loop with a per-message line in verbose mode, a summary
	 * line at the end, and a clean non-zero exit on any broker-side failure
	 * (instead of an unhandled exception with a full stack trace).
	 *
	 * A lost broker surfaces in many shapes — closed connection, closed channel,
	 * failed write, protocol error on an ack with a stale delivery tag, server-side
	 * consumer cancel — so the whole php-amqplib exception family is caught and the
	 * concrete class is printed instead of a stack trace.
	 *
	 * @param callable(Consumer): void $consume
	 */
	protected function runConsumer(string $name, OutputInterface $output, callable $consume): int
	{
		$consumer = $this->consumers[$name];

		if ($output->isVerbose()) {
			$consumer->setMessageObserver(static function (Message $message, int $result) use ($output): void {
				$output->writeln(sprintf(
					'[%s] %s %s (%d B)',
					date('H:i:s'),
					self::RESULT_LABELS[$result] ?? (string) $result,
					$message->routingKey,
					strlen($message->content),
				));
			});
		}

		$start = time();
		$exitCode = self::SUCCESS;

		try {
			$consume($consumer);
		} catch (AMQPExceptionInterface $e) {
			$errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
			$errorOutput->writeln(sprintf(
				'<error>Consumer stopped by RabbitMQ error [%s]: %s</error>',
				$e::class,
				$e->getMessage(),
			));
			$exitCode = self::FAILURE;
		} finally {
			$output->writeln(sprintf(
				'Consumed %d messages (%d acked, %d nacked, %d rejected) in %d s',
				$consumer->getConsumedCount(),
				$consumer->getAckedCount(),
				$consumer->getNackedCount(),
				$consumer->getRejectedCount(),
				time() - $start,
			));
		}

		return $exitCode;
	}
}
