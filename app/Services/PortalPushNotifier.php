<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PortalNotification;
use App\Models\PortalPushToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends notifications to the student's app installs through the Expo push
 * service (plain HTTP, `services.expo`), which hands them to APNs and FCM.
 *
 * Expo answers every message with a ticket in the order sent. A ticket
 * saying `DeviceNotRegistered` means the app was uninstalled (or its push
 * permission revoked): that token is removed so it is never sent to again.
 * A request Expo refuses as a whole (4xx) is logged and dropped, since
 * sending it again would be refused the same way; a server error, a rate
 * limit or no answer at all is thrown, for the job to try again.
 */
class PortalPushNotifier
{
    /**
     * Messages Expo accepts in one request.
     */
    private const BATCH_SIZE = 100;

    /**
     * @param  list<PortalNotification>  $notifications
     * @return int Messages Expo accepted.
     *
     * @throws ConnectionException|RequestException when Expo could not take them now
     */
    public function send(string $ownerHash, array $notifications): int
    {
        if ($notifications === []) {
            return 0;
        }

        $tokens = PortalPushToken::query()->forOwner($ownerHash)->pluck('token')->all();

        if ($tokens === []) {
            return 0;
        }

        $badge = PortalNotification::query()->forOwner($ownerHash)->unread()->count();
        $messages = [];

        foreach ($notifications as $notification) {
            foreach ($tokens as $token) {
                $messages[] = $this->message($token, $notification, $badge);
            }
        }

        $accepted = 0;

        foreach (array_chunk($messages, self::BATCH_SIZE) as $batch) {
            $accepted += $this->sendBatch($batch);
        }

        return $accepted;
    }

    /**
     * @param  list<array{to: string}>  $batch
     */
    private function sendBatch(array $batch): int
    {
        $request = Http::acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout((int) config('services.expo.timeout', 15));

        $accessToken = config('services.expo.access_token');

        if (is_string($accessToken) && $accessToken !== '') {
            $request = $request->withToken($accessToken);
        }

        $response = $request->post((string) config('services.expo.push_url'), $batch);

        if ($response->serverError() || $response->status() === 429) {
            $response->throw();
        }

        if ($response->failed()) {
            Log::warning('Expo refused a push request', [
                'status' => $response->status(),
                'errors' => $response->json('errors'),
            ]);

            return 0;
        }

        $tickets = $response->json('data');

        return is_array($tickets) ? $this->readTickets($batch, $tickets) : 0;
    }

    /**
     * @param  list<array{to: string}>  $batch
     * @param  array<int, mixed>  $tickets
     */
    private function readTickets(array $batch, array $tickets): int
    {
        $accepted = 0;
        $unregistered = [];

        foreach ($batch as $index => $message) {
            $ticket = $tickets[$index] ?? null;

            if (! is_array($ticket)) {
                continue;
            }

            if (($ticket['status'] ?? null) === 'ok') {
                $accepted++;

                continue;
            }

            $error = $ticket['details']['error'] ?? null;

            if ($error === 'DeviceNotRegistered') {
                $unregistered[] = $message['to'];

                continue;
            }

            Log::warning('Expo refused a push message', [
                'error' => is_string($error) ? $error : null,
                'message' => is_string($ticket['message'] ?? null) ? $ticket['message'] : null,
            ]);
        }

        if ($unregistered !== []) {
            PortalPushToken::query()->whereIn('token', array_values(array_unique($unregistered)))->delete();
        }

        return $accepted;
    }

    /**
     * The app opens `data.url` (a MyCIC path, like the notification's link
     * on the web) when the student taps the message.
     *
     * @return array<string, mixed>
     */
    private function message(string $token, PortalNotification $notification, int $badge): array
    {
        return array_filter([
            'to' => $token,
            'title' => $notification->title,
            'body' => $notification->body,
            'data' => [
                'notification_id' => $notification->id,
                'type' => $notification->type,
                'dataset' => $notification->dataset,
                'url' => $notification->url,
                'urgency' => $notification->urgency,
            ],
            'sound' => 'default',
            'badge' => $badge,
            'priority' => $notification->urgency === 'critical' ? 'high' : 'default',
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
