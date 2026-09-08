<?php

declare(strict_types=1);

// Preload application helpers before Composer vendor packages are loaded.
// This ensures application-level helper overrides (such as shopper_money_format
// and zero_decimal_currencies) take precedence over vendor fallback declarations.
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/Support/Overrides/ShippingField.php';
