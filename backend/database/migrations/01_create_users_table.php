<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* USERS — accounts and roles (panel comment #3: public sign-up is always 'attendee'). */
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
            $table->unsignedInteger('session_version')->default(1);   // bumped on password change → other sessions logged out
            $table->char('reset_code_hash', 64)->nullable();           // SHA-256 of an Administrator-issued reset code
            $table->dateTime('reset_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
