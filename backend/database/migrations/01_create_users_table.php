<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* USERS table - the accounts and their role. Anyone who signs up is always an 'attendee' (from the panel's comment). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_ID');
            $table->string('full_Name', 100);
            $table->string('email', 100)->unique('uq_users_email');
            $table->string('password_hash', 255);
            $table->enum('role', ['admin', 'attendee'])->default('attendee');
            $table->dateTime('created_at');
            $table->unsignedInteger('session_version')->default(1);   // goes up when the password changes, so other sessions get logged out
            $table->char('reset_code_hash', 64)->nullable();           // we only save the hash (SHA-256) of the reset code, not the code itself
            $table->dateTime('reset_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
