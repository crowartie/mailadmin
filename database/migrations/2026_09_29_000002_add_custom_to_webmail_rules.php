<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Свой скрипт Sieve, написанный руками (вкладка «Свой скрипт» в правилах): хранится рядом с правилами конструктора. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webmail_rules', function (Blueprint $t) {
            $t->text('custom')->nullable()->after('script');
        });
    }

    public function down(): void
    {
        Schema::table('webmail_rules', function (Blueprint $t) {
            $t->dropColumn('custom');
        });
    }
};
