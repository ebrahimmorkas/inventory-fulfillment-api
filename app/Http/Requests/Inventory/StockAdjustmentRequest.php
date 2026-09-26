<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageStock', $this->route('warehouse'));
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'quantity_delta' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            // Adjustments are audited, so a reason is mandatory.
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
