<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The default deposit stock mode changes from reserve_on_create to deduct_on_complete.
 * Deposits normally stamp the mode they were opened under in sales.metadata, but deposits
 * synced before that stamping existed have no stamp and already had their stock deducted
 * at creation. Stamp them as reserve_on_create so completing or cancelling them under the
 * new default neither deducts their stock a second time nor skips the restore.
 *
 * Idempotent: only rows without a valid stamp are touched.
 */
return new class extends Migration
{
    private const MODES = ['reserve_on_create', 'deduct_on_complete'];

    public function up(): void
    {
        DB::table('sales')
            ->where('sale_type', 'deposit')
            ->select(['id', 'metadata'])
            ->orderBy('id')
            ->chunkById(500, function ($sales): void {
                foreach ($sales as $sale) {
                    $metadata = is_string($sale->metadata) && $sale->metadata !== ''
                        ? json_decode($sale->metadata, true)
                        : [];
                    if (! is_array($metadata)) {
                        $metadata = [];
                    }

                    $stamped = $metadata['deposit_stock_mode'] ?? null;
                    if (is_string($stamped) && in_array($stamped, self::MODES, true)) {
                        continue;
                    }

                    $metadata['deposit_stock_mode'] = 'reserve_on_create';
                    DB::table('sales')->where('id', $sale->id)->update([
                        'metadata' => json_encode($metadata),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Non-destructive: the stamp records how the deposit's stock was handled.
    }
};
