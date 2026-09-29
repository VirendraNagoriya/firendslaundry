<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pickup/delivery zones. A zone is owned by one business.
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name');
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();

            $table->unique(['business_id', 'name']);
        });

        // Many-to-many: one employee serves several regions, one region has
        // several employees (spec section 17).
        Schema::create('employee_regions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('region_id')->constrained('regions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'region_id']);
            $table->index('region_id');
        });

        // Customers are per business: the same person ordering from two
        // different laundries is two separate customer records.
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name');
            $table->string('mobile', 20);
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            // Mobile is unique per tenant, not globally: a customer is
            // identified by the pair.
            $table->unique(['business_id', 'mobile']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('address_line');
            $table->string('city')->nullable();
            $table->string('pincode', 10)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('customer_id');
        });

        // Each business owns its own service catalogue and rates.
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name');
            $table->string('pricing_type', 10);   // KG | PIECE
            $table->decimal('rate', 10, 2);       // per KG or per piece
            $table->string('description')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('employee_regions');
        Schema::dropIfExists('regions');
    }
};
