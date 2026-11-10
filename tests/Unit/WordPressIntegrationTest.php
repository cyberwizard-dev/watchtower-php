<?php

declare(strict_types=1);

namespace WatchTower\Tests\Unit;

use WatchTower\Tests\Support\TestCase;
use WatchTower\Tests\Support\WireFixtures;
use WatchTower\WordPress\Integration;

final class WordPressIntegrationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__).'/Fixtures/wordpress-stubs.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wt_hooks'] = [];
        $GLOBALS['wt_conditionals'] = [];
        $GLOBALS['wt_current_user'] = (object) ['ID' => 0];
        $GLOBALS['wt_post_type'] = false;
        $GLOBALS['wt_doing_ajax'] = false;
    }

    protected function tearDown(): void
    {
        Integration::reset();
        parent::tearDown();
    }

    public function test_single_post_route_template_and_user(): void
    {
        $client = $this->client(['framework' => 'wordpress']);
        $client->startRequest(self::server(['REQUEST_URI' => '/2026/11/hello-world/']));
        Integration::register($client);

        wt_set_conditionals(['is_singular']);
        $GLOBALS['wt_post_type'] = 'post';
        $GLOBALS['wt_current_user'] = (object) ['ID' => 5, 'display_name' => 'Ada', 'user_login' => 'ada'];

        do_action('init');
        do_action('wp');
        apply_filters('template_include', '/var/www/wp-content/themes/twenty/single.php');
        do_action('shutdown');
        $client->finish(['status_code' => 200]);

        $records = $this->http->records();
        $request = self::one($records, 'request');
        $this->assertSame('/post/{slug}', $request['route_path']);
        $this->assertSame('wp.single.post', $request['route_name']);
        $this->assertSame('theme/single.php', $request['route_action']);
        $this->assertSame('5', $request['user']);
        $this->assertSame(['5', 'Ada', 'ada'], array_values(array_intersect_key(self::one($records, 'user'), array_flip(['id', 'name', 'username']))));
    }

    public function test_rest_routes_use_the_registered_pattern(): void
    {
        $client = $this->client();
        $client->startRequest(self::server(['REQUEST_URI' => '/wp-json/wp/v2/posts/12', 'REQUEST_METHOD' => 'POST']));
        Integration::register($client);

        $request = new class
        {
            public function get_method(): string
            {
                return 'POST';
            }
        };

        $this->assertSame('result', apply_filters('rest_dispatch_request', 'result', $request, '/wp/v2/posts/(?P<id>[\d]+)', []));
        $client->finish(['status_code' => 201]);

        $record = self::one($this->http->records(), 'request');
        $this->assertSame('/wp-json/wp/v2/posts/{id}', $record['route_path']);
        $this->assertSame('rest', $record['route_name']);
        $this->assertSame(['POST'], $record['route_methods']);
    }

    public function test_admin_ajax_route(): void
    {
        $GLOBALS['wt_doing_ajax'] = true;
        $_REQUEST['action'] = 'heartbeat';

        $client = $this->client();
        $client->startRequest(self::server(['REQUEST_URI' => '/wp-admin/admin-ajax.php']));
        Integration::register($client);
        $client->finish(['status_code' => 200]);
        unset($_REQUEST['action']);

        $this->assertSame('ajax.heartbeat', self::one($this->http->records(), 'request')['route_name']);
    }

    public function test_wpdb_queries_are_timed_between_hooks(): void
    {
        // The stub plays the role of wp-includes/class-wpdb.php, which the mu-plugin skips the same way.
        $client = $this->client(['location_skip' => ['wordpress-stubs.php']]);
        $client->startRequest(self::server());
        Integration::register($client);

        wt_wpdb_query("SELECT option_value FROM wp_options WHERE option_name = 'siteurl'", 2000);
        $line = __LINE__ - 1;
        do_action('the_post');
        wt_wpdb_query('SELECT * FROM wp_posts WHERE ID = 12', 1000);
        do_action('shutdown');

        $queries = self::ofType($client->buffered(), 'query');
        $this->assertCount(2, $queries);
        WireFixtures::assertShape($queries[0]);
        $this->assertSame("SELECT option_value FROM wp_options WHERE option_name = '?'", $queries[0]['sql']);
        $this->assertSame('SELECT * FROM wp_posts WHERE ID = ?', $queries[1]['sql']);
        $this->assertSame('wpdb', $queries[0]['connection']);
        $this->assertGreaterThanOrEqual(2000, $queries[0]['duration']);
        $this->assertSame('tests/Unit/WordPressIntegrationTest.php', $queries[0]['file']);
        $this->assertSame($line, $queries[0]['line']);
        $this->assertSame([], $GLOBALS['wt_hooks']['all'] ?? [], 'the temporary `all` hook is removed');
    }
}
