<?php

namespace App\Http\Middleware;

use App\Models\BranchAuthorization;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\ManagerActivity;
use App\Models\Product;
use App\Models\PurchaseOrder;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records stock and product changes made by managers so the owner can see
 * them on the Manager Activity page. The web dashboard sends the owner's
 * auth code in the X-Owner-Code header; we record whether it was valid.
 */
class RecordManagerActivity
{
    /** Route prefixes (after api/) whose writes are recorded. */
    private const PREFIXES = [
        'products',
        'branch-products',
        'inventory',
        'batches',
        'stock-writeoffs',
        'stock-transfer-requests',
        'shelf-store-move-requests',
        'purchase-orders',
        'goods-received-notes',
    ];

    /** Fields compared before and after a change, per model. */
    private const TRACKED_FIELDS = [
        BranchProduct::class => [
            'cost_price', 'selling_price', 'shelf_quantity', 'store_quantity',
            'low_stock_threshold', 'reorder_point', 'is_available',
        ],
        Product::class => ['name', 'sku', 'barcode', 'base_cost_price', 'base_selling_price'],
        PurchaseOrder::class => ['status'],
    ];

    /** Plain-words labels by "METHOD uri". */
    private const LABELS = [
        'POST products' => ['added_product', 'Added product'],
        'PUT products/{id}' => ['edited_product', 'Edited product'],
        'DELETE products/{id}' => ['deleted_product', 'Deleted product'],
        'PATCH products/{id}/price' => ['changed_price', 'Changed price of'],
        'PATCH products/{id}/base-selling-price' => ['changed_price', 'Changed selling price of'],
        'POST branch-products' => ['added_to_branch', 'Added'],
        'PUT branch-products/{id}' => ['edited_stock', 'Edited'],
        'DELETE branch-products/{id}' => ['removed_from_branch', 'Removed'],
        'PATCH branch-products/{id}/selling-price' => ['changed_price', 'Changed selling price of'],
        'POST branch-products/{id}/stock' => ['changed_stock', 'Changed stock of'],
        'POST branch-products/{id}/move-to-shelf' => ['moved_stock', 'Moved to shelf:'],
        'POST branch-products/{id}/move-to-store' => ['moved_stock', 'Moved to store:'],
        'POST branch-products/bulk-selling-price' => ['changed_price', 'Changed selling prices'],
        'POST branch-products/bulk-move' => ['moved_stock', 'Moved stock'],
        'POST branch-products/bulk-update' => ['edited_stock', 'Edited several items'],
        'POST stock-writeoffs' => ['wrote_off', 'Wrote off stock'],
        'POST purchase-orders' => ['purchase_order', 'Created purchase order'],
        'PUT purchase-orders/{id}' => ['purchase_order', 'Edited purchase order'],
        'POST purchase-orders/{id}/submit' => ['purchase_order', 'Submitted purchase order'],
        'POST purchase-orders/{id}/cancel' => ['purchase_order', 'Cancelled purchase order'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldRecord($request)) {
            return $next($request);
        }

        $subject = $this->findSubject($request);
        $before = $subject ? $this->snapshot($subject) : null;

        $response = $next($request);

        if ($response->isSuccessful()) {
            try {
                $this->record($request, $subject, $before);
            } catch (Throwable $e) {
                // Never fail the manager's change because the log could not be written.
                Log::warning('Could not record manager activity', ['error' => $e->getMessage()]);
            }
        }

        return $response;
    }

