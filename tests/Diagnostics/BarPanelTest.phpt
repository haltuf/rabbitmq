<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Tests\Diagnostics;

require __DIR__ . '/../bootstrap.php';

use Haltuf\RabbitMQ\Client;
use Haltuf\RabbitMQ\Connection\Connection;
use Haltuf\RabbitMQ\Diagnostics\BarPanel;
use Haltuf\RabbitMQ\Producer\Producer;
use Haltuf\RabbitMQ\Tests\TestConfig;
use Tester\Assert;
use Tester\TestCase;

class BarPanelTest extends TestCase
{
	private Connection $connection;

	/** @var list<string> */
	private array $declaredQueues = [];

	public function setUp(): void
	{
		$this->connection = TestConfig::createConnection();
	}

	public function tearDown(): void
	{
		foreach ($this->declaredQueues as $queue) {
			$this->connection->getChannel()->queue_delete($queue);
		}

		$this->declaredQueues = [];
		BarPanel::$displayCount = 100;
	}

	public function testRegistersCallbackOnEachProducerAndCollectsMessages(): void
	{
		$client = $this->createClient(['p1', 'p2']);

		$panel = new BarPanel($client);

		$client->getProducer('p1')->publish('msg-1');
		$client->getProducer('p1')->publish('msg-2');
		$client->getProducer('p2')->publish('msg-3');

		$panelHtml = $panel->getPanel();
		Assert::contains('p1', $panelHtml);
		Assert::contains('p2', $panelHtml);
		Assert::contains('msg-1', $panelHtml);
		Assert::contains('msg-2', $panelHtml);
		Assert::contains('msg-3', $panelHtml);
	}

	public function testTotalMessagesIncrementsAlwaysEvenAboveDisplayCount(): void
	{
		BarPanel::$displayCount = 3;
		$client = $this->createClient(['p1']);
		$panel = new BarPanel($client);

		for ($i = 1; $i <= 7; $i++) {
			$client->getProducer('p1')->publish('m' . $i);
		}

		Assert::contains('total sent 7', $panel->getPanel());
		Assert::contains('> 7</span>', $this->normalizeWhitespace($panel->getTab()));
	}

	public function testDisplayCountLimitsCollectedMessagesButNotTotal(): void
	{
		BarPanel::$displayCount = 3;
		$client = $this->createClient(['p1']);
		$panel = new BarPanel($client);

		for ($i = 1; $i <= 5; $i++) {
			$client->getProducer('p1')->publish('payload-' . $i);
		}

		$panelHtml = $panel->getPanel();
		Assert::contains('payload-1', $panelHtml);
		Assert::contains('payload-2', $panelHtml);
		Assert::contains('payload-3', $panelHtml);
		Assert::notContains('payload-4', $panelHtml);
		Assert::notContains('payload-5', $panelHtml);
		Assert::contains('Only first 3 messages are displayed.', $panelHtml);
		Assert::contains('total sent 5', $panelHtml);
	}

	public function testDisplayCountZeroMeansUnlimited(): void
	{
		BarPanel::$displayCount = 0;
		$client = $this->createClient(['p1']);
		$panel = new BarPanel($client);

		for ($i = 1; $i <= 5; $i++) {
			$client->getProducer('p1')->publish('unlimited-' . $i);
		}

		$panelHtml = $panel->getPanel();
		for ($i = 1; $i <= 5; $i++) {
			Assert::contains('unlimited-' . $i, $panelHtml);
		}

		Assert::notContains('Only first', $panelHtml);
	}

	public function testGetTabContainsCountAndIcon(): void
	{
		$client = $this->createClient(['p1']);
		$panel = new BarPanel($client);

		$client->getProducer('p1')->publish('a');
		$client->getProducer('p1')->publish('b');

		$tab = $panel->getTab();
		Assert::contains('data:image/svg+xml;base64,', $tab);
		Assert::contains(' 2', $tab);
	}

	public function testGetPanelHandlesEmptyProducer(): void
	{
		$client = $this->createClient(['empty']);
		$panel = new BarPanel($client);

		$panelHtml = $panel->getPanel();
		Assert::contains('empty', $panelHtml);
		Assert::contains('total sent 0', $panelHtml);
		Assert::contains('none', $panelHtml);
	}

	/**
	 * @param list<string> $names
	 */
	private function createClient(array $names): Client
	{
		$producers = [];
		foreach ($names as $name) {
			$queue = 'test_barpanel_' . $name . '_' . uniqid();
			$this->connection->getChannel()->queue_declare($queue, false, false, false, true);
			$this->declaredQueues[] = $queue;
			$producers[$name] = new Producer($this->connection, $queue);
		}

		return new Client($producers);
	}

	private function normalizeWhitespace(string $html): string
	{
		return (string) preg_replace('/\s+/', ' ', $html);
	}
}

(new BarPanelTest())->run();
