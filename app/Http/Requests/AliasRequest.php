<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AliasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        $editing = $this->route('alias');   // при правке свой же адрес не считается занятым
        $isCreate = $editing === null;

        return [
            'address' => [
                Rule::requiredIf($isCreate),
                'email:rfc',
                'max:255',
                Rule::unique('vmail.alias', 'address')->ignore($editing, 'address'),
                Rule::unique('vmail.mailbox', 'username'),
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'targets' => ['required', 'array', 'min:1'],
            'targets.*' => ['email:rfc', 'max:255'],
            'active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'address.unique' => 'Такой адрес уже есть — как псевдоним или как ящик.',
            'targets.required' => 'Укажите хотя бы один адрес доставки.',
            'targets.*.email' => 'Адрес доставки указан неверно.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'targets' => array_values(array_filter((array) $this->input('targets', []), fn ($v) => filled($v))),
        ]);
    }
}
