<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add driver assignment acceptance tracking to the orders table.
 *
 * New columns:
 *   - driver_assignment_status        null|pending|accepted|declined
 *   - driver_assignment_requested_at  When the driver was asked to accept/decline
 *   - driver_assignment_responded_at  When the driver accepted or declined
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('driver_assignment_status')->nullable()->after('driver_assigned_uuid');
            $table->dateTime('driver_assignment_requested_at')->nullable()->after('driver_assignment_status');
            $table->dateTime('driver_assignment_responded_at')->nullable()->after('driver_assignment_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'driver_assignment_status',
                'driver_assignment_requested_at',
                'driver_assignment_responded_at',
            ]);
        });
    }
};
