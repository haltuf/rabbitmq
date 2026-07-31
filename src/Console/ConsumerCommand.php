<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Console;

use Haltuf\RabbitMQ\Consumer\Consumer;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UnexpectedValueException;

#[AsCommand(name: 'rabbitmq:consumer', description: 'Run a RabbitMQ consumer')]
final class ConsumerCommand extends BaseConsumerCommand
{
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

		return $this->runConsumer(
			$consumerName,
			$output,
			static fn(Consumer $consumer) => $consumer->consume($secondsToLive),
		);
	}
}
