<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Вторая волна админки: журнал действий, настройки сервера в базе, пароли приложений,
// сеансы и входы веб-почты, подразделения, профили сотрудников, копии.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_actions', function (Blueprint $table) {
            $table->id();
            $table->string('actor')->index();
            $table->string('ip', 45)->default('');
            $table->string('action', 64)->index();
            $table->string('target')->nullable();
            $table->text('details')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        // Пароли приложений: Dovecot проверяет их вторым passdb (см. deploy/mail01-wave2.sh).
        Schema::create('app_passwords', function (Blueprint $table) {
            $table->id();
            $table->string('username')->index();
            $table->string('name', 60);
            $table->string('password');            // {SSHA512}…
            $table->boolean('active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mail_sessions', function (Blueprint $table) {
            $table->string('id', 100)->primary();   // id сессии Laravel
            $table->string('user')->index();
            $table->string('ip', 45)->default('');
            $table->string('agent', 300)->default('');
            $table->string('device', 120)->default('');
            $table->boolean('impersonated')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent()->index();
        });

        Schema::create('mail_logins', function (Blueprint $table) {
            $table->id();
            $table->string('user')->index();
            $table->string('ip', 45)->default('');
            $table->string('agent', 300)->default('');
            $table->string('result', 24)->index();   // ok | bad_password | bad_code | blocked | new_device
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->index();
            $table->string('name', 120);
            $table->string('address')->nullable()->unique();   // адрес отдела (рассылка на всех)
            $table->string('lead')->nullable();                // руководитель
            $table->unsignedInteger('calendar_id')->nullable();   // общий календарь отдела (dav_calendars)
            $table->unsignedInteger('addressbook_id')->nullable(); // книга отдела (dav_addressbooks)
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->string('username')->primary();
            $table->foreignId('unit_id')->nullable()->index();
            $table->string('title')->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('mobile', 60)->nullable();
            $table->string('personal_email')->nullable();
            $table->boolean('require_2fa')->default(false);
            $table->boolean('login_blocked')->default(false);
            $table->timestamps();
        });

        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('file')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('seconds')->default(0);
            $table->string('parts', 60)->default('mail,db,config');
            $table->string('status', 16)->default('ok');   // ok | failed
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 24)->default('admin')->after('is_active');   // owner | admin | viewer | operator
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('role'));
        foreach (['backups', 'employee_profiles', 'units', 'mail_logins', 'mail_sessions', 'app_passwords', 'app_settings', 'admin_actions'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
