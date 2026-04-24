<?php

namespace Spawn\Symfony\Tests;

use Async\Scope;
use PHPUnit\Framework\TestCase;

abstract class AsyncTestCase extends TestCase
{
    /**
     * Run closures in parallel coroutines, each in its own child Scope —
     * simulating per-request isolation exactly as the real servers do.
     */
    protected function runParallel(array $coroutines): array
    {
        $results = [];
        $scope   = new Scope();

        foreach ($coroutines as $key => $fn) {
            $scope->spawn(function () use ($key, $fn, &$results) {
                $requestScope = Scope::inherit();

                $requestScope->spawn(function () use ($key, $fn, &$results) {
                    $results[$key] = $fn();
                });

                $requestScope->awaitCompletion(\Async\timeout(5000));
            });
        }

        $scope->awaitCompletion(\Async\timeout(5000));

        return $results;
    }
}
