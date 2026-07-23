<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Talks to an external POS system's inbound stock endpoints (e.g. the Odoo
 * connector addon's `/fleetbase/stock/reserve` and `/fleetbase/stock/release`
 * routes) so Storefront checkout can confirm-and-decrement stock synchronously
 * before capturing payment, instead of trusting a possibly-stale local mirror.
 *
 * A store opts into this by setting `meta.pos_connector = ['base_url' => ..., 'token' => ...]`.
 * Stores without that meta skip the check entirely (nothing to reconcile against).
 */
class PosStockClient
{
    /**
     * Whether this store has a POS connector configured at all.
     */
    public static function isConfigured(Store $store): bool
    {
        return (bool) data_get($store->getMeta('pos_connector'), 'base_url');
    }

    /**
     * Check-and-reserve stock for a cart's line items against the POS system.
     *
     * Returns ['success' => bool, 'reservation_id' => ?string, 'message' => ?string].
     * `success => true` with a null `reservation_id` means no connector is
     * configured for this store, so the check was skipped.
     */
    public static function reserve(Store $store, iterable $cartItems): array
    {
        $config  = $store->getMeta('pos_connector');
        $baseUrl = data_get($config, 'base_url');

        if (!$baseUrl) {
            return ['success' => true, 'reservation_id' => null, 'message' => null];
        }

        $lines = static::linesFromCartItems($cartItems);
        if (empty($lines)) {
            return ['success' => true, 'reservation_id' => null, 'message' => null];
        }

        $reservationId = (string) Str::uuid();

        try {
            $response = Http::withToken(data_get($config, 'token'))
                ->timeout(5)
                ->post(static::endpoint($baseUrl, 'reserve'), [
                    'reservation_id' => $reservationId,
                    'lines'          => $lines,
                ]);

            if ($response->failed() || !data_get($response->json(), 'success', false)) {
                return [
                    'success'        => false,
                    'reservation_id' => null,
                    'message'        => data_get($response->json(), 'message', 'Some items in your cart are no longer in stock.'),
                ];
            }

            return [
                'success'        => true,
                'reservation_id' => data_get($response->json(), 'reservation_id', $reservationId),
                'message'        => null,
            ];
        } catch (\Throwable $e) {
            Log::error('[storefront] POS stock reserve call failed: ' . $e->getMessage());

            return [
                'success'        => false,
                'reservation_id' => null,
                'message'        => 'Unable to confirm stock availability right now, please try again.',
            ];
        }
    }

    /**
     * Release a stock reservation - called when order/payment creation fails
     * after a successful reserve(), so the POS system's decrement is undone.
     */
    public static function release(Store $store, ?string $reservationId): void
    {
        if (!$reservationId) {
            return;
        }

        $baseUrl = data_get($store->getMeta('pos_connector'), 'base_url');
        if (!$baseUrl) {
            return;
        }

        try {
            Http::withToken(data_get($store->getMeta('pos_connector'), 'token'))
                ->timeout(5)
                ->post(static::endpoint($baseUrl, 'release'), ['reservation_id' => $reservationId]);
        } catch (\Throwable $e) {
            Log::error('[storefront] POS stock release call failed: ' . $e->getMessage());
        }
    }

    private static function linesFromCartItems(iterable $cartItems): array
    {
        $lines = [];

        foreach ($cartItems as $cartItem) {
            $product = Product::where('public_id', $cartItem->product_id)->first();

            if (!$product || !$product->external_id) {
                continue;
            }

            $lines[] = [
                'external_id' => $product->external_id,
                'sku'         => $product->sku,
                'quantity'    => $cartItem->quantity,
            ];
        }

        return $lines;
    }

    private static function endpoint(string $baseUrl, string $action): string
    {
        return rtrim($baseUrl, '/') . '/fleetbase/stock/' . $action;
    }
}
