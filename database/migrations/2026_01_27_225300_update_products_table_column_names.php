<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if tax_rate column exists before trying to rename
        if (Schema::hasColumn('products', 'tax_rate')) {
            Schema::table('products', function (Blueprint $table) {
                $table->renameColumn('tax_rate', 'default_tax_rate');
            });
        }
        
        // Drop columns only if they exist
        Schema::table('products', function (Blueprint $table) {
            $columns = [];
            $columnsToCheck = [
                'cost_price',
                'selling_price',
                'compare_price',
                'discount_amount',
                'discount_type',
                'stock_quantity',
                'allow_backorder',
                'is_featured',
            ];
            
            foreach ($columnsToCheck as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $columns[] = $column;
                }
            }
            
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
        
        // Add the base price columns if they don't exist
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'base_cost_price')) {
                $table->decimal('base_cost_price', 15, 2)->default(0)->after('image');
            }
            if (!Schema::hasColumn('products', 'base_selling_price')) {
                $table->decimal('base_selling_price', 15, 2)->after('base_cost_price');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Remove the base price columns
            $table->dropColumn(['base_cost_price', 'base_selling_price']);
        });
        
        Schema::table('products', function (Blueprint $table) {
            // Restore the old columns
            $table->decimal('cost_price', 15, 2)->default(0)->after('image');
            $table->decimal('selling_price', 15, 2)->after('cost_price');
            $table->decimal('compare_price', 15, 2)->nullable()->after('selling_price');
            $table->decimal('discount_amount', 15, 2)->nullable()->after('default_tax_rate');
            $table->enum('discount_type', ['fixed', 'percentage'])->nullable()->after('discount_amount');
            $table->integer('stock_quantity')->default(0)->after('stock_tracking');
            $table->boolean('allow_backorder')->default(false)->after('low_stock_threshold');
            $table->boolean('is_featured')->default(false)->after('is_active');
        });
        
        Schema::table('products', function (Blueprint $table) {
            // Rename back
            $table->renameColumn('default_tax_rate', 'tax_rate');
        });
    }
};
