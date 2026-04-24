<?php

namespace Spawn\Symfony\Server;

use Symfony\Component\HttpFoundation\Response;

class ResponseEmitter
{
    public static function emit(mixed $client, Response $response): void
    {
        $statusCode = $response->getStatusCode();
        $reasonPhrase = Response::$statusTexts[$statusCode] ?? '';

        $out = "HTTP/1.1 {$statusCode} {$reasonPhrase}\r\n";

        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            foreach ($values as $value) {
                $out .= "{$name}: {$value}\r\n";
            }
        }

        foreach ($response->headers->getCookies() as $cookie) {
            $out .= "Set-Cookie: {$cookie}\r\n";
        }

        $body = (string) $response->getContent();
        $out .= 'Content-Length: ' . strlen($body) . "\r\n";
        $out .= "\r\n";
        $out .= $body;

        fwrite($client, $out);
    }
}
