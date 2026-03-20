<?php

namespace App\Http\Requests;

use App\Models\BranchProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BulkBranchProductSellingPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.branch_product_id' => ['required', 'integer', 'exists:branch_products,id'],
            'items.*.selling_price' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $branchId = (int) $this->input('branch_id');
            $items = $this->input('items', []);
            $ids = array_map(
                fn (array $item): int => (int) ($item['branch_product_id'] ?? 0),
                $items
            );
            $this->assertBranchProductsBelongToBranch($validator, $branchId, $ids);
        });
    }

    /**
     * @param  array<int, int>  $ids
     */
    protected function assertBranchProductsBelongToBranch(Validator $validator, int $branchId, array $ids): void
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return;
        }

        $count = BranchProduct::query()
            ->where('branch_id', $branchId)
            ->whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->count();

        if ($count !== count($ids)) {
            $validator->errors()->add(
                'items',
                'One or more branch products do not belong to the given branch or are invalid.'
            );
        }
    }
}
