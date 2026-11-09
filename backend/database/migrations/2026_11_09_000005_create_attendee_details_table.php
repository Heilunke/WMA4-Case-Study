<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* ATTENDEE_DETAILS — exactly one row per ticket (panel comment #6). ticket_code is the e-ticket
   (panel comment #1), issued on confirmation; checked_in_* record the check-in (panel comment #7). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendee_details', function (Blueprint $table) {
            $table->increments('attendee_ID');
            $table->unsignedInteger('reservation_ID');
            $table->string('full_name', 100);
            $table->string('contact_number', 20)->nullable();
            $table->string('ticket_code', 30)->nullable()->unique('uq_ticket_code'); // NULL until confirmed or after voiding
            $table->boolean('status')->default(false);                // 1 = checked in
            $table->dateTime('checked_in_at')->nullable();
            $table->unsignedInteger('checked_in_by')->nullable();    // Administrator who admitted the attendee

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
