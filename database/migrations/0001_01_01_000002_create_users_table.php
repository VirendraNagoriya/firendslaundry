<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * There is no password column: every role in this system authenticates
     * with a mobile OTP (spec sections 6-7). email/password_reset_tokens
     * are intentionally absent.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // Null only for the platform Super Admin.
            $table->foreignId('business_id')->nullable()
                ->constrained('businesses')->nullOnDelete();

            // Employees and branches operate out of one branch.
            $table->foreignId('branch_id')->nullable()
                ->constrained('branches')->nullOnDelete();

            $table->string('name');
            $table->string('mobile', 20);
            $table->string('role', 20);
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'role']);
            $table->index('mobile');
            $table->index(['business_id', 'status']);
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
