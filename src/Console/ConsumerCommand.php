<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Console;

use Haltuf\RabbitMQ\Consumer\Consumer;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UnexpectedValueException;

#[AsCommand(name: 'rabbitmq:consumer', description: 'Run a RabbitMQ consumer')]
final class ConsumerCommand extends Command
{
	/** @param array<string, Consumer> $consumers */
	public function __construct(
		private readonly array $consumers,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->addArgument('consumerName', InputArgument::REQUIRED, 'Name of the consumer');
		$this->addArgument('secondsToLive', InputArgument::OPTIONAL, 'Max seconds for consumer to run');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$consumerName = $input->getArgument('consumerName');
		if (!is_string($consumerName)) {
			throw new UnexpectedValueException('Argument [consumerName] must be a string');
		}
		$this->validateConsumer($consumerName);

		$secondsToLive = $input->getArgument('secondsToLive');
		if ($secondsToLive !== null) {
			if (!is_numeric($secondsToLive)) {
				throw new UnexpectedValueException('Argument [secondsToLive] must be numeric');
			}
			$secondsToLive = (int) $secondsToLive;
			if ($secondsToLive <= 0) {
				throw new InvalidArgumentException('Parameter [secondsToLive] has to be greater than 0');
			}
		}

		$this->consumers[$consumerName]->consume($secondsToLive);

		return 0;
	}

	private function validateConsumer(string $name): void
	{
		if (!isset($this->consumers[$name])) {
			throw new InvalidArgumentException(
				"Consumer [$name] does not exist\n\n Available consumers: "
				. implode('', array_map(static fn($s) => "\n\t- [{$s}]", array_keys($this->consumers))),
			);
		}
	}
}
