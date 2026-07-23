<?php

namespace Fleetbase\Storefront\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;

class RecordPosSaleRequest extends FleetbaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return session('storefront_key') || request()->session()->has('api_credential');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'pos_reference'        => 'required|string|max:191',
            'lines'                => 'required|array|min:1',
            'lines.*.name'         => 'required|string',
            'lines.*.sku'          => 'nullable|string',
            'lines.*.quantity'     => 'required|numeric|min:0',
            'lines.*.price'        => 'required|numeric|min:0',
            'subtotal'             => 'required|numeric|min:0',
            'total'                => 'required|numeric|min:0',
            'currency'             => 'nullable|string|size:3',
            'sold_at'              => 'nullable|date',
            'customer'             => 'nullable|array',
            'customer.external_id' => 'nullable|string',
            'customer.name'        => 'nullable|string',
            'customer.email'       => 'nullable|email',
            'customer.phone'       => 'nullable|string',
        ];
    }
}
