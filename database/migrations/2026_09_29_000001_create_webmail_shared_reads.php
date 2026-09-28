<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кто прочитал письмо в общей папке (обращение №51). Журнал действий нарочно не хранит, какое письмо
 * открывали, поэтому для «Прочитали: Аносов, Мусин» нужна своя таблица. Ключ — Message-ID: он не меняется
 * при переносе письма между папками ящика. См. App\Services\Mail\SharedReads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webmail_shared_reads', function (Blueprint $t) {
            $t->id();
            $t->string('owner', 190);                     // чей ящик (info@…)
            $t->string('message_id', 190);                // Message-ID без <>, обрезан до 190
            $t->string('user', 190);                      // кто прочитал
            $t->timestamp('read_at')->nullable();
            $t->unique(['owner', 'message_id', 'user']);
            $t->index('read_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webmail_shared_reads');
    }
};
