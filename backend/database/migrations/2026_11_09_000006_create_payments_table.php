<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* PAYMENTS — a record only, no online gateway (panel comment #4). One-to-one with reservations. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->increments('payment_ID');
            $table->unsignedInteger('reservation_ID')->unique('uq_payment_reservation');
            $table->decimal('amount', 8, 2);
            $table->string('payment_method', 100)->nullable();
            $table->enum('payment_status', ['unpaid', 'paid'])->default('unpaid');
            $table->dateTime('payment_date')->nullable();
            $table->unsignedInteger('recorded_by')->nullable();      // Administrator who recorded the payment

            $table->foreign('reservation_ID', 'fk_pay_res')->references('reservation_ID')->on('reservations')->cascadeOnDelete();
            $table->foreign('recorded_by', 'fk_pay_admin')->references('user_ID')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
