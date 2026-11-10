<?php

declare(strict_types=1);

namespace WatchTower\Tests\Unit;

use WatchTower\Config;
use WatchTower\Support\Redactor;
use WatchTower\Support\Sql;
use WatchTower\Tests\Support\TestCase;

final class RedactionTest extends TestCase
{
    private function redactor(): Redactor
    {
        $config = new Config(['token' => 't']);

        return new Redactor($config->redactKeys, $config->redactHeaders);
    }

    public function test_default_key_pattern_is_the_watch_tower_one(): void
    {
        $this->assertSame(
            '/pass(word|wd|phrase)?|secret|token|api[_-]?key|private[_-]?key|credential|authorization|cookie|session|credit[_-]?card|card[_-]?number|cvv|cvc|ssn|iban/i',
            Config::DEFAULT_REDACT_KEYS,
        );
    }

    public function test_query_string_values_with_sensitive_keys_are_masked(): void
    {
        $this->assertSame(
            'https://app.test/users?page=2&token=[redacted]&api_key=[redacted]',
            $this->redactor()->url('https://app.test/users?page=2&token=secret-value&api_key=abc'),
        );
        $this->assertSame('https://host.test/path', $this->redactor()->url('https://user:pass@host.test/path'));
    }

    public function test_headers_are_masked_by_name_and_pattern(): void
    {
        $headers = $this->redactor()->headers([
            'accept' => ['text/html'],
            'authorization' => ['Bearer abc'],
            'x-api-key' => ['k'],
            'x-session-id' => ['s'],
            'php-auth-pw' => ['hunter2'],
        ]);

        $this->assertSame(['text/html'], $headers['accept']);
        $this->assertSame(['[redacted]'], $headers['authorization']);
        $this->assertSame(['[redacted]'], $headers['x-api-key']);
        $this->assertSame(['[redacted]'], $headers['x-session-id']);
        $this->assertArrayNotHasKey('php-auth-pw', $headers);
    }

    public function test_nested_data_and_free_text_are_scrubbed(): void
    {
        $data = $this->redactor()->data([
            'order' => 1234,
            'password' => 'hunter2',
            'card' => ['card_number' => '4111', 'brand' => 'visa'],
            'note' => 'call with Bearer abc.def and secret=xyz',
        ]);

        $this->assertSame(1234, $data['order']);
        $this->assertSame('[redacted]', $data['password']);
        $this->assertSame(['card_number' => '[redacted]', 'brand' => 'visa'], $data['card']);
        $this->assertSame('call with Bearer [redacted] and secret=[redacted]', $data['note']);
    }

    public function test_custom_key_pattern(): void
    {
        $client = $this->client(['redact_keys' => '/^email$/i']);
        $client->startRequest(self::server());
        $client->log('info', 'Signup', ['email' => 'ada@example.com', 'password' => 'kept by custom pattern']);
        $client->finish();

        $context = json_decode(self::one($this->http->records(), 'log')['context'], true);
        $this->assertSame('[redacted]', $context['email']);
        $this->assertSame('kept by custom pattern', $context['password']);
    }

    public function test_sql_literals_are_stripped(): void
    {
        $this->assertSame(
            "select * from users where email = '?' and id = ? and name = '?' limit ?",
            Sql::stripLiterals("select * from users where email = 'ada@example.com' and id = 42 and name = 'O''Brien' limit 10"),
        );
        $this->assertSame('select t1.col2 from table1 t1 where t1.a = ?', Sql::stripLiterals('select t1.col2 from table1 t1 where t1.a = 5'));
        $this->assertSame('select * from t where id = $1', Sql::stripLiterals('select * from t where id = $1'));
        $this->assertSame('select * from t where id = 5', Sql::stripLiterals('select * from t where id = 5', false));
    }

    public function test_request_record_is_redacted_before_sending(): void
    {
        $client = $this->client();
        $client->startRequest(self::server(['HTTP_COOKIE' => 'laravel_session=abc']));
        $client->finish();

        $request = self::one($this->http->records(), 'request');
        $headers = json_decode($request['headers'], true);

        $this->assertSame('https://app.test/users/42?page=2&token=[redacted]', $request['url']);
        $this->assertSame(['[redacted]'], $headers['authorization']);
        $this->assertSame(['[redacted]'], $headers['x-api-key']);
        $this->assertSame(['[redacted]'], $headers['cookie']);
        $this->assertSame(['text/html'], $headers['accept']);
    }

    public function test_log_context_is_redacted_and_normalized(): void
    {
        $client = $this->client();
        $client->startRequest(self::server());
        $client->log('error', 'Payment {id} failed with token=abc', [
            'order' => 1234,
            'password' => 'hunter2',
            'exception' => new \RuntimeException('boom'),
        ]);
        $client->finish();

        $log = self::one($this->http->records(), 'log');
        $context = json_decode($log['context'], true);

        $this->assertSame('Payment {id} failed with token=[redacted]', $log['message']);
        $this->assertSame('[redacted]', $context['password']);
        $this->assertSame('RuntimeException', $context['exception']['class']);
        $this->assertSame('{}', $log['extra']);
    }

    public function test_headers_can_be_left_out(): void
    {
        $client = $this->client(['capture' => ['headers' => false, 'hello' => false]]);
        $client->startRequest(self::server());
        $client->finish();

        $this->assertSame('{}', self::one($this->http->records(), 'request')['headers']);
    }
}
