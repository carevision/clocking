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
        Schema::create('terminal_sync_history', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('uid')->nullable();
            $table->bigInteger('terminal_id')->nullable();
            $table->bigInteger('state')->nullable();
            $table->string('timestamp')->nullable();
            $table->integer('type')->nullable();
            $table->string('serial_number')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('terminal_sync_history');
    }
};
