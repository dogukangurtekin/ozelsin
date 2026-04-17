<?php

namespace App\Services;

use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Models\User;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class PushNotificationService
{
    public function sendToAll(string $type, string $title, string $body, ?string $url = null, array $context = []): array
    {
        $userIds = User::query()->pluck('id')->all();
        return $this->sendToUsers($userIds, $type, $title, $body, $url, $context);
    }

    public function sendToUsers(array $userIds, string $type, string $title, string $body, ?string $url = null, array $context = []): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return ['total' => 0, 'sent' => 0, 'failed' => 0, 'no_target' => 0];
        }

        $userIds = $this->applyPreferenceFilter($userIds, $type);
        if ($userIds === []) {
            return ['total' => 0, 'sent' => 0, 'failed' => 0, 'no_target' => 0];
        }

        $logs = [];
        foreach ($userIds as $userId) {
            $logs[$userId] = NotificationLog::query()->create([
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'url' => $url,
                'status' => 'queued',
                'provider' => 'webpush',
                'context' => $context,
            ]);
        }

        $vapid = config('webpush.vapid');
        $publicKey = (string) ($vapid['public_key'] ?? '');
        $privateKey = (string) ($vapid['private_key'] ?? '');
        $subject = (string) ($vapid['subject'] ?? '');
        if ($publicKey === '' || $privateKey === '' || $subject === '') {
            NotificationLog::query()->whereIn('id', array_map(fn ($x) => $x->id, $logs))->update([
                'status' => 'failed',
                'error_message' => 'VAPID ayarlari eksik',
                'sent_at' => now(),
            ]);
            return ['total' => count($logs), 'sent' => 0, 'failed' => count($logs), 'no_target' => 0];
        }

        $subs = PushSubscription::query()
            ->whereIn('user_id', $userIds)
            ->get(['id', 'user_id', 'endpoint', 'public_key', 'auth_token', 'content_encoding']);

        $subsByUser = [];
        foreach ($subs as $sub) {
            $subsByUser[(int) $sub->user_id][] = $sub;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $subject,
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ]);
        $webPush->setReuseVAPIDHeaders(true);

        $endpointMap = [];
        foreach ($logs as $userId => $log) {
            $userSubs = $subsByUser[(int) $userId] ?? [];
            if ($userSubs === []) {
                $log->update([
                    'status' => 'no_target',
                    'failed_count' => 1,
                    'error_message' => 'Aktif push aboneligi yok',
                    'sent_at' => now(),
                ]);
                continue;
            }

            $payload = json_encode([
                'log_id' => $log->id,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'url' => $url ?: url('/bildirimler'),
                'icon' => asset('logo192.png'),
            ], JSON_UNESCAPED_UNICODE);

            foreach ($userSubs as $s) {
                $subscription = Subscription::create([
                    'endpoint' => (string) $s->endpoint,
                    'publicKey' => (string) $s->public_key,
                    'authToken' => (string) $s->auth_token,
                    'contentEncoding' => (string) ($s->content_encoding ?: 'aes128gcm'),
                ]);
                $webPush->queueNotification($subscription, $payload);
                $endpointMap[(string) $s->endpoint] = ['user_id' => (int) $userId, 'log_id' => (int) $log->id];
            }
        }

        $deliveredByLog = [];
        $failedByLog = [];
        foreach ($webPush->flush() as $report) {
            $endpoint = (string) $report->getRequest()->getUri();
            $map = $endpointMap[$endpoint] ?? null;
            if (!$map) {
                continue;
            }
            $logId = (int) $map['log_id'];
            if ($report->isSuccess()) {
                $deliveredByLog[$logId] = ($deliveredByLog[$logId] ?? 0) + 1;
            } else {
                $failedByLog[$logId] = ($failedByLog[$logId] ?? 0) + 1;
                $reason = strtolower((string) $report->getReason());
                if (str_contains($reason, '404') || str_contains($reason, '410') || str_contains($reason, 'expired')) {
                    PushSubscription::query()->where('endpoint', $endpoint)->delete();
                }
            }
        }

        $sent = 0;
        $failed = 0;
        $noTarget = 0;
        foreach ($logs as $log) {
            if ($log->status === 'no_target') {
                $noTarget++;
                continue;
            }

            $d = (int) ($deliveredByLog[$log->id] ?? 0);
            $f = (int) ($failedByLog[$log->id] ?? 0);
            $status = 'failed';
            if ($d > 0 && $f === 0) {
                $status = 'sent';
            } elseif ($d > 0 && $f > 0) {
                $status = 'partial';
            }
            $log->update([
                'status' => $status,
                'delivered_count' => $d,
                'failed_count' => $f,
                'sent_at' => now(),
            ]);

            if ($status === 'sent' || $status === 'partial') {
                $sent++;
            } else {
                $failed++;
            }
        }

        return [
            'total' => count($logs),
            'sent' => $sent,
            'failed' => $failed,
            'no_target' => $noTarget,
        ];
    }

    private function applyPreferenceFilter(array $userIds, string $type): array
    {
        $disabledUserIds = NotificationPreference::query()
            ->whereIn('user_id', $userIds)
            ->where('type', $type)
            ->where('enabled', false)
            ->pluck('user_id')
            ->map(fn ($x) => (int) $x)
            ->all();

        if ($disabledUserIds === []) {
            return $userIds;
        }

        return array_values(array_diff($userIds, $disabledUserIds));
    }
}

