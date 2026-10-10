<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body for approve/reject/suspend/reinstate/deactivate/reactivate.
 * `reason` is shown to the affiliate; `internal_note` is staff-only.
 */
class MembershipActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $reasonRequired = ! $this->routeIs('api.v1.admin.affiliates.approve');

        return [
            'reason' => [$reasonRequired ? 'required' : 'nullable', 'string', 'min:5', 'max:1000'],
            'internal_note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
