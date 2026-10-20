<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The seller COD Remittance page (and the courier status webhooks that set
 * delivered_date) expect these columns on `orders`. Older imported databases
 * may not have them. Each column is only added if it is missing, so this is
 * safe to run on any copy of the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'payment_status')) {
                $table->string('payment_status')->nullable();
            }
            if (!Schema::hasColumn('orders', 'delivered_date')) {
                $table->date('delivered_date')->nullable();
            }
            if (!Schema::hasColumn('orders', 'shipping_status')) {
                $table->string('shipping_status')->nullable();
            }
            if (!Schema::hasColumn('orders', 'all_courier_name')) {
                $table->string('all_courier_name')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Intentionally left empty: these columns may have existed before this
        // migration ran, so dropping them could delete real data.
    }
};
