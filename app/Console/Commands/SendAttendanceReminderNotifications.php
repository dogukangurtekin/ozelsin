<?php

namespace App\Console\Commands;

use App\Models\NotificationLog;
use App\Models\User;
use App\Services\PushNotificationService;
use Illuminate\Console\Command;

class SendAttendanceReminderNotifications extends Command
{
    protected $signature = 'notifications:attendance-reminders';
    protected $description = 'Ogretmenlere gunluk yoklama hatirlatma push bildirimi gonderir.';

    public function __construct(private PushNotificationService $pushService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $teachers = User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('slug', ['teacher', 'admin']))
            ->pluck('id')
            ->map(fn ($x) => (int) $x)
            ->all();

        if ($teachers === []) {
            $this->info('Hedef ogretmen bulunamadi.');
            return self::SUCCESS;
        }

        $alreadySent = NotificationLog::query()
            ->where('type', 'attendance_reminder')
            ->whereDate('created_at', today())
            ->whereIn('user_id', $teachers)
            ->pluck('user_id')
            ->map(fn ($x) => (int) $x)
            ->all();

        $targets = array_values(array_diff($teachers, $alreadySent));
        if ($targets === []) {
            $this->info('Bugun icin hatirlatma zaten gonderildi.');
            return self::SUCCESS;
        }

        $result = $this->pushService->sendToUsers(
            $targets,
            'attendance_reminder',
            'Yoklama Hatirlatma',
            'Bugunku yoklama kayitlarini tamamlamayi unutmayin.',
            url('/ogrenci-verileri'),
            ['trigger' => 'cron_attendance_reminder']
        );

        $this->info('Hatirlatma sonucu: '.json_encode($result));
        return self::SUCCESS;
    }
}

