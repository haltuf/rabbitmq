<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Console;

use Haltuf\RabbitMQ\Queue\QueueDeclarator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'rabbitmq:declareQueuesAndExchanges', description: 'Creates all queues defined in configs. Intended to run during deploy process')]
final class DeclareCommand extends Command
{
	public function __construct(
		private readonly QueueDeclarator $queueDeclarator,
	) {
		parent::__construct();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$output->writeln('<info>Declaring queues:</info>');

		foreach ($this->queueDeclarator->getQueueNames() as $queueName) {
			$output->writeln($queueName);
			$this->queueDeclarator->declareQueue($queueName);
		}

		$output->writeln('');
		$output->writeln('<info>Declarations done!</info>');

		return 0;
	}
}
