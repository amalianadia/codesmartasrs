<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../controllers/AuthController.php';

$auth = new AuthController($pdo);

if ($auth->isLoggedIn()) {
    header("Location: dashboard.php");
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($auth->login($username, $password)) {
        header("Location: dashboard.php");
        exit;
    } else {
        $error = 'Username atau password salah!';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Automated Storage</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        body::before {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 15% 20%, rgba(255, 193, 7, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 85% 80%, rgba(255, 215, 0, 0.08) 0%, transparent 50%);
            animation: breathe 8s ease-in-out infinite;
        }

        @keyframes breathe {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.7; transform: scale(1.05); }
        }

        .particle {
            position: absolute;
            background: #ffc107;
            border-radius: 50%;
            pointer-events: none;
            animation: float 18s ease-in-out infinite;
            z-index: 0;
            box-shadow: 
                0 0 12px rgba(255, 193, 7, 0.7),
                0 0 24px rgba(255, 193, 7, 0.5),
                0 0 36px rgba(255, 193, 7, 0.3);
        }

        @keyframes float {
            0% { transform: translateY(100vh) translateX(0) scale(0) rotate(0deg); opacity: 0; }
            5% { opacity: 0.9; }
            95% { opacity: 0.9; }
            100% { transform: translateY(-100vh) translateX(40px) scale(1.2) rotate(360deg); opacity: 0; }
        }

        .login-container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 460px;
            padding: 2rem;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.98);
            padding: 3.5rem 3rem;
            border-radius: 24px;
            border: 1px solid rgba(255, 255, 255, 0.9);
            box-shadow: 
                0 20px 60px rgba(0, 0, 0, 0.08),
                0 8px 24px rgba(255, 193, 7, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 1),
                0 0 50px rgba(255, 193, 7, 0.1);
            animation: slideUp 0.7s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(40px);
        }

        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, 
                #ffc107 0%, 
                #ffeb3b 25%, 
                #ffc107 50%, 
                #ffeb3b 75%, 
                #ffc107 100%);
            background-size: 200% 100%;
            animation: neonFlow 3s ease infinite;
            box-shadow: 
                0 0 15px rgba(255, 193, 7, 0.9),
                0 0 25px rgba(255, 193, 7, 0.6),
                0 3px 35px rgba(255, 193, 7, 0.4);
        }

        @keyframes neonFlow {
            0%, 100% { background-position: 0% 0%; }
            50% { background-position: 100% 0%; }
        }

        @keyframes slideUp {
            from {
                transform: translateY(40px) scale(0.96);
                opacity: 0;
            }
            to {
                transform: translateY(0) scale(1);
                opacity: 1;
            }
        }

        .logo-icon {
            font-size: 3.5rem;
            text-align: center;
            display: block;
            margin-bottom: 1.5rem;
            filter: 
                drop-shadow(0 0 15px rgba(255, 193, 7, 0.8))
                drop-shadow(0 0 25px rgba(255, 193, 7, 0.6))
                drop-shadow(0 0 40px rgba(255, 193, 7, 0.3));
            animation: neonPulse 3s ease-in-out infinite;
        }

        @keyframes neonPulse {
            0%, 100% { 
                transform: translateY(0) scale(1);
                filter: 
                    drop-shadow(0 0 15px rgba(255, 193, 7, 0.8))
                    drop-shadow(0 0 25px rgba(255, 193, 7, 0.6))
                    drop-shadow(0 0 40px rgba(255, 193, 7, 0.3));
            }
            50% { 
                transform: translateY(-8px) scale(1.05);
                filter: 
                    drop-shadow(0 0 20px rgba(255, 193, 7, 1))
                    drop-shadow(0 0 35px rgba(255, 193, 7, 0.8))
                    drop-shadow(0 0 50px rgba(255, 193, 7, 0.5));
            }
        }

        .login-title {
            font-size: 2.2rem;
            font-weight: 800;
            text-align: center;
            color: #1a1a1a;
            letter-spacing: -0.5px;
            line-height: 1.2;
            margin-bottom: 0.5rem;
        }

        .login-subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 2.5rem;
            font-size: 0.9rem;
            line-height: 1.6;
            font-weight: 500;
        }

        .alert {
            padding: 1rem 1.25rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-weight: 600;
            animation: shake 0.5s ease-out;
            font-size: 0.875rem;
            border: 1px solid;
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
            border-color: rgba(239, 68, 68, 0.4);
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-8px); }
            75% { transform: translateX(8px); }
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.6rem;
            font-weight: 700;
            color: #1a1a1a;
            font-size: 0.9rem;
            letter-spacing: 0.3px;
        }

        .form-label::before {
            content: attr(data-icon);
            margin-right: 0.5rem;
            filter: 
                drop-shadow(0 0 8px rgba(255, 193, 7, 0.7))
                drop-shadow(0 0 15px rgba(255, 193, 7, 0.5));
        }

        .form-control {
            width: 100%;
            padding: 0.95rem 1.125rem;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 0.95rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-family: 'Poppins', sans-serif;
            background: #fafafa;
            color: #1a1a1a;
            font-weight: 500;
        }

        .form-control:hover {
            border-color: #d0d0d0;
            background: #ffffff;
        }

        .form-control:focus {
            outline: none;
            border-color: #ffc107;
            box-shadow: 
                0 0 0 4px rgba(255, 193, 7, 0.2),
                0 0 20px rgba(255, 193, 7, 0.25),
                0 4px 15px rgba(255, 193, 7, 0.15);
            background: #ffffff;
            transform: translateY(-1px);
        }

        .form-control::placeholder {
            color: #999;
            font-weight: 400;
        }

        .password-wrap {
            position: relative;
        }

        .password-wrap .form-control {
            padding-right: 3.5rem;
        }

        .toggle-pass {
            position: absolute;
            right: 6px;
            top: 50%;
            transform: translateY(-50%);
            width: 46px;
            height: 46px;
            border-radius: 10px;
            border: none;
            background: transparent;
            color: #ffc107;
            cursor: pointer;
            display: grid;
            place-items: center;
            transition: all 0.2s ease;
            font-size: 1.2rem;
            filter: 
                drop-shadow(0 0 6px rgba(255, 193, 7, 0.6))
                drop-shadow(0 0 12px rgba(255, 193, 7, 0.4));
        }

        .toggle-pass:hover {
            background: rgba(255, 193, 7, 0.1);
            filter: 
                drop-shadow(0 0 10px rgba(255, 193, 7, 0.8))
                drop-shadow(0 0 18px rgba(255, 193, 7, 0.6));
            transform: translateY(-50%) scale(1.08);
        }

        .toggle-pass:active {
            transform: translateY(-50%) scale(0.95);
        }

        .btn {
            padding: 1.05rem 1.5rem;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 1rem;
            font-family: 'Poppins', sans-serif;
            width: 100%;
            margin-top: 1rem;
            letter-spacing: 0.5px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #ffc107 0%, #ffcd38 100%);
            color: #1a1a1a;
            box-shadow: 
                0 6px 20px rgba(255, 193, 7, 0.5),
                0 0 25px rgba(255, 193, 7, 0.4),
                0 0 45px rgba(255, 193, 7, 0.2);
            position: relative;
            overflow: hidden;
            border: 2px solid transparent;
        }

        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.5), transparent);
            transition: left 0.5s;
        }

        .btn-primary:hover::before {
            left: 100%;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 
                0 10px 30px rgba(255, 193, 7, 0.6),
                0 0 35px rgba(255, 193, 7, 0.5),
                0 0 60px rgba(255, 193, 7, 0.3);
        }

        .btn-primary:active {
            transform: translateY(-1px);
        }

        .btn-primary:disabled {
            opacity: 0.65;
            cursor: not-allowed;
            transform: none;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Decorative glow */
        .login-card::after {
            content: '';
            position: absolute;
            bottom: -60px;
            right: -60px;
            width: 180px;
            height: 180px;
            background: radial-gradient(circle, rgba(255, 193, 7, 0.15) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
            animation: glowPulse 4s ease-in-out infinite;
        }

        @keyframes glowPulse {
            0%, 100% { opacity: 0.6; transform: scale(1); }
            50% { opacity: 1; transform: scale(1.3); }
        }

        @media (max-width: 600px) {
            .login-container {
                padding: 1rem;
            }

            .login-card {
                padding: 2.5rem 2rem;
            }

            .logo-icon {
                font-size: 3rem;
            }

            .login-title {
                font-size: 1.85rem;
            }

            .login-subtitle {
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body>
    <!-- Particles -->
    <div class="particle" style="width: 5px; height: 5px; left: 12%; animation-delay: 0s;"></div>
    <div class="particle" style="width: 7px; height: 7px; left: 28%; animation-delay: 2.5s;"></div>
    <div class="particle" style="width: 4px; height: 4px; left: 45%; animation-delay: 5s;"></div>
    <div class="particle" style="width: 6px; height: 6px; left: 62%; animation-delay: 7.5s;"></div>
    <div class="particle" style="width: 5px; height: 5px; left: 78%; animation-delay: 10s;"></div>
    <div class="particle" style="width: 8px; height: 8px; left: 88%; animation-delay: 12.5s;"></div>
    <div class="particle" style="width: 4px; height: 4px; left: 20%; animation-delay: 3.5s;"></div>
    <div class="particle" style="width: 6px; height: 6px; left: 70%; animation-delay: 6s;"></div>

    <div class="login-container">
        <div class="login-card">
            <span class="logo-icon">🤖</span>
            <h1 class="login-title">Automated Storage</h1>
            
            <p class="login-subtitle">
                Sistem Penyimpanan Otomatis dengan Robot
            </p>
            
            <?php if ($error): ?>
                <div class="alert alert-danger">❌ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <form method="POST" id="loginForm">
                <div class="form-group">
                    <label class="form-label" for="username" data-icon="👤">Username</label>
                    <input type="text" id="username" name="username" class="form-control" 
                           placeholder="Masukkan username" required autofocus>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="password" data-icon="🔒">Password</label>
                    <div class="password-wrap">
                        <input type="password" id="password" name="password" class="form-control" 
                               placeholder="Masukkan password" required autocomplete="current-password">
                        <button type="button" class="toggle-pass" id="togglePass" 
                                aria-pressed="false" aria-label="Tampilkan password">
                            👁️
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary" id="submitBtn">
                    🚀 Login Sekarang
                </button>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Focus username input
            document.getElementById('username')?.focus();

            // Toggle password visibility
            const passInput = document.getElementById('password');
            const toggleBtn = document.getElementById('togglePass');

            toggleBtn?.addEventListener('click', () => {
                const isHidden = passInput.type === 'password';
                passInput.type = isHidden ? 'text' : 'password';

                toggleBtn.setAttribute('aria-pressed', String(isHidden));
                toggleBtn.setAttribute('aria-label', isHidden ? 'Sembunyikan password' : 'Tampilkan password');
                toggleBtn.textContent = isHidden ? '🙈' : '👁️';

                passInput.focus();
            });

            // Form submit handler
            const form = document.getElementById('loginForm');
            const submitBtn = document.getElementById('submitBtn');
            
            form.addEventListener('submit', function() {
                submitBtn.innerHTML = '<span style="animation: spin 1s linear infinite;">⏳</span> Memproses...';
                submitBtn.disabled = true;
            });

            // Enter key handler for all form controls
            document.querySelectorAll('.form-control').forEach(input => {
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        form.submit();
                    }
                });
            });
        });
    </script>
</body>
</html>