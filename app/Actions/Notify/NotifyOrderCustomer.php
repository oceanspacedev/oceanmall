<?php

declare(strict_types=1);

namespace App\Actions\Notify;

use App\Enums\OrderNotificationType;
use App\Models\User;
use App\Notifications\OrderStatusMail;
use App\Notifications\OrderStatusNotification;
use Illuminate\Support\Facades\DB;
use Shopper\Core\Models\Order;
use Throwable;

final class NotifyOrderCustomer
{
    /**
     * Tell the customer once per order and type. Best effort by design:
     * a notification that cannot be stored or mailed is reported and never
     * stops what the caller is doing (placing an order, marking it paid,
     * cancelling it, syncing a shipment). Inside a database transaction it
     * waits for the commit: a failed insert would otherwise leave a
     * PostgreSQL transaction aborted even when the exception is caught, and
     * a mail would be sent for work that is rolled back afterwards.
     */
    public function handle(Order $order, OrderNotificationType $type): void
    {
        DB::afterCommit(function () use ($order, $type): void {
            try {
                $customer = $order->customer;

                if (! $customer instanceof User || $this->alreadySent($customer, $order, $type)) {
                    return;
                }

                $customer->notify(new OrderStatusNotification($order, $type));
            } catch (Throwable $e) {
                report($e);

                return;
            }

            // The e-mail goes through the queue (retried there); a queue
            // that cannot take it is reported, never thrown.
            try {
                $customer->notify(new OrderStatusMail($order, $type));
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * Each type is sent once per order. `notifications.data` is a TEXT
     * column (Laravel's default migration), so it is never queried with a
     * JSON path: PostgreSQL has no `->>` operator for text and the query
     * would fail there. The customer's notifications since the order exists
     * are few; they are compared in PHP instead, the same on every database.
     */
    private function alreadySent(User $customer, Order $order, OrderNotificationType $type): bool
    {
        $query = $customer->notifications()
            ->where('type', OrderStatusNotification::class);

        if ($order->created_at !== null) {
            $query->where('created_at', '>=', $order->created_at->copy()->subMinute());
        }

        foreach ($query->cursor() as $notification) {
            $data = is_array($notification->data) ? $notification->data : [];

            if ((string) ($data['order_id'] ?? '') === (string) $order->id
                && ($data['type'] ?? null) === $type->value) {
                return true;
            }
        }

        return false;
    }
}
