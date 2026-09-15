<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('event_seat_id')->constrained('event_seats')->restrictOnDelete();
            $table->integer('price_cents');

            $table->index('order_id');
            $table->index('event_seat_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
