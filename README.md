# symfony-spawn

Symfony adapter for [PHP TrueAsync](https://github.com/true-async/php-trueasync).

## Requirements

- PHP 8.6+ with the **TrueAsync** extension
- Symfony 7.0 or 8.0
- `ext-pcntl`, `ext-pdo`

## Installation

```bash
composer require yangusik/symfony-spawn
```

```php
// config/bundles.php
Spawn\Symfony\TrueAsyncBundle::class => ['all' => true],
```

The runtime is registered automatically via `composer.json`. That's it.

## Running

```bash
php bin/console async:serve

php bin/console async:serve --host=0.0.0.0 --port=9000
```

FrankenPHP worker mode is detected automatically via `FRANKENPHP_WORKER`.

## Doctrine

When `doctrine/orm` is installed, the bundle automatically enables TrueAsync's PDO connection pool and isolates transaction state per coroutine.

Default pool settings (override in `config/packages/true_async.yaml`):

```yaml
true_async:
    db_pool:
        enabled: true
        min: 2
        max: 10
        healthcheck_interval: 30
```

## License

MIT
