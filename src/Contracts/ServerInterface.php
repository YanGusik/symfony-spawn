<?php

namespace Spawn\Symfony\Contracts;

interface ServerInterface
{
    public function prepareApp(): void;

    public function start(): void;
}
