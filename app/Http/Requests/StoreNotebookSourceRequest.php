<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\NotebookSource;
use App\Services\NotebookSourceReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A source to add: archived course files, an uploaded file, or pasted text.
 * An upload the model that reads files cannot take (a scanned PDF or an
 * image, for a model without PDF or image input) is refused here, before
 * it is stored.
 */
class StoreNotebookSourceRequest extends ApiFormRequest
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
        $maxUploadKb = (int) config('notebook.limits.max_upload_kb', 20480);
        $maxTextChars = (int) config('notebook.limits.max_text_chars', 200000);

        return [
            /**
             * Jenis sumber.
             *
             * @example "archived_file"
             */
            'type' => ['required', Rule::in([NotebookSource::KIND_ARCHIVED_FILE, NotebookSource::KIND_UPLOAD, NotebookSource::KIND_TEXT])],
            /**
             * Id berkas arsip (`/api/archive/files`), untuk `type=archived_file`.
             *
             * @example [7, 8]
             */
            'archived_file_ids' => ['required_if:type,'.NotebookSource::KIND_ARCHIVED_FILE, 'array', 'min:1', 'max:30'],
            'archived_file_ids.*' => ['integer', 'min:1'],
            /**
             * Berkas PDF, DOCX, PPTX, TXT, MD, PNG, atau JPG, untuk `type=upload`.
             */
            'file' => ['required_if:type,'.NotebookSource::KIND_UPLOAD, 'file', 'max:'.$maxUploadKb, 'mimes:'.implode(',', (array) config('notebook.uploads.mimes', []))],
            /**
             * Judul catatan, untuk `type=text`.
             *
             * @example "Catatan kuliah minggu 3"
             */
            'title' => ['required_if:type,'.NotebookSource::KIND_TEXT, 'nullable', 'string', 'max:120'],
            /**
             * Isi catatan, untuk `type=text`.
             */
            'content' => ['required_if:type,'.NotebookSource::KIND_TEXT, 'nullable', 'string', 'max:'.$maxTextChars],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'Pilih jenis sumber.',
            'type.in' => 'Jenis sumber tidak dikenal.',
            'archived_file_ids.required_if' => 'Pilih minimal satu berkas materi.',
            'archived_file_ids.array' => 'Daftar berkas tidak valid.',
            'archived_file_ids.min' => 'Pilih minimal satu berkas materi.',
            'archived_file_ids.max' => 'Maksimal 30 berkas sekaligus.',
            'archived_file_ids.*.integer' => 'Id berkas harus berupa angka.',
            'archived_file_ids.*.min' => 'Id berkas tidak valid.',
            'file.required_if' => 'Pilih berkas yang ingin diunggah.',
            'file.file' => 'Berkas gagal diunggah.',
            'file.max' => 'Ukuran berkas maksimal '.(int) round((int) config('notebook.limits.max_upload_kb', 20480) / 1024).' MB.',
            'file.mimes' => 'Format berkas harus PDF, DOCX, PPTX, TXT, MD, PNG, atau JPG.',
            'title.required_if' => 'Beri judul catatan.',
            'title.max' => 'Judul catatan maksimal 120 karakter.',
            'content.required_if' => 'Isi catatan masih kosong.',
            'content.max' => 'Catatan terlalu panjang.',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(NotebookSourceReader $reader): array
    {
        return [
            function (Validator $validator) use ($reader): void {
                $file = $this->file('file');

                if ($validator->errors()->isNotEmpty() || $this->input('type') !== NotebookSource::KIND_UPLOAD || ! $file instanceof UploadedFile) {
                    return;
                }

                $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
                $filename = $file->getClientOriginalName();

                if (! $reader->needsModel($mime, $filename)) {
                    return;
                }

                $unreadable = $reader->unreadable((string) $file->get(), $mime, $filename);

                if ($unreadable !== null) {
                    $validator->errors()->add('file', $unreadable);
                }
            },
        ];
    }

    /**
     * @return list<int>
     */
    public function archivedFileIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->validated('archived_file_ids', []))));
    }
}
