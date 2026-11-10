<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* ATTENDEE_DETAILS table - one row for every ticket (from the panel's comment).
   ticket_code is the e-ticket, it's made when the admin confirms. checked_in_at and checked_in_by save the check-in. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendee_details', function (Blueprint $table) {
            $table->increments('attendee_ID');
            $table->unsignedInteger('reservation_ID');
            $table->string('full_name', 100);
            $table->string('contact_number', 20)->nullable();
            $table->string('ticket_code', 30)->nullable()->unique('uq_ticket_code'); // empty until confirmed, and emptied again if cancelled
            $table->boolean('status')->default(false);                // 1 = already checked in
            $table->dateTime('checked_in_at')->nullable();
            $table->unsignedInteger('checked_in_by')->nullable();    // the admin who checked them in

            $table->index('reservation_ID', 'idx_att_res');
            $table->foreign('reservation_ID', 'fk_att_res')->references('reservation_ID')->on('reservations')->cascadeOnDelete();
            $table->foreign('checked_in_by', 'fk_att_admin')->references('user_ID')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendee_details');
    }
};
