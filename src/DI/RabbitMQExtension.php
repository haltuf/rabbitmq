<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\DI;

use Haltuf\RabbitMQ\Client;
use Haltuf\RabbitMQ\Connection\ConnectionFactory;
use Haltuf\RabbitMQ\Console\ConsumerCommand;
use Haltuf\RabbitMQ\Console\DeclareCommand;
use Haltuf\RabbitMQ\Console\StaticConsumerCommand;
use Haltuf\RabbitMQ\Consumer\BulkConsumer;
use Haltuf\RabbitMQ\Consumer\Consumer;
use Haltuf\RabbitMQ\Producer\Producer;
use Haltuf\RabbitMQ\Queue\QueueDeclarator;
use InvalidArgumentException;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\Definitions\Statement;

final class RabbitMQExtension extends CompilerExtension
{
	/** @var array<string, mixed> */
	private array $defaults = [
		'connections' => [],
		'queues' => [],
		'producers' => [],
		'consumers' => [],
	];

	/** @var array<string, mixed> */
	private array $connectionDefaults = [
		'host' => '127.0.0.1',
		'port' => 5672,
		'user' => 'guest',
		'password' => 'guest',
		'vhost' => '/',
		'timeout' => 1,
		'heartbeat' => 60,
		'lazy' => false,
	];

	/** @var array<string, mixed> */
	private array $queueDefaults = [
		'connection' => 'default',
		'passive' => false,
		'durable' => true,
		'exclusive' => false,
		'autoDelete' => false,
		'noWait' => false,
		'arguments' => [],
	];

	/** @var array<string, mixed> */
	private array $producerDefaults = [
		'queue' => null,
		'contentType' => 'text/plain',
		'deliveryMode' => Producer::DELIVERY_MODE_PERSISTENT,
	];

	/** @var array<string, mixed> */
	private array $consumerDefaults = [
		'queue' => null,
		'callback' => null,
		'bulk' => [
			'size' => null,
			'timeout' => null,
		],
		'qos' => [
			'prefetchSize' => null,
			'prefetchCount' => null,
		],
	];

	public function loadConfiguration(): void
	{
		$config = $this->validateConfig($this->defaults);
		$builder = $this->getContainerBuilder();

		$connectionsConfig = [];
		foreach ($config['connections'] as $name => $data) {
			$connectionsConfig[$name] = $this->validateConfig($this->connectionDefaults, $data);
		}

		$builder->addDefinition($this->prefix('connectionFactory'))
			->setFactory(ConnectionFactory::class, [$connectionsConfig]);

		$queuesConfig = [];
		foreach ($config['queues'] as $name => $data) {
			$queuesConfig[$name] = $this->validateConfig($this->queueDefaults, $data);
		}

		$builder->addDefinition($this->prefix('queueDeclarator'))
			->setFactory(QueueDeclarator::class, [
				new Statement('@' . $this->prefix('connectionFactory')),
				$queuesConfig,
			]);

		foreach ($config['producers'] as $name => $producerData) {
			$producerConfig = $this->validateConfig($this->producerDefaults, $producerData);
			$queueName = $producerConfig['queue'];

			if ($queueName === null) {
				throw new InvalidArgumentException("Producer [$name] must have a queue configured");
			}

			$queueConfig = $queuesConfig[$queueName] ?? null;
			if ($queueConfig === null) {
				throw new InvalidArgumentException("Queue [$queueName] used by producer [$name] does not exist");
			}

			$builder->addDefinition($this->prefix('producer.' . $name))
				->setFactory(Producer::class, [
					new Statement('@' . $this->prefix('connectionFactory') . '::getConnection', [$queueConfig['connection']]),
					$queueName,
					$producerConfig['contentType'],
					$producerConfig['deliveryMode'],
				])
				->setAutowired(false);
		}

		$builder->addDefinition($this->prefix('client'))
			->setFactory(Client::class);

		foreach ($config['consumers'] as $name => $consumerData) {
			$consumerConfig = $this->validateConfig($this->consumerDefaults, $consumerData);
			$queueName = $consumerConfig['queue'];

			if ($queueName === null) {
				throw new InvalidArgumentException("Consumer [$name] must have a queue configured");
			}

			$queueConfig = $queuesConfig[$queueName] ?? null;
			if ($queueConfig === null) {
				throw new InvalidArgumentException("Queue [$queueName] used by consumer [$name] does not exist");
			}

			$prefetchSize = $consumerConfig['qos']['prefetchSize'];
			$prefetchCount = $consumerConfig['qos']['prefetchCount'];

			$isBulk = is_array($consumerConfig['bulk']) && !empty($consumerConfig['bulk']['size']);

			if ($isBulk) {
				$builder->addDefinition($this->prefix('consumer.' . $name))
					->setFactory(BulkConsumer::class, [
						$name,
						new Statement('@' . $this->prefix('connectionFactory') . '::getConnection', [$queueConfig['connection']]),
						$queueName,
						$consumerConfig['callback'],
						$prefetchSize,
						$prefetchCount,
						(int) $consumerConfig['bulk']['size'],
						(int) $consumerConfig['bulk']['timeout'],
					])
					->setAutowired(false);
			} else {
				$builder->addDefinition($this->prefix('consumer.' . $name))
					->setFactory(Consumer::class, [
						$name,
						new Statement('@' . $this->prefix('connectionFactory') . '::getConnection', [$queueConfig['connection']]),
						$queueName,
						$consumerConfig['callback'],
						$prefetchSize,
						$prefetchCount,
					])
					->setAutowired(false);
			}
		}

		$builder->addDefinition($this->prefix('console.consumerCommand'))
			->setFactory(ConsumerCommand::class)
			->setTags(['console.command' => 'rabbitmq:consumer']);

		$builder->addDefinition($this->prefix('console.staticConsumerCommand'))
			->setFactory(StaticConsumerCommand::class)
			->setTags(['console.command' => 'rabbitmq:staticConsumer']);

		$builder->addDefinition($this->prefix('console.declareCommand'))
			->setFactory(DeclareCommand::class)
			->setTags(['console.command' => 'rabbitmq:declareQueuesAndExchanges']);
	}

	public function beforeCompile(): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->validateConfig($this->defaults);

		$producerRefs = [];
		foreach (array_keys($config['producers']) as $name) {
			$producerRefs[$name] = $builder->getDefinition($this->prefix('producer.' . $name));
		}

		$consumerRefs = [];
		foreach (array_keys($config['consumers']) as $name) {
			$consumerRefs[$name] = $builder->getDefinition($this->prefix('consumer.' . $name));
		}

		/** @var ServiceDefinition $clientDef */
		$clientDef = $builder->getDefinition($this->prefix('client'));
		$clientDef->setArguments([$producerRefs, $consumerRefs]);

		/** @var ServiceDefinition $consumerCommandDef */
		$consumerCommandDef = $builder->getDefinition($this->prefix('console.consumerCommand'));
		$consumerCommandDef->setArguments([$consumerRefs]);

		/** @var ServiceDefinition $staticConsumerCommandDef */
		$staticConsumerCommandDef = $builder->getDefinition($this->prefix('console.staticConsumerCommand'));
		$staticConsumerCommandDef->setArguments([$consumerRefs]);
	}
}
