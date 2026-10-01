<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the Supabase identity column.
 *
 * Identity is now held by this app: `users.password` for a first-party
 * account, and `oauth_accounts` for Google. The `supabase_user_id` column
 * that migration 000400 added for the old OTP flow has nothing left pointing
 * at it, so it goes.
 *
 * `users.email` and `businesses.slug`, which the same migration created,
 * are both still in use and are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['supabase_user_id']);
            $table->dropColumn('supabase_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('supabase_user_id')->nullable()->after('id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('supabase_user_id');
        });
    }
};
