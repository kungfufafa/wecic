<?php

declare(strict_types=1);

namespace App\Http\Requests;

class PortalPasswordChangeRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /**
             * Password portal kampus saat ini.
             */
            'current_password' => ['required', 'string', 'max:255'],
            /**
             * Password portal kampus baru.
             */
            'new_password' => ['required', 'string', 'min:6', 'max:255', 'confirmed', 'different:current_password'],
            /**
             * Konfirmasi password portal kampus baru.
             */
            'new_password_confirmation' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Password lama wajib diisi.',
            'new_password.required' => 'Password baru wajib diisi.',
            'new_password.min' => 'Password baru minimal 6 karakter.',
            'new_password.confirmed' => 'Konfirmasi password baru tidak sesuai.',
            'new_password.different' => 'Password baru harus berbeda dari password lama.',
            'new_password_confirmation.required' => 'Konfirmasi password baru wajib diisi.',
        ];
    }

    public function currentPassword(): string
    {
        return (string) $this->validated('current_password');
    }

    public function newPassword(): string
    {
        return (string) $this->validated('new_password');
    }

    public function newPasswordConfirmation(): string
    {
        return (string) $this->validated('new_password_confirmation');
    }

    protected function prepareForValidation(): void
    {
        $data = $this->input('data', []);
        $data = is_array($data) ? $data : [];

        $this->merge([
            'current_password' => $this->input(
                'current_password',
                $this->input('old_password', $this->input('passwordold', $data['passwordold'] ?? null))
            ),
            'new_password' => $this->input(
                'new_password',
                $this->input('password', $data['password'] ?? null)
            ),
            'new_password_confirmation' => $this->input(
                'new_password_confirmation',
                $this->input('password_confirmation', $this->input('konfirm', $data['konfirm'] ?? null))
            ),
        ]);
    }
}
