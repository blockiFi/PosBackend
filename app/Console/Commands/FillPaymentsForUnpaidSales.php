<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\ChangeLog;

class FillPaymentsForUnpaidSales extends Command
{
    /**
     * The name and signature of the console command.
     *
     * --dry-run: do not persist changes, only print what would be done
     */
    protected $signature = 'sales:fill-unpaid {--dry-run : Show actions without making changes} {--limit=0 : Limit number of sales processed (0 = unlimited)}';

    /**
     * The console command description.
     */
    protected $description = 'Create a completed payment for every sale with payment_status = unpaid and mark the sale completed';

    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $query = Sale::query()
            ->where('payment_status', 'unpaid')
            ->where('status', 'pending')
            ->with('payments')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $count = $query->count();
        if ($count === 0) {
            $this->info('No unpaid pending sales found.');
            return 0;
        }

        $this->info("Found {$count} unpaid pending sale(s). Dry run: " . ($dryRun ? 'yes' : 'no'));

        $processed = 0;
        foreach ($query->cursor() as $sale) {
            // Skip deposit sales
            if (isset($sale->sale_type) && $sale->sale_type === 'deposit') {
                $this->line("Skipping sale {$sale->id} ({$sale->sale_number}): deposit sale.");
                continue;
            }

            // Ensure items are loaded so we can compute totals from item rows
            $sale->loadMissing('items');

            // Recalculate per-item totals in memory and aggregate
            $itemsSubtotal = 0.0;
            $itemsTax = 0.0;
            $itemsTotal = 0.0;

            foreach ($sale->items as $item) {
                // Use SaleItem's helper to compute totals for the item instance
                if (method_exists($item, 'calculateTotals')) {
                    $item->calculateTotals();
                } else {
                    // Fallback calculation if method missing
                    $qty = (float) ($item->quantity ?? 0);
                    $unit = (float) ($item->unit_price ?? 0);
                    $subtotal = $qty * $unit;
                    $discountAmount = (float) ($item->discount_amount ?? 0);
                    if (! empty($item->discount_percentage)) {
                        $discountAmount = $subtotal * ((float) $item->discount_percentage / 100.0);
                    }
                    $afterDiscount = $subtotal - $discountAmount;
                    $taxAmount = $afterDiscount * ((float) ($item->tax_rate ?? 0) / 100.0);
                    $item->subtotal = $subtotal;
                    $item->discount_amount = $discountAmount;
                    $item->tax_amount = $taxAmount;
                    $item->total = $afterDiscount + $taxAmount;
                }

                $itemsSubtotal += (float) $item->subtotal;
                $itemsTax += (float) $item->tax_amount;
                $itemsTotal += (float) $item->total;
            }

            // Apply sale-level discount_amount (if present) to compute sale total
            $saleDiscount = (float) ($sale->discount_amount ?? 0);
            $computedTotal = $itemsTotal - $saleDiscount;

            // Update sale totals in DB when not a dry run
            if ($dryRun) {
                $this->line(sprintf(
                    "Sale %d (%s) - computed subtotal: %.2f, tax: %.2f, discount: %.2f, total: %.2f, paid_amount: %.2f",
                    $sale->id,
                    $sale->sale_number,
                    $itemsSubtotal,
                    $itemsTax,
                    $saleDiscount,
                    $computedTotal,
                    (float) $sale->paid_amount
                ));
            } else {
                // Persist corrected totals before creating payment
                DB::beginTransaction();
                try {
                    // capture old values for audit
                    $oldTotals = [
                        'subtotal' => $sale->subtotal,
                        'tax_amount' => $sale->tax_amount,
                        'total_amount' => $sale->total_amount,
                    ];

                    // bump version
                    $newVersion = ((int) ($sale->version ?? 0)) + 1;

                    $sale->subtotal = $itemsSubtotal;
                    $sale->tax_amount = $itemsTax;
                    $sale->total_amount = $computedTotal;
                    $sale->version = $newVersion;
                    $sale->save();

                    // log change to change_logs table
                    $changes = [];
                    foreach ($oldTotals as $k => $old) {
                        $new = $sale->{$k};
                        if ((string) $old !== (string) $new) {
                            $changes[$k] = ['old' => $old, 'new' => $new];
                        }
                    }

                    if (! empty($changes)) {
                        ChangeLog::logChange(
                            'sales',
                            $sale->id,
                            $sale->client_uuid ?? null,
                            'updated',
                            $newVersion,
                            $changes,
                            null,
                            null,
                            $sale->business_id
                        );
                    }

                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->error("Failed to update totals for sale {$sale->id} ({$sale->sale_number}): " . $e->getMessage());
                    continue;
                }
            }

            // Recompute balance after (possible) update
            $balance = (float) ($computedTotal - $sale->paid_amount);
            if ($balance <= 0) {
                $this->line("Skipping sale {$sale->id} ({$sale->sale_number}): zero or negative balance.");
                continue;
            }

            // Try to pick a sensible payment method for this business
            $paymentMethod = PaymentMethod::query()
                ->when($sale->business_id !== null, function ($q) use ($sale) {
                    return $q->where('business_id', $sale->business_id);
                })
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->first();

            if (! $paymentMethod) {
                // fallback to any active method
                $paymentMethod = PaymentMethod::where('is_active', true)->orderBy('id')->first();
            }

            if (! $paymentMethod) {
                $this->warn("No payment method available for sale {$sale->id} ({$sale->sale_number}). Skipping.");
                continue;
            }

            $this->line("Sale {$sale->id} ({$sale->sale_number}) -> create payment of {$balance} using payment method {$paymentMethod->id} ({$paymentMethod->name})");

            if ($dryRun) {
                $processed++;
                continue;
            }

            DB::beginTransaction();
            try {
                $payment = Payment::create([
                    'sale_id' => $sale->id,
                    'shift_id' => null,
                    'payment_method_id' => $paymentMethod->id,
                    'amount' => $balance,
                    'reference_number' => 'AUTO-'.strtoupper(uniqid()),
                    'payment_date' => Carbon::now(),
                    'status' => 'completed',
                    'notes' => 'Auto-generated payment to clear unpaid sale',
                ]);

                // log created payment to change_logs for sync
                try {
                    ChangeLog::logChange(
                        'payments',
                        $payment->id,
                        null,
                        'created',
                        1,
                        $payment->toArray(),
                        null,
                        null,
                        $sale->business_id
                    );
                } catch (\Exception $__e) {
                    // non-fatal: log and continue
                    $this->warn("Failed to write change log for payment {$payment->id}: " . $__e->getMessage());
                }

                // Recalculate payment status and paid amount
                $sale->updatePaymentStatus();

                // If sale is fully paid and is pending, mark completed (non-deposit behavior kept simple)
                if ($sale->isFullyPaid() && $sale->status === 'pending') {
                    $sale->status = 'completed';
                    $sale->save();
                }

                // log sale payment/status update
                try {
                    $saleChanges = [
                        'paid_amount' => (float) $sale->paid_amount,
                        'payment_status' => $sale->payment_status,
                        'status' => $sale->status,
                        'version' => ($sale->version ?? 0) + 1,
                    ];
                    $sale->version = $saleChanges['version'];
                    $sale->save();

                    ChangeLog::logChange(
                        'sales',
                        $sale->id,
                        $sale->client_uuid ?? null,
                        'updated',
                        $sale->version,
                        $saleChanges,
                        null,
                        null,
                        $sale->business_id
                    );
                } catch (\Exception $__e) {
                    $this->warn("Failed to write change log for sale {$sale->id}: " . $__e->getMessage());
                }

                DB::commit();
                $processed++;
            } catch (\Exception $e) {
                DB::rollBack();
                $this->error("Failed to process sale {$sale->id} ({$sale->sale_number}): " . $e->getMessage());
            }
        }

        $this->info("Processed: {$processed} sale(s).");

        return 0;
    }
}
