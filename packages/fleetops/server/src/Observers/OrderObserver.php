<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\LiveCacheService;
use Illuminate\Support\Facades\Cache;

class OrderObserver
{
    /**
     * Handle the Order "created" event.
     *
     * @return void
     */
    public function created(Order $order)
    {
        $this->invalidateCache($order);
        $this->applyDriverAssignmentAcceptanceGateOnCreate($order);
    }

    /**
     * Handle the Order "updating" event.
     *
     * This event is fired before the order is persisted to the database.
     * It is used to mutate attributes as part of the same update operation
     * without triggering additional save cycles.
     *
     * @param Order $order The order being updated
     */
    public function updating(Order $order): void
    {
        $this->ensureOrderStarted($order);
        $this->applyDriverAssignmentAcceptanceGate($order);
    }

    /**
     * Handle the Order "updated" event.
     *
     * @return void
     */
    public function updated(Order $order)
    {
        $order->setDriverLocationAsPickup();

        if ($order->wasChanged('driver_assigned_uuid')) {
            $order->notifyDriverAssigned();
        }

        $this->invalidateCache($order);
    }

    /**
     * Handle the Order "deleted" event.
     *
     * @return void
     */
    public function deleted(Order $order)
    {
        if ($order->isIntegratedVendorOrder()) {
            $order->facilitator->provider()->callback('onDeleted', $order);
        }

        $this->invalidateCache($order);
    }

    /**
     * Invalidate relevant cache tags for live endpoints.
     *
     * @param Order|null $order Optional order to invalidate specific tracker cache
     */
    protected function invalidateCache(?Order $order = null): void
    {
        LiveCacheService::invalidateMultiple(['orders', 'routes', 'coordinates']);

        // Invalidate order-specific tracker cache if order is provided
        if ($order && $order->uuid) {
            Cache::forget("order:{$order->uuid}:tracker");
        }
    }

    /**
     * Detects when an order has just transitioned to the "started" status
     * and initializes start-related fields.
     *
     * This method should be called during the "updating" lifecycle event
     * to ensure that the changes are persisted as part of the same database
     * update and do not trigger additional observer events.
     *
     * An order is considered "started" when:
     * - The "status" attribute is being changed in the current update
     * - The previous status was not "started"
     * - The new status is "started"
     *
     * When these conditions are met, the order's start timestamp and
     * started flag are set if they have not already been initialized.
     *
     * @param Order $order The order being evaluated for a start transition
     */
    protected function ensureOrderStarted(Order $order): void
    {
        if (
            $order->isDirty('status')
            && $order->getOriginal('status') === 'dispatched'
            && $order->status === 'started'
        ) {
            // Only set defaults if not explicitly provided
            if (is_null($order->started_at)) {
                $order->started_at = now();
            }

            if (!$order->started) {
                $order->started = true;
            }
        }
    }

    /**
     * Gates newly-set direct driver assignments behind a pending-acceptance
     * state, so the driver must explicitly accept/decline before the order
     * can proceed. Ad-hoc broadcast orders and explicit silent assignments
     * (Order::$skipAssignmentAcceptance) keep today's instant-assign behavior.
     *
     * Runs during "updating" so the mutation is persisted as part of the same
     * save the caller already triggered (console PATCH, Order::assignDriver(),
     * or the Orchestration engine's direct writes to driver_assigned_uuid).
     *
     * @param Order $order The order being evaluated for a driver assignment change
     */
    protected function applyDriverAssignmentAcceptanceGate(Order $order): void
    {
        // Accept/decline endpoints set a terminal status explicitly in the same
        // save as clearing/confirming the assignment -- don't override those.
        if ($order->isDirty('driver_assignment_status') && in_array($order->driver_assignment_status, ['accepted', 'declined'], true)) {
            return;
        }

        if (!$order->isDirty('driver_assigned_uuid')) {
            return;
        }

        if (empty($order->driver_assigned_uuid)) {
            $order->driver_assignment_status         = null;
            $order->driver_assignment_requested_at    = null;
            $order->driver_assignment_responded_at    = null;

            return;
        }

        if ($order->adhoc === true || $order->skipAssignmentAcceptance === true) {
            $order->driver_assignment_status = null;

            return;
        }

        $order->driver_assignment_status       = 'pending';
        $order->driver_assignment_requested_at = now();
        $order->driver_assignment_responded_at = null;
    }

    /**
     * Mirrors applyDriverAssignmentAcceptanceGate() for orders created with a
     * driver already assigned (e.g. OrderController::create() accepts a
     * `driver` param and sets driver_assigned_uuid before the model exists) --
     * "updating" never fires for a brand new model, so this gate and the
     * driver's notification must be applied explicitly here instead.
     *
     * @param Order $order The order that was just created
     */
    protected function applyDriverAssignmentAcceptanceGateOnCreate(Order $order): void
    {
        if (empty($order->driver_assigned_uuid) || $order->adhoc === true || $order->skipAssignmentAcceptance === true) {
            return;
        }

        $order->driver_assignment_status       = 'pending';
        $order->driver_assignment_requested_at = now();
        $order->save();

        $order->notifyDriverAssigned();
    }
}
