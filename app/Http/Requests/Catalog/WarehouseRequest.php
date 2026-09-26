<?php

namespace App\Http\Requests\Catalog;

use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Used for both create (POST) and partial update (PATCH). */
class WarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $warehouse = $this->route('warehouse');

        return $warehouse
            ? $this->user()->can('update', $warehouse)
            : $this->user()->can('create', Warehouse::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'code' => $this->has('code') ? strtoupper(trim((string) $this->input('code'))) : null,
            'country_code' => $this->has('country_code') ? strtoupper((string) $this->input('country_code')) : null,
        ], fn ($value) => $value !== null));
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/', Rule::unique('warehouses', 'code')->ignore($this->route('warehouse'))],
            'name' => [$required, 'string', 'max:255'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'country_code' => [$required, 'string', 'size:2', 'alpha'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
