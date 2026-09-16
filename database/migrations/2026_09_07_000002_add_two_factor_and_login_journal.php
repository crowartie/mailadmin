<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('totp_secret')->nullable()->after('password');       // хранится зашифрованным
            $table->timestamp('totp_enabled_at')->nullable()->after('totp_secret');
            $table->boolean('is_active')->default(true)->after('totp_enabled_at');
        });

        // Журнал входов в админку — то, что в Kerio называется Audit.
        Schema::create('admin_logins', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('ip', 45);
            $table->string('user_agent', 500)->nullable();
            $table->string('result', 32)->index();   // ok | bad_password | bad_code | blocked
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_logins');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['totp_secret', 'totp_enabled_at', 'is_active']);
        });
    }
};
