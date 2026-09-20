<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Своё хранилище больших вложений: файл лежит на диске почтового сервера, в письмо
// уходит ссылка вида https://files.<домен>/<токен> со сроком. Срок — у ссылки, не у файла:
// ссылку можно продлить, файл никуда не девается.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webmail_files', function (Blueprint $table) {
            $table->id();
            $table->string('user')->index();                 // чей файл (ящик отправителя)
            $table->string('token', 64)->unique();            // часть ссылки; подобрать нельзя
            $table->string('name', 255);                      // имя файла, как показываем и отдаём
            $table->unsignedBigInteger('size');
            $table->string('mime', 120)->default('application/octet-stream');
            $table->string('path', 255);                      // относительно storage/app/files
            $table->string('message_id', 998)->nullable();    // письмо, к которому файл приложен
            $table->timestamp('expires_at')->nullable()->index();   // до какого дня действует ссылка
            $table->unsignedInteger('downloads')->default(0);
            $table->timestamp('last_download_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webmail_files');
    }
};
