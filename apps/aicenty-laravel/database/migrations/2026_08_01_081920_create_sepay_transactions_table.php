<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sepay_transactions')) {
            return;
        }

        Schema::create('sepay_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('sepay_transaction_id')->unique();
            $table->string('order_id')->nullable()->index();
            $table->string('reference_code')->nullable()->index();
            $table->string('gateway')->nullable();
            $table->string('account_number')->nullable();
            $table->string('sub_account')->nullable();
            $table->string('transfer_type')->nullable();
            $table->decimal('amount_in', 15, 2)->default(0);
            $table->decimal('amount_out', 15, 2)->default(0);
            $table->text('content')->nullable();
            $table->string('status')->default('received')->index();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sepay_transactions');
    }
};
