<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supabase Auth owns identity, so our `users` table needs to record which
 * Supabase account each staff or customer profile belongs to.
 *
 * The short `slug` on businesses is the piece that tells the app which
 * laundry a phone number is signing in to. A person can be a customer of
 * two different laundries, so the mobile number alone cannot identify the
 * tenant; the code printed on the bill (or in the URL they were given)
 * can.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('slug', 40)->nullable()->after('business_name')->comment('Short public code used to pick this laundry at sign-in, e.g. ABC');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('supabase_user_id')->nullable()->after('id');
            $table->string('email')->nullable()->after('mobile');
        });

        // Assigned after the columns exist, because existing demo rows have
        // no Supabase account yet.
        Schema::table('businesses', function (Blueprint $table) {
            $table->unique('slug');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('supabase_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['supabase_user_id']);
            $table->dropColumn(['supabase_user_id', 'email']);
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
