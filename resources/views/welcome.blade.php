<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title lang="fr" data-title-fr="{{ config('app.name', 'Association Imam Muslim') }}" data-title-ar="جمعية الامام مسلم">{{ config('app.name', 'Association Imam Muslim') }}</title>

        <script>
            (function () {
                try {
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
    <body class="bg-[#FDFDFC] dark:bg-[#1c1b1a] text-[#1b1b18] dark:text-[#e7e3de]">
        <div id="root"></div>
    </body>
</html>
