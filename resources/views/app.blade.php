<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- Link preview card when the address is shared on Facebook, Messenger, Viber, etc. --}}
        @php
            $shareTitle = config('app.name').' — Boarding House Management System';
            $shareText = 'Monthly bills, payments with receipts, boarder ledgers and the Statement of Accounts for '.config('app.name').', Kabankalan City.';
        @endphp
        <meta name="description" content="{{ $shareText }}">
        <meta name="theme-color" content="#0B3466">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ config('app.name') }}">
        <meta property="og:title" content="{{ $shareTitle }}">
        <meta property="og:description" content="{{ $shareText }}">
        {{-- The page itself: "/" redirects to /login, so pointing here at "/" sends Facebook in a redirect loop (no card). --}}
        <meta property="og:url" content="{{ url()->current() }}">
        <link rel="canonical" href="{{ url()->current() }}">
        <meta property="og:locale" content="en_PH">
        <meta property="og:image" content="{{ asset('branding/og-image.jpg') }}">
        <meta property="og:image:secure_url" content="{{ secure_asset('branding/og-image.jpg') }}">
        <meta property="og:image:type" content="image/jpeg">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="{{ config('app.name') }} logo">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $shareTitle }}">
        <meta name="twitter:description" content="{{ $shareText }}">
        <meta name="twitter:image" content="{{ asset('branding/og-image.jpg') }}">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=fira-sans:400,500,600,700|fira-code:500,600&display=swap" rel="stylesheet" />

        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
