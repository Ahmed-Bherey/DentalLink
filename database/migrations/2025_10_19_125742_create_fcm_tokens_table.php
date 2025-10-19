<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('fcm_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('device', ['desktop', 'web', 'mob'])->default('web');
            $table->string('device_id')->nullable(); // مثلاً: device unique id for mobile
            $table->string('fcm_token')->index();
            $table->timestamps();

            // unique combination to avoid duplicates (device_id nullable is allowed)
            $table->unique(['user_id', 'device', 'device_id'], 'fcm_unique');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('fcm_tokens');
    }
};
