<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/includes/db.php';

$message = '';
$error = '';
$reset_link = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Please enter your email address.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
} else {
        $db = getDB();
        // Check if user exists
        $stmt = $db->prepare('SELECT id, name FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Generate token
            $token = bin2hex(random_bytes(32));
            
            // In a real application, you'd send an email here.
            // For this demo, we'll store the token and display the link.
            $stmt = $db->prepare('INSERT INTO password_resets (email, token) VALUES (?, ?)');
            if ($stmt->execute([$email, $token])) {
                $message = 'Password reset link generated.';
                
                // Simulate sending an email by showing the link directly for testing purposes
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                $uri = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                $reset_link = "$protocol://$host$uri/reset_password.php?token=$token&email=" . urlencode($email);
            } else {
                $error = 'Failed to generate reset link. Please try again.';
        }
} else {
            // Do not reveal that the email doesn't exist for security reasons, 
            // but for a demo we can show an error or just show a generic success message.
            $message = 'If an account with that email exists, a password reset link has been sent.';
}
    }
}
?>
<?php
$pageTitle = 'Forgot Password — Advent Library';
include __DIR__ . '/includes/header.php';
?>
    <style type="text/tailwindcss">
        :root {
            --c-bg: theme('colors.soft-white');
            --c-surface: #ffffff;
            --c-border: rgba(30, 58, 95, 0.1);
            --c-gold: theme('colors.gold');
            --c-gold-hover: #e8b84a;
        }
        
        .auth-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            padding: 1rem;
        }

        .glass-panel {
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            border-radius: 1.5rem;
            position: relative;
            z-index: 10;
        }
        
        .form-input {
            background: theme('colors.soft-white');
            border: 1px solid var(--c-border);
            color: theme('colors.navy');
            transition: all 0.3s ease;
        }
        .form-input:focus {
            border-color: var(--c-gold);
            outline: none;
            box-shadow: 0 0 0 1px var(--c-gold);
        }
    </style>

<div class="auth-container">

    <!-- Background Decoration -->
    <div class="absolute inset-0 z-0">
        <div class="absolute top-0 left-1/4 w-96 h-96 bg-[var(--c-gold)] rounded-full mix-blend-multiply filter blur-[128px] opacity-10 animate-blob"></div>
        <div class="absolute bottom-0 right-1/4 w-96 h-96 bg-blue-900 rounded-full mix-blend-multiply filter blur-[128px] opacity-20 animate-blob animation-delay-2000"></div>
    </div>

    <!-- Main Container -->
    <main class="w-full max-w-md relative z-10">
        
        <!-- Logo/Header -->
        <div class="text-center mb-8">
            <a href="index.php" class="inline-flex items-center gap-2 mb-6 group">
                <div class="w-10 h-10 flex items-center justify-center transition-transform duration-300 group-hover:scale-110">
                    <img src="includes/logo.png" alt="Advent Library Logo" class="w-full h-full object-contain rounded-lg" />
                </div>
                <span class="text-2xl font-bold tracking-tight text-navy font-display">Advent<span class="text-[var(--c-gold)]">Library</span></span>
            </a>
            <h1 class="text-2xl font-bold text-navy font-display mb-2">Reset Password</h1>
            <p class="text-slate-500 text-sm">Enter your email to receive a password reset link.</p>
        </div>

        <div class="glass-panel p-8 shadow-2xl">
            
            <?php if ($error): ?>
                <div class="bg-red-500/10 border border-red-500/50 text-red-400 text-sm p-4 rounded-xl mb-6 text-center">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="bg-green-500/10 border border-green-500/50 text-green-400 text-sm p-4 rounded-xl mb-6 text-center">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <?php if ($reset_link): ?>
                <div class="bg-blue-500/10 border border-blue-500/50 p-4 rounded-xl mb-6 text-center break-all">
                    <p class="text-xs text-slate-400 mb-2 uppercase tracking-wider font-semibold">Simulated Email Link:</p>
                    <a href="<?= htmlspecialchars($reset_link) ?>" class="text-blue-400 text-sm hover:text-blue-300 underline underline-offset-4">
                        <?= htmlspecialchars($reset_link) ?>
                    </a>
                </div>
            <?php endif; ?>

            <!-- Form -->
            <form action="forgot_password.php" method="POST" class="space-y-5">
                
                <div>
                    <label for="email" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Email Address</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        </div>
                        <input type="email" id="email" name="email" class="form-input w-full rounded-xl pl-12 pr-4 py-3.5 text-sm" placeholder="john@example.com" required>
                    </div>
                </div>

                <button type="submit" class="w-full bg-[var(--c-gold)] hover:bg-[var(--c-gold-hover)] text-[#ffffff] font-bold py-3.5 px-4 rounded-xl transition-all duration-300 hover:-translate-y-0.5 mt-2 flex justify-center items-center gap-2">
                    Send Reset Link
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </button>
            </form>

            <div class="mt-8 text-center">
                <a href="login.php" class="text-sm font-medium text-slate-500 hover:text-[var(--c-gold)] transition-colors">&larr; Back to Login</a>
            </div>

        </div>
    </main>

</div>
</html>
