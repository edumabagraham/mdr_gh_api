<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePatientRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $collectable = collect(config('identifiers.systems'))
            ->filter(fn (array $system) => $system['collectable'])
            ->keys()
            ->all();

        return [
            'client_ref' => ['required', 'uuid'],

            'family_name' => ['required', 'string', 'max:80'],
            'given_name' => ['required', 'string', 'max:80'],
            'other_names' => ['nullable', 'string', 'max:120'],
            'sex' => ['required', Rule::in(['male', 'female', 'other', 'unknown'])],

            // Many patients do not know their date of birth. They may give an
            // age instead, but then the record must say the date is estimated.
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today', 'required_without:age'],
            'age' => ['nullable', 'integer', 'min:0', 'max:130', 'required_without:date_of_birth'],
            'dob_estimated' => ['boolean', Rule::when(
                fn () => $this->input('date_of_birth') === null,
                ['accepted'],
            )],

            'phone_primary' => ['nullable', 'string', 'max:24'],
            'phone_alt' => ['nullable', 'string', 'max:24'],

            'identifiers' => ['array'],
            'identifiers.*.system' => ['required_with:identifiers', Rule::in($collectable)],
            'identifiers.*.value' => ['required_with:identifiers', 'string', 'max:64'],
            'identifiers.*.is_primary' => ['boolean'],
            'identifiers.*.assigner' => ['nullable', 'string', 'max:120'],

            'folder_absent_reason' => ['nullable', Rule::in(config('identifiers.folder_absent_reasons'))],

            'duplicate_check_token' => ['required', 'string'],
            'duplicate_decision' => ['required', Rule::in(['no_match', 'new_person_despite_match'])],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $hasFolder = collect($this->input('identifiers', []))
                ->contains(fn ($identifier) => ($identifier['system'] ?? null) === 'folder');

            // A folder number is required, but a missing one is a fact of
            // clinic life — so it may be absent with a coded reason, and never
            // silently absent.
            if (! $hasFolder && ! $this->input('folder_absent_reason')) {
                $validator->errors()->add(
                    'folder_absent_reason',
                    'Give a hospital folder number, or say why there is none.',
                );
            }

            foreach ($this->input('identifiers', []) as $index => $identifier) {
                $system = config("identifiers.systems.{$identifier['system']}");

                if (($system['requires_assigner'] ?? false) && empty($identifier['assigner'])) {
                    $validator->errors()->add(
                        "identifiers.{$index}.assigner",
                        'This identifier needs to say which facility issued it.',
                    );
                }
            }
        });
    }
}
