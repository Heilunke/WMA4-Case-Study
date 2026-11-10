<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* EVENTS table - the events that the admin creates. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->increments('event_ID');
            $table->unsignedInteger('user_ID');                       // the admin who created the event
            $table->string('event_name', 150);
            $table->string('category', 50)->default('General');
            $table->text('description')->nullable();
            $table->date('event_date');
            $table->time('event_time');
            $table->string('venue', 150);
            $table->dateTime('created_at');

            $table->index(['event_date', 'event_time'], 'idx_events_date');
            $table->index('category', 'idx_events_category');
            $table->foreign('user_ID', 'fk_events_user')->references('user_ID')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
