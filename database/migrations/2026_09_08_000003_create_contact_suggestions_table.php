<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Предложения сотрудников «добавить в общую книгу»: ждут решения администратора.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_suggestions', function (Blueprint $table) {
            $table->id();
            $table->string('user')->index();
            $table->string('fn');
            $table->string('email')->nullable();
            $table->text('vcard');
            $table->string('status', 16)->default('pending')->index(); // pending | approved | rejected
            $table->string('note', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_suggestions');
    }
};
