<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_demonstration')->default(false);
            $table->timestamps();
        });

        Schema::create('allergens', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('short_description')->nullable();
            $table->text('description')->nullable();
            $table->text('ingredients_text')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_new')->default(false);
            $table->boolean('is_seasonal')->default(false);
            $table->string('season_label')->nullable();
            $table->boolean('is_demonstration')->default(false);
            $table->boolean('dietary_vegan')->nullable();
            $table->boolean('dietary_gluten_free')->nullable();
            $table->json('nutrition')->nullable();
            $table->decimal('warehouse_portions', 14, 3)->default(0);
            $table->decimal('low_stock_portions', 14, 3)->nullable();
            $table->string('portion_unit')->default('porcion');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('allergen_product', function (Blueprint $table) {
            $table->foreignId('allergen_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['allergen_id', 'product_id']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('sku')->nullable()->unique();
            $table->decimal('price', 12, 2);
            $table->decimal('portions', 8, 3)->default(1);
            $table->string('container')->default('cup');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('toppings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained();
            $table->string('name');
            $table->string('sku')->nullable()->unique();
            $table->decimal('stock_quantity', 14, 3)->default(0);
            $table->decimal('min_stock', 14, 3)->default(0);
            $table->decimal('cost_per_unit', 12, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ingredient_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('lot_code');
            $table->decimal('quantity', 14, 3)->default(0);
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->date('expires_on')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });

        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('yield_quantity', 14, 3);
            $table->string('yield_unit')->default('porcion');
            $table->text('instructions')->nullable();
            $table->unsignedInteger('shelf_life_hours')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->string('unit');
            $table->timestamps();
        });

        Schema::create('display_cases', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('location')->nullable();
            $table->decimal('capacity_liters', 10, 2)->nullable();
            $table->decimal('capacity_portions', 14, 3)->nullable();
            $table->decimal('min_threshold_portions', 14, 3)->default(0);
            $table->decimal('temp_min', 6, 2)->nullable();
            $table->decimal('temp_max', 6, 2)->nullable();
            $table->boolean('needs_cleaning')->default(false);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('display_case_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('display_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 14, 3)->default(0);
            $table->string('container_label')->nullable();
            $table->timestamps();
            $table->unique(['display_case_id', 'product_id']);
        });

        Schema::create('display_case_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('display_case_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->timestamp('performed_at');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('temperature_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('display_case_id')->constrained()->cascadeOnDelete();
            $table->decimal('celsius', 6, 2);
            $table->timestamp('recorded_at');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->default('manual');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('temperature_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('display_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('temperature_reading_id')->constrained()->cascadeOnDelete();
            $table->decimal('celsius', 6, 2);
            $table->string('message');
            $table->timestamp('recorded_at');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->string('item_type');
            $table->unsignedBigInteger('item_id');
            $table->string('movement_type');
            $table->decimal('quantity', 14, 3);
            $table->string('location');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('lot_code')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();
            $table->index(['item_type', 'item_id']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('recipient');
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city');
            $table->string('phone')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('channels');
            $table->boolean('is_active')->default(false);
            $table->boolean('records_as_paid')->default(false);
            $table->timestamps();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('type');
            $table->decimal('value', 12, 2);
            $table->decimal('min_subtotal', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamps();
        });

        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('open');
            $table->decimal('opening_amount', 12, 2)->default(0);
            $table->decimal('closing_amount', 12, 2)->nullable();
            $table->decimal('expected_amount', 12, 2)->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel');
            $table->string('fulfillment');
            $table->string('status');
            $table->string('payment_status');
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone');
            $table->json('address')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('shipping_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamp('inventory_reversed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['channel', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('variant_name');
            $table->unsignedInteger('quantity');
            $table->decimal('portions', 14, 3);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->json('toppings')->nullable();
            $table->json('allocations')->nullable();
            $table->text('special_instructions')->nullable();
            $table->timestamps();
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_ordered', 14, 3);
            $table->decimal('quantity_received', 14, 3)->default(0);
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('product_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('production_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('batches', 12, 3);
            $table->decimal('quantity_produced', 14, 3);
            $table->string('yield_unit');
            $table->timestamp('produced_at');
            $table->timestamp('expires_on')->nullable();
            $table->string('lot_code');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->decimal('estimated_cost', 12, 2)->nullable();
            $table->boolean('costs_complete')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_batches');
        Schema::dropIfExists('product_audits');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('cash_sessions');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('temperature_alerts');
        Schema::dropIfExists('temperature_readings');
        Schema::dropIfExists('display_case_events');
        Schema::dropIfExists('display_case_items');
        Schema::dropIfExists('display_cases');
        Schema::dropIfExists('recipe_items');
        Schema::dropIfExists('recipes');
        Schema::dropIfExists('ingredient_lots');
        Schema::dropIfExists('ingredients');
        Schema::dropIfExists('toppings');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('allergen_product');
        Schema::dropIfExists('products');
        Schema::dropIfExists('allergens');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('units');
    }
};
