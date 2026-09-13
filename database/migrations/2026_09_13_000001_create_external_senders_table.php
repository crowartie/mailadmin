<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Адреса нашего домена, которым разрешено приходить с чужих серверов (mail.ru, Яндекс…) без входа на наш SMTP.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_senders', function (Blueprint $table) {
            $table->id();
            $table->string('address')->unique();
            $table->string('provider', 20);          // mailru | yandex | gmail | any
            $table->string('note')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_senders');
    }
};
