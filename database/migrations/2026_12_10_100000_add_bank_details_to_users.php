<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('bank_holder', 120)->nullable();
            $table->string('bank_tax_id', 20)->nullable();
            $table->string('bank_name', 80)->nullable();
            $table->string('bank_cbu', 22)->nullable();
            $table->string('bank_alias', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['bank_holder', 'bank_tax_id', 'bank_name', 'bank_cbu', 'bank_alias']);
        });
    }
};
