<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * @mixin \Illuminate\Http\Request
 */
class SipandaiAttendanceRequest extends ApiFormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /**
             * Identifier `courseid` pada URL absensi SIPANDAI.
             *
             * @example 8833
             */
            'courseid' => ['required', 'integer', 'min:1'],
            /**
             * Identifier `modulesid` atau discussion/module pada URL absensi SIPANDAI.
             *
             * @example 30728
             */
            'modulesid' => ['required', 'integer', 'min:1'],
            /**
             * Kode QR atau unix untuk absensi perkuliahan.
             *
             * @example "35533429"
             */
            'kode' => ['required', 'string', 'min:1'],
            /**
             * Nomor pertemuan jika halaman absensi mengharuskannya.
             *
             * @example 8
             */
            'pertemuan' => ['sometimes', 'nullable', 'integer', 'min:1'],
            /**
             * Nilai tambahan yang akan digabungkan ke payload absensi portal.
             *
             * @var array<string, string>
             *
             * @example {"cmd":"Simpan"}
             */
            'form_values' => ['sometimes', 'array'],
            /**
             * @ignoreParam
             */
            'form_values.*' => ['nullable', 'string'],
        ];
    }

    /**
     * Custom messages for validation errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'courseid.required' => 'Parameter courseid wajib diisi.',
            'courseid.integer' => 'Parameter courseid harus berupa angka.',
            'modulesid.required' => 'Parameter modulesid wajib diisi.',
            'modulesid.integer' => 'Parameter modulesid harus berupa angka.',
            'kode.required' => 'Parameter kode (kode QR/unix) wajib diisi.',
            'kode.string' => 'Parameter kode harus berupa string.',
            'pertemuan.integer' => 'Parameter pertemuan harus berupa angka.',
            'form_values.array' => 'Parameter form_values harus berupa object/array.',
        ];
    }

    /**
     * Custom attribute mapping.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'courseid' => 'course identifier (courseid)',
            'modulesid' => 'discussion/module identifier (modulesid)',
            'kode' => 'QR/unix attendance code',
            'pertemuan' => 'meeting number',
            'form_values' => 'form override values',
        ];
    }

    public function courseId(): int
    {
        return (int) $this->validated('courseid');
    }

    public function discussionId(): int
    {
        return (int) $this->validated('modulesid');
    }

    public function meetingNumber(): ?int
    {
        $meetingNumber = $this->validated('pertemuan');

        return $meetingNumber === null ? null : (int) $meetingNumber;
    }

    /**
     * @return array<string, string>
     */
    public function formValues(): array
    {
        $values = $this->validated('form_values', []);

        if (! is_array($values)) {
            return [];
        }

        return array_filter(
            $values,
            static fn (mixed $value): bool => is_string($value) && $value !== ''
        );
    }

    protected function prepareForValidation(): void
    {
        $formValues = $this->input('form_values');
        $legacyFormValues = is_array($formValues) ? $formValues : [];

        $this->merge([
            'courseid' => $this->input('courseid', $this->input('course_id')),
            'modulesid' => $this->input('modulesid', $this->input('discussion_id', $this->input('id'))),
            'kode' => $this->input('kode', $legacyFormValues['kode'] ?? null),
            'pertemuan' => $this->input('pertemuan', $this->input('meeting_number')),
            'form_values' => $legacyFormValues,
        ]);
    }
}
