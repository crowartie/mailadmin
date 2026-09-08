<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Перенос ящиков со старого сервера (Kerio) через imapsync и CardDAV/CalDAV. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_migrations', function (Blueprint $table) {
            $table->id();
            $table->string('source_host', 200);
            $table->unsignedSmallInteger('source_port')->default(993);
            $table->boolean('source_ssl')->default(true);
            $table->string('source_login', 200);
            $table->text('source_password');
            $table->string('target', 200)->index();
            $table->string('what', 10)->default('all');
            $table->string('status', 12)->default('new')->index();
            $table->json('stats')->nullable();
            $table->json('dav_stats')->nullable();
            $table->json('options')->nullable();
            $table->text('error')->nullable();
            $table->string('log_path', 300)->nullable();
            $table->string('created_by', 200)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_migrations');
    }
};
