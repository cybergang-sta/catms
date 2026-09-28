<?php

declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * Deliberately minimal: the allocation engine suite must not require a database,
 * a web server, or any environment variable. If adding a test makes this file
 * grow, the test has reached outside its unit — see docs/TESTING.md §2.
 */

require __DIR__ . '/../vendor/autoload.php';
