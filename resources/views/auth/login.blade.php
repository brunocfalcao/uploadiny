@extends('layouts.app', ['title' => 'Sign in · Uploadiny'])
@section('content')
<main class="login-page">
    <div class="login-panel">
        <a class="brand" href="{{ route('projects.index') }}"><span class="brand-symbol" aria-hidden="true">u</span>Uploadiny</a>
        <h1>Your feedback,<br>in one place.</h1>
        <p class="muted">Sign in to your private project workspace.</p>
        <form action="{{ route('login.store') }}" method="POST" class="stack mt-8">
            @csrf
            <label class="form-label">Email<input class="qr-field qr-field-default" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required autofocus></label>
            <label class="form-label">Password<input class="qr-field qr-field-default" name="password" type="password" autocomplete="current-password" required></label>
            @if($errors->any())<p role="alert" class="error-message">{{ $errors->first() }}</p>@endif
            <button class="button button-primary w-full" type="submit">Sign in</button>
        </form>
        <p class="login-footnote">A private workspace for Bruno.</p>
    </div>
</main>
@endsection
