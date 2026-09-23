<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Личное облако сотрудника (файлы лежат в Nextcloud, в папке служебной учётки).
 * Здесь — только то, чего Nextcloud сам не знает про «наших» людей: публичные ссылки,
 * незаконченные загрузки (чтобы докачать после обрыва) и корзина.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webmail_cloud_links', function (Blueprint $t) {
            $t->id();
            $t->string('user', 190)->index();
            $t->string('path', 1000);                  // относительно папки сотрудника
            $t->string('share_id', 40);                // id публичной ссылки в Nextcloud
            $t->string('url', 500);
            $t->date('expires_at')->nullable();
            $t->boolean('has_password')->default(false);
            $t->timestamps();
        });

        Schema::create('webmail_cloud_uploads', function (Blueprint $t) {
            $t->string('id', 40)->primary();           // он же имя папки загрузки в Nextcloud
            $t->string('user', 190)->index();
            $t->string('path', 1000);                  // куда ляжет файл, относительно папки сотрудника
            $t->unsignedBigInteger('size');
            $t->unsignedInteger('chunk_size');
            $t->timestamp('finished_at')->nullable()->index();
            $t->timestamps();
        });

        Schema::create('webmail_cloud_trash', function (Blueprint $t) {
            $t->id();
            $t->string('user', 190)->index();
            $t->string('trash_name', 300);             // имя внутри .Корзина
            $t->string('original_path', 1000);
            $t->boolean('is_dir')->default(false);
            $t->unsignedBigInteger('size')->default(0);
            $t->timestamp('deleted_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webmail_cloud_trash');
        Schema::dropIfExists('webmail_cloud_uploads');
        Schema::dropIfExists('webmail_cloud_links');
    }
};
