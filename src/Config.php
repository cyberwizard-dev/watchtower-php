<?php

declare(strict_types=1);

namespace WatchTower;

use Composer\Autoload\ClassLoader;
use WatchTower\Support\ClientIp;
use WatchTower\Transport\HttpClient;

/**
 * Resolved SDK options. Every option has a safe default; token and base URL
 * also fall back to the WATCHTOWER_TOKEN / WATCHTOWER_URL environment variables.
 */
final class Config
{
    /** Same pattern Watch Tower applies server-side (config/watchtower.php redaction.key_pattern). */
    public const DEFAULT_REDACT_KEYS = '/pass(word|wd|phrase)?|secret|token|api[_-]?key|private[_-]?key|credential|authorization|cookie|session|credit[_-]?card|card[_-]?number|cvv|cvc|ssn|iban/i';

    /** @var list<string> */
    public const DEFAULT_REDACT_HEADERS = [
        'authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-xsrf-token',
        'x-csrf-token', 'x-api-key', 'x-auth-token', 'php-auth-pw', 'x-amz-security-token',
    ];

    /** @var array<string, bool> */
    public const DEFAULT_CAPTURE = [
        'requests' => true,
        'commands' => true,
        'exceptions' => true,
        'errors' => true,
        'deprecations' => false,
        'fatal_errors' => true,
        'logs' => true,
        'queries' => true,
        'outgoing_requests' => true,
        'headers' => true,
        'response_size' => true,
        'payload' => false,
        'code_snippets' => true,
        'command_output' => false,
        'trace_context' => true,
        'hello' => true,
    ];

    public bool $enabled;

    public ?string $token;

    public string $baseUrl;

    public ?string $environment;

    public ?string $service;

    public string $deploy;

    public string $server;

    public float $sampleRate;

    public float $exceptionSampleRate;

    /** @var array<string, bool> */
    public array $capture;

    public string $redactKeys;

    /** @var list<string> */
    public array $redactHeaders;

    /** @var list<string> */
    public array $ignorePaths;

    public ?float $slowRequestMs;

    public ?float $slowQueryMs;

    /** @deprecated Use the `client_ip` option; kept as an alias for `client_ip.header`. */
    public ?string $trustedProxyHeader;

    /** Resolves the client IP from `client_ip` (preset, header, trusted_proxies). */
    public ClientIp $clientIp;

    public string $mode;

    public string $spoolDir;

    public int $spoolMaxBytes;

    public int $spoolDrainBatches;

    public int $maxRecords;

    public float $connectTimeout;

    public float $timeout;

    public bool $finishRequest;

    public string $basePath;

    public ?int $errorReporting;

    public ?HttpClient $httpClient;

    public int $outputMaxBytes;

    public ?string $framework;

    public string $logLevel;

    public bool $stripNumericLiterals;

    /** @var list<string> */
    public array $locationSkip;

