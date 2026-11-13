<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cobros, liquidaciones al equipo, egresos, comunicados y registro de recordatorios. Dinero siempre en centavos. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('client_id')->constrained();
            $table->foreignId('client_service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('ARS');
            $table->string('period', 7)->nullable();       // 2026-11 (mensual) | 'unico' | null (cobro manual)
            $table->date('issue_date');
            $table->date('due_date')->index();
            $table->string('status')->default('pendiente')->index();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('void_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // Un servicio no puede tener dos cobros del mismo período (garantiza que generar sea idempotente).
            $table->unique(['client_service_id', 'period']);
        });

        Schema::create('team_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('ARS');
            $table->string('calculation');                  // "30 % de $ 280.000,00" (queda como constancia)
            $table->string('status')->default('pendiente')->index();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('settlement_note')->nullable();
            $table->timestamps();
            $table->unique(['invoice_id', 'user_id']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category');
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('ARS');
            $table->string('frequency')->default('mensual');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('subject');
            $table->text('body');
            $table->string('audience')->default('todos');   // todos | estado | clientes
            $table->json('audience_value')->nullable();
            $table->string('status')->default('borrador');
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('announcement_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('email')->nullable();
            $table->string('status');                       // enviado | sin_email
            $table->timestamp('sent_at')->nullable();
            $table->unique(['announcement_id', 'client_id']);
        });

        Schema::create('reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('type');                         // antes | mora
            $table->timestamp('sent_at');
            $table->unique(['invoice_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_logs');
        Schema::dropIfExists('announcement_recipients');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('team_payouts');
        Schema::dropIfExists('invoices');
    }
};
