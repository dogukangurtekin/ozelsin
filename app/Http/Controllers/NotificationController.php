<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class NotificationController extends Controller
{
    public function index()
    {
        $recentAnnouncements = $this->announcementQueryForUser(auth()->user())
            ->latest('id')
            ->limit(20)
            ->get(['id', 'title', 'content', 'audience', 'published_at'])
            ->reverse()
            ->values();

        return view('notifications.index', [
            'recentAnnouncements' => $recentAnnouncements,
        ]);
    }

    public function storeMessage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'content' => ['required', 'string', 'max:4000'],
            'audience' => ['required', 'in:all,students,teachers'],
        ]);

        $announcement = Announcement::query()->create([
            'title' => trim((string) $data['title']),
            'content' => trim((string) $data['content']),
            'audience' => (string) $data['audience'],
            'published_by' => (int) auth()->id(),
            'published_at' => now(),
        ]);

        $this->sendWebPushNotifications($announcement);

        return response()->json([
            'ok' => true,
            'id' => $announcement->id,
            'message' => 'Bildirim gonderildi.',
        ]);
    }

    public function pushSubscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:2000'],
            'expirationTime' => ['nullable'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:1500'],
            'keys.auth' => ['required', 'string', 'max:500'],
        ]);

        PushSubscription::query()->updateOrCreate(
            ['endpoint' => (string) $data['endpoint']],
            [
                'user_id' => auth()->id(),
                'content_encoding' => 'aes128gcm',
                'public_key' => (string) $data['keys']['p256dh'],
                'auth_token' => (string) $data['keys']['auth'],
                'user_agent' => substr((string) $request->userAgent(), 0, 2000),
                'last_seen_at' => now(),
            ]
        );

        return response()->json(['ok' => true]);
    }

    public function pushUnsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:2000'],
        ]);

        PushSubscription::query()
            ->where('endpoint', (string) $data['endpoint'])
            ->delete();

        return response()->json(['ok' => true]);
    }

    public function feed(Request $request): JsonResponse
    {
        $query = $this->announcementQueryForUser(auth()->user());

        if ($request->boolean('latest_id_only')) {
            $latestId = (int) ($query->max('id') ?? 0);
            return response()->json(['latest_id' => $latestId]);
        }

        $afterId = (int) $request->integer('after_id', 0);
        $items = $query
            ->when($afterId > 0, fn ($q) => $q->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'title', 'content', 'audience', 'published_at'])
            ->map(fn (Announcement $item) => [
                'id' => $item->id,
                'title' => (string) $item->title,
                'content' => (string) $item->content,
                'audience' => (string) $item->audience,
                'published_at' => optional($item->published_at)->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'items' => $items,
            'latest_id' => (int) ($items->last()['id'] ?? $afterId),
        ]);
    }

    private function announcementQueryForUser($user)
    {
        $audiences = ['all'];
        if ($user?->hasRole('student')) {
            $audiences[] = 'students';
        } else {
            $audiences[] = 'teachers';
        }

        return Announcement::query()
            ->whereNotNull('published_at')
            ->whereIn('audience', $audiences);
    }

    private function sendWebPushNotifications(Announcement $announcement): void
    {
        $vapidPublic = (string) config('services.webpush.public_key', '');
        $vapidPrivate = (string) config('services.webpush.private_key', '');
        $vapidSubject = (string) config('services.webpush.subject', '');

        if ($vapidPublic === '' || $vapidPrivate === '' || $vapidSubject === '') {
            return;
        }

        $subsQuery = PushSubscription::query();
        if ($announcement->audience === 'students') {
            $subsQuery->whereHas('user.role', fn ($q) => $q->where('slug', 'student'));
        } elseif ($announcement->audience === 'teachers') {
            $subsQuery->whereHas('user.role', fn ($q) => $q->whereIn('slug', ['teacher', 'admin']));
        }

        $subscriptions = $subsQuery->get(['id', 'endpoint', 'public_key', 'auth_token', 'content_encoding']);
        if ($subscriptions->isEmpty()) {
            return;
        }

        $auth = [
            'VAPID' => [
                'subject' => $vapidSubject,
                'publicKey' => $vapidPublic,
                'privateKey' => $vapidPrivate,
            ],
        ];
        $webPush = new WebPush($auth);
        $webPush->setReuseVAPIDHeaders(true);

        $payload = json_encode([
            'title' => (string) $announcement->title,
            'body' => (string) $announcement->content,
            'id' => (int) $announcement->id,
            'audience' => (string) $announcement->audience,
            'url' => (string) url('/bildirimler'),
        ], JSON_UNESCAPED_UNICODE);

        foreach ($subscriptions as $sub) {
            $subscription = Subscription::create([
                'endpoint' => (string) $sub->endpoint,
                'publicKey' => (string) $sub->public_key,
                'authToken' => (string) $sub->auth_token,
                'contentEncoding' => (string) ($sub->content_encoding ?: 'aes128gcm'),
            ]);
            $webPush->queueNotification($subscription, $payload);
        }

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                continue;
            }
            $endpoint = (string) $report->getRequest()->getUri();
            $reason = strtolower((string) $report->getReason());
            if (str_contains($reason, '410') || str_contains($reason, '404') || str_contains($reason, 'expired')) {
                PushSubscription::query()->where('endpoint', $endpoint)->delete();
            }
        }
    }
}
