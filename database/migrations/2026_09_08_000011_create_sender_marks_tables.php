<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Решения сотрудников об отправителях: «не спам» / «спам» / «рассылка» по адресу или домену.
 * sender_marks — кто что отметил (личные правила), sender_rules — общие для всех (набрали голоса или поставил админ).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sender_marks', function (Blueprint $table) {
            $table->id();
            $table->string('user', 200);
            $table->string('kind', 10);      // ham | spam | lists
            $table->string('match', 10);     // address | domain
            $table->string('value', 255);
            $table->timestamp('created_at')->nullable();
            $table->unique(['user', 'kind', 'value']);
            $table->index(['kind', 'value']);
        });
        Schema::create('sender_rules', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10);      // spam | lists (ham живёт в белом списке Amavis)
            $table->string('match', 10);
            $table->string('value', 255);
            $table->string('source', 20)->default('votes'); // votes | admin
            $table->unsignedInteger('votes')->default(0);
            $table->string('created_by', 200)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['kind', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sender_rules');
        Schema::dropIfExists('sender_marks');
    }
};
