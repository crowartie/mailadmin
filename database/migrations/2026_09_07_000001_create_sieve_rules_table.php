<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sieve_rules', function (Blueprint $table) {
            $table->id();
            $table->string('owner')->index();            // адрес сотрудника
            $table->string('owner_name')->nullable();
            $table->string('kind', 32)->index();         // fileinto | redirect | vacation | discard | flag | other
            $table->string('condition', 500);            // «если» — человеческим языком
            $table->string('action', 500);               // «то»
            $table->boolean('active')->default(true);
            $table->date('until')->nullable();           // для автоответов с датой окончания
            $table->unsignedSmallInteger('position')->default(0);
            $table->text('raw')->nullable();             // исходный фрагмент скрипта
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sieve_rules');
    }
};
