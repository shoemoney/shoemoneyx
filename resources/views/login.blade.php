@php
    $bootstrap = app(\App\Desk\Settings::class)->masterPasswordIsBootstrap();
    $region = (string) config('desk.aws_region');
    $region = preg_match('/^[a-z]{2}(-[a-z]+)+-[0-9]+$/', $region) === 1 ? $region : null;
    $awsGuide = app(\App\Desk\Settings::class)->needsPasswordSetup() && config('desk.first_login_guide') === 'aws';
    $consoleUrl = $region
        ? "https://{$region}.console.aws.amazon.com/ec2/home?region={$region}#Instances:"
        : 'https://console.aws.amazon.com/ec2/home#Instances:';
@endphp
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ShoeMoneyX — sign in</title>
    @vite('resources/css/login.css')
</head>
<body class="bg-zinc-950 text-xl leading-relaxed text-zinc-200 antialiased">
    <div class="flex min-h-screen items-center justify-center px-4 py-8">
        <section class="w-full max-w-xl rounded-xl border border-zinc-800 bg-zinc-900 p-6" aria-label="Sign in">
            <h1 class="text-3xl font-semibold">ShoeMoneyX</h1>
            @if ($tlsBlocked)
                <div id="tls-warning" role="alert" class="mb-4 rounded-lg border border-amber-500 bg-amber-950 p-4 text-lg font-medium text-amber-200">
                    This server doesn't have its secure certificate yet. Don't enter your instance ID. Wait a few minutes and reload, or reboot the instance from the EC2 console.
                </div>
                <p class="mb-4 text-lg text-zinc-300">First login is locked until this address has trusted HTTPS. Keep the browser's certificate checks enabled.</p>
                <a id="reload-login" href="/login" class="mb-3 block rounded-md bg-zinc-100 px-3 py-2 text-center font-medium text-zinc-900">Reload certificate status</a>
                <a id="aws-instances-link" href="{{ $consoleUrl }}" target="_blank" rel="noopener"
                    class="block rounded-md border border-zinc-600 px-3 py-2 text-center font-medium text-zinc-100 hover:border-zinc-400">Open my AWS instances</a>
            @else
            <form method="POST" action="/login">
            @csrf
            @if ($awsGuide)
                <p class="mb-4 text-lg text-zinc-400">First time here? Your first login key is this server's Instance ID. You'll use it once, then choose your own password.</p>
                <div id="first-login-guide" class="mb-4 rounded-lg border border-zinc-700 bg-zinc-950 p-4">
                    <ol class="space-y-4 text-lg text-zinc-300">
                        <li class="flex gap-3">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-lg font-semibold text-zinc-900" aria-hidden="true">1</span>
                            <span>
                                Open your list of servers in AWS.
                                <a id="aws-instances-link" href="{{ $consoleUrl }}" target="_blank" rel="noopener"
                                    class="mt-2 block rounded-md border border-zinc-600 px-3 py-2 text-center font-medium text-zinc-100 hover:border-zinc-400">Open my AWS instances</a>
                            </span>
                        </li>
                        <li class="flex gap-3">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-lg font-semibold text-zinc-900" aria-hidden="true">2</span>
                            <span>
                                Find this server in the list and copy the <strong class="text-zinc-100">Instance ID</strong> column. It starts with <code class="break-all rounded bg-zinc-800 px-1">i-</code>, for example <code class="break-all rounded bg-zinc-800 px-1">i-0abc123def4567890</code>.
                                <div class="mt-3 rounded-md border border-amber-500 bg-zinc-900 p-3" role="img" aria-label="Example AWS server: ShoeMoneyX, instance ID i-0abc123def4567890, running">
                                    <p class="font-semibold text-zinc-100">ShoeMoneyX server</p>
                                    <p class="text-amber-200">Instance ID</p>
                                    <code class="break-all text-lg text-amber-200">i-0abc123def4567890</code>
                                    <p class="text-zinc-300">State: Running</p>
                                </div>
                            </span>
                        </li>
                        <li class="flex gap-3">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-lg font-semibold text-zinc-900" aria-hidden="true">3</span>
                            <span>Paste it below. You'll use it once, then choose your own password.</span>
                        </li>
                    </ol>
                </div>
            @else
                <p class="mb-4 text-lg text-zinc-400">Enter the master password to open the desk.</p>
                @if (config('desk.master_password_hint') && $bootstrap)
                    <p id="master-password-hint" class="mb-4 text-lg text-zinc-500">{{ config('desk.master_password_hint') }}</p>
                @endif
            @endif
            @error('password')
                <p class="mb-3 text-lg text-red-400" role="alert">{{ $message }}</p>
            @enderror
            <label for="password" class="sr-only">{{ $awsGuide ? 'Instance ID' : 'Master password' }}</label>
            <input id="password" type="password" name="password" autofocus autocapitalize="none" autocomplete="{{ $awsGuide ? 'off' : 'current-password' }}" spellcheck="false"
                placeholder="{{ $awsGuide ? 'Paste your Instance ID (i-…)' : 'Master password' }}"
                class="mb-3 w-full rounded-md border border-zinc-700 bg-zinc-950 px-3 py-2 outline-none focus:border-zinc-500" />
            <button class="w-full rounded-md bg-zinc-100 px-3 py-2 font-medium text-zinc-900">Sign in</button>
            </form>
            @endif
        </section>
    </div>
</body>
</html>
