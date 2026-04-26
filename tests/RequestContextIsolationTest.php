<?php

namespace Spawn\Symfony\Tests;

use Spawn\Symfony\Routing\AsyncRequestContext;
use Symfony\Component\HttpFoundation\Request;

use function Async\delay;

class RequestContextIsolationTest extends AsyncTestCase
{
    public function test_each_coroutine_gets_its_own_context(): void
    {
        $context = new AsyncRequestContext();

        $results = $this->runParallel([
            'en' => function () use ($context) {
                $context->fromRequest(Request::create('https://en.example.com/home'));
                delay(200);
                return $context->getHost();
            },
            'ru' => function () use ($context) {
                $context->fromRequest(Request::create('https://ru.example.com/home'));
                delay(200);
                return $context->getHost();
            },
        ]);

        $this->assertSame('en.example.com', $results['en']);
        $this->assertSame('ru.example.com', $results['ru']);
    }

    public function test_scheme_isolated_per_coroutine(): void
    {
        $context = new AsyncRequestContext();

        $results = $this->runParallel([
            'https' => function () use ($context) {
                $context->setScheme('https');
                delay(100);
                return $context->getScheme();
            },
            'http' => function () use ($context) {
                $context->setScheme('http');
                delay(100);
                return $context->getScheme();
            },
        ]);

        $this->assertSame('https', $results['https']);
        $this->assertSame('http', $results['http']);
    }

    public function test_falls_back_to_boot_defaults_when_not_set(): void
    {
        $context = new AsyncRequestContext('', 'GET', 'default.host', 'http');

        $results = $this->runParallel([
            'check' => function () use ($context) {
                // No fromRequest() called — should see boot-time defaults
                return $context->getHost();
            },
        ]);

        $this->assertSame('default.host', $results['check']);
    }
}
