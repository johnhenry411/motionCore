<?php

namespace Fleetbase\FleetOps\Listeners;

use Fleetbase\FleetOps\Events\OrderDriverAssigned;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\OrderAssigned;
use Fleetbase\FleetOps\Notifications\OrderAssignmentRequested;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class HandleOrderDriverAssigned implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     *
     * @param object $event
     *
     * @return void
     */
    public function handle(OrderDriverAssigned $event)
    {
        /** @var Order $order */
        $order = $event->getModelRecord();

        // halt if unable to resolve order record from event
        if (!$order instanceof Order) {
            return;
        }

        /** @var Driver */
        $driver = Driver::where('uuid', $order->driver_assigned_uuid)->withoutGlobalScopes()->first();
        $order->setRelation('driverAssigned', $driver);

        if (!$driver) {
            return;
        }

        // ad-hoc broadcast orders have their own acceptance mechanism (OrderPing) - no-op here
        if ($order->adhoc === true) {
            return;
        }

        // driver must explicitly accept/decline this assignment
        if ($order->driver_assignment_status === 'pending') {
            $driver->notify(new OrderAssignmentRequested($order));

            return;
        }

        // legacy/explicit-skip path (e.g. Order::assignDriver($driver, true))
        $driver->notify(new OrderAssigned($order));
    }
}