    /**
     * Shield (bot & spam protection): enabled, mode_override ("monitor"|"enforce"|null),
     * cache_dir, turnstile_secret, counters ("auto"|"apcu"|"file"), pass_minutes,
     * honeypot_field, http_client (a Shield\ShieldHttp, for tests).
     *
     * @var array{enabled: bool, mode_override: ?string, cache_dir: string, turnstile_secret: ?string, counters: string, pass_minutes: int, honeypot_field: string, http_client: ?Shield\ShieldHttp, throttle_seconds: int}
     */
    public array $shield;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(array $options = [])
    {
        $env = static fn (string $name): ?string => is_string($value = getenv($name)) && $value !== '' ? $value : (isset($_ENV[$name]) && is_string($_ENV[$name]) && $_ENV[$name] !== '' ? $_ENV[$name] : null);

        $this->token = self::nullableString($options['token'] ?? $env('WATCHTOWER_TOKEN'));
        $this->baseUrl = rtrim((string) ($options['base_url'] ?? $env('WATCHTOWER_URL') ?? ''), '/');
        $this->enabled = (bool) ($options['enabled'] ?? self::bool($env('WATCHTOWER_ENABLED'), true));
        $this->environment = self::nullableString($options['environment'] ?? $env('WATCHTOWER_ENV'));
        $this->service = self::nullableString($options['service'] ?? $env('WATCHTOWER_SERVICE'));
        $this->deploy = (string) ($options['deploy'] ?? $env('WATCHTOWER_DEPLOY') ?? '');
        $this->server = (string) ($options['server'] ?? $env('WATCHTOWER_SERVER') ?? (gethostname() ?: 'unknown'));
        $this->sampleRate = self::rate($options['sample_rate'] ?? $env('WATCHTOWER_SAMPLE_RATE') ?? 1.0);
        $this->exceptionSampleRate = self::rate($options['exception_sample_rate'] ?? 1.0);

        $capture = is_array($options['capture'] ?? null) ? $options['capture'] : [];
        $this->capture = self::DEFAULT_CAPTURE;
        foreach ($capture as $flag => $value) {
            $this->capture[(string) $flag] = (bool) $value;
        }

        $this->redactKeys = (string) ($options['redact_keys'] ?? self::DEFAULT_REDACT_KEYS);
        if (@preg_match($this->redactKeys, '') === false) {
            $this->redactKeys = self::DEFAULT_REDACT_KEYS;
        }

        $this->redactHeaders = array_values(array_unique(array_map(
            'strtolower',
            array_map('strval', [...self::DEFAULT_REDACT_HEADERS, ...(array) ($options['redact_headers'] ?? [])]),
        )));
        $this->ignorePaths = array_values(array_map('strval', (array) ($options['ignore_paths'] ?? ['/health'])));
        $this->slowRequestMs = isset($options['slow_request_ms']) ? (float) $options['slow_request_ms'] : null;
        $this->slowQueryMs = isset($options['slow_query_ms']) ? (float) $options['slow_query_ms'] : null;
        $this->trustedProxyHeader = self::nullableString($options['trusted_proxy_header'] ?? null);
        $clientIp = is_array($options['client_ip'] ?? null) ? $options['client_ip'] : [];
        $clientIp['preset'] ??= $env('WATCHTOWER_CLIENT_IP_PRESET');
        $clientIp['trusted_proxies'] ??= $env('WATCHTOWER_TRUSTED_PROXIES');
        $this->clientIp = ClientIp::fromOptions($clientIp, $this->trustedProxyHeader);
        $this->mode = in_array($options['mode'] ?? null, ['auto', 'spool'], true) ? $options['mode'] : 'auto';
        $this->spoolDir = rtrim((string) ($options['spool_dir'] ?? $env('WATCHTOWER_SPOOL_DIR') ?? sys_get_temp_dir().DIRECTORY_SEPARATOR.'watchtower'), '/\\');
        $this->spoolMaxBytes = max(1024, (int) ($options['spool_max_bytes'] ?? 50 * 1024 * 1024));
        $this->spoolDrainBatches = max(0, (int) ($options['spool_drain_batches'] ?? 5));
        $this->maxRecords = max(10, (int) ($options['max_records'] ?? 500));
        $this->connectTimeout = max(0.1, (float) ($options['connect_timeout'] ?? 1.0));
        $this->timeout = max($this->connectTimeout, (float) ($options['timeout'] ?? 2.0));
        $this->finishRequest = (bool) ($options['finish_request'] ?? true);
        $this->basePath = rtrim((string) ($options['base_path'] ?? self::guessBasePath()), '/\\');
        $this->errorReporting = isset($options['error_reporting']) ? (int) $options['error_reporting'] : null;
        $this->httpClient = ($options['http_client'] ?? null) instanceof HttpClient ? $options['http_client'] : null;
        $this->outputMaxBytes = max(1024, (int) ($options['output_max_bytes'] ?? 65536));
        $this->framework = self::nullableString($options['framework'] ?? null);
        $this->logLevel = strtolower((string) ($options['log_level'] ?? 'debug'));
        $this->stripNumericLiterals = (bool) ($options['strip_numeric_literals'] ?? true);
        $this->locationSkip = array_values(array_map('strval', (array) ($options['location_skip'] ?? [])));

        $shield = is_array($options['shield'] ?? null) ? $options['shield'] : [];
        $this->shield = [
            'enabled' => (bool) ($shield['enabled'] ?? self::bool($env('WATCHTOWER_SHIELD'), false)),
            'mode_override' => in_array($shield['mode_override'] ?? null, ['monitor', 'enforce'], true) ? $shield['mode_override'] : null,
            'cache_dir' => rtrim((string) ($shield['cache_dir'] ?? $this->spoolDir), '/\\'),
            'turnstile_secret' => self::nullableString($shield['turnstile_secret'] ?? $env('TURNSTILE_SECRET')),
            'counters' => in_array($shield['counters'] ?? null, ['apcu', 'file'], true) ? $shield['counters'] : 'auto',
            'pass_minutes' => max(1, (int) ($shield['pass_minutes'] ?? 30)),
            'honeypot_field' => self::nullableString($shield['honeypot_field'] ?? null) ?? 'wt_hp_website',
            'http_client' => ($shield['http_client'] ?? null) instanceof Shield\ShieldHttp ? $shield['http_client'] : null,
            'throttle_seconds' => max(0, (int) ($shield['throttle_seconds'] ?? $env('WATCHTOWER_SHIELD_THROTTLE_SECONDS') ?? 10)),
        ];
    }

    public function shieldUrl(): string
    {
        return $this->baseUrl.'/api/shield/v1/rules';
    }

    public function captures(string $flag): bool
    {
        return $this->capture[$flag] ?? false;
    }

    /** Whether the SDK has everything it needs to deliver records. */
    public function canSend(): bool
    {
        return $this->enabled && $this->token !== null && $this->baseUrl !== '';
    }

    public function ingestUrl(): string
    {
        return $this->baseUrl.'/api/ingest/v1';
    }

    /** A short, non-reversible id of the token for marker file names. */
    public function tokenId(): string
    {
        return substr(hash('xxh128', (string) $this->token), 0, 12);
    }

    private static function guessBasePath(): string
    {
        if (class_exists(ClassLoader::class, false)) {
            $file = (new \ReflectionClass(ClassLoader::class))->getFileName();

            if (is_string($file)) {
                return dirname($file, 3);
            }
        }

        $root = $_SERVER['DOCUMENT_ROOT'] ?? '';

        if (is_string($root) && $root !== '') {
            return $root;
        }

        return getcwd() ?: '';
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private static function rate(mixed $value): float
    {
        return is_numeric($value) ? max(0.0, min(1.0, (float) $value)) : 1.0;
    }

    private static function bool(?string $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        return ! in_array(strtolower($value), ['0', 'false', 'off', 'no', ''], true);
    }
}
