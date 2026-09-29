<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 20);
            $table->string('phone', 20)->nullable();
            $table->string('address')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();

            // Branch codes identify a branch within its business.
            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
