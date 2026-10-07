<?php

declare(strict_types=1);

namespace App\Http\Requests;

class ChatNotebookRequest extends ApiFormRequest
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
            'prompt' => ['required', 'string'],
            'action_type' => ['nullable', 'string', 'in:chat,deep_dive,task_solve,task_outline,summary,quiz,task_review'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'active_sources' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'prompt.required' => 'Pertanyaan atau instruksi untuk AI wajib diisi.',
            'action_type.in' => 'Tipe aksi studio AI tidak valid.',
        ];
    }
}
