<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* TICKET_TYPES table - the seats of each ticket type (from the panel's comment about inventory).
   quantity_available = seats left, quantity_sold = seats taken by Pending and Confirmed reservations. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_types', function (Blueprint $table) {
            $table->increments('ticket_ID');
            $table->unsignedInteger('event_ID');
            $table->string('type_name', 50);
            $table->decimal('price', 8, 2)->default(0);                // 0.00 means the event is free
            $table->unsignedInteger('quantity_available');           // UNSIGNED so it can never be negative
            $table->unsignedInteger('quantity_sold')->default(0);

            $table->unique(['event_ID', 'type_name'], 'uq_ticket_type_per_event');
            $table->foreign('event_ID', 'fk_tickets_event')->references('event_ID')->on('events')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE ticket_types ADD CONSTRAINT chk_ticket_price CHECK (price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_types');
    }
};
