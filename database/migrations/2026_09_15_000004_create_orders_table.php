<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();
            $table->enum('status', ['pending', 'paid', 'payment_failed', 'cancelled', 'expired']);
            $table->integer('total_cents');
            $table->string('currency', 3);
            $table->string('idempotency_key')->unique();
            $table->timestamps();

            $table->index('buyer_id');
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
