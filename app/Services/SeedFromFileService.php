<?php

namespace App\Services;

use App\Imports\SeedFileImport;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SeedImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;

class SeedFromFileService
{
    /**
     * Columns that belong to BranchProduct only (not set on Product).
     *
     * @var list<string>
     */
    protected array $branchOnlyColumns = [
        'cost_price',
        'selling_price',
        'compare_price',
        'stock_quantity',
        'shelf_quantity',
        'store_quantity',
        'low_stock_threshold',
        'is_available',
    ];

    /**
     * Run the seed: parse file, apply mapping, upsert entities, optionally attach to branches.
     *
     * @param  array<string, string>  $mapping  File header -> DB column
     * @param  int  $branchId  Branch ID to attach products to (products only)
     * @param  bool  $delete  When true, rows are looked up and hard-deleted instead of upserted
     * @param  SeedImport|null  $import  Optional import record for progress tracking
     * @return array{created: int, updated: int, deleted: int, failed: int, errors: array<int, string>}
     */
    public function run(
        UploadedFile $file,
        string $entity,
        array $mapping,
        string $uniqueKey,
        int $businessId,
        int $branchId,
        bool $delete = false,
        ?SeedImport $import = null
    ): array {
        $result = [
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $rows = $this->parseFile($file);
        if (empty($rows)) {
            return $result;
        }

        if ($import) {
            $import->update(['total_rows' => count($rows)]);
        }

        $processedCount = 0;

        foreach ($rows as $rowIndex => $row) {
            $oneBased = $rowIndex + 1;
            try {
                if ($this->isEmptyRow($row)) {
                    continue;
                }
                $payload = $this->mapRowToPayload($row, $mapping);

                if ($delete) {
                    if ($entity === 'products') {
                        $this->deleteProduct($payload, $uniqueKey, $businessId, $result);
                    } elseif ($entity === 'product_categories') {
                        $this->deleteProductCategory($payload, $uniqueKey, $businessId, $result);
                    }
                } elseif ($entity === 'products') {
                    $this->upsertProduct($payload, $uniqueKey, $businessId, $branchId, $row, $mapping, $result);
                } elseif ($entity === 'product_categories') {
                    $this->upsertProductCategory($payload, $uniqueKey, $businessId, $result);
                }
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['errors'][$oneBased] = $e->getMessage();
            }

            $processedCount++;
            if ($import && $processedCount % 50 === 0) {
                $this->syncProgress($import, $result);
            }
        }

        if ($import) {
            $this->syncProgress($import, $result);
        }

        return $result;
    }

    /**
     * Flush current counters to the SeedImport record for progress tracking.
     *
     * @param  array{created: int, updated: int, deleted: int, failed: int, errors: array<int, string>}  $result
     */
    protected function syncProgress(SeedImport $import, array $result): void
    {
        $import->update([
            'created' => $result['created'],
            'updated' => $result['updated'],
            'deleted' => $result['deleted'],
            'failed' => $result['failed'],
        ]);
    }

    /**
     * Parse CSV/Excel file to array of rows (associative by header).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function parseFile(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension === 'csv') {
            return $this->parseCsvFile($file);
        }
        HeadingRowFormatter::default('none');
        try {
            $sheets = Excel::toArray(new SeedFileImport, $file);
            $rows = $sheets[0] ?? [];
            if (! is_array($rows) || empty($rows)) {
                return [];
            }
            $first = reset($rows);
            if (! is_array($first)) {
                return [];
            }
            $firstKeys = array_keys($first);
            if (isset($firstKeys[0]) && $firstKeys[0] === 0) {
                return $this->rekeyRowsWithFirstRowAsHeaders($rows);
            }

            return $rows;
        } finally {
            HeadingRowFormatter::reset();
        }
    }

    /**
     * Parse CSV using native PHP for predictable header keys (exact as in file).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function parseCsvFile(UploadedFile $file): array
    {
        $path = $file->getPathname();
        if (is_readable($path)) {
            $content = file_get_contents($path);
            if (is_string($content) && $content !== '') {
                return $this->parseCsvString($content);
            }
        }
        try {
            $content = $file->getContent();
            if (is_string($content) && $content !== '') {
                return $this->parseCsvString($content);
            }
        } catch (\Throwable) {
            // ignore
        }

        return [];
    }

    /**
     * Parse CSV string to array of associative rows.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function parseCsvString(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        if ($lines === false || empty($lines)) {
            return [];
        }
        $headerLine = array_shift($lines);
        $headers = str_getcsv($headerLine ?? '');
        if (empty($headers)) {
            return [];
        }
        $headers = array_map('trim', $headers);
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line);
            $assoc = [];
            foreach ($headers as $i => $header) {
                $assoc[$header] = $cells[$i] ?? null;
            }
            $rows[] = $assoc;
        }

        return $rows;
    }

    /**
     * When reader returns numeric-keyed rows, use first row as headers and re-key the rest.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function rekeyRowsWithFirstRowAsHeaders(array $rows): array
    {
        $headerRow = array_shift($rows);
        $headers = array_map(function ($h) {
            return trim((string) $h);
        }, array_values($headerRow));
        $out = [];
        foreach ($rows as $row) {
            $assoc = [];
            foreach (array_values($row) as $i => $val) {
                $key = $headers[$i] ?? $i;
                $assoc[$key] = $val;
            }
            $out[] = $assoc;
        }

        return $out;
    }

    protected function isEmptyRow(array $row): bool
    {
        foreach ($row as $v) {
            if ($v !== null && $v !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Map file row to payload using mapping (raw values; no entity-specific resolution yet).
     * Tries exact, trimmed, slug and lowercase header keys so CSV/Excel reader output is matched.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $mapping
     * @return array<string, mixed>
     */
    protected function mapRowToPayload(array $row, array $mapping): array
    {
        $payload = [];
        foreach ($mapping as $fileHeader => $dbColumn) {
            $value = $this->getRowValue($row, (string) $fileHeader);
            if ($value !== null && $value !== '') {
                $payload[$dbColumn] = $value;
            }
        }

        return $payload;
    }

    /**
     * Get value from row by header name; try exact, trimmed, slug and lowercase to match reader output.
     *
     * @param  array<string, mixed>  $row
     */
    protected function getRowValue(array $row, string $fileHeader): mixed
    {
        if (array_key_exists($fileHeader, $row)) {
            return $row[$fileHeader];
        }
        $trimmed = trim($fileHeader);
        if (array_key_exists($trimmed, $row)) {
            return $row[$trimmed];
        }
        $slug = Str::slug($fileHeader, '_');
        if (array_key_exists($slug, $row)) {
            return $row[$slug];
        }
        foreach ($row as $key => $val) {
            if (Str::slug($key, '_') === $slug || strtolower(trim((string) $key)) === strtolower($trimmed)) {
                return $val;
            }
        }

        return null;
    }

    /**
     * Upsert product and optionally attach to branches.
     */
    protected function upsertProduct(
        array $payload,
        string $uniqueKey,
        int $businessId,
        int $branchId,
        array $row,
        array $mapping,
        array &$result
    ): void {
        if (! isset($payload[$uniqueKey])) {
            throw new \RuntimeException("Unique key \"{$uniqueKey}\" is missing in mapped row.");
        }
        $uniqueValue = $payload[$uniqueKey];
        if ((string) $uniqueValue === '') {
            throw new \RuntimeException("Unique key \"{$uniqueKey}\" is empty.");
        }

        $payload = $this->resolveRetailValue($payload);

        if (isset($payload['stock_quantity']) && ! isset($payload['shelf_quantity'])) {
            $payload['shelf_quantity'] = $payload['stock_quantity'];
        }

        $productPayload = $this->productPayloadFromMapped($payload);
        $categoryName = $payload['category'] ?? null;
        if ($categoryName !== null && $categoryName !== '') {
            $productPayload['category_id'] = $this->resolveCategoryId($businessId, (string) $categoryName);
        }
        unset($productPayload['category'], $productPayload['retail_value']);

        $product = Product::query()
            ->where('business_id', $businessId)
            ->where($uniqueKey, $uniqueValue)
            ->first();

        DB::transaction(function () use (
            &$product,
            $productPayload,
            $payload,
            $businessId,
            $branchId,
            &$result
        ) {
            if ($product) {
                $product->update($productPayload);
                $result['updated']++;
            } else {
                $productPayload['business_id'] = $businessId;
                if (empty($productPayload['sku'])) {
                    $productPayload['sku'] = null;
                }
                if (! isset($productPayload['name']) || $productPayload['name'] === '') {
                    throw new \RuntimeException('Product name is required.');
                }
                if (! array_key_exists('base_selling_price', $productPayload) || $productPayload['base_selling_price'] === null || $productPayload['base_selling_price'] === '') {
                    $stockQty = $payload['stock_quantity'] ?? null;
                    $retailVal = $payload['retail_value'] ?? null;
                    if ($retailVal !== null && is_numeric($retailVal) && $stockQty !== null && is_numeric($stockQty) && (float) $stockQty > 0) {
                        $productPayload['base_selling_price'] = round((float) $retailVal / (float) $stockQty, 2);
                    } else {
                        $productPayload['base_selling_price'] = $productPayload['base_cost_price'] ?? 0;
                    }
                }
                $product = Product::create($productPayload);
                $result['created']++;
            }

            $branchPayload = $this->branchProductPayloadFromRow($payload);
            $this->upsertBranchProduct($product->id, $branchId, $product, $branchPayload);
        });
    }

    /**
     * Build product fillable payload from mapped payload (exclude branch-only columns).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function productPayloadFromMapped(array $payload): array
    {
        $productFillable = (new Product)->getFillable();
        $out = [];
        foreach ($payload as $key => $value) {
            if (in_array($key, $productFillable, true) && ! in_array($key, $this->branchOnlyColumns, true)) {
                $out[$key] = $this->castProductValue($key, $value);
            }
        }

        return $out;
    }

    /**
     * Build BranchProduct payload from mapped payload (only branch columns).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function branchProductPayloadFromRow(array $payload): array
    {
        $out = [];
        foreach ($this->branchOnlyColumns as $col) {
            if (array_key_exists($col, $payload)) {
                $out[$col] = $this->castBranchProductValue($col, $payload[$col]);
            }
        }

        return $out;
    }

    protected function castProductValue(string $key, mixed $value): mixed
    {
        if (in_array($key, ['base_cost_price', 'base_selling_price', 'default_tax_rate', 'weight'], true)) {
            return is_numeric($value) ? (float) $value : 0;
        }
        if (in_array($key, ['low_stock_threshold', 'sort_order'], true)) {
            return is_numeric($value) ? (int) $value : 0;
        }
        if (in_array($key, ['is_taxable', 'is_active', 'is_available_online'], true)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return $value;
    }

    protected function castBranchProductValue(string $key, mixed $value): mixed
    {
        if (in_array($key, ['cost_price', 'selling_price', 'compare_price'], true)) {
            return is_numeric($value) ? (float) $value : 0;
        }
        if (in_array($key, ['stock_quantity', 'shelf_quantity', 'store_quantity', 'low_stock_threshold'], true)) {
            return is_numeric($value) ? (int) max(0, $value) : 0;
        }
        if ($key === 'is_available') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return $value;
    }

    protected function resolveCategoryId(int $businessId, string $name): ?int
    {
        $category = ProductCategory::query()
            ->where('business_id', $businessId)
            ->where('name', $name)
            ->first();
        if ($category) {
            return $category->id;
        }
        $newCategory = ProductCategory::create([
            'business_id' => $businessId,
            'name' => $name,
            'is_active' => true,
        ]);

        return $newCategory->id;
    }

    protected function upsertBranchProduct(int $productId, int $branchId, Product $product, array $branchPayload): void
    {
        $bp = BranchProduct::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->first();

        $data = array_merge([
            'cost_price' => $product->base_cost_price ?? 0,
            'selling_price' => $product->base_selling_price ?? 0,
            'compare_price' => null,
            'stock_quantity' => 0,
            'shelf_quantity' => 0,
            'store_quantity' => 0,
            'low_stock_threshold' => null,
            'is_available' => true,
        ], $branchPayload);

        if (isset($branchPayload['stock_quantity']) && ! isset($branchPayload['shelf_quantity'])) {
            $data['shelf_quantity'] = $data['stock_quantity'];
        }

        if ($bp) {
            $bp->update($data);
        } else {
            BranchProduct::create([
                'branch_id' => $branchId,
                'product_id' => $productId,
                'cost_price' => $data['cost_price'],
                'selling_price' => $data['selling_price'],
                'compare_price' => $data['compare_price'],
                'stock_quantity' => $data['stock_quantity'],
                'shelf_quantity' => $data['shelf_quantity'],
                'store_quantity' => $data['store_quantity'],
                'low_stock_threshold' => $data['low_stock_threshold'],
                'is_available' => $data['is_available'],
            ]);
        }
    }

    /**
     * Resolve the virtual retail_value column: selling_price = retail_value / stock_quantity.
     * Sets both base_selling_price (for Product) and selling_price (for BranchProduct).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function resolveRetailValue(array $payload): array
    {
        $retailValue = $payload['retail_value'] ?? null;
        $stockQty = $payload['stock_quantity'] ?? null;

        if ($retailValue !== null && is_numeric($retailValue) && $stockQty !== null && is_numeric($stockQty) && (float) $stockQty > 0) {
            $sellingPrice = round((float) $retailValue / (float) $stockQty, 2);
            $payload['base_selling_price'] = $sellingPrice;
            $payload['selling_price'] = $sellingPrice;
        }

        unset($payload['retail_value']);

        return $payload;
    }

    /**
     * Hard-delete a product and its branch products by unique key.
     */
    protected function deleteProduct(array $payload, string $uniqueKey, int $businessId, array &$result): void
    {
        if (! isset($payload[$uniqueKey])) {
            throw new \RuntimeException("Unique key \"{$uniqueKey}\" is missing in mapped row.");
        }
        $uniqueValue = $payload[$uniqueKey];
        if ((string) $uniqueValue === '') {
            throw new \RuntimeException("Unique key \"{$uniqueKey}\" is empty.");
        }

        $product = Product::query()
            ->where('business_id', $businessId)
            ->where($uniqueKey, $uniqueValue)
            ->first();

        if (! $product) {
            return;
        }

        DB::transaction(function () use ($product, &$result) {
            BranchProduct::where('product_id', $product->id)->delete();
            $product->forceDelete();
            $result['deleted']++;
        });
    }

    /**
     * Hard-delete a product category by unique key.
     */
    protected function deleteProductCategory(array $payload, string $uniqueKey, int $businessId, array &$result): void
    {
        if (! isset($payload[$uniqueKey])) {
            throw new \RuntimeException("Unique key \"{$uniqueKey}\" is missing in mapped row.");
        }
        $uniqueValue = $payload[$uniqueKey];
        if ((string) $uniqueValue === '') {
            throw new \RuntimeException("Unique key \"{$uniqueKey}\" is empty.");
        }

        $category = ProductCategory::query()
            ->where('business_id', $businessId)
            ->where($uniqueKey, $uniqueValue)
            ->first();

        if (! $category) {
            return;
        }

        $category->forceDelete();
        $result['deleted']++;
    }

    protected function upsertProductCategory(array $payload, string $uniqueKey, int $businessId, array &$result): void
    {
        if (! isset($payload[$uniqueKey])) {
            throw new \RuntimeException("Unique key \"{$uniqueKey}\" is missing in mapped row.");
        }
        $uniqueValue = $payload[$uniqueKey];
        if ((string) $uniqueValue === '') {
            throw new \RuntimeException("Unique key \"{$uniqueKey}\" is empty.");
        }

        $category = ProductCategory::query()
            ->where('business_id', $businessId)
            ->where($uniqueKey, $uniqueValue)
            ->first();

        $categoryFillable = (new ProductCategory)->getFillable();
        $updateData = array_intersect_key($payload, array_flip($categoryFillable));
        unset($updateData['category']);

        if ($category) {
            $category->update($updateData);
            $result['updated']++;
        } else {
            ProductCategory::create(array_merge($updateData, ['business_id' => $businessId]));
            $result['created']++;
        }
    }
}
