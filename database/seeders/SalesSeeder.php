<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesShift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SalesSeeder extends Seeder
{
    private int $salesCount = 1000; // Number of sales to generate

    /**
     * Run the database seeds.
     */
    public function run(?int $count = null): void
    {
        if ($count !== null) {
            $this->salesCount = $count;
        } else {
            $size = config('seeding.size', 'large');
            $this->salesCount = (int) config("seeding.limits.{$size}.sales", 1000);
        }

        $businesses = Business::with('branches')->get();

        if ($businesses->isEmpty()) {
            $this->command->warn('No businesses found. Please run BusinessSeeder first.');

            return;
        }

        foreach ($businesses as $business) {
            $this->command->info("Seeding sales for business: {$business->name}");
            $this->createSalesForBusiness($business);
        }
    }

    private function createSalesForBusiness(Business $business): void
    {
        $branches = $business->branches;
        $products = Product::where('business_id', $business->id)->get();
        $customers = Customer::where('business_id', $business->id)->get();
        $paymentMethods = PaymentMethod::where('business_id', $business->id)->get();

        if ($products->isEmpty()) {
            $this->command->warn("No products found for {$business->name}. Skipping sales.");

            return;
        }

        if ($paymentMethods->isEmpty()) {
            $this->command->warn("No payment methods found for {$business->name}. Creating defaults.");
            $this->createDefaultPaymentMethods($business);
            $paymentMethods = PaymentMethod::where('business_id', $business->id)->get();
        }

        if ($customers->isEmpty()) {
            $this->command->info("Creating customers for {$business->name}...");
            $customerCount = (int) config('seeding.limits.'.config('seeding.size', 'large').'.customers', 50);
            Customer::factory($customerCount)->create(['business_id' => $business->id]);
            $customers = Customer::where('business_id', $business->id)->get();
        }

        $days = $this->salesCount <= 100 ? 14 : 60;
        $startDate = Carbon::now()->subDays($days);
        $endDate = Carbon::now();

        $salesPerDay = (int) ceil($this->salesCount / $days);
        $currentDate = $startDate->copy();
        $totalSalesGenerated = 0;

        while ($currentDate <= $endDate && $totalSalesGenerated < $this->salesCount) {
            // Skip some random days (business not operating)
            if (fake()->boolean(10)) { // 10% chance to skip
                $currentDate->addDay();

                continue;
            }

            $dailySalesCount = fake()->numberBetween(
                (int) ($salesPerDay * 0.5),
                (int) ($salesPerDay * 1.5)
            );

            foreach ($branches as $branch) {
                // Get or create shifts for this branch on this day
                $shifts = $this->getOrCreateShiftsForDay($business, $branch, $currentDate);

                foreach ($shifts as $shift) {
                    $shiftSalesCount = (int) ceil($dailySalesCount / count($shifts) / count($branches));

                    for ($i = 0; $i < $shiftSalesCount && $totalSalesGenerated < $this->salesCount; $i++) {
                        $this->createSale(
                            $business,
                            $branch,
                            $shift,
                            $products,
                            $customers,
                            $paymentMethods,
                            $currentDate
                        );
                        $totalSalesGenerated++;
                    }
                }
            }

            $currentDate->addDay();
        }

        $this->command->info("Generated {$totalSalesGenerated} sales for {$business->name}");

        // Close old shifts
        $this->closeOldShifts($business);
    }

    private function getOrCreateShiftsForDay(Business $business, Branch $branch, Carbon $date): array
    {
        $shiftsForDay = SalesShift::where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->whereDate('start_time', $date->toDateString())
            ->get();

        if ($shiftsForDay->isNotEmpty()) {
            return $shiftsForDay->all();
        }

        $shiftLimits = config('seeding.limits.'.config('seeding.size', 'large').'.shifts_per_day', [1, 3]);
        $shiftCount = fake()->numberBetween($shiftLimits[0], $shiftLimits[1]);
        $shifts = [];

        $users = $business->users()->get();
        if ($users->isEmpty()) {
            $users = User::factory(2)->create();
            foreach ($users as $user) {
                $user->businesses()->attach($business->id, [
                    'is_active' => true,
                ]);
            }
        }

        for ($i = 0; $i < $shiftCount; $i++) {
            $startHour = 8 + ($i * 6); // Shifts at 8AM, 2PM, 8PM
            $startTime = $date->copy()->setTime($startHour, 0, 0);
            $endTime = $startTime->copy()->addHours(6);

            $user = $users->random();

            $shift = SalesShift::create([
                'shift_number' => 'SHIFT-'.$branch->id.'-'.$date->format('Ymd').'-'.str_pad($i + 1, 3, '0', STR_PAD_LEFT),
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'user_id' => $user->id,
                'start_time' => $startTime,
                'end_time' => $date->isPast() ? $endTime : null,
                'opening_balance' => 500.00,
                'status' => $date->isPast() ? 'closed' : 'open',
            ]);

            $shifts[] = $shift;
        }

        return $shifts;
    }

    private function createSale(
        Business $business,
        Branch $branch,
        SalesShift $shift,
        $products,
        $customers,
        $paymentMethods,
        Carbon $baseDate
    ): void {
        // Random time within shift hours
        $saleTime = $shift->start_time->copy()->addMinutes(fake()->numberBetween(0, 360));

        // 60% chance of having a customer
        $customer = fake()->boolean(60) ? $customers->random() : null;

        // Create sale
        $sale = Sale::create([
            'sale_number' => 'SALE-'.$saleTime->format('Ymd').'-'.str_pad(fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer?->id,
            'user_id' => $shift->user_id,
            'shift_id' => $shift->id,
            'sale_date' => $saleTime,
            'subtotal' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 0,
            'status' => 'completed',
            'payment_status' => 'paid',
            'paid_amount' => 0,
            'sale_type' => fake()->randomElement(['pos', 'online', 'wholesale']),
            'created_at' => $saleTime,
            'updated_at' => $saleTime,
        ]);

        // Add 1-8 items to the sale
        $itemCount = fake()->numberBetween(1, 8);
        $subtotal = 0;
        $totalTax = 0;
        $totalItemsAmount = 0;

        for ($i = 0; $i < $itemCount; $i++) {
            $product = $products->random();
            $quantity = fake()->numberBetween(1, 5);
            $unitPrice = (float) $product->base_selling_price;
            $itemSubtotal = $quantity * $unitPrice;

            // Random discount (20% chance)
            $discountPercentage = fake()->boolean(20) ? fake()->randomFloat(2, 5, 20) : 0;
            $discountAmount = $discountPercentage > 0 ? round($itemSubtotal * ($discountPercentage / 100), 2) : 0;

            $afterDiscount = $itemSubtotal - $discountAmount;
            $taxRate = $product->is_taxable ? (float) $product->default_tax_rate : 0;
            $taxAmount = round($afterDiscount * ($taxRate / 100), 2);
            $itemTotal = round($afterDiscount + $taxAmount, 2);

            SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_sku' => $product->sku,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_amount' => $discountAmount,
                'discount_percentage' => $discountPercentage,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'subtotal' => $itemSubtotal,
                'total' => $itemTotal,
                'created_at' => $saleTime,
                'updated_at' => $saleTime,
            ]);

            $subtotal += $itemSubtotal;
            $totalTax += $taxAmount;
            $totalItemsAmount += $itemTotal;
        }

        // Calculate sale total - use sum of item totals for accuracy
        $saleDiscountAmount = fake()->boolean(10) ? round($subtotal * 0.05, 2) : 0;
        $totalAmount = round($totalItemsAmount - $saleDiscountAmount, 2);

        $sale->update([
            'subtotal' => $subtotal,
            'tax_amount' => $totalTax,
            'discount_amount' => $saleDiscountAmount,
            'total_amount' => $totalAmount,
            'paid_amount' => $totalAmount,
        ]);

        // Create payment(s) - 90% single payment, 10% split payment
        if (fake()->boolean(90)) {
            // Single payment
            $paymentMethod = $paymentMethods->random();

            Payment::create([
                'sale_id' => $sale->id,
                'payment_method_id' => $paymentMethod->id,
                'amount' => $totalAmount,
                'reference_number' => $paymentMethod->type !== 'cash' ? 'TXN-'.fake()->numerify('##########') : null,
                'payment_date' => $saleTime,
                'status' => 'completed',
                'created_at' => $saleTime,
                'updated_at' => $saleTime,
            ]);
        } else {
            // Split payment (2 methods)
            $payment1Amount = $totalAmount * fake()->randomFloat(2, 0.3, 0.7);
            $payment2Amount = $totalAmount - $payment1Amount;

            $paymentMethod1 = $paymentMethods->random();
            $paymentMethod2 = $paymentMethods->where('id', '!=', $paymentMethod1->id)->random();

            Payment::create([
                'sale_id' => $sale->id,
                'payment_method_id' => $paymentMethod1->id,
                'amount' => $payment1Amount,
                'reference_number' => $paymentMethod1->type !== 'cash' ? 'TXN-'.fake()->numerify('##########') : null,
                'payment_date' => $saleTime,
                'status' => 'completed',
                'created_at' => $saleTime,
                'updated_at' => $saleTime,
            ]);

            Payment::create([
                'sale_id' => $sale->id,
                'payment_method_id' => $paymentMethod2->id,
                'amount' => $payment2Amount,
                'reference_number' => $paymentMethod2->type !== 'cash' ? 'TXN-'.fake()->numerify('##########') : null,
                'payment_date' => $saleTime,
                'status' => 'completed',
                'created_at' => $saleTime,
                'updated_at' => $saleTime,
            ]);
        }

        // Update shift totals
        $this->updateShiftTotals($shift);
    }

    private function updateShiftTotals(SalesShift $shift): void
    {
        $sales = Sale::where('shift_id', $shift->id)->get();
        $totalSales = $sales->sum('total_amount');
        $transactionsCount = $sales->count();

        // Calculate payment method breakdown
        $cashSales = 0;
        $cardSales = 0;
        $otherSales = 0;

        foreach ($sales as $sale) {
            foreach ($sale->payments as $payment) {
                switch ($payment->paymentMethod->type) {
                    case 'cash':
                        $cashSales += (float) $payment->amount;
                        break;
                    case 'card':
                        $cardSales += (float) $payment->amount;
                        break;
                    default:
                        $otherSales += (float) $payment->amount;
                }
            }
        }

        $expectedCash = $shift->opening_balance + $cashSales;
        $variance = fake()->randomFloat(2, -20, 20); // Small variance
        $actualCash = $expectedCash + $variance;

        $shift->update([
            'cash_sales' => $cashSales,
            'card_sales' => $cardSales,
            'other_sales' => $otherSales,
            'total_sales' => $totalSales,
            'transactions_count' => $transactionsCount,
            'expected_cash' => $expectedCash,
            'actual_cash' => $shift->status === 'closed' ? $actualCash : null,
            'variance' => $shift->status === 'closed' ? $variance : null,
        ]);
    }

    private function closeOldShifts(Business $business): void
    {
        $openShifts = SalesShift::where('business_id', $business->id)
            ->where('status', 'open')
            ->where('start_time', '<', Carbon::now()->subHours(6))
            ->get();

        foreach ($openShifts as $shift) {
            $this->updateShiftTotals($shift);
            $shift->update([
                'end_time' => $shift->start_time->copy()->addHours(6),
                'status' => 'closed',
            ]);
        }
    }

    private function createDefaultPaymentMethods(Business $business): void
    {
        PaymentMethod::create([
            'business_id' => $business->id,
            'name' => 'Cash',
            'type' => 'cash',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        PaymentMethod::create([
            'business_id' => $business->id,
            'name' => 'Credit/Debit Card',
            'type' => 'card',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        PaymentMethod::create([
            'business_id' => $business->id,
            'name' => 'Mobile Money',
            'type' => 'mobile_money',
            'is_active' => true,
            'sort_order' => 3,
        ]);
    }
}
