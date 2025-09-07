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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('doctor_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->float('amount')->nullable();
            $table->enum('status', ['confirmed', 'pending', 'rejected'])->default('confirmed');
            $table->timestamps();
            $table->foreign('doctor_id')->references('id')->on('users')
                ->onUpdate('cascade')->onDelete('cascade');
            //
            $table->foreign('supplier_id')->references('id')->on('users')
                ->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('payments');
    }
};
