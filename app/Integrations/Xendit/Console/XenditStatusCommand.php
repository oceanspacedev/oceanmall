<?php

declare(strict_types=1);

namespace App\Integrations\Xendit\Console;

use App\Integrations\Xendit\Payment\XenditDriver;
use App\Integrations\Xendit\Xendit;
use App\Payment\PaymentMethodCatalog;
use App\Support\OperatorSwitch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Shopper\Core\Models\PaymentMethod;

/**
 * Readiness report of the Xendit module, without calling Xendit: key mode
 * (test / live), why a key is refused, operator switch, webhook URL and
 * the Xendit payment methods enabled in cpanel. Secrets are never printed.
 */
final class XenditStatusCommand extends Command
{
    protected $signature = 'xendit:status {--json : Print machine-readable JSON}';

    protected $description = 'Show whether the Xendit payment module is configured, its mode and webhook URL';

    public function handle(): int
    {
        $report = [
            'configured' => Xendit::configured(),
            'mode' => Xendit::mode(),
            'refusal' => Xendit::refusal(),
            'operator_switch' => OperatorSwitch::switchedOn(OperatorSwitch::PAYMENT, XenditDriver::CODE),
            // The store sells through one gateway at a time (PAYMENT_PROVIDER).
            'selected' => OperatorSwitch::on(OperatorSwitch::PAYMENT, XenditDriver::CODE),
            'checkout_ready' => (new PaymentMethodCatalog)->driverReady(XenditDriver::CODE),
            'callback_token_set' => Xendit::callbackToken() !== '',
            'webhook_url' => Route::has('webhooks.xendit.invoice') ? route('webhooks.xendit.invoice') : null,
            'enabled_methods' => PaymentMethod::query()
                ->where('driver', XenditDriver::CODE)
                ->where('is_enabled', true)
                ->orderBy('slug')
                ->pluck('slug')
                ->all(),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(['Check', 'Value'], [
            ['Configured', $report['configured'] ? 'yes' : 'no'],
            ['Mode', $report['mode'] ?? '-'],
            ['Refusal', $report['refusal'] ?? '-'],
            ['Operator switch (PAYMENT_XENDIT_ENABLED)', $report['operator_switch'] ? 'on' : 'off'],
            ['Selected gateway (PAYMENT_PROVIDER)', $report['selected'] ? 'yes' : 'no'],
            ['Offered at checkout', $report['checkout_ready'] ? 'yes' : 'no'],
            ['Callback token set', $report['callback_token_set'] ? 'yes' : 'no'],
            ['Webhook URL', $report['webhook_url'] ?? '-'],
            ['Enabled methods', $report['enabled_methods'] === [] ? '-' : implode(', ', $report['enabled_methods'])],
        ]);

        return self::SUCCESS;
    }
}
