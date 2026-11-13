<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Grillas mensuales de contenido (PDF armados en Canva), una por cliente y mes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_grids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('month');                              // siempre el primer día del mes
            $table->string('title');
            $table->string('file_path');                        // disco privado, nombre aleatorio
            $table->string('original_name');                    // solo para mostrar; nunca se usa como ruta
            $table->unsignedBigInteger('size');
            $table->string('status')->default('borrador')->index();
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('shared_at')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'month']);
            $table->index('month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_grids');
    }
};
