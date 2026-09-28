<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>STEDAM.RU — авторские программы обучения</title>

    <!-- Bootstrap 5 CSS -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous"
    >

    <style>
        :root {
            --stedam-blue: #1e73be;
            --stedam-dark: #1c2b4a;
            --stedam-orange: #f57c1f;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f8fafc 0%, #eef4fb 100%);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: var(--stedam-dark);
            padding: 2rem 1rem;
        }

        .landing-card {
            max-width: 720px;
            width: 100%;
            background: #ffffff;
            border-radius: 1.5rem;
            padding: 3rem 2.5rem;
            box-shadow: 0 20px 60px rgba(28, 43, 74, 0.08);
            text-align: center;
        }

        .landing-logo {
            max-width: 220px;
            width: 100%;
            height: auto;
            margin-bottom: 2rem;
        }

        .landing-title {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 1.25rem;
            color: var(--stedam-dark);
        }

        .landing-description {
            font-size: 1.0625rem;
            line-height: 1.65;
            color: #4a5568;
            margin-bottom: 2.5rem;
        }

        .btn-stedam {
            background-color: var(--stedam-blue);
            border-color: var(--stedam-blue);
            color: #fff;
            font-weight: 600;
            padding: 0.875rem 2.5rem;
            border-radius: 0.75rem;
            font-size: 1.0625rem;
            transition: all 0.2s ease;
        }

        .btn-stedam:hover,
        .btn-stedam:focus {
            background-color: #175a96;
            border-color: #175a96;
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(30, 115, 190, 0.25);
        }

        /* Планшеты и небольшие экраны */
        @media (max-width: 768px) {
            .landing-card {
                padding: 2.5rem 1.75rem;
                border-radius: 1.25rem;
            }

            .landing-logo {
                max-width: 180px;
                margin-bottom: 1.5rem;
            }

            .landing-title {
                font-size: 1.5rem;
            }

            .landing-description {
                font-size: 1rem;
                margin-bottom: 2rem;
            }

            .btn-stedam {
                width: 100%;
                padding: 0.875rem 1.5rem;
            }
        }

        /* Телефоны */
        @media (max-width: 480px) {
            body {
                padding: 1rem 0.75rem;
            }

            .landing-card {
                padding: 2rem 1.25rem;
                border-radius: 1rem;
            }

            .landing-logo {
                max-width: 150px;
            }

            .landing-title {
                font-size: 1.375rem;
            }
        }
    </style>
</head>
<body>
<main class="landing-card">
    <!-- Логотип -->
    <img
        src="{{ asset('images/stedam560.jpg') }}"
        alt="STEDAM.RU"
        class="landing-logo"
    >

    <!-- Заголовок -->
    <h1 class="landing-title">Авторские программы обучения</h1>

    <!-- Краткое описание -->
    <p class="landing-description">
        STEDAM.RU — платформа, где преподаватели создают собственные авторские
        программы обучения, а ученики осваивают материал через практические
        проекты. Преподаватели делятся экспертизой, ученики получают реальный
        опыт — и всё это в одном пространстве.
    </p>

    <!-- Ссылка в приложение -->
    <a href="/app" class="btn btn-stedam">
        Перейти в приложение
    </a>
</main>

<!-- Bootstrap 5 JS (необязательно для этой страницы, но полезно на будущее) -->
<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
    crossorigin="anonymous"
></script>
</body>
</html>
