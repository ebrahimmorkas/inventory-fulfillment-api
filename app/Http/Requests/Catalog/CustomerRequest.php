<?php

namespace App\Http\Requests\Catalog;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Used for both create (POST) and partial update (PATCH). */
class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $customer
            ? $this->user()->can('update', $customer)
            : $this->user()->can('create', Customer::class);
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'email' => [$required, 'string', 'email', 'max:255', Rule::unique('customers', 'email')->ignore($this->route('customer'))],
            'company_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
        ];
    }
}
