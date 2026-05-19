<?php
/**
 * Bootstrap for smoxy PHPUnit tests.
 *
 * Expects the WordPress test library to be installed locally — run
 * `bash tests/bin/install-wp-tests.sh <db> <user> <pass> <host> <wp-version>`
 * to provision it. The WP_TESTS_DIR env var overrides the default location.
 */

$_tests_dir = getenv('WP_TESTS_DIR');
if (false === $_tests_dir || '' === $_tests_dir) {
    $_tests_dir = rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';
}

if (!file_exists($_tests_dir . '/includes/functions.php')) {
    fwrite(STDERR, "WordPress test library not found at {$_tests_dir}\n");
    fwrite(STDERR, "Run tests/bin/install-wp-tests.sh first.\n");
    exit(1);
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', function () {
    require dirname(__DIR__) . '/smoxy.php';
});

// Safety net: short-circuit every outbound HTTP request so no test ever hits
// the real network (smoxy ingress, wp-cron spawn, WP.org pings, etc.). Runs at
// a very low priority so per-test capture filters get first crack at recording
// the request; those filters can return $preempt unchanged and let this mock
// produce the final fake response.
tests_add_filter(
    'pre_http_request',
    static function ($preempt) {
        if (false !== $preempt) {
            return $preempt;
        }
        return array(
            'headers'  => array(),
            'body'     => '',
            'response' => array('code' => 200, 'message' => 'OK'),
            'cookies'  => array(),
            'filename' => null,
        );
    },
    9999,
    3
);

require $_tests_dir . '/includes/bootstrap.php';

/**
 * Exception thrown from the wp_redirect filter in feature tests to bubble out
 * of admin-post handlers before their trailing `exit` runs.
 */
class SmoxyRedirectException extends \RuntimeException
{
    public string $location;

    public function __construct(string $location)
    {
        parent::__construct($location);
        $this->location = $location;
    }
}

/**
 * Capture buffer for the Smoxy\WP\header() stub in tests/_header-stub.php.
 * CacheTagsTest resets this in set_up and asserts on it afterwards.
 */
class CacheTagsTestHeaderCapture
{
    /** @var string[] */
    public static array $captured = array();

    /**
     * Return value for the stubbed Smoxy\WP\headers_sent(). Defaults to false
     * so the happy-path tests proceed; tests that need to exercise the
     * "headers already sent" guard flip this to true for the duration of the
     * test.
     */
    public static bool $headers_sent = false;

    public static function reset(): void
    {
        self::$captured     = array();
        self::$headers_sent = false;
    }

    public static function push(string $value): void
    {
        self::$captured[] = $value;
    }
}

require_once __DIR__ . '/_header-stub.php';
