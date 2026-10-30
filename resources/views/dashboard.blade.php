<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <link rel="icon" type="image/png" href="/favicon.png" />
        <title>Dashboard - {{ config('app.name', 'Laravel') }}</title>

        <script>
            (function () {
                try {
                    var stored = localStorage.getItem('tahfiz-theme');
                    var dark = stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
                    document.documentElement.classList.toggle('dark', dark);
                } catch (e) {}
            })();
        </script>

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    </head>
    <body class="bg-gray-50">
        <div
            id="root"
            data-page="dashboard"
            data-user='@json(auth()->user())'
        ></div>
    </body>
</html>
