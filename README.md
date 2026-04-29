# haltuf/rabbitmq

Odlehčená RabbitMQ integrace pro [Nette Framework](https://nette.org) postavená nad [`php-amqplib/php-amqplib`](https://github.com/php-amqplib/php-amqplib).

## Proč tento balíček

- **Seamless switch z `contributte/rabbitmq`.** Konfigurační formát i veřejné API jsou záměrně drženy kompatibilně — migrace existující aplikace zabere řádově deset minut (detaily v sekci [Migrace z contributte/rabbitmq](#migrace-z-contributterabbitmq)).
- **Kompatibilita s PHP 8.4+.** `contributte/rabbitmq` závisí na `bunny/bunny`, který nad `react/promise` v2 generuje deprecation errory a není aktivně udržován. Tento balíček běží nad `php-amqplib` v3, který je pod oficiální RabbitMQ organizací a podporuje aktuální PHP.
- **Autor balíček aktivně používá ve vlastních produkčních projektech**, takže opravy chyb a kompatibilita s novými verzemi PHP a Nette jsou motivovány skutečnou potřebou.
- Minimum závislostí, žádné skryté abstrakce.

## Obsah

- [Instalace](#instalace)
- [Registrace rozšíření](#registrace-rozšíření)
- [Konfigurace](#konfigurace)
- [Použití](#použití)
  - [Producer](#producer)
  - [Consumer](#consumer)
  - [BulkConsumer](#bulkconsumer)
- [Konzolové příkazy](#konzolové-příkazy)
- [Tracy bar panel](#tracy-bar-panel)
- [Migrace z contributte/rabbitmq](#migrace-z-contributterabbitmq)

## Instalace

```bash
composer require haltuf/rabbitmq
```

Vyžaduje PHP 8.3+ a běžící RabbitMQ server.

## Registrace rozšíření

V souboru `config.neon`:

```neon
extensions:
	rabbitmq: Haltuf\RabbitMQ\DI\RabbitMQExtension
```

## Konfigurace

```neon
rabbitmq:
	connections:
		default:
			host: 127.0.0.1
			port: 5672
			user: guest
			password: guest
			vhost: /
			heartbeat: 60
			timeout: 1
			lazy: false

	queues:
		eventQueue:
			connection: default
			durable: true
			arguments:
				x-dead-letter-exchange: events-dlx

	producers:
		eventProducer:
			queue: eventQueue
			contentType: application/json
			deliveryMode: 2

	consumers:
		eventConsumer:
			queue: eventQueue
			callback: [@App\Consumers\EventHandler, consume]
			qos:
				prefetchCount: 10
			bulk:
				size: 50
				timeout: 5
```

### Connection options

| Klíč | Výchozí | Popis |
|------|---------|-------|
| `host` | `127.0.0.1` | Hostname RabbitMQ serveru |
| `port` | `5672` | Port |
| `user` | `guest` | Uživatel |
| `password` | `guest` | Heslo |
| `vhost` | `/` | Virtual host |
| `heartbeat` | `60` | Heartbeat interval v sekundách |
| `timeout` | `1` | Connection timeout v sekundách |
| `lazy` | `false` | Pokud `true`, připojení se navazuje až při prvním použití |

### Queue options

| Klíč | Výchozí | Popis |
|------|---------|-------|
| `connection` | `default` | Jméno connection |
| `passive` | `false` | Pasivní deklarace (jen kontrola existence) |
| `durable` | `true` | Persistentní fronta |
| `exclusive` | `false` | Exkluzivní přístup |
| `autoDelete` | `false` | Automatické mazání |
| `noWait` | `false` | Neblokující deklarace |
| `arguments` | `[]` | AMQP argumenty (např. `x-dead-letter-exchange`, `x-message-ttl`) |

### Producer options

| Klíč | Výchozí | Popis |
|------|---------|-------|
| `queue` | — | Jméno fronty (povinné) |
| `contentType` | `text/plain` | MIME type |
| `deliveryMode` | `2` | `1` = non-persistent, `2` = persistent |

### Consumer options

| Klíč | Výchozí | Popis |
|------|---------|-------|
| `queue` | — | Jméno fronty (povinné) |
| `callback` | — | Callable (povinné); pro bulk dostává `Message[]` indexované podle `deliveryTag`, jinak jednu `Message` |
| `qos.prefetchSize` | `null` | QoS prefetch size |
| `qos.prefetchCount` | `null` | QoS prefetch count |
| `bulk.size` | `null` | Maximální velikost batch. Pokud je vyplněno, použije se `BulkConsumer`. |
| `bulk.timeout` | `null` | Flush timeout v sekundách |

## Použití

### Producer

```php
use Haltuf\RabbitMQ\Client;

final class EventDispatcher
{
	public function __construct(
		private readonly Client $rabbitClient,
	) {}

	public function dispatch(array $event): void
	{
		$this->rabbitClient->getProducer('eventProducer')->publish(
			json_encode($event),
			['x-source' => 'api'],
		);
	}
}
```

### Consumer

Consumer callback dostává instanci `Haltuf\RabbitMQ\Consumer\Message` a musí vrátit jednu z konstant `IConsumer::MESSAGE_*`:

```php
use Haltuf\RabbitMQ\Consumer\IConsumer;
use Haltuf\RabbitMQ\Consumer\Message;

final class EventHandler
{
	public function consume(Message $message): int
	{
		$data = json_decode($message->content, true);

		try {
			$this->processEvent($data);
			return IConsumer::MESSAGE_ACK;
		} catch (\Throwable) {
			return IConsumer::MESSAGE_REJECT;
		}
	}
}
```

Dostupné výsledky:

| Konstanta | Chování |
|-----------|---------|
| `MESSAGE_ACK` | Potvrdit zprávu |
| `MESSAGE_NACK` | Vrátit zpět do fronty (requeue) |
| `MESSAGE_REJECT` | Zahodit (případně routovat do DLX, pokud je nakonfigurován) |
| `MESSAGE_ACK_AND_TERMINATE` | Potvrdit a ukončit consumer loop |
| `MESSAGE_REJECT_AND_TERMINATE` | Zahodit a ukončit consumer loop |

### BulkConsumer

Pokud v configu vyplníš `bulk.size`, consumer dostává vždy pole zpráv indexované podle `deliveryTag` a musí vrátit asociativní pole `deliveryTag => status`:

```php
public function consume(array $messages): array
{
	$result = [];
	foreach ($messages as $deliveryTag => $message) {
		$result[$deliveryTag] = $this->process($message)
			? IConsumer::MESSAGE_ACK
			: IConsumer::MESSAGE_REJECT;
	}
	return $result;
}
```

## Konzolové příkazy

Balíček registruje tři Symfony Console příkazy:

| Příkaz | Popis |
|--------|-------|
| `rabbitmq:consumer <consumerName> [secondsToLive]` | Spustí consumer; pokud je `secondsToLive` nastaveno, běží max uvedenou dobu |
| `rabbitmq:staticConsumer <consumerName> <amountOfMessages>` | Spustí consumer a ukončí se po zpracování daného počtu zpráv |
| `rabbitmq:declareQueuesAndExchanges` | Deklaruje všechny fronty z configu; volá se typicky v deploy pipeline |

## Tracy bar panel

`Haltuf\RabbitMQ\Diagnostics\BarPanel` zobrazuje v Tracy debug baru ikonku, počet odeslaných zpráv a přehled payloadů per producer. Vyžaduje [`tracy/tracy`](https://github.com/nette/tracy) (typicky `composer require --dev tracy/tracy`).

Registrace v `config.neon` (typicky pouze v development konfiguraci):

```neon
tracy:
	bar:
		- Haltuf\RabbitMQ\Diagnostics\BarPanel
```

Maximální počet zobrazených zpráv lze upravit přes statickou property `BarPanel::$displayCount` (výchozí `100`, hodnota `0` znamená neomezeně). V dlouhoběžících procesech (CLI, workery) hodnotu `0` nepoužívej — pole sebraných payloadů by rostlo bez omezení.

## Migrace z `contributte/rabbitmq`

Balíček je navržen jako přímá náhrada — API a konfigurace jsou záměrně drženy kompatibilně. Migrace má tři kroky:

**1. Výměna composer závislosti**

```bash
composer remove contributte/rabbitmq
composer require haltuf/rabbitmq
```

**2. Přejmenování rozšíření v `config.neon`**

```diff
 extensions:
-	rabbitmq: Contributte\RabbitMQ\DI\RabbitMQExtension
+	rabbitmq: Haltuf\RabbitMQ\DI\RabbitMQExtension
```

Konfigurační bloky (`connections`, `queues`, `producers`, `consumers`) zůstávají beze změny.

**3. Přejmenování namespace v aplikačním kódu**

Ve všech souborech najdi a nahraď:

```diff
-use Contributte\RabbitMQ\
+use Haltuf\RabbitMQ\
```

Rename se týká **všech souborů, které importují jakoukoli třídu nebo konstantu z `Contributte\RabbitMQ\`** — typicky consumer handlery (`IConsumer` konstanty), ale i služby, presentery a event dispatchery, které mají typovaný `Client` pro publikování zpráv. V reálném Nette projektu střední velikosti jde řádově o **10–15 souborů**. Najdi všechny výskyty:

```bash
grep -rlF 'use Contributte\RabbitMQ' app/ src/ bin/
```

DI container konfigurace (`config.neon`) a runtime publish volání přes `$client->getProducer('name')->publish(...)` jsou namespace-agnostická a žádné změny nevyžadují.

### Drobné rozdíly

- Contributte používalo `bunny/bunny`, tento balíček používá `php-amqplib`. Výsledné AMQP framy jsou identické, ale pokud jsi sahal přímo do bunny interních objektů (např. přes reflexi), budeš muset přepsat.
- `Message` DTO má pevné pole: `content`, `deliveryTag`, `routingKey`, `headers`, `redelivered`, `exchange`, `consumerTag`. Pokud jsi z contributte-messagové objekty tahal přes `get('application_headers')`, teď máš rovnou `$message->headers`.

## Licence

MIT — viz [LICENSE](LICENSE).
