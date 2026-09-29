<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('business_name');
            $table->string('owner_name');
            $table->string('mobile', 20);
            $table->unsignedSmallInteger('branch_limit')->default(1);
            $table->string('status', 20)->default('ACTIVE');

            // Owner settings (spec section 19). Denormalised onto the
            // business row because these are read on nearly every order
            // and rarely change, which makes them cheap to cache.
            $table->decimal('pickup_charge', 10, 2)->default(0);
            $table->decimal('delivery_charge', 10, 2)->default(0);
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->time('working_hours_start')->nullable();
            $table->time('working_hours_end')->nullable();
            $table->json('notification_templates')->nullable();

            $table->timestamps();

            $table->index(['status']);
            $table->index('mobile');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
