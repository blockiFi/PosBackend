<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SeedDataRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('delete')) {
            $this->merge([
                'delete' => filter_var($this->input('delete'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $entity = $this->input('entity');
        $allowedEntities = array_keys(config('seed.allowed_entities', []));

        return [
            'file' => [
                'required',
                'file',
                'max:10240',
                function (string $attribute, \Illuminate\Http\UploadedFile $value, \Closure $fail): void {
                    $ext = strtolower($value->getClientOriginalExtension() ?? '');
                    $mime = $value->getMimeType();
                    $validExt = in_array($ext, ['csv', 'xlsx', 'xls'], true);
                    $validMime = in_array($mime, [
                        'text/csv',
                        'text/plain',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ], true);
                    if (! $validExt && ! $validMime) {
                        $fail('The file must be a CSV or Excel file (csv, xlsx, xls).');
                    }
                },
            ],
            'entity' => ['required', 'string', 'in:'.implode(',', $allowedEntities)],
            'mapping' => ['required', 'array', 'min:1'],
            'mapping.*' => ['required', 'string', 'max:255'],
            'unique_key' => ['required', 'string', 'max:255'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'delete' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Configure the validator after the request is validated for basic rules.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $entity = $this->input('entity');
            $mapping = $this->input('mapping', []);
            $uniqueKey = $this->input('unique_key');
            $allowed = config("seed.allowed_entities.{$entity}", []);

            if (empty($allowed)) {
                return;
            }

            foreach ($mapping as $header => $dbColumn) {
                if (! in_array($dbColumn, $allowed, true)) {
                    $validator->errors()->add(
                        'mapping',
                        "Mapping value \"{$dbColumn}\" is not allowed for entity \"{$entity}\". Allowed: ".implode(', ', $allowed)
                    );
                    break;
                }
            }

            $mappingValues = array_values($mapping);
            if ($uniqueKey !== '' && ! in_array($uniqueKey, $mappingValues, true)) {
                $validator->errors()->add(
                    'unique_key',
                    'unique_key must be one of the mapping target columns: '.implode(', ', $mappingValues)
                );
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimes' => 'The file must be a CSV or Excel file (csv, xlsx, xls).',
            'file.max' => 'The file may not be greater than 10MB.',
        ];
    }
}
