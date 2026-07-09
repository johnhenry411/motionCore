<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SanitizeDecimalInputs
{
    /**
     * Strip currency symbols, commas, and spaces from any input value
     * that looks like a formatted monetary/decimal string (e.g. "KSh1,500,000.00").
     * Runs before the request reaches controllers so Eloquent decimal casts
     * never see a non-numeric value.
     */
    public function handle(Request $request, Closure $next)
    {
        $input = $request->all();
        array_walk_recursive($input, function (&$value) {
            if (is_string($value) && $this->looksLikeCurrencyFormatted($value)) {
                $value = preg_replace('/[^\d.\-]/', '', $value);
            }
        });
        $request->replace($input);

        return $next($request);
    }

    /**
     * Returns true only for strings like "KSh1,500,000.00" or "$1,234.56" —
     * i.e. an optional currency prefix (letters only at the start) followed
     * by digits, commas, and periods. Rejects UUIDs, slugs, and any value
     * where letters appear after the first digit.
     */
    protected function looksLikeCurrencyFormatted(string $value): bool
    {
        // Must have at least one digit
        if (!preg_match('/\d/', $value)) {
            return false;
        }
        // Skip plain numbers — nothing to strip
        if (is_numeric($value)) {
            return false;
        }
        // Pattern: optional leading currency code/symbol, then digits/commas/periods only
        // Letters AFTER the first digit disqualify it (catches UUIDs, slugs, public_ids)
        if (!preg_match('/^[A-Za-z$£€¥₦₹₱]{0,5}[\s]?[\d,. ]+$/', $value)) {
            return false;
        }
        // After stripping, must be a valid number
        $cleaned = preg_replace('/[^\d.\-]/', '', $value);

        return is_numeric($cleaned) && $cleaned !== '';
    }
}
