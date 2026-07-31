<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Console;

use Haltuf\RabbitMQ\Consumer\Consumer;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UnexpectedValueException;

#[AsCommand(name: 'rabbitmq:staticConsumer', description: 'Run a RabbitMQ consumer but consume just particular amount of messages')]
final class StaticConsumerCommand extends BaseConsumerCommand
{
	protected function configure(): void
	{
		$this->addArgument('consumerName', InputArgument::REQUIRED, 'Name of the consumer');
		$this->addArgument('amountOfMessages', InputArgument::REQUIRED, 'Amount of messages to consume');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$consumerName = $input->getArgument('consumerName');
		if (!is_string($consumerName)) {
			throw new UnexpectedValueException('Argument [consumerName] must be a string');
		}
		$this->validateConsumer($consumerName);

		$amountOfMessages = $input->getArgument('amountOfMessages');
		if (!is_numeric($amountOfMessages)) {
			throw new UnexpectedValueException('Argument [amountOfMessages] must be numeric');
		}
		$amountOfMessages = (int) $amountOfMessages;
		if ($amountOfMessages <= 0) {
			throw new InvalidArgumentException('Parameter [amountOfMessages] has to be greater than 0');
		}

		return $this->runConsumer(
			$consumerName,
			$output,
			static fn(Consumer $consumer) => $consumer->consume(null, $amountOfMessages),
		);
	}
}
