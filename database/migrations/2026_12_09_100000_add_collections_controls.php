<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('reminders_paused')->default(false)->after('is_active');
        });

        Schema::table('reminder_logs', function (Blueprint $table) {
            $table->foreignId('sent_by')->nullable()->after('sent_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reminder_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sent_by');
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('reminders_paused');
        });
    }
};
