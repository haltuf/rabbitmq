<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Console;

use Haltuf\RabbitMQ\Consumer\Consumer;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'rabbitmq:staticConsumer', description: 'Run a RabbitMQ consumer but consume just particular amount of messages')]
final class StaticConsumerCommand extends Command
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
		$this->addArgument('amountOfMessages', InputArgument::REQUIRED, 'Amount of messages to consume');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$consumerName = (string) $input->getArgument('consumerName');
		$this->validateConsumer($consumerName);

		$amountOfMessages = (int) $input->getArgument('amountOfMessages');
		if ($amountOfMessages <= 0) {
			throw new InvalidArgumentException('Parameter [amountOfMessages] has to be greater than 0');
		}

		$this->consumers[$consumerName]->consume(null, $amountOfMessages);

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
