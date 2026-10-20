<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\PortalUrlGuard;
use Closure;

class PortalFileDownloadRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => [
                'required',
                'string',
                'max:2048',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && PortalUrlGuard::campusFileUrl($value) === null) {
                        $fail('Tautan unduhan tidak valid. Hanya file materi, soal, tugas, dan dokumen kampus yang bisa diunduh.');
                    }
                },
            ],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'path.required' => 'Parameter path wajib diisi.',
            'path.string' => 'Parameter path harus berupa string.',
            'name.string' => 'Parameter name harus berupa string.',
        ];
    }

    public function campusPath(): string
    {
        return (string) $this->validated('path');
    }

    public function downloadName(): ?string
    {
        $name = $this->validated('name');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'path' => $this->input('path', $this->input('url')),
            'name' => $this->input('name', $this->input('filename')),
        ]);
    }
}
