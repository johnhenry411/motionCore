<?php

namespace App\Observers;

use Fleetbase\FleetOps\Models\Vehicle;

class VehicleObserver
{
    protected array $decimalFields = [
        'loan_amount',
        'cargo_volume',
        'passenger_volume',
        'interior_volume',
        'weight',
        'width',
        'length',
        'height',
        'towing_capacity',
        'payload_capacity',
        'payload_capacity_volume',
        'ground_clearance',
        'bed_length',
        'fuel_capacity',
        'engine_displacement',
        'engine_size',
        'horsepower',
        'torque',
        'gvwr',
        'gcwr',
    ];

    public function creating(Vehicle $vehicle): void
    {
        $this->sanitizeDecimalFields($vehicle);
    }

    public function updating(Vehicle $vehicle): void
    {
        $this->sanitizeDecimalFields($vehicle);
    }

    protected function sanitizeDecimalFields(Vehicle $vehicle): void
    {
        foreach ($this->decimalFields as $field) {
            $value = $vehicle->getAttribute($field);
            if ($value !== null && $value !== '') {
                // Strip currency symbols, commas, spaces — keep digits, dot, minus
                $cleaned = preg_replace('/[^\d.\-]/', '', (string) $value);
                $vehicle->setAttribute($field, $cleaned === '' ? null : $cleaned);
            }
        }
    }
}
