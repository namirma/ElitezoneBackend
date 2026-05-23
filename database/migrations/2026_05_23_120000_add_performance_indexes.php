<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes for heavily-queried columns.
 *
 * - orders.status / payment_status: filtered by every "current/delivered/cancelled"
 *   scope, every admin list view, and the reservation expiry job.
 * - orders.reservation_expires_at: scanned by CheckReservationExpiryJob each minute.
 * - orders composite (placer_user_id, status): user-facing "my orders" filtering.
 * - users.national_code: hot path in admin back-office user search.
 *
 * All operations are additive — no data is modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('status', 'orders_status_idx');
            $table->index('payment_status', 'orders_payment_status_idx');
            $table->index('reservation_expires_at', 'orders_reservation_expires_at_idx');
            $table->index(['placer_user_id', 'status'], 'orders_placer_status_idx');
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'national_code')) {
                $table->index('national_code', 'users_national_code_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_status_idx');
            $table->dropIndex('orders_payment_status_idx');
            $table->dropIndex('orders_reservation_expires_at_idx');
            $table->dropIndex('orders_placer_status_idx');
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'national_code')) {
                $table->dropIndex('users_national_code_idx');
            }
        });
    }
};
