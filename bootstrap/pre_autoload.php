<?php

declare(strict_types=1);

// Load app helpers before Composer vendor files so IDR money formatting and
// other `function_exists` overrides win over Shopper's copies.
require_once __DIR__.'/../app/helpers.php';
