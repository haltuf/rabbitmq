<?php declare(strict_types=1);

namespace Haltuf\RabbitMQ\Diagnostics;

use Haltuf\RabbitMQ\Client;
use Throwable;
use Tracy\IBarPanel;

final class BarPanel implements IBarPanel
{
	public static int $displayCount = 100;

	/** @var array<string, list<string>> */
	private array $sentMessages = [];

	private int $totalMessages = 0;

	public function __construct(Client $client)
	{
		foreach ($client->getProducers() as $name => $producer) {
			$this->sentMessages[$name] = [];
			$producer->addOnPublishCallback(
				function (string $message) use ($name): void {
					if (self::$displayCount === 0 || $this->totalMessages < self::$displayCount) {
						$this->sentMessages[$name][] = $message;
					}

					$this->totalMessages++;
				},
			);
		}
	}

	public function getTab(): string
	{
		$icon = (string) file_get_contents(__DIR__ . '/rabbitmq-icon.svg');
		$iconSrc = 'data:image/svg+xml;base64,' . base64_encode($icon);

		return '<span title="RabbitMq"><img src="' . htmlspecialchars($iconSrc, ENT_QUOTES) . '" alt="">'
			. ' ' . $this->totalMessages . '</span>';
	}

	public function getPanel(): string
	{
		$totalMessages = $this->totalMessages;
		$sentMessages = $this->sentMessages;
		$displayCount = self::$displayCount;

		ob_start();
		try {
			require __DIR__ . '/BarPanel.phtml';
		} catch (Throwable $e) {
			ob_end_clean();
			throw $e;
		}

		return (string) ob_get_clean();
	}
}
