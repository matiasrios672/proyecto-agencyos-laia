<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Plantillas de correo propias (nombre, descripción) y la plantilla elegida para cada comunicado. */
    public function up(): void
    {
        Schema::table('mail_templates', function (Blueprint $table) {
            $table->boolean('is_custom')->default(false)->after('key');
            $table->string('label')->nullable()->after('is_custom');
            $table->string('description')->nullable()->after('label');
        });

        Schema::table('announcements', function (Blueprint $table) {
            $table->string('template_key')->nullable()->after('audience_value');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', fn (Blueprint $t) => $t->dropColumn('template_key'));
        Schema::table('mail_templates', fn (Blueprint $t) => $t->dropColumn(['is_custom', 'label', 'description']));
    }
};
