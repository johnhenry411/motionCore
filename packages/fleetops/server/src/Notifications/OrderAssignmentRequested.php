<?php

namespace Fleetbase\FleetOps\Notifications;

use Fleetbase\Events\ResourceLifecycleEvent;
use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Support\PushNotification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnChannel;
use NotificationChannels\Fcm\FcmChannel;

class OrderAssignmentRequested extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The order instance this notification is for.
     *
     * @var Order
     */
    public $order;

    /**
     * Notification name.
     */
    public static string $name = 'Order Assignment Requested';

    /**
     * Notification description.
     */
    public static string $description = 'Notify a driver they have been directly assigned an order and must accept or decline it.';

    /**
     * Notification package.
     */
    public static string $package = 'fleet-ops';

    /**
     * The title of the notification.
     */
    public string $title;

    /**
     * The message body of the notification.
     */
    public string $message;

    /**
     * Additional data to be sent with the notification.
     */
    public array $data = [];

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        $this->order   = $order;
        $this->title   = 'New order assignment — action required';
        $this->message = 'You\'ve been assigned order ' . $this->order->trackingNumber->tracking_number . '. Tap to accept or decline.';
        $this->data    = ['id' => $this->order->public_id, 'type' => 'order_assignment_requested'];
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array
     */
    public function via($notifiable)
    {
        return ['broadcast', FcmChannel::class, ApnChannel::class];
    }

    /**
     * Get the type of the notification being broadcast.
     *
     * @return string
     */
    public function broadcastType()
    {
        return 'order.assignment_requested';
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return Channel|array
     */
    public function broadcastOn()
    {
        return [
            new Channel('company.' . session('company', data_get($this->order, 'company.uuid'))),
            new Channel('company.' . data_get($this->order, 'company.public_id')),
            new Channel('api.' . session('api_credential')),
            new Channel('order.' . $this->order->uuid),
            new Channel('order.' . $this->order->public_id),
            new Channel('driver.' . data_get($this->order, 'driverAssigned.uuid')),
            new Channel('driver.' . data_get($this->order, 'driverAssigned.public_id')),
        ];
    }

    /**
     * Get notification as array.
     *
     * @return void
     */
    public function toArray()
    {
        return [
            'event' => 'order.assignment_requested',
            'title' => $this->title,
            'body'  => $this->message,
            'data'  => $this->data,
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     *
     * @return BroadcastMessage
     */
    public function toBroadcast($notifiable)
    {
        $resource     = new OrderResource($this->order);
        $resourceData = [];

        if (method_exists($resource, 'toWebhookPayload')) {
            $resourceData = $resource->toWebhookPayload();
        } elseif (method_exists($resource, 'toArray')) {
            $resourceData = $resource->toArray(request());
        }

        $resourceData = ResourceLifecycleEvent::transformResourceChildrenToId($resourceData);

        $data = [
            'id'          => uniqid('event_'),
            'api_version' => config('api.version'),
            'event'       => 'order.assignment_requested',
            'created_at'  => now()->toDateTimeString(),
            'data'        => $resourceData,
        ];

        return new BroadcastMessage($data);
    }

    /**
     * Get the firebase cloud message representation of the notification.
     *
     * @return array
     */
    public function toFcm($notifiable)
    {
        return PushNotification::createFcmMessage($this->title, $this->message, $this->data);
    }

    /**
     * Get the apns message representation of the notification.
     *
     * @return array
     */
    public function toApn($notifiable)
    {
        return PushNotification::createApnMessage($this->title, $this->message, $this->data, 'view_order');
    }
}
