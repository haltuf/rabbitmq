<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Console;

use Haltuf\RabbitMQ\Consumer\Consumer;
use Haltuf\RabbitMQ\Consumer\IConsumer;
use InvalidArgumentException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

abstract class BaseConsumerCommand extends Command
{
	private const ResultLabels = [
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
	 * line at the end, and a clean non-zero exit when the broker connection is
	 * lost (instead of an unhandled exception with a full stack trace).
	 *
	 * @param callable(Consumer): void $consume
	 */
	protected function runConsumer(string $name, OutputInterface $output, callable $consume): int
	{
		$consumer = $this->consumers[$name];

		if ($output->isVerbose()) {
			$consumer->setMessageObserver(static function (AMQPMessage $message, int $result) use ($output): void {
				$output->writeln(sprintf(
					'[%s] %s %s (%d B)',
					date('H:i:s'),
					self::ResultLabels[$result] ?? (string) $result,
					$message->getRoutingKey() ?? '',
					strlen($message->getBody()),
				));
			});
		}

		$start = time();
		$exitCode = self::SUCCESS;

		try {
			$consume($consumer);
		} catch (AMQPConnectionClosedException | AMQPIOException $e) {
			$output->writeln(sprintf('<error>Connection to RabbitMQ lost: %s</error>', $e->getMessage()));
			$exitCode = self::FAILURE;
		}

		$output->writeln(sprintf(
			'Consumed %d messages (%d acked, %d nacked, %d rejected) in %d s',
			$consumer->getConsumedCount(),
			$consumer->getAckedCount(),
			$consumer->getNackedCount(),
			$consumer->getRejectedCount(),
			time() - $start,
		));

		return $exitCode;
	}
}
