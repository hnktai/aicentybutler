<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('homepage_appointments', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 255);
            $table->string('phone', 40);
            $table->string('title', 120);
            $table->date('appointment_date');
            $table->string('appointment_time', 30);
            $table->dateTime('scheduled_at');
            $table->text('problem');
            $table->boolean('consent')->default(true);
            $table->text('zoom_url');
            $table->string('zoom_passcode', 80);
            $table->dateTime('confirmation_sent_at')->nullable();
            $table->dateTime('reminder_2d_sent_at')->nullable();
            $table->dateTime('reminder_1d_sent_at')->nullable();
            $table->dateTime('reminder_2h_sent_at')->nullable();
            $table->string('status', 30)->default('scheduled');
            $table->timestamps();
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homepage_appointments');
    }
};
