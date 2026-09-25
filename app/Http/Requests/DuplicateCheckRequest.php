<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DuplicateCheckRequest extends FormRequest
{
    /**
     * The proposed patient, loose enough to check a half-filled form: the
     * point is to catch a duplicate before the clerk finishes typing, not to
     * enforce the registration rules a second time.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'family_name' => ['required', 'string', 'max:80'],
            'given_name' => ['required', 'string', 'max:80'],
            'other_names' => ['nullable', 'string', 'max:120'],
            'sex' => ['nullable', Rule::in(['male', 'female', 'other', 'unknown'])],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'estimated_age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'phone_primary' => ['nullable', 'string', 'max:24'],
            'phone_alt' => ['nullable', 'string', 'max:24'],
            'identifiers' => ['array'],
            'identifiers.*.system' => ['required_with:identifiers', Rule::in(array_keys(config('identifiers.systems')))],
            'identifiers.*.value' => ['required_with:identifiers', 'string', 'max:64'],
        ];
    }
}
