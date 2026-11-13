@php
    $google_fonts_string = '';
    $theme_google_fonts = Theme::getSetting('landingPage.googleFonts');
    $app_js_path = 'resources/views/' . get_theme() . '/js/app.js';

    $i = 0;
    foreach ($theme_google_fonts as $font_name => $weights) {
        $font_string = 'family=' . str_replace(' ', '+', $font_name);
        if (!empty($weights)) {
            $font_string .= ':wght@' . implode(';', $weights);
        }
        $google_fonts_string .= $font_string . ($i === count($theme_google_fonts) - 1 ? '' : '&');
        $i++;
    }
@endphp

<!DOCTYPE html>
<html
        class="max-sm:overflow-x-hidden"
        lang="{{ LaravelLocalization::getCurrentLocale() }}"
        dir="{{ LaravelLocalization::getCurrentLocaleDirection() }}"
>

<head>
    <meta charset="UTF-8" />
    <meta
            http-equiv="X-UA-Compatible"
            content="IE=edge"
    />
    <meta
            name="viewport"
            content="width=device-width, initial-scale=1.0"
    />
    <meta
            name="description"
            content="{{ getMetaDesc($setting, $settings_two) }}"
    >
    @if (isset($setting->meta_keywords))
        <meta
                name="keywords"
                content="{{ $setting->meta_keywords }}"
        >
    @endif

    <link
            rel="icon"
            href="{{ custom_theme_url($setting->favicon_path ?? 'assets/favicon.ico') }}"
    >

    <title>
        {{ getMetaTitle($setting, $settings_two) }}
    </title>

    @if (filled($google_fonts_string))
        <link
                rel="preconnect"
                href="https://fonts.googleapis.com"
        >
        <link
                rel="preconnect"
                href="https://fonts.gstatic.com"
                crossorigin
        >
        <link
                href="https://fonts.googleapis.com/css2?{{ $google_fonts_string }}&display=swap"
                rel="stylesheet"
        >
    @endif

    <link
            rel="stylesheet"
            href="{{ custom_theme_url('assets/css/frontend/flickity.min.css') }}"
    >
    <link
            href="{{ custom_theme_url('assets/libs/toastr/toastr.min.css') }}"
            rel="stylesheet"
    />

    @php
        $link = 'resources/views/' . get_theme() . '/scss/landing-page.scss';
    @endphp
    @vite($link)

    @if ($setting->frontend_custom_css != null)
        <link
                rel="stylesheet"
                href="{{ $setting->frontend_custom_css }}"
        />
    @endif

    @if ($setting->frontend_code_before_head != null)
        {!! $setting->frontend_code_before_head !!}
    @endif

    <script>
        window.liquid = {
            isLandingPage: true
        };
    </script>

    <style>
        :root {
            color-scheme: dark;
        }

        .google-ads-728 {
            width: 100%;
            max-width: 728px;
            height: auto;
        }

        body.aicenty-coming-soon {
            min-height: 100vh;
            min-height: 100dvh;
            margin: 0;
            overflow-x: hidden;
            background: #030406;
            color: #fff;
            font-family: 'Golos Text', 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        body.aicenty-coming-soon::before,
        body.aicenty-coming-soon::after {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
        }

        body.aicenty-coming-soon::before {
            background:
                linear-gradient(90deg, rgba(255, 255, 255, 0.075) 1px, transparent 1px),
                linear-gradient(180deg, rgba(255, 255, 255, 0.075) 1px, transparent 1px),
                linear-gradient(135deg, rgba(0, 214, 255, 0.16), transparent 34%),
                linear-gradient(315deg, rgba(120, 255, 214, 0.09), transparent 42%);
            background-size: 72px 72px, 72px 72px, 100% 100%, 100% 100%;
            opacity: 0.5;
        }

        body.aicenty-coming-soon::after {
            background:
                repeating-linear-gradient(180deg, rgba(255, 255, 255, 0.045) 0, rgba(255, 255, 255, 0.045) 1px, transparent 1px, transparent 8px),
                linear-gradient(180deg, rgba(3, 4, 6, 0.1), rgba(3, 4, 6, 0.72));
            opacity: 0.42;
        }

        .aicenty-shell {
            position: relative;
            z-index: 1;
            display: grid;
            min-height: 100vh;
            min-height: 100dvh;
            place-items: center;
            padding: 72px 24px;
        }

        .aicenty-content {
            position: relative;
            width: min(100%, 860px);
            text-align: center;
        }

        .aicenty-content::before,
        .aicenty-content::after {
            content: '';
            position: absolute;
            left: 50%;
            width: min(100%, 720px);
            height: 1px;
            transform: translateX(-50%);
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.72), transparent);
        }

        .aicenty-content::before {
            top: -48px;
        }

        .aicenty-content::after {
            bottom: -48px;
            opacity: 0.45;
        }

        .aicenty-logo {
            display: block;
            width: 420px;
            max-width: 100%;
            height: auto;
            margin: 0 auto 44px;
            filter: drop-shadow(0 14px 36px rgba(0, 214, 255, 0.18));
        }

        .aicenty-title {
            max-width: 780px;
            margin: 0 auto 20px;
            color: #fff;
            font-size: 64px;
            font-weight: 800;
            line-height: 1.04;
            letter-spacing: 0;
        }

        .aicenty-subtitle {
            max-width: 760px;
            margin: 0 auto;
            color: rgba(255, 255, 255, 0.92);
            font-size: 28px;
            font-weight: 700;
            line-height: 1.35;
            letter-spacing: 0;
            text-transform: uppercase;
        }

        .aicenty-footer {
            display: block;
            margin-top: 36px;
            color: rgba(255, 255, 255, 0.66);
            font-size: 15px;
            line-height: 1.6;
            letter-spacing: 0;
        }

        @media (max-width: 768px) {
            body.aicenty-coming-soon::before {
                background-size: 52px 52px, 52px 52px, 100% 100%, 100% 100%;
                opacity: 0.42;
            }

            .aicenty-shell {
                padding: 56px 20px;
            }

            .aicenty-content::before {
                top: -34px;
            }

            .aicenty-content::after {
                bottom: -34px;
            }

            .aicenty-logo {
                width: 320px;
                margin-bottom: 34px;
            }

            .aicenty-title {
                font-size: 44px;
                line-height: 1.08;
            }

            .aicenty-subtitle {
                font-size: 22px;
                line-height: 1.38;
            }
        }

        @media (max-width: 480px) {
            .aicenty-shell {
                padding: 44px 18px;
            }

            .aicenty-logo {
                width: 280px;
                margin-bottom: 30px;
            }

            .aicenty-title {
                font-size: 34px;
            }

            .aicenty-subtitle {
                font-size: 18px;
            }

            .aicenty-footer {
                margin-top: 28px;
                font-size: 14px;
            }
        }
    </style>

    <!--Google AdSense-->
    {!! adsense_header() !!}
    <!--Google AdSense End-->

    {{-- Rewordfull start --}}
    {{-- <script>(function(w,r){w._rwq=r;w[r]=w[r]||function(){(w[r].q=w[r].q||[]).push(arguments)}})(window,'rewardful');</script> --}}
    {{-- <script async src='https://r.wdfl.co/rw.js' data-rewardful='API_KEY'></script> --}}
    {{-- Rewordfull end --}}


    @stack('css')

    @if (setting('additional_custom_css') != null)
        {!! setting('additional_custom_css') !!}
    @endif
</head>
<body class="aicenty-coming-soon">
<main class="aicenty-shell">
    <section
            class="aicenty-content"
            aria-labelledby="aicenty-title"
    >
        <img
                class="aicenty-logo"
                src="{{ asset('images/brand/aicenty-coming-soon-logo.png') }}"
                alt="AICENTY"
                width="531"
                height="80"
        >
        <h1
                id="aicenty-title"
                class="aicenty-title"
        >{{ data_get($data, 'header') }}</h1>
        <p class="aicenty-subtitle">{{ data_get($data, 'paragraph') }}</p>
        <small class="aicenty-footer">{!! data_get($data, 'footer') !!}</small>
    </section>
</main>
</body>
</html>
