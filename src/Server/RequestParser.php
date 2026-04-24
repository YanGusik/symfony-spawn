<?php

namespace Spawn\Symfony\Server;

use Symfony\Component\HttpFoundation\Request;

class RequestParser
{
    public static function parse(string $raw): Request
    {
        $headerEnd  = strpos($raw, "\r\n\r\n");
        $headerPart = substr($raw, 0, $headerEnd);
        $body       = substr($raw, $headerEnd + 4);

        $lines       = explode("\r\n", $headerPart);
        $requestLine = array_shift($lines);

        [$method, $uri, $protocol] = explode(' ', $requestLine, 3);

        $headers = [];
        foreach ($lines as $line) {
            if (str_contains($line, ': ')) {
                [$name, $value] = explode(': ', $line, 2);
                $headers[strtolower($name)] = $value;
            }
        }

        $parsedUrl   = parse_url($uri);
        $path        = $parsedUrl['path'] ?? '/';
        $queryString = $parsedUrl['query'] ?? '';

        $queryParams = [];
        if ($queryString !== '') {
            parse_str($queryString, $queryParams);
        }

        $postData    = [];
        $contentType = $headers['content-type'] ?? '';
        if ($method === 'POST' && str_contains($contentType, 'application/x-www-form-urlencoded') && $body !== '') {
            parse_str($body, $postData);
        }

        $cookies      = [];
        $cookieHeader = $headers['cookie'] ?? '';
        if ($cookieHeader !== '') {
            foreach (explode('; ', $cookieHeader) as $pair) {
                $parts = explode('=', $pair, 2);
                if (count($parts) === 2) {
                    $cookies[trim($parts[0])] = trim($parts[1]);
                }
            }
        }

        $server = [
            'REQUEST_METHOD'  => strtoupper($method),
            'REQUEST_URI'     => $path . ($queryString !== '' ? '?' . $queryString : ''),
            'QUERY_STRING'    => $queryString,
            'SERVER_PROTOCOL' => $protocol ?? 'HTTP/1.1',
        ];

        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            match ($key) {
                'CONTENT_TYPE'   => $server['CONTENT_TYPE'] = $value,
                'CONTENT_LENGTH' => $server['CONTENT_LENGTH'] = $value,
                'HOST'           => $server['HTTP_HOST'] = $value,
                default          => $server['HTTP_' . $key] = $value,
            };
        }

        return Request::create(
            uri: $server['REQUEST_URI'],
            method: $method,
            parameters: in_array($method, ['GET', 'HEAD']) ? $queryParams : $postData,
            cookies: $cookies,
            files: [],
            server: $server,
            content: $body !== '' ? $body : null,
        );
    }
}
