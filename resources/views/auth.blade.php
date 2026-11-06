<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <link rel="icon" type="image/png" href="/favicon.png" />
        <title>Login - {{ config('app.name', 'Laravel') }}</title>

        <script>
            (function () {
                try {
                    var stored = localStorage.getItem('tahfiz-theme');
                    var dark = stored === 'dark';
                    document.documentElement.classList.toggle('dark', dark);

                    // Apply the persisted language before first paint (avoids an RTL flash).
                    var lang = localStorage.getItem('tahfiz-language') === 'ar' ? 'ar' : 'fr';
                    document.documentElement.setAttribute('lang', lang);
                    document.documentElement.setAttribute('dir', lang === 'ar' ? 'rtl' : 'ltr');
                } catch (e) {}
            })();
        </script>

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    </head>
    <body class="bg-gray-50">
        <div id="root" data-page="login"></div>
    </body>
</html>
