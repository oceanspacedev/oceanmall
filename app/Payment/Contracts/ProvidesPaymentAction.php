<?php

declare(strict_types=1);

namespace App\Payment\Contracts;

use Shopper\Core\Models\Order;
use Shopper\Payment\DataTransferObjects\PaymentResult;

/**
 * A payment driver that tells the customer how to pay an initiated order
 * (VA number, QR string, hosted page, client secret...). Storefront, account
 * pages and the mobile API render the neutral payment action it returns, so a
 * new gateway needs no new page props or branches.
 *
 * Drivers without this contract get a generic action built from Shopper's
 * PaymentResult (client secret, redirect URL), kept on the Initiate
 * PaymentTransaction Shopper records (metadata.payment_action) so later reads
 * (account order page, mobile order screen, success-page reload) return the
 * same action; Shopper's manual driver gets 'none'. Implement this contract
 * when the action can change after initiation (expiring VA, QR refresh).
 */
interface ProvidesPaymentAction
{
    /**
     * Called after Shopper's PaymentProcessingService::initiate() recorded the
     * Initiate transaction: persist whatever paymentAction() needs later.
     */
    public function afterPaymentInitiated(Order $order, PaymentResult $result): void;

    /**
     * The pending payment action for an unpaid order, or null when there is
     * none (not initiated, payment setup failed).
     *
     * `data` for kind 'instructions' / 'qr' is the store's neutral payment
     * instructions payload, rendered by payment-instructions-panel.vue (web)
     * and the mobile order screens; a gateway maps its answer onto it:
     *
     *   payment_id              string   provider payment reference
     *   payment_type            'bank_transfer' | 'qris' | other
     *   virtual_account_number  ?string  for bank_transfer
     *   bank_code               ?string  bank of the VA (e.g. "BCA")
     *   qris_string             ?string  raw QRIS payload (the page draws the QR)
     *   payment_url             ?string  optional hosted page / deeplink
     *   expiry_date             ?string  ISO-8601
     *   amount                  int      amount to pay, as stored on the order
     *   currency_code           string
     *
     * kind 'redirect' carries data.url; 'client_secret' carries
     * data.client_secret (+ data.publishable_key / data.return_url).
     *
     * @return array{kind: 'instructions'|'redirect'|'client_secret'|'qr'|'none', data: array<string, mixed>}|null
     */
    public function paymentAction(Order $order): ?array;
}
