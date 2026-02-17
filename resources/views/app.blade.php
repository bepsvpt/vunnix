<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Vunnix') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
    <script>
        window.__REVERB_CONFIG__ = {
            key: @json(config('broadcasting.connections.reverb.key')),
            host: @json(config('broadcasting.connections.reverb.options.host')),
            port: @json(config('broadcasting.connections.reverb.options.port')),
            scheme: @json(config('broadcasting.connections.reverb.options.scheme')),
        };
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body class="bg-white dark:bg-zinc-950 text-zinc-900 dark:text-zinc-100 antialiased">
    <div id="app"></div>
</body>
</html>
