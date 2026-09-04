<?php

declare(strict_types=1);

namespace EuroSms\Tests\Helpers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OutOfBoundsException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Builds a Guzzle client backed by a queue of canned responses, so that no test opens a network
 * connection. Every request the client makes is recorded and can be inspected afterwards.
 */
final class MockClientFactory
{
    /** @var array<int, array<string, mixed>> $history */
    private array $history = [];

    /**
     * @param ResponseInterface[] $responses queued in the order the client will consume them
     * @return Client
     */
    public function create(array $responses): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new Client(['handler' => $stack]);
    }

    /**
     * @param string $body
     * @param int $status
     * @return Response
     */
    public static function json(string $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], $body);
    }

    /**
     * @return int
     */
    public function requestCount(): int
    {
        return count($this->history);
    }

    /**
     * @param int $index
     * @return string
     */
    public function requestUri(int $index = 0): string
    {
        return (string)$this->request($index)->getUri()->getPath();
    }

    /**
     * @param int $index
     * @return string
     */
    public function requestMethod(int $index = 0): string
    {
        return $this->request($index)->getMethod();
    }

    /**
     * The request body the library actually put on the wire, decoded from JSON.
     * @param int $index
     * @return array<string, mixed>
     */
    public function requestBody(int $index = 0): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = (array)json_decode((string)$this->request($index)->getBody(), true);

        return $decoded;
    }

    /**
     * @param int $index
     * @return RequestInterface
     */
    private function request(int $index): RequestInterface
    {
        if (!isset($this->history[$index]['request'])) {
            throw new OutOfBoundsException(sprintf('No request recorded at index %d.', $index));
        }

        /** @var RequestInterface $request */
        $request = $this->history[$index]['request'];

        return $request;
    }
}
