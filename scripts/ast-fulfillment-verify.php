<?php

declare(strict_types=1);

/**
 * AST verification of the paid → provider AWB path after the provider-neutral
 * refactor (Komerce lives in app/Integrations/Komerce, generic code reaches it
 * only through Shopper events and the App\Shipping contracts).
 * Parses PHP sources (not runtime) and asserts the call graph still exists:
 *
 *   Komerce webhook → MarkOrderPaidFromKomerce → MarkOrderPaid → Shopper OrderPaid
 *   → FulfillShipmentsOnOrderPaid → DispatchShipmentFulfillment (after commit)
 *   → FulfillOrderShipment job → ShippingCapabilities::fulfillerFor()->fulfillShipment()
 *   → KomerceShippingDriver → KomerceDeliveryFulfillment (store-order + pickup + label)
 *   → SyncOrderShippingFromShipments (Shopper OrderShipping + events).
 *
 * Manual QA tool: php scripts/ast-fulfillment-verify.php
 */

require __DIR__.'/../vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

$root = dirname(__DIR__);
$parser = (new ParserFactory)->createForVersion(PhpVersion::fromComponents(8, 4));
$finder = new NodeFinder;

$files = [
    'webhook_payment' => 'app/Integrations/Komerce/Http/Controllers/KomercePaymentWebhookController.php',
    'webhook_qrisly' => 'app/Integrations/Komerce/Http/Controllers/KomerceQrislyWebhookController.php',
    'webhook_delivery' => 'app/Integrations/Komerce/Http/Controllers/KomerceDeliveryWebhookController.php',
    'mark_paid_komerce' => 'app/Integrations/Komerce/Actions/Payment/MarkOrderPaidFromKomerce.php',
    'mark_paid' => 'app/Actions/Orders/MarkOrderPaid.php',
    'listener' => 'app/Listeners/FulfillShipmentsOnOrderPaid.php',
    'dispatch' => 'app/Actions/Shipping/DispatchShipmentFulfillment.php',
    'job' => 'app/Jobs/FulfillOrderShipment.php',
    'driver' => 'app/Integrations/Komerce/Shipping/KomerceShippingDriver.php',
    'fulfillment' => 'app/Integrations/Komerce/Services/KomerceDeliveryFulfillment.php',
    'delivery_client' => 'app/Integrations/Komerce/Services/ShippingDeliveryClient.php',
    'print_labels' => 'app/Actions/Shipping/PrintShipmentLabels.php',
    'sync_shopper' => 'app/Actions/Shipping/SyncOrderShippingFromShipments.php',
    'issue' => 'app/Actions/Shipping/IssueOrderFulfillment.php',
    'fulfillment_ui' => 'app/Livewire/Shopper/OrderFulfillment.php',
    'events' => 'app/Providers/AppServiceProvider.php',
    'schedule' => 'routes/console.php',
    'routes' => 'app/Integrations/Komerce/routes/webhooks.php',
];

/**
 * @return array{calls: list<string>, names: list<string>, strings: list<string>}
 */
function astIndex(Parser $parser, NodeFinder $finder, string $path): array
{
    $code = file_get_contents($path);
    if ($code === false) {
        throw new RuntimeException("Cannot read {$path}");
    }

    $ast = $parser->parse($code);
    if ($ast === null) {
        throw new RuntimeException("Cannot parse {$path}");
    }

    $calls = [];
    foreach ([...$finder->findInstanceOf($ast, Node\Expr\MethodCall::class), ...$finder->findInstanceOf($ast, Node\Expr\NullsafeMethodCall::class)] as $node) {
        if ($node->name instanceof Node\Identifier) {
            $calls[] = $node->name->toString();
        }
    }
    foreach ($finder->findInstanceOf($ast, Node\Expr\StaticCall::class) as $node) {
        $class = $node->class instanceof Node\Name ? $node->class->toString() : '';
        $method = $node->name instanceof Node\Identifier ? $node->name->toString() : '';
        $calls[] = $class === '' ? $method : "{$class}::{$method}";
    }
    foreach ($finder->findInstanceOf($ast, Node\Expr\FuncCall::class) as $node) {
        if ($node->name instanceof Node\Name) {
            $calls[] = $node->name->toString();
        }
    }
    foreach ($finder->findInstanceOf($ast, Node\Expr\New_::class) as $node) {
        if ($node->class instanceof Node\Name) {
            $calls[] = 'new '.$node->class->toString();
        }
    }

    $names = [];
    foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $node) {
        $names[] = $node->name->toString();
    }

    $strings = [];
    foreach ($finder->findInstanceOf($ast, Node\Scalar\String_::class) as $node) {
        $strings[] = $node->value;
    }

    $classes = [];
    foreach ($finder->findInstanceOf($ast, Node\Expr\ClassConstFetch::class) as $node) {
        if ($node->class instanceof Node\Name) {
            $classes[] = $node->class->toString();
        }
    }
    foreach ($finder->findInstanceOf($ast, Node\Name::class) as $node) {
        $classes[] = $node->toString();
    }

    return [
        'calls' => array_values(array_unique($calls)),
        'names' => $names,
        'strings' => $strings,
        'classes' => array_values(array_unique($classes)),
    ];
}

$index = [];
foreach ($files as $key => $relative) {
    $path = $root.'/'.$relative;
    $index[$key] = astIndex($parser, $finder, $path);
}

$failures = [];

