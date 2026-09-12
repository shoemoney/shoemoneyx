<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ShoeMoneyX — sign in</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="bg-zinc-950 text-zinc-200 antialiased">
    <div class="flex min-h-screen items-center justify-center px-4">
        <form method="POST" action="/login" class="w-full max-w-sm rounded-xl border border-zinc-800 bg-zinc-900 p-6">
            @csrf
            <h1 class="text-lg font-semibold">ShoeMoneyX</h1>
            <p class="mb-4 text-sm text-zinc-400">Enter the master password to open the desk.</p>
            @if (config('desk.master_password_hint') && app(\App\Desk\Settings::class)->masterPasswordIsBootstrap())
                <p id="master-password-hint" class="mb-4 text-xs text-zinc-500">{{ config('desk.master_password_hint') }}</p>
            @endif
            @error('password')
                <p class="mb-3 text-sm text-red-400">{{ $message }}</p>
            @enderror
            <input type="password" name="password" autofocus placeholder="Master password"
                class="mb-3 w-full rounded-md border border-zinc-700 bg-zinc-950 px-3 py-2 outline-none focus:border-zinc-500" />
            <button class="w-full rounded-md bg-zinc-100 px-3 py-2 font-medium text-zinc-900">Sign in</button>
        </form>
    </div>
</body>
</html>
