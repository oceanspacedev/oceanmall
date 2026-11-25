<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\OrderNotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Shopper\Core\Models\Order;

/**
 * The e-mail that goes with an order status notification, sent by the queue.
 *
 * Placing an order, a paid webhook and a cancellation must not wait for (or
 * fail with) the mail transport: the in-app notification
 * (OrderStatusNotification) is stored at once, the mail is retried by the
 * queue worker. Sent only by App\Actions\Notify\NotifyOrderCustomer, which
 * decides once per order and type.
 */
final class OrderStatusMail extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        private readonly Order $order,
        private readonly OrderNotificationType $type,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $number = (string) $this->order->number;

        return (new MailMessage)
            ->subject($this->type->title().' — '.$number)
            ->greeting('Halo,')
            ->line($this->type->body($number))
            ->action('Lihat pesanan', route('account.orders.show', $this->order))
            ->line('Terima kasih telah belanja di OceanMall.');
    }
}
