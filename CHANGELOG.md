# Changelog

Všechny významné změny v tomto balíčku budou zdokumentovány v tomto souboru.

Formát vychází z [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) a balíček používá [sémantické verzování](https://semver.org/lang/cs/).

## [Unreleased]

### Added

- `Haltuf\RabbitMQ\Diagnostics\BarPanel` — Tracy bar panel zobrazující ikonu, počet odeslaných zpráv a přehled payloadů per producer.
- `Haltuf\RabbitMQ\Producer\Producer::addOnPublishCallback()` — registrace callbacku volaného po úspěšném `basic_publish`.
- `Haltuf\RabbitMQ\Client::getProducers()` — výpis všech producerů (používá BarPanel pro hromadnou registraci callbacků).

## [0.1.0] - 2026-04-11

První veřejné vydání balíčku extrahovaného z produkční aplikace.

### Added

- `Haltuf\RabbitMQ\DI\RabbitMQExtension` — Nette DI rozšíření pro konfiguraci connections, queues, producers a consumers přes NEON.
- `Haltuf\RabbitMQ\Client` — registr všech nakonfigurovaných producerů.
- `Haltuf\RabbitMQ\Producer\Producer` — publikace zpráv s auto-reconnect na connection/channel/IO chybě.
- `Haltuf\RabbitMQ\Consumer\Consumer` — single-message consumer s `MESSAGE_ACK`/`NACK`/`REJECT` sémantikou a podporou `maxSeconds` / `maxMessages`.
- `Haltuf\RabbitMQ\Consumer\BulkConsumer` — batch-processing consumer s konfigurovatelnou velikostí batch a flush timeoutem.
- `Haltuf\RabbitMQ\Consumer\Message` — readonly DTO s `content`, `deliveryTag`, `routingKey`, `headers`, `redelivered`, `exchange`, `consumerTag`.
- `Haltuf\RabbitMQ\Connection\Connection` — wrapper nad `php-amqplib` s podporou lazy/eager připojení a explicitního reconnectu.
- `Haltuf\RabbitMQ\Queue\QueueDeclarator` — deklarace front z configu včetně AMQP argumentů (DLX, TTL, …).
- Konzolové příkazy `rabbitmq:consumer`, `rabbitmq:staticConsumer`, `rabbitmq:declareQueuesAndExchanges`.
- Integrační testy proti živému RabbitMQ serveru.

[Unreleased]: https://github.com/haltuf/rabbitmq/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/haltuf/rabbitmq/releases/tag/v0.1.0
