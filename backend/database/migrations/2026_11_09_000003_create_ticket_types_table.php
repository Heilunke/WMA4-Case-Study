<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* TICKET_TYPES — inventory per ticket type (panel comment #5).
   quantity_available = seats left; quantity_sold = seats held by Pending + Confirmed reservations. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_types', function (Blueprint $table) {
            $table->increments('ticket_ID');
            $table->unsignedInteger('event_ID');
            $table->string('type_name', 50);
            $table->decimal('price', 8, 2)->default(0);                // 0.00 = free
            $table->unsignedInteger('quantity_available');           // UNSIGNED: can never go negative
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
