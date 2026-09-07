<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MailboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isCreate = $this->route('mailbox') === null;

        return [
            // Локальная часть адреса: то же, что разрешает сам почтовый сервер.
            'local_part' => [
                Rule::requiredIf($isCreate),
                'string',
                'max:64',
                'regex:/^[a-z0-9][a-z0-9._-]*$/i',
            ],
            'domain' => [Rule::requiredIf($isCreate), 'string', 'max:255', 'exists:vmail.domain,domain'],

            'password' => [$isCreate ? 'required' : 'nullable', 'string', 'min:8', 'max:255'],

            'name' => ['nullable', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:120'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'is_service' => ['boolean'],
            'quota' => ['required', 'integer', 'min:0', 'max:1048576'],
            'active' => ['boolean'],

            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'rank' => ['nullable', 'string', 'max:255'],
            'employeeid' => ['nullable', 'string', 'max:255'],
            'recovery_email' => ['nullable', 'email:rfc', 'max:255'],

            'forwardings' => ['array'],
            'forwardings.*' => ['email:rfc'],
            'keep_copy' => ['boolean'],

            'aliases' => ['array'],
            'aliases.*' => ['email:rfc'],

            'isadmin' => ['boolean'],
            'isglobaladmin' => ['boolean'],

            'services' => ['array'],
            'services.*' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'local_part.regex' => 'Допустимы латинские буквы, цифры, точка, дефис и подчёркивание; первый символ — буква или цифра.',
            'forwardings.*.email' => 'Адрес пересылки указан неверно.',
            'aliases.*.email' => 'Дополнительный адрес указан неверно.',
            'password.min' => 'Пароль короче 8 символов.',
            'recovery_email.email' => 'Контактный адрес указан неверно.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'forwardings' => array_values(array_filter(
                (array) $this->input('forwardings', []),
                fn ($value) => filled($value)
            )),
            'aliases' => array_values(array_filter(
                (array) $this->input('aliases', []),
                fn ($value) => filled($value)
            )),
        ]);
    }
}
