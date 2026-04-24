<?php

namespace Spawn\Symfony\Tests;

use Spawn\Symfony\Http\AsyncRequestStack;
use Symfony\Component\HttpFoundation\Request;

use function Async\delay;

class RequestStackIsolationTest extends AsyncTestCase
{
    public function test_each_coroutine_gets_its_own_request(): void
    {
        $stack = new AsyncRequestStack();

        $results = $this->runParallel([
            'user1' => function () use ($stack) {
                $stack->push(Request::create('/api?user=1'));
                delay(200);
                return $stack->getCurrentRequest()?->query->get('user');
            },
            'user2' => function () use ($stack) {
                $stack->push(Request::create('/api?user=2'));
                delay(200);
                return $stack->getCurrentRequest()?->query->get('user');
            },
            'user3' => function () use ($stack) {
                $stack->push(Request::create('/api?user=3'));
                delay(200);
                return $stack->getCurrentRequest()?->query->get('user');
            },
        ]);

        $this->assertSame('1', $results['user1']);
        $this->assertSame('2', $results['user2']);
        $this->assertSame('3', $results['user3']);
    }

    public function test_pop_removes_only_from_current_coroutine(): void
    {
        $stack = new AsyncRequestStack();

        $results = $this->runParallel([
            'a' => function () use ($stack) {
                $stack->push(Request::create('/a'));
                delay(100);
                $stack->pop();
                return $stack->getCurrentRequest()?->getPathInfo();
            },
            'b' => function () use ($stack) {
                $stack->push(Request::create('/b'));
                delay(200);
                return $stack->getCurrentRequest()?->getPathInfo();
            },
        ]);

        $this->assertNull($results['a']);   // a popped its own request
        $this->assertSame('/b', $results['b']); // b's request untouched
    }

    public function test_stack_is_empty_at_coroutine_start(): void
    {
        $stack = new AsyncRequestStack();

        $results = $this->runParallel([
            'check' => function () use ($stack) {
                return $stack->getCurrentRequest();
            },
        ]);

        $this->assertNull($results['check']);
    }
}
