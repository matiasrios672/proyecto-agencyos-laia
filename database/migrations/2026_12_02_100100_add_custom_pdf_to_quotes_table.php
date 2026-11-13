<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PDF propio (armado en Canva) que reemplaza al generado por el sistema. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->string('custom_pdf_path')->nullable()->after('notes');
            $table->string('custom_pdf_name')->nullable()->after('custom_pdf_path');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn(['custom_pdf_path', 'custom_pdf_name']);
        });
    }
};
