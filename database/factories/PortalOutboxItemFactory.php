<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PortalOutboxItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PortalOutboxItem>
 */
class PortalOutboxItemFactory extends Factory
{
    protected $model = PortalOutboxItem::class;

    /**
     * Define the model's default state: an assignment upload waiting for the portal.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_hash' => 'student_'.$this->faker->regexify('[a-f0-9]{16}'),
            'action' => PortalOutboxItem::ACTION_ASSIGNMENT_UPLOAD,
            'payload' => [
                'courses_id' => 9000,
                'id' => 29833,
                'pertemuan' => 2,
                'meeting_id' => 29833,
                'form_values' => [],
                'file_name' => 'tugas.pdf',
                'mime_type' => 'application/pdf',
            ],
            'file_path' => null,
            'subject' => 'Pemrograman Web · Tugas pertemuan 2',
            'deadline_at' => null,
            'status' => PortalOutboxItem::STATUS_QUEUED,
            'attempts' => 0,
            'last_error' => null,
            'sent_at' => null,
        ];
    }

    public function forOwner(string $ownerHash): static
    {
        return $this->state(fn (): array => ['owner_hash' => $ownerHash]);
    }

    /**
     * Stores a small PDF on the default disk, as the controller does.
     */
    public function withFile(string $name = 'tugas.pdf', string $content = '%PDF-1.4 outbox'): static
    {
        return $this->state(function (array $attributes) use ($name, $content): array {
            $path = 'portal-outbox/'.$this->faker->uuid().'.pdf';
            Storage::put($path, $content);

            return [
                'file_path' => $path,
                'payload' => [...($attributes['payload'] ?? []), 'file_name' => $name],
            ];
        });
    }

    public function attendance(int $courseId = 8837, int $moduleId = 29808, ?int $meetingNumber = 2, string $code = '35533429'): static
    {
        return $this->state(fn (): array => [
            'action' => PortalOutboxItem::ACTION_ATTENDANCE,
            'payload' => [
                'courseid' => $courseId,
                'modulesid' => $moduleId,
                'pertemuan' => $meetingNumber,
                'kode' => $code,
                'form_values' => [],
            ],
            'subject' => 'Basis Data · Pertemuan '.($meetingNumber ?? '-'),
            'deadline_at' => now()->endOfDay(),
        ]);
    }

    public function examUpload(int $examId = 8833): static
    {
        return $this->state(fn (): array => [
            'action' => PortalOutboxItem::ACTION_EXAM_UPLOAD,
            'payload' => [
                'id' => $examId,
                'form_values' => [],
                'file_name' => 'uts.zip',
                'mime_type' => 'application/zip',
            ],
            'subject' => 'Ujian Basis Data',
        ]);
    }

    public function dueAt(\DateTimeInterface $deadline): static
    {
        return $this->state(fn (): array => ['deadline_at' => $deadline]);
    }

    /**
     * Queued by an offline session opened with this password (its offline-login hash).
     */
    public function queuedWithPassword(string $password): static
    {
        return $this->state(fn (): array => ['credential_hash' => Hash::make($password)]);
    }

    /**
     * The portal accepted the password it was queued with: it may be sent.
     */
    public function confirmed(): static
    {
        return $this->state(fn (): array => ['confirmed_at' => now()]);
    }

    /**
     * Left mid-way by a flush that died.
     */
    public function sending(): static
    {
        return $this->state(fn (): array => ['status' => PortalOutboxItem::STATUS_SENDING, 'confirmed_at' => now()]);
    }

    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => PortalOutboxItem::STATUS_SENT,
            'attempts' => 1,
            'sent_at' => now(),
        ]);
    }

    public function failed(string $error = 'Portal menolak kiriman.'): static
    {
        return $this->state(fn (): array => [
            'status' => PortalOutboxItem::STATUS_FAILED,
            'attempts' => 1,
            'last_error' => $error,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => PortalOutboxItem::STATUS_EXPIRED,
            'deadline_at' => now()->subHour(),
        ]);
    }
}
