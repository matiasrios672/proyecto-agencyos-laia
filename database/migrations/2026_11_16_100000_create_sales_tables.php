<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Catálogo de servicios, presupuestos, servicios contratados y tablero inicial. Dinero siempre en centavos. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price')->default(0);
            $table->string('currency', 3)->default('ARS');
            $table->string('frequency')->default('mensual');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('prospect_name')->nullable();
            $table->string('prospect_email')->nullable();
            $table->string('prospect_phone', 40)->nullable();
            $table->string('prospect_tax_id', 13)->nullable();
            $table->string('currency', 3)->default('ARS');
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->string('status')->default('borrador')->index();
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('converted_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('service_catalog')->nullOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->string('frequency')->default('mensual');
            $table->unsignedSmallInteger('position')->default(0);
        });

        Schema::create('client_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_item_id')->nullable()->constrained('quote_items')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('currency', 3)->default('ARS');
            $table->string('frequency')->default('mensual');
            $table->unsignedTinyInteger('due_day')->default(1);
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('split_rule')->default('100_agencia');
            $table->decimal('split_percent', 5, 2)->nullable();
            $table->unsignedBigInteger('split_amount')->nullable();
            $table->string('status')->default('activo')->index();
            $table->date('started_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('kanban_boards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('kanban_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('kanban_boards')->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['board_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kanban_columns');
        Schema::dropIfExists('kanban_boards');
        Schema::dropIfExists('client_services');
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('service_catalog');
    }
};
