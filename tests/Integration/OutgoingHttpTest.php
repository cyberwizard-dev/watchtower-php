<?php

declare(strict_types=1);

namespace WatchTower\Tests\Integration;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\HandlerStack;
use WatchTower\Http\CurlTracer;
use WatchTower\Http\GuzzleMiddleware;
use WatchTower\Tests\Support\BuiltInServer;
use WatchTower\Tests\Support\TestCase;
use WatchTower\Tests\Support\WireFixtures;

final class OutgoingHttpTest extends TestCase
{
    private static BuiltInServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = new BuiltInServer;
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    public function test_curl_wrapper_records_the_call_and_sends_traceparent(): void
    {
        $client = $this->client();
        $execution = $client->startRequest(self::server());

        $handle = curl_init(self::$server->url.'/echo?status=201&api_key=secret');
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{"a":1}']);
        $body = CurlTracer::exec($client, $handle, ['Content-Type: application/json']);

        $echo = json_decode((string) $body, true);
        $this->assertSame('POST', $echo['method']);
        $this->assertSame('application/json', $echo['headers']['content-type']);
        $this->assertStringStartsWith('00-'.str_replace('-', '', $execution->traceId).'-', $echo['headers']['traceparent']);

        $record = self::one($client->buffered(), 'outgoing-request');
        WireFixtures::assertShape($record);
        $this->assertSame('POST', $record['method']);
        $this->assertSame('127.0.0.1', $record['host']);
        $this->assertSame(hash('xxh128', '127.0.0.1'), $record['_group']);
        $this->assertSame(201, $record['status_code']);
        $this->assertSame(7, $record['request_size']);
        $this->assertSame(strlen((string) $body), $record['response_size']);
        $this->assertStringEndsWith('/echo?status=201&api_key=[redacted]', $record['url']);
        $this->assertGreaterThan(0, $record['duration']);
    }

    public function test_curl_wrapper_records_connection_failures_as_status_zero(): void
    {
        $client = $this->client();
        $client->startRequest(self::server());

        $handle = curl_init('http://127.0.0.1:9/');
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 300]);
        $this->assertFalse(CurlTracer::exec($client, $handle));

        $record = self::one($client->buffered(), 'outgoing-request');
        $this->assertSame(0, $record['status_code']);
        $this->assertSame('GET', $record['method']);
    }

    public function test_guzzle_middleware(): void
    {
        $client = $this->client();
        $execution = $client->startRequest(self::server());

        $stack = HandlerStack::create();
        $stack->push(GuzzleMiddleware::create(static fn () => $client), 'watchtower');
        $guzzle = new Guzzle(['handler' => $stack, 'base_uri' => self::$server->url]);

        $response = $guzzle->put('/echo?status=200', ['body' => 'hello']);
        $echo = json_decode((string) $response->getBody(), true);
        $this->assertStringStartsWith('00-'.str_replace('-', '', $execution->traceId).'-', $echo['headers']['traceparent']);

        try {
            $guzzle->get('/echo?status=404');
            $this->fail('Guzzle should throw for 404');
        } catch (ClientException $e) {
            $this->assertSame(404, $e->getResponse()->getStatusCode());
        }

        $records = self::ofType($client->buffered(), 'outgoing-request');
        $this->assertCount(2, $records);
        WireFixtures::assertShape($records[0]);
        $this->assertSame(['PUT', 200, 5], [$records[0]['method'], $records[0]['status_code'], $records[0]['request_size']]);
        $this->assertSame(['GET', 404], [$records[1]['method'], $records[1]['status_code']]);
    }
}