    private function shouldRecord(Request $request): bool
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return false;
        }

        $path = $this->relativePath($request);
        $matchesPrefix = collect(self::PREFIXES)->contains(
            fn (string $prefix) => $path === $prefix || str_starts_with($path, $prefix.'/')
        );
        if (! $matchesPrefix) {
            return false;
        }

        $user = $request->user();
        $businessId = $this->businessId($request);
        if (! $user || ! $businessId) {
            return false;
        }

        if ($request->hasHeader('X-Owner-Code')) {
            return true;
        }

        $business = Business::find($businessId);
        if ($business && (int) $business->owner_id === (int) $user->id) {
            return false;
        }

        return $user->hasRole('Manager');
    }

    private function relativePath(Request $request): string
    {
        return preg_replace('#^api/#', '', trim($request->path(), '/'));
    }

    private function routeKey(Request $request): string
    {
        $uri = preg_replace('#^api/#', '', $request->route()?->uri() ?? $this->relativePath($request));

        return $request->method().' '.$uri;
    }

    private function businessId(Request $request): ?int
    {
        $id = app()->bound('current_business_id') ? app('current_business_id') : $request->header('X-Business-Id');

        return $id ? (int) $id : null;
    }

    private function findSubject(Request $request): ?Model
    {
        $id = $request->route('id');
        if (! $id) {
            return null;
        }

        $path = $this->relativePath($request);
        $class = match (true) {
            str_starts_with($path, 'branch-products/') => BranchProduct::class,
            str_starts_with($path, 'products/') => Product::class,
            str_starts_with($path, 'purchase-orders/') => PurchaseOrder::class,
            default => null,
        };

        return $class ? $class::find($id) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Model $model): array
    {
        $fields = self::TRACKED_FIELDS[$model::class] ?? [];

        return collect($fields)->mapWithKeys(fn (string $f) => [$f => $model->getAttribute($f)])->all();
    }

    private function record(Request $request, ?Model $subject, ?array $before): void
    {
        $businessId = $this->businessId($request);
        [$action, $label] = self::LABELS[$this->routeKey($request)] ?? ['other', $request->method().' '.$this->relativePath($request)];

        $changes = [];
        if ($subject && $before !== null) {
            $fresh = $subject->fresh();
            $after = $fresh ? $this->snapshot($fresh) : [];
            foreach ($before as $field => $old) {
                $new = $after[$field] ?? null;
                if ($fresh && (string) $old !== (string) $new) {
                    $changes[$field] = ['from' => $old, 'to' => $new];
                }
            }
        }

        $details = $this->requestDetails($request);
        if ($details) {
            $changes = array_merge($details, $changes);
        }

        ManagerActivity::create([
            'business_id' => $businessId,
            'branch_id' => $this->branchId($request, $subject),
            'user_id' => $request->user()->id,
            'action' => $action,
            'description' => trim($label.' '.$this->subjectName($request, $subject)),
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'changes' => $changes ?: null,
            'owner_code_ok' => $this->ownerCodeIsValid($request, $businessId),
            'method' => $request->method(),
            'path' => mb_substr($this->relativePath($request), 0, 255),
        ]);
    }

    /**
     * Small, readable extras from the request (quantities, counts) for actions
     * that don't change a single tracked record.
     *
     * @return array<string, mixed>
     */
    private function requestDetails(Request $request): array
    {
        $details = [];
        foreach (['quantity', 'reason', 'type'] as $key) {
            if ($request->filled($key) && is_scalar($request->input($key))) {
                $details[$key] = ['to' => $request->input($key)];
            }
        }
        foreach (['items', 'lines', 'moves'] as $key) {
            if (is_array($request->input($key))) {
                $details['items_count'] = ['to' => count($request->input($key))];
            }
        }

        return $details;
    }

    private function subjectName(Request $request, ?Model $subject): string
    {
        if ($subject instanceof BranchProduct) {
            return (string) ($subject->product?->name ?? '');
        }
        if ($subject instanceof Product) {
            return (string) $subject->name;
        }
        if ($subject instanceof PurchaseOrder) {
            return (string) $subject->po_number;
        }
        if ($request->filled('name') && is_string($request->input('name'))) {
            return mb_substr($request->input('name'), 0, 150);
        }
        if ($request->filled('branch_product_id')) {
            return (string) (BranchProduct::find($request->input('branch_product_id'))?->product?->name ?? '');
        }

        return '';
    }

    private function branchId(Request $request, ?Model $subject): ?int
    {
        $id = $subject?->getAttribute('branch_id') ?? $request->input('branch_id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function ownerCodeIsValid(Request $request, ?int $businessId): bool
    {
        $code = trim((string) $request->header('X-Owner-Code', ''));
        if ($code === '' || ! $businessId) {
            return false;
        }

        $ownerId = Business::whereKey($businessId)->value('owner_id');

        // Only the owner's own codes count. A code typed just before it expired
        // still counts for the next couple of minutes.
        return BranchAuthorization::where('auth_code', $code)
            ->where('business_id', $businessId)
            ->where('user_id', $ownerId)
            ->where('expires_at', '>', now()->subMinutes(2))
            ->exists();
    }
}
