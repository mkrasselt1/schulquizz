<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Zugriff erlauben') }} – {{ config('app.name', 'Schulquizz') }}</title>
    {{-- Bewusst ohne externe Fonts/CDNs (Datenschutz). --}}
    <style>
        :root { --bg:#eef2ff; --card:#fff; --text:#111827; --muted:#6b7280; --border:#e5e7eb; --primary:#4f46e5; --primary-text:#fff; --soft:#f9fafb; }
        @media (prefers-color-scheme: dark) {
            :root { --bg:#111827; --card:#1f2937; --text:#f3f4f6; --muted:#9ca3af; --border:#374151; --soft:#111827; }
        }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:16px;
               background:var(--bg); color:var(--text); font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .card { width:100%; max-width:440px; background:var(--card); border:1px solid var(--border); border-radius:12px; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,.08); }
        h1 { font-size:1.35rem; margin:.25rem 0 .5rem; text-align:center; }
        p { margin:.4rem 0; line-height:1.45; }
        .muted { color:var(--muted); font-size:.9rem; }
        .icon { font-size:2.5rem; text-align:center; }
        .box { background:var(--soft); border:1px solid var(--border); border-radius:8px; padding:12px; margin:14px 0; font-size:.92rem; }
        ul { margin:.3rem 0 0; padding-left:1.2rem; }
        li { margin:.2rem 0; }
        .actions { display:flex; gap:10px; margin-top:18px; }
        .actions form { flex:1; }
        button { width:100%; padding:10px 14px; border-radius:8px; font-size:1rem; cursor:pointer; border:1px solid var(--border); background:var(--card); color:var(--text); }
        button.primary { background:var(--primary); color:var(--primary-text); border-color:var(--primary); font-weight:600; }
        .break { word-break: break-all; }
    </style>
</head>
<body>
<main class="card">
    <div class="icon">🤖🔐</div>
    <h1>„{{ $client->name }}“ möchte auf Schulquizz zugreifen</h1>
    <p class="muted" style="text-align:center">Angemeldet als <strong>{{ $user->email }}</strong></p>

    <div class="box">
        <strong>Der KI-Assistent darf dann:</strong>
        <ul>
            <li>deine eigenen Quizze und Escape-Rooms ansehen</li>
            <li>Rätsel/Fragen anlegen, ändern und löschen</li>
            <li>Räume und Hotspots deiner Escape-Rooms bearbeiten</li>
        </ul>
        <p class="muted" style="margin-top:.6rem">
            Er sieht <strong>keine</strong> Schülerdaten und kann keine Live-Spiele steuern.
            Du kannst den Zugriff jederzeit in deinem Profil widerrufen.
        </p>
    </div>

    @php($redirectUri = request()->query('redirect_uri', $client->redirect_uris[0] ?? null))
    @if ($redirectUri)
        <p class="muted break">Danach geht es weiter zu: {{ $redirectUri }}</p>
    @endif

    <div class="actions">
        <form method="POST" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit">Ablehnen</button>
        </form>
        <form method="POST" action="{{ route('passport.authorizations.approve') }}">
            @csrf
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="primary">Erlauben</button>
        </form>
    </div>
</main>
</body>
</html>
