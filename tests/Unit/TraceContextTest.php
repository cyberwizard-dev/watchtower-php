<?php

declare(strict_types=1);

namespace WatchTower\Tests\Unit;

use WatchTower\Support\TraceContext;
use WatchTower\Tests\Support\TestCase;
use WatchTower\Tests\Support\WireFixtures;

final class TraceContextTest extends TestCase
{
    public function test_incoming_traceparent_emits_a_trace_link(): void
    {
        $parent = '00-'.str_repeat('a', 32).'-'.str_repeat('b', 16).'-01';
        $client = $this->client();
        $execution = $client->startRequest(self::server(['HTTP_TRACEPARENT' => $parent]));
        $client->user(7);
        $client->finish();

        $link = self::one($this->http->records(), 'wt-trace-link');
        WireFixtures::assertShape($link);
        $this->assertSame(str_repeat('a', 32), $link['parent_trace_id']);
        $this->assertSame(str_repeat('b', 16), $link['parent_span_id']);
        $this->assertSame($execution->traceId, $link['trace_id']);
        $this->assertSame($execution->traceId, $link['execution_id']);
        $this->assertSame('request', $link['execution_source']);
    }

    public function test_invalid_traceparents_are_ignored(): void
    {
        foreach (['garbage', '00-'.str_repeat('0', 32).'-'.str_repeat('b', 16).'-01', 'ff-'.str_repeat('a', 32).'-'.str_repeat('b', 16).'-01'] as $header) {
            $client = $this->client();
            $client->startRequest(self::server(['HTTP_TRACEPARENT' => $header]));
            $this->assertSame([], self::ofType($client->buffered(), 'wt-trace-link'), $header);
        }
    }

    public function test_outgoing_traceparent_uses_the_trace_id(): void
    {
        $client = $this->client();
        $execution = $client->startRequest(self::server());
        $traceparent = $client->traceparent();

        $this->assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', (string) $traceparent);
        $this->assertSame(str_replace('-', '', $execution->traceId), explode('-', (string) $traceparent)[1]);
        $this->assertNotNull(TraceContext::parse($traceparent));
    }

    public function test_trace_context_can_be_disabled(): void
    {
        $client = $this->client(['capture' => ['trace_context' => false, 'hello' => false]]);
        $client->startRequest(self::server(['HTTP_TRACEPARENT' => '00-'.str_repeat('a', 32).'-'.str_repeat('b', 16).'-01']));

        $this->assertNull($client->traceparent());
        $this->assertSame([], self::ofType($client->buffered(), 'wt-trace-link'));
    }
}
