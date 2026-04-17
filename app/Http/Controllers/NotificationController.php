<?php

namespace App\Http\Controllers;

use App\Models\NotificationLog;
use App\Models\NotificationLogRead;
use App\Models\NotificationPreference;
use App\Models\PushDeviceStatus;
use App\Models\PushSubscription;
use App\Models\Student;
use App\Models\User;
use App\Services\PushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private PushNotificationService $pushService)
    {
    }

    public function index()
    {
        abort_unless(auth()->user()?->hasRole('admin', 'teacher'), 403);

        $types = (array) config('notification-preferences.types', []);
        $prefs = NotificationPreference::query()
            ->where('user_id', auth()->id())
            ->whereIn('type', array_keys($types))
            ->pluck('enabled', 'type')
            ->all();

        $preferences = [];
        foreach ($types as $key => $label) {
            $preferences[] = [
                'type' => $key,
                'label' => $label,
                'enabled' => array_key_exists($key, $prefs) ? (bool) $prefs[$key] : true,
            ];
        }

        $recentLogs = NotificationLog::query()
            ->with('user:id,name')
            ->latest('id')
            ->limit(30)
            ->get();

        return view('notifications.index', [
            'preferences' => $preferences,
            'recentLogs' => $recentLogs,
            'types' => $types,
        ]);
    }

    public function publicKey(): JsonResponse
    {
        return response()->json([
            'public_key' => (string) config('webpush.vapid.public_key', ''),
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:2000'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:1500'],
            'keys.auth' => ['required', 'string', 'max:500'],
            'encoding' => ['nullable', 'string', 'max:32'],
        ]);

        PushSubscription::query()->updateOrCreate(
            ['endpoint' => (string) $data['endpoint']],
            [
                'user_id' => auth()->id(),
                'content_encoding' => (string) ($data['encoding'] ?? 'aes128gcm'),
                'public_key' => (string) $data['keys']['p256dh'],
                'auth_token' => (string) $data['keys']['auth'],
                'user_agent' => substr((string) $request->userAgent(), 0, 2000),
                'last_seen_at' => now(),
            ]
        );

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:2000'],
        ]);

        PushSubscription::query()
            ->where('endpoint', (string) $data['endpoint'])
            ->delete();

        return response()->json(['ok' => true]);
    }

    public function syncDeviceStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['nullable', 'url', 'max:2000'],
            'permission' => ['required', 'in:default,granted,denied'],
            'platform' => ['nullable', 'string', 'max:80'],
            'is_pwa' => ['nullable', 'boolean'],
        ]);

        $endpoint = (string) ($data['endpoint'] ?? '');
        if ($endpoint !== '') {
            PushDeviceStatus::query()->updateOrCreate(
                ['endpoint' => $endpoint],
                [
                    'user_id' => auth()->id(),
                    'permission' => (string) $data['permission'],
                    'platform' => (string) ($data['platform'] ?? ''),
                    'is_pwa' => (bool) ($data['is_pwa'] ?? false),
                    'user_agent' => substr((string) $request->userAgent(), 0, 2000),
                    'last_seen_at' => now(),
                ]
            );
        } else {
            PushDeviceStatus::query()->create([
                'user_id' => auth()->id(),
                'permission' => (string) $data['permission'],
                'platform' => (string) ($data['platform'] ?? ''),
                'is_pwa' => (bool) ($data['is_pwa'] ?? false),
                'user_agent' => substr((string) $request->userAgent(), 0, 2000),
                'last_seen_at' => now(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $types = array_keys((array) config('notification-preferences.types', []));
        $data = $request->validate([
            'preferences' => ['required', 'array'],
        ]);

        foreach ($data['preferences'] as $type => $enabled) {
            if (!in_array($type, $types, true)) {
                continue;
            }
            NotificationPreference::query()->updateOrCreate(
                ['user_id' => auth()->id(), 'type' => (string) $type],
                ['enabled' => (bool) $enabled]
            );
        }

        return response()->json(['ok' => true]);
    }

    public function markRead(NotificationLog $log): JsonResponse
    {
        NotificationLogRead::query()->updateOrCreate(
            ['notification_log_id' => $log->id, 'user_id' => auth()->id()],
            ['read_at' => now()]
        );

        return response()->json(['ok' => true]);
    }

    public function sendMessage(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasRole('admin', 'teacher'), 403);

        $data = $request->validate([
            'type' => ['required', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:4000'],
            'url' => ['nullable', 'string', 'max:500'],
            'target' => ['required', 'in:all,students,teachers'],
        ]);

        $target = (string) $data['target'];
        if ($target === 'all') {
            $result = $this->pushService->sendToAll((string) $data['type'], (string) $data['title'], (string) $data['body'], $data['url'] ?? null, [
                'trigger' => 'admin_send',
                'by' => auth()->id(),
            ]);
        } else {
            $userIds = $target === 'students'
                ? Student::query()->whereNotNull('user_id')->pluck('user_id')->map(fn ($x) => (int) $x)->all()
                : User::query()->whereHas('role', fn ($q) => $q->whereIn('slug', ['teacher', 'admin']))->pluck('id')->map(fn ($x) => (int) $x)->all();
            $result = $this->pushService->sendToUsers($userIds, (string) $data['type'], (string) $data['title'], (string) $data['body'], $data['url'] ?? null, [
                'trigger' => 'admin_send',
                'by' => auth()->id(),
            ]);
        }

        return response()->json(['ok' => true, 'result' => $result]);
    }

    public function resend(NotificationLog $log): JsonResponse
    {
        abort_unless(auth()->user()?->hasRole('admin', 'teacher'), 403);
        if (!$log->user_id) {
            return response()->json(['ok' => false, 'message' => 'Kullanici hedefi yok.'], 422);
        }
        $result = $this->pushService->sendToUsers([(int) $log->user_id], (string) $log->type, (string) $log->title, (string) $log->body, $log->url, [
            'trigger' => 'resend',
            'source_log_id' => $log->id,
            'by' => auth()->id(),
        ]);
        return response()->json(['ok' => true, 'result' => $result]);
    }

    public function destroyLog(NotificationLog $log): JsonResponse
    {
        abort_unless(auth()->user()?->hasRole('admin', 'teacher'), 403);
        $log->delete();
        return response()->json(['ok' => true]);
    }
}