$expectCall = static function (string $file, string $needle, string $why) use ($index, &$failures): void {
    $haystack = $index[$file]['calls'] ?? [];
    $hit = false;
    foreach ($haystack as $call) {
        if ($call === $needle || str_ends_with($call, $needle) || str_contains($call, $needle)) {
            $hit = true;
            break;
        }
    }
    if (! $hit) {
        $failures[] = "[{$file}] missing call `{$needle}` — {$why}";
    }
};

$expectMethod = static function (string $file, string $method, string $why) use ($index, &$failures): void {
    if (! in_array($method, $index[$file]['names'] ?? [], true)) {
        $failures[] = "[{$file}] missing method `{$method}()` — {$why}";
    }
};

$expectString = static function (string $file, string $needle, string $why) use ($index, &$failures): void {
    $hit = false;
    foreach ($index[$file]['strings'] ?? [] as $value) {
        if ($value === $needle || str_contains($value, $needle)) {
            $hit = true;
            break;
        }
    }
    if (! $hit) {
        $failures[] = "[{$file}] missing string `{$needle}` — {$why}";
    }
};

$expectClass = static function (string $file, string $needle, string $why) use ($index, &$failures): void {
    $hit = false;
    foreach ($index[$file]['classes'] ?? [] as $value) {
        if ($value === $needle || str_ends_with($value, $needle)) {
            $hit = true;
            break;
        }
    }
    if (! $hit) {
        $failures[] = "[{$file}] missing class `{$needle}` — {$why}";
    }
};

$expectCall('webhook_payment', 'handleWebhook', 'Payment webhook must verify HMAC via Komerce driver');
$expectCall('webhook_payment', 'handle', 'Payment webhook must mark the order paid');
$expectCall('webhook_qrisly', 'handle', 'QRISLY webhook must mark the order paid');
$expectCall('webhook_delivery', 'handle', 'Delivery webhook must apply tracking');
$expectClass('mark_paid_komerce', 'MarkOrderPaid', 'Komerce confirmation must hand over to the neutral MarkOrderPaid');
$expectClass('mark_paid', 'OrderPaid', 'MarkOrderPaid must fire Shopper OrderPaid');
$expectClass('events', 'FulfillShipmentsOnOrderPaid', 'OrderPaid must be wired to the fulfilment listener');
$expectClass('listener', 'DispatchShipmentFulfillment', 'Listener must dispatch shipment fulfilment');
$expectMethod('dispatch', 'handle', 'Dispatcher must exist');
$expectMethod('dispatch', 'dispatchOne', 'Dispatcher must send per-shipment work');
$expectCall('dispatch', 'terminating', 'HTTP path must run AWB after the webhook 200, not wait on unique queue lock');
$expectCall('dispatch', 'dispatch', 'Failed HTTP attempt must fall back to the queue');
$expectCall('dispatch', 'afterCommit', 'Must not create AWB before payment commit');
$expectCall('job', 'fulfillerFor', 'Job must route by the shipment driver');
$expectCall('job', 'fulfillShipment', 'Job must go through the FulfillsShipments driver');
$expectCall('driver', 'fulfill', 'Komerce driver must delegate to the Delivery fulfilment service');
$expectCall('fulfillment', 'storeOrder', 'Fulfilment must call official store-order');
$expectCall('fulfillment', 'requestPickup', 'Fulfilment must call official pickup to obtain AWB');
$expectCall('fulfillment', 'printOfficialLabel', 'Fulfilment must fetch official print-label after pickup');
$expectClass('fulfillment', 'SyncOrderShippingFromShipments', 'Fulfilment must record AWB on Shopper OrderShipping');
$expectMethod('delivery_client', 'storeOrder', 'Official store-order client method');
$expectMethod('delivery_client', 'requestPickup', 'Official pickup client method');
$expectMethod('delivery_client', 'printLabel', 'Official print-label client method');
$expectString('delivery_client', '/order/api/v1/orders/store', 'Store-order path');
$expectString('delivery_client', '/order/api/v1/pickup/request', 'Pickup path');
$expectString('delivery_client', '/order/api/v1/orders/print-label', 'Print-label path');
$expectString('routes', 'webhooks/komerce/payment', 'Payment webhook route');
$expectString('routes', 'webhooks/komerce/qrisly', 'QRISLY webhook route');
$expectString('routes', 'webhooks/komerce/delivery', 'Delivery webhook route');
$expectString('schedule', 'shipping:fulfill-paid', 'Scheduler must retry paid orders without AWB');
$expectCall('issue', 'fulfillShipment', 'Admin issue path must use the same driver call');
$expectClass('fulfillment_ui', 'IssueOrderFulfillment', 'Shopper label button must not open CRUD');
$expectCall('print_labels', 'printShipmentLabels', 'Print action must use the PrintsLabels driver');
$expectCall('sync_shopper', 'firstOrCreate', 'Shopper must record tracking, not invent it');
$expectCall('sync_shopper', 'logEvent', 'Provider scans must reach the Shopper shipment event log');

fwrite(STDOUT, 'AST files parsed: '.count($index).PHP_EOL);
foreach ($files as $key => $relative) {
    fwrite(STDOUT, sprintf(
        "  %-18s methods=%-2d calls=%-3d  %s\n",
        $key,
        count($index[$key]['names']),
        count($index[$key]['calls']),
        $relative,
    ));
}

if ($failures !== []) {
    fwrite(STDERR, PHP_EOL.'AST FAILURES:'.PHP_EOL);
    foreach ($failures as $failure) {
        fwrite(STDERR, '  - '.$failure.PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, PHP_EOL.'AST OK: paid webhook → MarkOrderPaid → OrderPaid → dispatch after commit → FulfillsShipments driver → store-order + pickup + print-label → Shopper record.'.PHP_EOL);
exit(0);
