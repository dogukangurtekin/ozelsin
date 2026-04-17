@extends('layout.app')
@section('title','Bildirimler')
@section('content')
<div class="teacher-v2 teacher-v2-compact">
    <div class="teacher-v2-layout">
        <div class="teacher-v2-main">
            <section class="v2-hero card soft-surface soft-surface-blue">
                <div>
                    <h1>Bildirimler</h1>
                    <p>Web Push, tercih ve log yonetimi.</p>
                </div>
            </section>

            <section class="card soft-surface soft-surface-mint">
                <h2>Bildirim Gonder</h2>
                <form id="adminSendForm" class="parent-wa-form">
                    @csrf
                    <div class="parent-wa-row">
                        <label>Tip</label>
                        <select id="notifType" class="form-control" required>
                            @foreach($types as $type => $label)
                                <option value="{{ $type }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="parent-wa-row">
                        <label>Hedef</label>
                        <select id="notifTarget" class="form-control" required>
                            <option value="all">Tum Kullanicilar</option>
                            <option value="students">Sadece Ogrenciler</option>
                            <option value="teachers">Sadece Ogretmenler</option>
                        </select>
                    </div>
                    <div class="parent-wa-row">
                        <label>Baslik</label>
                        <input id="notifTitle" class="form-control" maxlength="190" required>
                    </div>
                    <div class="parent-wa-row">
                        <label>Mesaj</label>
                        <textarea id="notifBody" class="form-control" rows="4" maxlength="4000" required></textarea>
                    </div>
                    <div class="parent-wa-row">
                        <label>Yonlendirme URL (opsiyonel)</label>
                        <input id="notifUrl" class="form-control" placeholder="{{ url('/dashboard') }}">
                    </div>
                    <div class="parent-wa-actions">
                        <button id="notifSendBtn" class="btn" type="submit">Gonder</button>
                    </div>
                </form>
                <div id="notifSendStatus" class="pdf-status">Hazir</div>
            </section>

            <section class="card soft-surface soft-surface-lilac">
                <h2>Kullanici Tercihleri (Benim)</h2>
                <form id="notifPrefForm" class="parent-wa-form">
                    @csrf
                    @foreach($preferences as $pref)
                        <label class="parent-wa-checkbox">
                            <input type="checkbox" data-type="{{ $pref['type'] }}" {{ $pref['enabled'] ? 'checked' : '' }}>
                            {{ $pref['label'] }}
                        </label>
                    @endforeach
                    <div class="parent-wa-actions">
                        <button class="btn" type="submit">Tercihleri Kaydet</button>
                    </div>
                </form>
                <div id="notifPrefStatus" class="pdf-status">Hazir</div>
            </section>

            <section class="card soft-surface soft-surface-peach">
                <h2>Son Gonderim Loglari</h2>
                <div class="notification-recent-list">
                    @forelse($recentLogs as $log)
                        <article class="notification-recent-item" data-log-id="{{ $log->id }}">
                            <header>
                                <strong>#{{ $log->id }} - {{ $log->title }}</strong>
                                <span>{{ strtoupper($log->status) }}</span>
                            </header>
                            <p>{{ $log->body }}</p>
                            <p><small>Tip: {{ $log->type }} | Hedef: {{ $log->user?->name ?? 'N/A' }} | Teslim: {{ $log->delivered_count }} | Hata: {{ $log->failed_count }}</small></p>
                            <div class="actions" style="margin-top:6px;">
                                <button type="button" class="btn btn-secondary js-resend" data-id="{{ $log->id }}">Tekrar Gonder</button>
                                <button type="button" class="btn btn-danger js-delete-log" data-id="{{ $log->id }}">Sil</button>
                            </div>
                        </article>
                    @empty
                        <p>Henuz log yok.</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const sendForm = document.getElementById('adminSendForm');
    const sendStatus = document.getElementById('notifSendStatus');
    const prefForm = document.getElementById('notifPrefForm');
    const prefStatus = document.getElementById('notifPrefStatus');

    const setStatus = (el, text, ok = true) => {
        if (!el) return;
        el.classList.add('show');
        el.textContent = text;
        el.style.background = ok ? '#ecfdf5' : '#fef2f2';
        el.style.color = ok ? '#065f46' : '#991b1b';
        el.style.borderColor = ok ? '#10b981' : '#ef4444';
    };

    sendForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const payload = {
            type: document.getElementById('notifType')?.value || 'system_message',
            target: document.getElementById('notifTarget')?.value || 'all',
            title: document.getElementById('notifTitle')?.value?.trim() || '',
            body: document.getElementById('notifBody')?.value?.trim() || '',
            url: document.getElementById('notifUrl')?.value?.trim() || '',
        };
        if (!payload.title || !payload.body) {
            setStatus(sendStatus, 'Baslik ve mesaj zorunlu.', false);
            return;
        }
        try {
            const res = await fetch('{{ route('notifications.send') }}', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok) throw new Error();
            setStatus(sendStatus, `Gonderildi. Sent:${data.result?.sent ?? 0} Failed:${data.result?.failed ?? 0} NoTarget:${data.result?.no_target ?? 0}`, true);
            window.setTimeout(() => window.location.reload(), 700);
        } catch (_) {
            setStatus(sendStatus, 'Gonderim hatasi.', false);
        }
    });

    prefForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const preferences = {};
        prefForm.querySelectorAll('input[type="checkbox"][data-type]').forEach((el) => {
            preferences[el.getAttribute('data-type')] = el.checked;
        });
        try {
            const res = await fetch('{{ route('notifications.preferences.update') }}', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ preferences }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok) throw new Error();
            setStatus(prefStatus, 'Tercihler kaydedildi.', true);
        } catch (_) {
            setStatus(prefStatus, 'Tercih kaydi basarisiz.', false);
        }
    });

    document.querySelectorAll('.js-resend').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const id = btn.getAttribute('data-id');
            if (!id) return;
            try {
                const res = await fetch(`{{ url('/app-notifications') }}/${id}/resend`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json',
                    },
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.ok) throw new Error();
                window.location.reload();
            } catch (_) {}
        });
    });

    document.querySelectorAll('.js-delete-log').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const id = btn.getAttribute('data-id');
            if (!id) return;
            if (!window.confirm('Log silinsin mi?')) return;
            try {
                const res = await fetch(`{{ url('/app-notifications') }}/${id}`, {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json',
                    },
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.ok) throw new Error();
                window.location.reload();
            } catch (_) {}
        });
    });
})();
</script>
@endpush

