<?php

namespace Smoxy\WP;

/**
 * Test-only override of PHP's built-in header() inside the Smoxy\WP namespace.
 *
 * CacheTags::send_header() calls header() unqualified; PHP resolves that
 * against the current namespace first, so with this stub defined the test
 * suite can capture what would have been emitted (CLI PHP otherwise drops the
 * header silently — headers_list() stays empty).
 */
function header( string $value ): void {
	\CacheTagsTestHeaderCapture::push( $value );
}

/**
 * Test-only override of PHP's built-in headers_sent().
 *
 * CLI PHP flips headers_sent() to true as soon as PHPUnit's progress output
 * starts, which would trip the real skip guard in send_header() and hide
 * everything from the capture buffer. The stub defaults to false so the
 * happy-path tests proceed, and the "skip when headers already sent" test
 * flips the flag to true.
 */
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature mirrors PHP's native headers_sent() by contract.
function headers_sent( &$file = null, &$line = null ): bool {
	return \CacheTagsTestHeaderCapture::$headers_sent;
}
