<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Отчёты DMARC (aggregate) и TLS-RPT — чтобы видеть, кто подделывает домен и где ломается TLS. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dmarc_reports', function (Blueprint $table) {
            $table->id();
            $table->string('org', 120);
            $table->string('report_id', 200);
            $table->string('domain')->nullable();
            $table->string('policy', 20)->nullable();
            $table->dateTime('begin_at');
            $table->dateTime('end_at')->index();
            $table->dateTime('received_at');
            $table->unique(['org', 'report_id']);
        });
        Schema::create('dmarc_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->index();
            $table->string('source_ip', 45)->index();
            $table->unsignedInteger('count');
            $table->string('disposition', 20)->nullable();
            $table->string('dkim', 10)->nullable();
            $table->string('spf', 10)->nullable();
            $table->string('header_from')->nullable();
            $table->string('dkim_domain')->nullable();
            $table->string('spf_domain')->nullable();
        });
        Schema::create('tls_reports', function (Blueprint $table) {
            $table->id();
            $table->string('org', 120);
            $table->string('report_id', 200);
            $table->string('policy_type', 20)->nullable();
            $table->dateTime('begin_at');
            $table->dateTime('end_at')->index();
            $table->unsignedInteger('success')->default(0);
            $table->unsignedInteger('failure')->default(0);
            $table->text('failures')->nullable();
            $table->dateTime('received_at');
            $table->unique(['org', 'report_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tls_reports');
        Schema::dropIfExists('dmarc_records');
        Schema::dropIfExists('dmarc_reports');
    }
};
