<?php

namespace Spawn\Symfony;

/**
 * Services isolated per-coroutine via current_context().
 *
 * Enum cases as object keys guarantee uniqueness across libraries
 * and let static analysis find every place scoped state is read or written.
 */
enum ScopedService
{
    case REQUEST_STACK;
    case SECURITY_TOKEN;
    case SECURITY_INITIALIZER;
    case REQUEST_CONTEXT;
    case LOCALE;
    case FALLBACK_LOCALES;
}
