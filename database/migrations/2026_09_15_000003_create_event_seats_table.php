<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();
            $table->string('section');
            $table->integer('row');
            $table->integer('number');
            $table->integer('x');
            $table->integer('y');
            $table->integer('price_cents');
            $table->string('currency', 3);
            $table->enum('status', ['free', 'sold']);

            $table->unique(['event_id', 'section', 'row', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_seats');
    }
};
