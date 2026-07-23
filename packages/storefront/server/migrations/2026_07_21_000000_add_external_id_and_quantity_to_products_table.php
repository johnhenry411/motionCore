<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection(config('storefront.connection.db'))->table('products', function (Blueprint $table) {
            $table->string('external_id', 191)->nullable()->after('sku')->index();
            $table->integer('quantity')->nullable()->after('sale_price');
            $table->unique(['store_uuid', 'external_id'], 'products_store_uuid_external_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection(config('storefront.connection.db'))->table('products', function (Blueprint $table) {
            $table->dropUnique('products_store_uuid_external_id_unique');
            $table->dropColumn(['external_id', 'quantity']);
        });
    }
};
