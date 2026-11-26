<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Orders\GenerateInvoiceNumber;
use App\Http\Middleware\EnsureCpanelIsStaffOnly;
use App\Models\OrderShipment;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Shopper\Core\Enum\OrderRefundStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderRefund;

/**
 * Renders the printable HTML/PDF-ready A4 commercial / tax invoice for an order.
 */
final class OrderInvoiceController extends Controller
{
    public function customer(Request $request, Order $order): View
    {
        abort_unless((int) $order->customer_id === (int) $request->user()?->id, 403);

        return $this->renderInvoice($order, isStaff: false);
    }

    public function signedCustomer(Order $order): View
    {
        return $this->renderInvoice($order, isStaff: false);
    }

    public function staff(Request $request, Order $order): View
    {
        abort_unless(EnsureCpanelIsStaffOnly::userIsStaff($request->user()), 403);

        return $this->renderInvoice($order, isStaff: true);
    }

    private function renderInvoice(Order $order, bool $isStaff): View
    {
        $order->loadMissing([
            'items',
            'customer',
            'shippingAddress',
            'billingAddress',
            'shippingOption.carrier',
            'paymentMethod',
        ]);

        if ($order->payment_status === PaymentStatus::Paid && (! is_string($order->invoice_number) || trim($order->invoice_number) === '')) {
            $invoiceNumber = resolve(GenerateInvoiceNumber::class)->handle($order);
            $order->forceFill([
                'invoice_number' => $invoiceNumber,
                'paid_at' => $order->paid_at ?? now(),
            ])->save();
            $order->refresh();
        }

        $itemsSubtotal = (int) $order->items->sum(static fn ($item): int => (int) $item->unit_price_amount * (int) $item->quantity);
        $discountAmount = (int) $order->items->sum(static fn ($item): int => (int) ($item->discount_amount ?? 0));
        $shipmentsCost = (int) OrderShipment::query()->where('order_id', $order->id)->sum('cost');
        $shippingAmount = (int) ($order->shippingOption?->price ?? ($shipmentsCost > 0 ? $shipmentsCost : max(0, (int) $order->price_amount - $itemsSubtotal + $discountAmount)));
        $refundedTotal = (int) OrderRefund::query()
            ->where('order_id', $order->id)
            ->where('status', '!=', OrderRefundStatus::Rejected->value)
            ->where('status', '!=', OrderRefundStatus::Cancelled->value)
            ->sum('amount');

        $isPkp = (bool) (shopper_setting('is_pkp', false) ?? false);
        $grandTotal = (int) $order->price_amount;
        $dpp = $isPkp ? (int) round($grandTotal / 1.11) : $grandTotal;
        $ppn = $isPkp ? max(0, $grandTotal - $dpp) : 0;

        return view('invoices.order', [
            'order' => $order,
            'isStaff' => $isStaff,
            'storeName' => (string) (shopper_setting('name', false) ?: config('app.name', 'OceanMall')),
            'legalName' => (string) (shopper_setting('pkp_name', false) ?: shopper_setting('legal_name', false) ?: shopper_setting('name', false) ?: config('app.name')),
            'storeEmail' => (string) (shopper_setting('email', false) ?: ''),
            'storePhone' => (string) (shopper_setting('phone_number', false) ?: ''),
            'storeAddress' => (string) (shopper_setting('pkp_address', false) ?: trim(implode(', ', array_filter([
                shopper_setting('street_address', false),
                shopper_setting('city', false),
                shopper_setting('state', false),
                shopper_setting('postal_code', false),
            ])))),
            'isPkp' => $isPkp,
            'companyNpwp' => (string) (shopper_setting('company_npwp', false) ?: ''),
            'itemsSubtotal' => $itemsSubtotal,
            'discountAmount' => $discountAmount,
            'shippingAmount' => $shippingAmount,
            'refundedTotal' => $refundedTotal,
            'grandTotal' => $grandTotal,
            'dpp' => $dpp,
            'ppn' => $ppn,
        ]);
    }
}
