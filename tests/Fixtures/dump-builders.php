<?php

/*
 * Loads watch-tower/tests/Support/NightwatchRecords.php with tiny stand-ins for
 * the Laravel helpers it uses and prints field => type of each builder default.
 * Usage: php dump-builders.php /path/to/NightwatchRecords.php
 */

namespace Illuminate\Support {
    class Str
    {
        public static function uuid(): string
        {
            return '00000000-0000-4000-8000-000000000000';
        }
    }
}

namespace {
    use Tests\Support\NightwatchRecords;

    final class FakeNow
    {
        public function __call(string $name, array $arguments): self|int
        {
            return $name === 'getTimestamp' ? time() : $this;
        }
    }

    function now(): FakeNow
    {
        return new FakeNow;
    }

    require $argv[1];

    $builders = [
        'request' => 'request', 'query' => 'query', 'exception' => 'exception', 'log' => 'log', 'command' => 'command',
        'outgoing-request' => 'outgoingRequest', 'user' => 'user', 'wt-hello' => 'wtHello', 'wt-trace-link' => 'wtTraceLink',
        'wt-output' => 'wtOutput',
    ];

    $types = [];

    foreach ($builders as $type => $method) {
        foreach (NightwatchRecords::$method() as $field => $value) {
            $types[$type][$field] = get_debug_type($value);
        }
    }

    echo json_encode($types);
}
