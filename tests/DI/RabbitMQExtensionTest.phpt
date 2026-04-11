<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Tests\DI;

require __DIR__ . '/../bootstrap.php';

use Haltuf\RabbitMQ\Client;
use Haltuf\RabbitMQ\Connection\ConnectionFactory;
use Haltuf\RabbitMQ\DI\RabbitMQExtension;
use Haltuf\RabbitMQ\Producer\Producer;
use Haltuf\RabbitMQ\Queue\QueueDeclarator;
use Haltuf\RabbitMQ\Tests\TestConfig;
use Nette\DI\Compiler;
use Nette\DI\Container;
use Nette\DI\ContainerLoader;
use Tester\Assert;
use Tester\TestCase;

class RabbitMQExtensionTest extends TestCase
{
	private function createContainer(): Container
	{
		$tempDir = sys_get_temp_dir() . '/haltuf-rabbitmq-tests-' . uniqid();
		mkdir($tempDir);

		$host = TestConfig::host();
		$port = TestConfig::port();
		$user = TestConfig::user();
		$password = TestConfig::password();
		$vhost = TestConfig::vhost();

		$neonConfig = <<<NEON
rabbitmq:
	connections:
		default:
			host: $host
			port: $port
			user: $user
			password: $password
			vhost: $vhost
			lazy: true
	queues:
		testQueue:
			connection: default
	producers:
		testProducer:
			queue: testQueue
			contentType: application/json
NEON;

		$configFile = $tempDir . '/config.neon';
		file_put_contents($configFile, $neonConfig);

		$loader = new ContainerLoader($tempDir, autoRebuild: true);
		$class = $loader->load(function (Compiler $compiler) use ($configFile): void {
			$compiler->addExtension('rabbitmq', new RabbitMQExtension());
			$compiler->loadConfig($configFile);
		});

		return new $class();
	}

	public function testClientIsRegistered(): void
	{
		$client = $this->createContainer()->getByType(Client::class);
		Assert::type(Client::class, $client);
	}

	public function testConnectionFactoryIsRegistered(): void
	{
		$factory = $this->createContainer()->getByType(ConnectionFactory::class);
		Assert::type(ConnectionFactory::class, $factory);
	}

	public function testQueueDeclaratorIsRegistered(): void
	{
		$declarator = $this->createContainer()->getByType(QueueDeclarator::class);
		Assert::type(QueueDeclarator::class, $declarator);
	}

	public function testConfiguredProducerAvailable(): void
	{
		$client = $this->createContainer()->getByType(Client::class);
		Assert::type(Producer::class, $client->getProducer('testProducer'));
	}

	public function testConnectionFactoryReturnsSameInstance(): void
	{
		$factory = $this->createContainer()->getByType(ConnectionFactory::class);
		$conn1 = $factory->getConnection('default');
		$conn2 = $factory->getConnection('default');
		Assert::same($conn1, $conn2);
	}

	public function testMissingProducerThrows(): void
	{
		$client = $this->createContainer()->getByType(Client::class);
		Assert::exception(
			fn () => $client->getProducer('nonExistent'),
			\InvalidArgumentException::class,
			'Producer [nonExistent] does not exist',
		);
	}

	public function testPublishViaDIProducer(): void
	{
		$container = $this->createContainer();
		$factory = $container->getByType(ConnectionFactory::class);
		$channel = $factory->getConnection('default')->getChannel();

		$integrationQueue = 'test_di_' . uniqid();
		$channel->queue_declare($integrationQueue, false, false, false, true);

		try {
			$client = $container->getByType(Client::class);
			$client->getProducer('testProducer')->publish(json_encode(['event' => 'test']), [], $integrationQueue);

			$msg = $channel->basic_get($integrationQueue, true);
			Assert::notNull($msg);
			Assert::same('{"event":"test"}', $msg->getBody());
		} finally {
			$channel->queue_delete($integrationQueue);
		}
	}
}

(new RabbitMQExtensionTest())->run();
