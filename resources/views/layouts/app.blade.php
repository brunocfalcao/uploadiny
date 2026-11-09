<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#eef0f4">
    <title>{{ $title ?? 'Uploadiny' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    @yield('content')
    <div class="toast-region" aria-label="Notifications">
        @if(session('status'))
            @include('toast', ['text' => session('status'), 'transient' => true])
        @endif
        @stack('toasts')
    </div>
</body>
</html>
