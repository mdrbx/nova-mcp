<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mdrbx\NovaMcp\Models\OAuthClient;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection((new OAuthClient)->getConnectionName())->create('nova_mcp_clients', function (Blueprint $table): void {
            // Passport supports both integer and UUID client keys, including custom client models.
            $table->string('client_id', 100)->primary();
            $table->string('resource', 2048);
        });
    }

    public function down(): void
    {
        Schema::connection((new OAuthClient)->getConnectionName())->dropIfExists('nova_mcp_clients');
    }
};
