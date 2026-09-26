<?php

namespace App\Http\Requests\Users;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'role' => ['sometimes', Rule::enum(Role::class)],
            'is_active' => ['sometimes', 'boolean'],
            'warehouse_ids' => ['sometimes', 'array'],
            'warehouse_ids.*' => ['integer', 'distinct', Rule::exists('warehouses', 'id')],
        ];
    }

    /**
     * An administrator must not be able to lock themselves out.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $this->user()->is($this->route('user'))) {
                    return;
                }

                if ($this->has('is_active') && ! $this->boolean('is_active')) {
                    $validator->errors()->add('is_active', 'You cannot deactivate your own account.');
                }

                if ($this->has('role') && $this->input('role') !== Role::Admin->value && $this->user()->hasRole(Role::Admin->value)) {
                    $validator->errors()->add('role', 'You cannot remove your own administrator role.');
                }
            },
        ];
    }
}
