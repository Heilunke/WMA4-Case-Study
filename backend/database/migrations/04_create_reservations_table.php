<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* RESERVATIONS — shown as RSV-### in the UI. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->increments('reservation_ID');
            $table->unsignedInteger('user_ID');                       // the attendee who booked
            $table->unsignedInteger('ticket_ID');
            $table->unsignedTinyInteger('quantity');
            $table->decimal('total_amount', 8, 2);
            $table->enum('reservation_status', ['pending', 'confirmed', 'cancelled'])->default('pending');
            $table->dateTime('reservation_date');

            $table->index('user_ID', 'idx_res_user');
            $table->index('ticket_ID', 'idx_res_ticket');
            $table->index('reservation_status', 'idx_res_status');
            $table->foreign('user_ID', 'fk_res_user')->references('user_ID')->on('users');
            $table->foreign('ticket_ID', 'fk_res_ticket')->references('ticket_ID')->on('ticket_types');
        });
        DB::statement('ALTER TABLE reservations ADD CONSTRAINT chk_res_qty CHECK (quantity BETWEEN 1 AND 10)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
