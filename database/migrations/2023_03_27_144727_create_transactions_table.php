<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payme_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('paycom_transaction_id');
            $table->string('paycom_time');
            $table->dateTime('paycom_time_datetime');
            $table->dateTime('create_time')->default(null);
            $table->dateTime('perform_time')->default(null);
            $table->dateTime('cancel_time')->default(null);
            $table->integer('amount');
            $table->tinyInteger('state');
            $table->tinyInteger('reason');
            $table->string('receivers')->default(null);
            $table->integer('order_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
