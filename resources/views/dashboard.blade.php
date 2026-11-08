<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <link rel="icon" type="image/png" href="/favicon.png" />
        <title lang="fr" data-title-fr="Tableau de bord - {{ config('app.name', 'Association Imam Muslim') }}" data-title-ar="لوحة التحكم - جمعية الامام مسلم">Tableau de bord - {{ config('app.name', 'Association Imam Muslim') }}</title>

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
                    var title = document.querySelector('title');
                    if (title) {
                        title.textContent = lang === 'ar' ? title.dataset.titleAr : title.dataset.titleFr;
                        title.setAttribute('lang', lang);
                    }
                } catch (e) {}
            })();
        </script>

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    </head>
    <body class="bg-gray-50">
        @php
            $authUser = auth()->user();
            $dashboardUser = json_encode([
                'id' => auth()->id(),
                'name' => $authUser?->name,
                'email' => $authUser?->email,
                'role' => $authUser?->role,
            ]);
        @endphp
        <div
            id="root"
            data-page="dashboard"
            data-user="{{ $dashboardUser }}"
        ></div>
    </body>
</html>
