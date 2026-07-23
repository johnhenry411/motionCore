<?php

namespace Fleetbase\Storefront\Http\Controllers\v1;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Storefront\Http\Requests\RecordPosSaleRequest;
use Fleetbase\Storefront\Http\Requests\SyncCustomerRequest;
use Fleetbase\Storefront\Http\Requests\SyncProductRequest;
use Fleetbase\Storefront\Http\Resources\Customer as CustomerResource;
use Fleetbase\Storefront\Http\Resources\Product as StorefrontProduct;
use Fleetbase\Storefront\Models\Customer;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Support\Utils;

/**
 * Server-to-server sync endpoints for an external POS system (e.g. Odoo).
 *
 * These are intentionally separate from the console-facing CRUD/checkout
 * endpoints: a POS push is a trusted, already-decided write (a sale already
 * happened, a product already changed in the source of truth), not a
 * customer-facing cart/checkout flow, so it should not run through
 * CheckoutController's service-quote/payment/gateway logic.
 */
class PosSyncController extends Controller
{
    /**
     * Upsert a Storefront product pushed from the POS system's catalog.
     *
     * Matched on (store_uuid, external_id). Odoo is the source of truth for
     * catalog/price/stock, so this is a one-way push - Storefront never
     * writes back to the POS from this endpoint.
     */
    public function syncProduct(SyncProductRequest $request)
    {
        $input = $request->only(['external_id', 'name', 'description', 'sku', 'barcode', 'price', 'sale_price', 'currency', 'quantity', 'is_available']);

        $input['store_uuid']   = session('storefront_store');
        $input['company_uuid'] = session('company');
        $input['currency']     = data_get($input, 'currency', session('storefront_currency', 'USD'));
        $input['price']        = Utils::numbersOnly(data_get($input, 'price', 0));
        $input['sale_price']   = Utils::numbersOnly(data_get($input, 'sale_price', 0));

        $product = Product::where([
            'store_uuid'  => session('storefront_store'),
            'external_id' => $input['external_id'],
        ])->first();

        if ($product) {
            $product->update($input);
        } else {
            $product = Product::create($input);
        }

        return new StorefrontProduct($product);
    }

    /**
     * Match-or-create a Storefront customer pushed from the POS system.
     *
     * Matches by email/phone within the company before creating, so a
     * customer who has already ordered online is not duplicated when they
     * later walk into the store (and vice-versa).
     */
    public function syncCustomer(SyncCustomerRequest $request)
    {
        $customer = $this->findOrCreateCustomer($request->only(['external_id', 'name', 'email', 'phone']));

        return new CustomerResource($customer);
    }

    /**
     * Record a completed POS sale as a lightweight, non-delivery Order.
     *
     * `payload_uuid` is intentionally left unset - this is not a delivery,
     * it is a walk-out sale being mirrored in for reporting so Storefront's
     * dashboard (AnalyticsController::orders()) can blend online and
     * in-store revenue. Sales that request delivery are created directly
     * against the Fleet-Ops order API by the POS connector instead, since
     * those are genuine delivery orders with a payload.
     */
    public function recordSale(RecordPosSaleRequest $request)
    {
        $storeUuid   = session('storefront_store');
        $companyUuid = session('company');
        $currency    = $request->input('currency', session('storefront_currency', 'USD'));

        $customerInput = $request->input('customer');
        $customer      = null;
        if (is_array($customerInput) && (data_get($customerInput, 'email') || data_get($customerInput, 'phone'))) {
            $customer = $this->findOrCreateCustomer($customerInput);
        }

        $order = Order::create([
            'company_uuid'  => $companyUuid,
            'internal_id'   => $request->input('pos_reference'),
            'customer_uuid' => $customer?->uuid,
            'customer_type' => $customer ? Utils::getMutationType('fleet-ops:contact') : null,
            'type'          => 'pos',
            'status'        => 'completed',
            'meta'          => [
                'source_system' => 'Shopit_pos',
                'pos_reference' => $request->input('pos_reference'),
                'storefront_id' => Store::where('uuid', $storeUuid)->value('public_id'),
                'lines'         => $request->input('lines'),
                // normalized the same way as CheckoutController's order totals (Utils::numbersOnly), so
                // AnalyticsController::sumOrderRevenue() sums pos and storefront orders on the same unit scale
                'subtotal'      => Utils::numbersOnly($request->input('subtotal')),
                'total'         => Utils::numbersOnly($request->input('total')),
                'currency'      => $currency,
                'sold_at'       => $request->input('sold_at', now()->toIso8601String()),
            ],
        ]);

        return response()->json(['order' => $order->public_id]);
    }

    /**
     * Find a customer by email/phone within the current company, or create
     * one and stamp it with the POS system's own record id for reconciliation.
     */
    private function findOrCreateCustomer(array $input): Customer
    {
        $companyUuid = session('company');
        $email       = data_get($input, 'email');
        $phone       = data_get($input, 'phone') ? CustomerController::phone(data_get($input, 'phone')) : null;

        $customer = Customer::where('company_uuid', $companyUuid)
            ->where(function ($query) use ($email, $phone) {
                if ($email) {
                    $query->orWhere('email', $email);
                }
                if ($phone) {
                    $query->orWhere('phone', $phone);
                }
            })
            ->first();

        if ($customer) {
            $customer->update([
                'name'  => data_get($input, 'name', $customer->name),
                'email' => $email ?: $customer->email,
                'phone' => $phone ?: $customer->phone,
                'meta'  => array_merge((array) $customer->meta, ['odoo_partner_id' => data_get($input, 'external_id')]),
            ]);

            return $customer;
        }

        return Customer::create([
            'company_uuid' => $companyUuid,
            'name'         => data_get($input, 'name'),
            'email'        => $email,
            'phone'        => $phone,
            'meta'         => [
                'odoo_partner_id' => data_get($input, 'external_id'),
                'source'          => 'odoo_pos',
            ],
        ]);
    }
}
