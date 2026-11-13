<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensajería unificada: chats directos 1 a 1 e hilos contextuales (por ahora, uno por cliente).
 * El sujeto del hilo es polimórfico pero acotado por lista blanca (ver Conversation::SUBJECTS): sin FKs a tablas de otros módulos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16);                                  // direct | thread
            $table->string('subject_type', 32)->nullable();              // client (lista blanca)
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('direct_key', 32)->nullable()->unique();      // "3-7": un solo chat por par de personas
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['subject_type', 'subject_id']);              // un hilo por sujeto (NULL no choca en Postgres)
        });

        Schema::create('conversation_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();
            // Los no leídos se calculan por id (no por hora): dos mensajes en el mismo segundo no se confunden.
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'user_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->text('body');
            $table->json('mentions')->nullable();                        // ids de usuarios mencionados (ya validados)
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_user');
        Schema::dropIfExists('conversations');
    }
};
