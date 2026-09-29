<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('address_id')->nullable()->constrained('customer_addresses')->nullOnDelete();

            $table->string('order_number', 30);

            // CUSTOMER | WALK_IN | ADMIN | BRANCH — one table for every
            // channel (spec section 10).
            $table->string('order_source', 20);
            $table->string('order_status', 30)->default('ORDER_CREATED');
            $table->string('fulfilment_type', 20)->default('DELIVERY'); // DELIVERY | CUSTOMER_PICKUP
            $table->text('notes')->nullable();

            // The customer's estimate is kept forever and never
            // overwritten; actual_weight is what the business is billed on.
            $table->decimal('approx_weight', 10, 3)->nullable();
            $table->decimal('actual_weight', 10, 3)->nullable();

            $table->decimal('pickup_charge', 10, 2)->default(0);
            $table->decimal('delivery_charge', 10, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_status', 20)->default('PENDING');
            $table->timestamp('paid_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Order numbers are unique per tenant, not globally.
            $table->unique(['business_id', 'order_number']);

            // The workhorse index: every list view filters by tenant and
            // status and sorts newest first (spec section 22).
            $table->index(['business_id', 'order_status', 'created_at']);
            $table->index(['branch_id', 'order_status']);
            $table->index('customer_id');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();

            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('approx_weight', 10, 3)->nullable();
            $table->decimal('actual_weight', 10, 3)->nullable();

            // The rate is copied onto the item so a later price change
            // never rewrites the value of a historical order.
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total_amount', 12, 2);
            $table->timestamps();

            $table->index('order_id');
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('old_status', 30)->nullable();
            $table->string('new_status', 30);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });

        Schema::create('pickup_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('pickup_boy_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('picked_at')->nullable();
            $table->string('status', 20)->default('ASSIGNED');
            $table->timestamps();

            $table->index(['pickup_boy_id', 'status']);
            $table->index('order_id');
        });

        Schema::create('delivery_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('delivery_boy_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('delivered_at')->nullable();
            $table->string('status', 20)->default('ASSIGNED');
            $table->timestamps();

            $table->index(['delivery_boy_id', 'status']);
            $table->index('order_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method', 10);            // CASH | UPI
            $table->string('status', 20)->default('PENDING');
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('collected_at')->nullable();
            $table->string('reference_no', 100)->nullable();  // UPI UTR
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'method']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('delivery_assignments');
        Schema::dropIfExists('pickup_assignments');
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
