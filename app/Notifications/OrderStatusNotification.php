<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\OrderNotificationType;
use Illuminate\Notifications\Notification;
use Shopper\Core\Models\Order;

final class OrderStatusNotification extends Notification
{
    public function __construct(
        private readonly Order $order,
        private readonly OrderNotificationType $type,
    ) {}

    /**
     * @return list<string>
     */
    /**
     * The in-app notification only, stored at once (NotifyOrderCustomer
     * reads it to send each type once). The e-mail is OrderStatusMail, sent
     * by the queue.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{title: string, body: string, order_id: int, order_number: string, url: string, type: string}
     */
    public function toDatabase(object $notifiable): array
    {
        $number = (string) $this->order->number;

        return [
            'title' => $this->type->title(),
            'body' => $this->type->body($number),
            'order_id' => (int) $this->order->id,
            'order_number' => $number,
            'url' => route('account.orders.show', $this->order),
            'type' => $this->type->value,
        ];
    }
}
