<?php /** Login Page - Premium Design */ ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Login — ArbitraFy</title>
    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
    <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="ArbitraFy">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body style="margin:0;padding:0;">
<style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        -webkit-font-smoothing: antialiased;
        -webkit-tap-highlight-color: transparent;
    }

    .login-page {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #000000;
        padding: 20px;
        position: relative;
        overflow: hidden;
    }

    /* Animated gradient orbs */
    .login-page::before,
    .login-page::after {
        content: '';
        position: absolute;
        border-radius: 50%;
        filter: blur(120px);
        opacity: 0.3;
        animation: float 8s ease-in-out infinite;
    }

    .login-page::before {
        width: 500px;
        height: 500px;
        background: radial-gradient(circle, #0a84ff 0%, transparent 70%);
        top: -150px;
        right: -100px;
        animation-delay: 0s;
    }

    .login-page::after {
        width: 400px;
        height: 400px;
        background: radial-gradient(circle, #5e5ce6 0%, transparent 70%);
        bottom: -100px;
        left: -100px;
        animation-delay: -4s;
    }

    @keyframes float {
        0%, 100% { transform: translate(0, 0) scale(1); }
        33% { transform: translate(30px, -20px) scale(1.05); }
        66% { transform: translate(-20px, 15px) scale(0.95); }
    }

    /* Grid pattern overlay */
    .login-bg-grid {
        position: absolute;
        inset: 0;
        background-image:
            linear-gradient(rgba(10,132,255,0.03) 1px, transparent 1px),
            linear-gradient(90deg, rgba(10,132,255,0.03) 1px, transparent 1px);
        background-size: 60px 60px;
        z-index: 0;
    }

    /* Floating particles */
    .login-particles {
        position: absolute;
        inset: 0;
        z-index: 0;
        overflow: hidden;
    }

    .login-particles span {
        position: absolute;
        width: 3px;
        height: 3px;
        background: rgba(10, 132, 255, 0.4);
        border-radius: 50%;
        animation: particle-float linear infinite;
    }

    .login-particles span:nth-child(1) { left: 10%; animation-duration: 12s; animation-delay: 0s; }
    .login-particles span:nth-child(2) { left: 25%; animation-duration: 15s; animation-delay: -3s; width: 2px; height: 2px; }
    .login-particles span:nth-child(3) { left: 40%; animation-duration: 10s; animation-delay: -5s; }
    .login-particles span:nth-child(4) { left: 55%; animation-duration: 18s; animation-delay: -7s; width: 4px; height: 4px; }
    .login-particles span:nth-child(5) { left: 70%; animation-duration: 14s; animation-delay: -2s; }
    .login-particles span:nth-child(6) { left: 85%; animation-duration: 11s; animation-delay: -9s; width: 2px; height: 2px; }
    .login-particles span:nth-child(7) { left: 50%; animation-duration: 16s; animation-delay: -4s; }
    .login-particles span:nth-child(8) { left: 15%; animation-duration: 13s; animation-delay: -6s; width: 4px; height: 4px; background: rgba(94, 92, 230, 0.3); }

    @keyframes particle-float {
        0% { transform: translateY(100vh) rotate(0deg); opacity: 0; }
        10% { opacity: 1; }
        90% { opacity: 1; }
        100% { transform: translateY(-10vh) rotate(720deg); opacity: 0; }
    }

    .login-container {
        width: 100%;
        max-width: 440px;
        z-index: 1;
        animation: loginSlideUp 0.6s ease-out;
    }

    @keyframes loginSlideUp {
        from { opacity: 0; transform: translateY(30px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .login-card {
        background: rgba(28, 28, 30, 0.75);
        backdrop-filter: blur(40px) saturate(180%);
        -webkit-backdrop-filter: blur(40px) saturate(180%);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 20px;
        padding: 52px 44px;
        box-shadow:
            0 20px 60px rgba(0, 0, 0, 0.5),
            0 0 0 1px rgba(255, 255, 255, 0.05) inset,
            0 1px 0 rgba(255, 255, 255, 0.08) inset;
        position: relative;
        overflow: hidden;
    }

    .login-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 2px;
        background: linear-gradient(90deg, #0a84ff, #5e5ce6, #bf5af2, #0a84ff);
        background-size: 300% 100%;
        animation: shimmer 3s ease-in-out infinite;
    }

    @keyframes shimmer {
        0% { background-position: 0% 50%; }
        50% { background-position: 100% 50%; }
        100% { background-position: 0% 50%; }
    }

    /* Logo */
    .login-logo {
        text-align: center;
        margin-bottom: 12px;
    }

    .login-logo-icon {
        width: 72px;
        height: 72px;
        background: rgba(10, 132, 255, 0.12);
        border-radius: 20px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 36px;
        margin-bottom: 20px;
        border: 1px solid rgba(10, 132, 255, 0.15);
    }

    @keyframes logoPulse {
        0%, 100% { box-shadow: 0 8px 32px rgba(99, 102, 241, 0.3); }
        50% { box-shadow: 0 8px 48px rgba(99, 102, 241, 0.5); }
    }

    .login-logo-text {
        font-size: 26px;
        font-weight: 800;
        background: linear-gradient(135deg, #f5f5f7 0%, #98989f 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        letter-spacing: -0.5px;
    }

    .login-subtitle {
        text-align: center;
        color: #636366;
        font-size: 14px;
        margin-bottom: 36px;
        font-weight: 400;
    }

    /* Form */
    .login-form-group {
        margin-bottom: 20px;
        position: relative;
    }

    .login-form-label {
        display: block;
        font-size: 12px;
        font-weight: 600;
        color: #98989f;
        margin-bottom: 8px;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .login-input-wrapper {
        position: relative;
    }

    .login-input-icon {
        position: absolute;
        left: 16px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 16px;
        opacity: 0.4;
        transition: all 0.3s ease;
        z-index: 1;
    }

    .login-form-input {
        width: 100%;
        padding: 14px 16px 14px 46px;
        background: rgba(28, 28, 30, 0.6);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 12px;
        color: #f5f5f7;
        font-family: inherit;
        font-size: 14px;
        transition: all 0.2s ease;
        outline: none;
    }

    .login-form-input::placeholder {
        color: #48484a;
    }

    .login-form-input:focus {
        border-color: rgba(10, 132, 255, 0.5);
        box-shadow: 0 0 0 4px rgba(10, 132, 255, 0.1);
        background: rgba(28, 28, 30, 0.8);
    }

    .login-form-input:focus ~ .login-input-icon,
    .login-input-wrapper:focus-within .login-input-icon {
        opacity: 1;
        color: #0a84ff;
    }

    /* Button */
    .login-btn {
        width: 100%;
        padding: 14px;
        background: linear-gradient(135deg, #0a84ff, #5e5ce6);
        color: white;
        border: none;
        border-radius: 12px;
        font-family: inherit;
        font-size: 15px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s ease;
        margin-top: 8px;
        position: relative;
        overflow: hidden;
        letter-spacing: 0.3px;
    }

    .login-btn::before {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(135deg, #409cff, #7d7aff);
        opacity: 0;
        transition: opacity 0.2s ease;
    }

    .login-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 30px rgba(10, 132, 255, 0.3);
    }

    .login-btn:hover::before { opacity: 1; }

    .login-btn:active {
        transform: translateY(0);
    }

    .login-btn span {
        position: relative;
        z-index: 1;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    /* Error alert */
    .login-alert {
        padding: 14px 18px;
        background: rgba(255, 69, 58, 0.1);
        border: 1px solid rgba(255, 69, 58, 0.2);
        border-radius: 12px;
        color: #ff453a;
        font-size: 13px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 10px;
        animation: shake 0.5s ease;
    }

    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        20% { transform: translateX(-8px); }
        40% { transform: translateX(8px); }
        60% { transform: translateX(-4px); }
        80% { transform: translateX(4px); }
    }

    /* Footer */
    .login-footer {
        text-align: center;
        margin-top: 28px;
        color: #48484a;
        font-size: 12px;
    }

    .login-footer span {
        color: #636366;
    }

    /* Remember me */
    .login-remember {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 16px 0 4px;
    }

    .login-remember input[type="checkbox"] {
        width: 18px;
        height: 18px;
        accent-color: #0a84ff;
        border-radius: 4px;
        cursor: pointer;
        flex-shrink: 0;
    }

    .login-remember label {
        font-size: 13px;
        color: #98989f;
        cursor: pointer;
        user-select: none;
    }

    /* ============================================================
       MOBILE — App-Like Login
       ============================================================ */
    @media (max-width: 768px) {
        .login-page {
            padding: 16px;
            align-items: center;
        }

        .login-container {
            max-width: 100%;
        }

        .login-card {
            padding: 40px 28px;
            border-radius: 22px;
            box-shadow: 0 16px 48px rgba(0, 0, 0, 0.5);
        }

        .login-logo-icon {
            width: 64px;
            height: 64px;
            border-radius: 18px;
            font-size: 32px;
            margin-bottom: 16px;
        }

        .login-logo-text {
            font-size: 24px;
        }

        .login-subtitle {
            font-size: 13px;
            margin-bottom: 28px;
        }

        .login-form-group {
            margin-bottom: 18px;
        }

        .login-form-label {
            font-size: 11px;
            margin-bottom: 8px;
        }

        .login-form-input {
            min-height: 52px;
            padding: 16px 16px 16px 46px;
            font-size: 16px; /* 16px prevents iOS zoom on focus */
            border-radius: 14px;
            background: rgba(15, 20, 40, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .login-btn {
            min-height: 52px;
            font-size: 16px;
            border-radius: 14px;
            margin-top: 12px;
        }

        .login-btn:hover {
            transform: none;
        }

        .login-btn:active {
            transform: scale(0.98);
            opacity: 0.9;
        }

        .login-alert {
            border-radius: 14px;
            font-size: 13px;
            padding: 14px 16px;
        }

        .login-footer {
            margin-top: 24px;
            font-size: 11px;
        }

        /* Reduce heavy effects for performance */
        .login-page::before {
            width: 280px;
            height: 280px;
            animation: none;
            opacity: 0.3;
        }

        .login-page::after {
            width: 220px;
            height: 220px;
            animation: none;
            opacity: 0.3;
        }

        .login-particles span {
            animation-duration: 25s;
        }
    }

    @media (max-width: 480px) {
        .login-page {
            padding: 14px;
        }

        .login-card {
            padding: 36px 24px;
            border-radius: 20px;
        }

        .login-logo-icon {
            width: 56px;
            height: 56px;
            font-size: 28px;
            border-radius: 16px;
        }

        .login-logo-text {
            font-size: 22px;
        }

        .login-subtitle {
            font-size: 12px;
            margin-bottom: 24px;
        }
    }

    @media (max-width: 360px) {
        .login-card {
            padding: 32px 20px;
            border-radius: 18px;
        }

        .login-logo-icon {
            width: 52px;
            height: 52px;
            font-size: 26px;
        }

        .login-logo-text {
            font-size: 20px;
        }

        .login-form-input {
            min-height: 48px;
            font-size: 15px;
        }

        .login-btn {
            min-height: 48px;
            font-size: 15px;
        }
    }
</style>

<div class="login-page">
    <div class="login-bg-grid"></div>
    <div class="login-particles">
        <span></span><span></span><span></span><span></span>
        <span></span><span></span><span></span><span></span>
    </div>

    <div class="login-container">
        <div class="login-card">
            <div class="login-logo">
                <img src="assets/img/logo-arbitrafy.svg" alt="ArbitraFy" style="height:48px;width:auto;display:block;margin:0 auto 16px;">
            </div>
            <p class="login-subtitle">Painel de Controle de Arbitragem</p>

            <?php if (!empty($loginError)): ?>
            <div class="login-alert">⚠️ <?= $loginError ?></div>
            <?php endif; ?>

            <form method="POST" action="?page=login">
                <div class="login-form-group">
                    <label class="login-form-label">Usuário</label>
                    <div class="login-input-wrapper">
                        <input type="text" name="username" class="login-form-input" placeholder="Digite seu usuário" required autofocus>
                        <span class="login-input-icon">👤</span>
                    </div>
                </div>
                <div class="login-form-group">
                    <label class="login-form-label">Senha</label>
                    <div class="login-input-wrapper">
                        <input type="password" name="password" class="login-form-input" placeholder="Digite sua senha" required>
                        <span class="login-input-icon">🔒</span>
                    </div>
                </div>
                <div class="login-remember">
                    <input type="checkbox" id="remember_me" name="remember_me">
                    <label for="remember_me">Manter conectado</label>
                </div>
                <button type="submit" class="login-btn">
                    <span>Entrar →</span>
                </button>
            </form>

            <div class="login-footer">
                <span>© <?= date('Y') ?></span> ArbitraFy
            </div>
        </div>
    </div>
</div>
</body>
</html>
