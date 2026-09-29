<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->boolean('can_use_nova')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id');
            $table->string('name');
            $table->string('secret')->default('server secret');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('records');
        Schema::dropIfExists('users');
    }
};
