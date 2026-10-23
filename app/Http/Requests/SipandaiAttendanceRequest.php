<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonImmutable;

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
            'courseid' => ['sometimes', 'nullable', 'integer', 'min:1'],
            /**
             * Identifier `modulesid` atau discussion/module pada URL absensi SIPANDAI.
             *
             * @example 30728
             */
            'modulesid' => ['sometimes', 'nullable', 'integer', 'min:1'],
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
            /**
             * Konteks kelas untuk membiarkan backend menyelesaikan target absensi terbaru.
             *
             * @var array{class_id:int,dosen_id:int,course_id:int}
             */
            'shortcut_context' => ['sometimes', 'array'],
            /**
             * @ignoreParam
             */
            'shortcut_context.class_id' => ['required_with:shortcut_context', 'integer', 'min:1'],
            /**
             * @ignoreParam
             */
            'shortcut_context.dosen_id' => ['required_with:shortcut_context', 'integer', 'min:1'],
            /**
             * @ignoreParam
             */
            'shortcut_context.course_id' => ['required_with:shortcut_context', 'integer', 'min:1'],
            /**
             * Batas waktu absensi (ISO 8601) bila klien mengetahuinya. Saat sesi offline,
             * kode yang masih di antrean setelah batas ini tidak dikirim.
             *
             * @example "2026-04-14T10:00:00+07:00"
             */
            'deadline' => ['sometimes', 'nullable', 'date'],
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
            'shortcut_context.array' => 'Parameter shortcut_context harus berupa object.',
            'shortcut_context.class_id.required_with' => 'shortcut_context.class_id wajib diisi saat menggunakan konteks kelas.',
            'shortcut_context.dosen_id.required_with' => 'shortcut_context.dosen_id wajib diisi saat menggunakan konteks kelas.',
            'shortcut_context.course_id.required_with' => 'shortcut_context.course_id wajib diisi saat menggunakan konteks kelas.',
            'deadline.date' => 'Parameter deadline harus berupa tanggal yang valid.',
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
            'shortcut_context' => 'attendance shortcut context',
        ];
    }

    public function hasDirectAttendancePayload(): bool
    {
        return $this->filled('courseid') && $this->filled('modulesid');
    }

    public function hasShortcutContext(): bool
    {
        return is_array($this->input('shortcut_context'));
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

    public function deadline(): ?CarbonImmutable
    {
        $deadline = $this->validated('deadline');

        return is_string($deadline) && $deadline !== '' ? CarbonImmutable::parse($deadline, config('app.timezone')) : null;
    }

    /**
     * @return array{class_id:int,dosen_id:int,course_id:int}|null
     */
    public function shortcutContext(): ?array
    {
        $context = $this->validated('shortcut_context');

        if (! is_array($context)) {
            return null;
        }

        return [
            'class_id' => (int) $context['class_id'],
            'dosen_id' => (int) $context['dosen_id'],
            'course_id' => (int) $context['course_id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->hasDirectAttendancePayload() && ! $this->hasShortcutContext()) {
                $validator->errors()->add('courseid', 'Parameter courseid/modulesid atau shortcut_context wajib diisi.');
            }
        });
    }

    /**
     * A typed code reaches the portal in the same form as a scanned one:
     * trimmed, and a hex code in lower case (the QR scanner lowercases it).
     */
    private function normalizeAttendanceCode(mixed $code): mixed
    {
        if (! is_string($code)) {
            return $code;
        }

        $code = trim($code);

        return preg_match('/^[0-9a-f]+$/i', $code) === 1 ? strtolower($code) : $code;
    }

    protected function prepareForValidation(): void
    {
        $formValues = $this->input('form_values');
        $legacyFormValues = is_array($formValues) ? $formValues : [];

        $this->merge([
            'courseid' => $this->input('courseid', $this->input('course_id')),
            'modulesid' => $this->input('modulesid', $this->input('discussion_id', $this->input('id'))),
            'kode' => $this->normalizeAttendanceCode($this->input('kode', $legacyFormValues['kode'] ?? null)),
            'pertemuan' => $this->input('pertemuan', $this->input('meeting_number')),
            'form_values' => $legacyFormValues,
        ]);
    }
}
