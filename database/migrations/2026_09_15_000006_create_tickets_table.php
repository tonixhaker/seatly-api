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
        Schema::create('tickets', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('event_seat_id')->constrained('event_seats')->restrictOnDelete();
            $table->string('qr_code', 12)->unique();
            $table->enum('status', ['issued', 'checked_in']);
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index('event_seat_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
