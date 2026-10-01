<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity for first-party and Google sign-in.
 *
 * Two independent ways in, both ending at the same `users` row:
 *
 *  - a password, for a person who signed up with an email address;
 *  - a linked Google account, recorded in `oauth_accounts`.
 *
 * Both are additive. Nothing is dropped here, so the existing Supabase
 * column stays until the Supabase code is gone (see the next migration).
 *
 * `email` is unique but nullable: a customer known only by a phone number
 * has no address, and MySQL allows any number of NULLs in a unique index,
 * which is exactly the behaviour wanted. A duplicate address is therefore
 * refused at the database, not just in the validator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->after('email');
            $table->string('avatar')->nullable()->after('password');
            $table->timestamp('email_verified_at')->nullable()->after('avatar');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('email');
        });

        // One row per linked external account. A separate table rather than
        // a `provider`/`provider_id` pair on `users`, because a person may
        // later link more than one provider to the same account.
        Schema::create('oauth_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // 'google' today; the column is generic so another provider can
            // be added without another schema change.
            $table->string('provider', 20);

            // The account's identifier at the provider ("sub" for Google).
            // Not an email: emails change and can be reassigned.
            $table->string('provider_id', 191);

            $table->string('avatar')->nullable();
            $table->timestamps();

            // One external account can only ever belong to one user.
            $table->unique(['provider', 'provider_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_accounts');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->dropColumn(['password', 'avatar', 'email_verified_at']);
        });
    }
};
