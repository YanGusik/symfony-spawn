<?php

namespace Spawn\Symfony\Tests;

use Spawn\Symfony\Security\AsyncTokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function Async\delay;

class TokenStorageIsolationTest extends AsyncTestCase
{
    private function makeToken(string $identifier): UsernamePasswordToken
    {
        $user = new InMemoryUser($identifier, null, ['ROLE_USER']);

        return new UsernamePasswordToken($user, 'main', ['ROLE_USER']);
    }

    public function test_each_coroutine_gets_its_own_token(): void
    {
        $storage = new AsyncTokenStorage();

        $results = $this->runParallel([
            'user1' => function () use ($storage) {
                $storage->setToken($this->makeToken('user1'));
                delay(200);
                return $storage->getToken()?->getUserIdentifier();
            },
            'user2' => function () use ($storage) {
                $storage->setToken($this->makeToken('user2'));
                delay(200);
                return $storage->getToken()?->getUserIdentifier();
            },
        ]);

        $this->assertSame('user1', $results['user1']);
        $this->assertSame('user2', $results['user2']);
    }

    public function test_token_is_null_at_coroutine_start(): void
    {
        $storage = new AsyncTokenStorage();

        $results = $this->runParallel([
            'check' => function () use ($storage) {
                return $storage->getToken();
            },
        ]);

        $this->assertNull($results['check']);
    }

    public function test_initializer_runs_once_per_coroutine(): void
    {
        $storage = new AsyncTokenStorage();

        $results = $this->runParallel([
            'a' => function () use ($storage) {
                $calls = 0;
                $storage->setInitializer(function () use ($storage, &$calls) {
                    $calls++;
                    $storage->setToken($this->makeToken('user_a'));
                });

                delay(50);

                $storage->getToken(); // triggers initializer
                $storage->getToken(); // must NOT trigger again

                return $calls;
            },
            'b' => function () use ($storage) {
                $calls = 0;
                $storage->setInitializer(function () use ($storage, &$calls) {
                    $calls++;
                    $storage->setToken($this->makeToken('user_b'));
                });

                $storage->getToken();

                return $calls;
            },
        ]);

        $this->assertSame(1, $results['a'], 'Initializer must run exactly once');
        $this->assertSame(1, $results['b'], 'Initializer must run exactly once');
    }
}
