<?php

namespace App\Http\Requests;

use App\Models\BranchProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BulkBranchProductMoveRequest extends FormRequest
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
        $mode = $this->input('mode');

        $rules = [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'direction' => ['required', 'string', 'in:to_shelf,to_store'],
            'mode' => ['required', 'string', 'in:all,fixed_quantity,per_item'],
        ];

        if ($mode === 'fixed_quantity') {
            $rules['branch_product_ids'] = ['required', 'array', 'min:1'];
            $rules['branch_product_ids.*'] = ['required', 'integer', 'exists:branch_products,id'];
            $rules['quantity'] = ['required', 'integer', 'min:1'];
        }

        if ($mode === 'per_item') {
            $rules['items'] = ['required', 'array', 'min:1'];
            $rules['items.*.branch_product_id'] = ['required', 'integer', 'exists:branch_products,id'];
            $rules['items.*.quantity'] = ['required', 'integer', 'min:1'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $branchId = (int) $this->input('branch_id');
            $mode = $this->input('mode');

            if ($mode === 'fixed_quantity') {
                $ids = $this->input('branch_product_ids', []);
                $this->assertBranchProductsBelongToBranch($validator, $branchId, $ids);
            }

            if ($mode === 'per_item') {
                $items = $this->input('items', []);
                $ids = array_map(
                    fn (array $item): int => (int) ($item['branch_product_id'] ?? 0),
                    $items
                );
                $this->assertBranchProductsBelongToBranch($validator, $branchId, $ids);
            }
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
                'branch_product_ids',
                'One or more branch products do not belong to the given branch or are invalid.'
            );
        }
    }
}
