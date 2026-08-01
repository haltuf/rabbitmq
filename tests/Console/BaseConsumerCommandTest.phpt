<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Tests\Console;

require __DIR__ . '/../bootstrap.php';

use Haltuf\RabbitMQ\Connection\Connection;
use Haltuf\RabbitMQ\Console\ConsumerCommand;
use Haltuf\RabbitMQ\Console\StaticConsumerCommand;
use Haltuf\RabbitMQ\Consumer\Consumer;
use Haltuf\RabbitMQ\Consumer\IConsumer;
use Haltuf\RabbitMQ\Consumer\Message;
use Haltuf\RabbitMQ\Tests\TestConfig;
use InvalidArgumentException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Tester\Assert;
use Tester\TestCase;
use Throwable;

class BaseConsumerCommandTest extends TestCase
{
	// The consume loop is replaced by a stub, so no broker is needed — this test covers
	// the command layer only (summary line, verbose lines, exit codes, error routing).
	private function createConsumer(?Throwable $failure = null): Consumer
	{
		return new class (TestConfig::createConnection(lazy: true), $failure) extends Consumer {
			public function __construct(Connection $connection, private readonly ?Throwable $failure)
			{
				parent::__construct('test', $connection, 'test_queue', static fn (): int => IConsumer::MESSAGE_ACK, null, null);
			}

			public function consume(?int $maxSeconds = null, ?int $maxMessages = null): void
			{
				$this->resetCounters();
				$this->messages = 2;
				$this->acked = 1;
				$this->rejected = 1;

				if ($this->onMessage !== null) {
					($this->onMessage)(new Message('hello', 1, 'shop.created'), IConsumer::MESSAGE_ACK);
				}

				if ($this->failure !== null) {
					throw $this->failure;
				}
			}
		};
	}

	public function testPrintsSummaryAndSucceeds(): void
	{
		$tester = new CommandTester(new ConsumerCommand(['events' => $this->createConsumer()]));
		$exitCode = $tester->execute(['consumerName' => 'events', 'secondsToLive' => '5']);

		Assert::same(Command::SUCCESS, $exitCode);
		Assert::contains('Consumed 2 messages (1 acked, 0 nacked, 1 rejected)', $tester->getDisplay());
	}

	public function testVerboseOutputPrintsLinePerMessage(): void
	{
		$tester = new CommandTester(new ConsumerCommand(['events' => $this->createConsumer()]));
		$tester->execute(
			['consumerName' => 'events', 'secondsToLive' => '5'],
			['verbosity' => OutputInterface::VERBOSITY_VERBOSE],
		);

		Assert::match('%A%ack shop.created (5 B)%A%', $tester->getDisplay());
	}

	public function testNonVerboseOutputHasNoPerMessageLine(): void
	{
		$tester = new CommandTester(new ConsumerCommand(['events' => $this->createConsumer()]));
		$tester->execute(['consumerName' => 'events', 'secondsToLive' => '5']);

		Assert::notContains('shop.created', $tester->getDisplay());
	}

	public function testBrokerFailureExitsCleanlyWithSummary(): void
	{
		$failure = new AMQPRuntimeException('fwrite(): Send of 21 bytes failed with errno=104 Connection reset by peer');
		$tester = new CommandTester(new StaticConsumerCommand(['events' => $this->createConsumer($failure)]));

		$exitCode = $tester->execute(
			['consumerName' => 'events', 'amountOfMessages' => '10'],
			['capture_stderr_separately' => true],
		);

		Assert::same(Command::FAILURE, $exitCode);
		Assert::contains(
			'Consumer stopped by RabbitMQ error [PhpAmqpLib\Exception\AMQPRuntimeException]: fwrite()',
			$tester->getErrorOutput(),
		);
		Assert::contains('Consumed 2 messages (1 acked, 0 nacked, 1 rejected)', $tester->getDisplay());
	}

	public function testUnknownConsumerIsRejectedBeforeRunning(): void
	{
		$tester = new CommandTester(new ConsumerCommand(['events' => $this->createConsumer()]));

		Assert::exception(
			static fn () => $tester->execute(['consumerName' => 'missing']),
			InvalidArgumentException::class,
			'Consumer [missing] does not exist%A%',
		);
	}
}

(new BaseConsumerCommandTest())->run();
