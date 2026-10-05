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
        Schema::connection(config('operations.connection'))->create('operation_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->string('operation');
            $table->string('name');
            $table->json('value');
            $table->unique(['operation', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(config('operations.connection'))->dropIfExists('operation_checkpoints');
    }
};
