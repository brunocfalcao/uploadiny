@extends('layouts.app', ['title' => 'Agent API access · Uploadiny'])
@section('content')
<main class="access-page">
    <header class="workspace-header">
        <a class="brand" href="{{ route('projects.index') }}"><span class="brand-symbol" aria-hidden="true">u</span>Uploadiny</a>
        <a class="button" href="{{ route('projects.index') }}">Back to projects</a>
    </header>
    <section class="access-section" aria-labelledby="access-title">
        <h1 id="access-title">Agent API access</h1>
        <p class="muted access-intro">Connect Claude or Codex to your projects, screenshots, recordings, and feedback. This key grants read-only access to your workspace.</p>
        @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="notice error-message" role="alert">{{ $errors->first() }}</div>@endif
        @if($apiKey)
            <label for="agent-api-key">API key</label>
            <div class="credential-controls">
                <input id="agent-api-key" type="password" value="{{ $apiKey }}" readonly autocomplete="off" spellcheck="false" aria-describedby="api-key-hint">
                <button class="button" type="button" data-reveal-key aria-controls="agent-api-key" aria-pressed="false">Show key</button>
                <button class="button" type="button" data-copy-field="agent-api-key">Copy key</button>
            </div>
            <p id="api-key-hint" class="muted">Keep this key private. Save it in your agent’s local configuration.</p>
        @else
            <div class="access-empty"><h2>No active API key</h2><p class="muted">Generate a key to connect your coding agents.</p></div>
        @endif
        <p class="copy-status muted" data-copy-status role="status" aria-live="polite"></p>
        <div class="access-actions">
            <form method="POST" action="{{ route('agent-access.store') }}" @if($apiKey) data-confirm-action="Rotate the API key? Agents using the current key will lose access until you update their configuration." @endif>
                @csrf
                <button class="button button-primary" type="submit">{{ $apiKey ? 'Rotate API key' : 'Generate API key' }}</button>
            </form>
            @if($apiKey)
                <form method="POST" action="{{ route('agent-access.destroy') }}" data-confirm-action="Revoke agent API access? Your iPhone will stay connected.">
                    @csrf @method('DELETE')
                    <button class="button danger" type="submit">Revoke API key</button>
                </form>
            @endif
        </div>
        <p class="muted access-note">Keys generated here stay valid until you rotate or revoke them. Rotating the key replaces agent access across all projects; your iPhone stays connected.</p>
    </section>
</main>
@endsection
