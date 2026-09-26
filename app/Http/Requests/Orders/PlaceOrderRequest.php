<?php

namespace App\Http\Requests\Orders;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlaceOrderRequest extends FormRequest
{
    /**
     * Only the permission is checked here; access to the chosen warehouse is
     * authorised in the controller once the warehouse id has been validated.
     */
    public function authorize(): bool
    {
        return $this->user()->can(Permission::OrdersCreate->value);
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->where('is_active', true)],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'ship_to' => ['required', 'array:name,line1,city,postal_code,country'],
            'ship_to.name' => ['required', 'string', 'max:255'],
            'ship_to.line1' => ['required', 'string', 'max:255'],
            'ship_to.city' => ['required', 'string', 'max:255'],
            'ship_to.postal_code' => ['required', 'string', 'max:20'],
            'ship_to.country' => ['required', 'string', 'size:2', 'alpha'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.product_id.distinct' => 'Each product may only appear once per order.',
            'items.*.product_id.exists' => 'The product does not exist or is no longer sold.',
            'warehouse_id.exists' => 'The warehouse does not exist or is inactive.',
        ];
    }
}
