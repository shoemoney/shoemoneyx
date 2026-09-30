@php
    $bootstrap = app(\App\Desk\Settings::class)->masterPasswordIsBootstrap();
    $region = (string) config('desk.aws_region');
    $region = preg_match('/^[a-z]{2}(-[a-z]+)+-[0-9]+$/', $region) === 1 ? $region : null;
    $awsGuide = $bootstrap && config('desk.first_login_guide') === 'aws';
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
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="bg-zinc-950 text-zinc-200 antialiased">
    <div class="flex min-h-screen items-center justify-center px-4 py-8">
        <form method="POST" action="/login" class="w-full max-w-sm rounded-xl border border-zinc-800 bg-zinc-900 p-6">
            @csrf
            <h1 class="text-lg font-semibold">ShoeMoneyX</h1>
            @if ($awsGuide)
                <p class="mb-4 text-sm text-zinc-400">First time here? Your first login key is this server's Instance ID. You'll use it once, then choose your own password.</p>
                <div id="first-login-guide" class="mb-4 rounded-lg border border-zinc-700 bg-zinc-950 p-4">
                    <ol class="space-y-4 text-sm text-zinc-300">
                        <li class="flex gap-3">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-xs font-semibold text-zinc-900" aria-hidden="true">1</span>
                            <span>
                                Open your list of servers in AWS.
                                <a id="aws-instances-link" href="{{ $consoleUrl }}" target="_blank" rel="noopener"
                                    class="mt-2 block rounded-md border border-zinc-600 px-3 py-2 text-center font-medium text-zinc-100 hover:border-zinc-400">Open my AWS instances</a>
                            </span>
                        </li>
                        <li class="flex gap-3">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-xs font-semibold text-zinc-900" aria-hidden="true">2</span>
                            <span>
                                Find this server in the list and copy the <strong class="text-zinc-100">Instance ID</strong> column. It starts with <code class="rounded bg-zinc-800 px-1">i-</code>, for example <code class="rounded bg-zinc-800 px-1">i-0abc123def4567890</code>.
                                <svg class="mt-3 w-full" viewBox="0 0 320 96" role="img" aria-label="Example of the AWS instances table with the Instance ID column highlighted">
                                    <rect x="1" y="1" width="318" height="94" rx="6" fill="#18181b" stroke="#3f3f46" />
                                    <rect x="1" y="1" width="318" height="26" rx="6" fill="#27272a" />
                                    <rect x="118" y="1" width="112" height="94" fill="#fbbf24" fill-opacity="0.16" stroke="#fbbf24" stroke-width="1.5" />
                                    <g font-family="ui-monospace, Menlo, monospace" font-size="10" fill="#d4d4d8">
                                        <text x="12" y="18" font-weight="700">Name</text>
                                        <text x="126" y="18" font-weight="700" fill="#fde68a">Instance ID</text>
                                        <text x="242" y="18" font-weight="700">State</text>
                                        <text x="12" y="48">shoemoneyx</text>
                                        <text x="126" y="48" fill="#fde68a">i-0abc123def4567890</text>
                                        <text x="242" y="48">Running</text>
                                        <text x="12" y="76" fill="#71717a">other-server</text>
                                        <text x="126" y="76" fill="#71717a">i-0fed987cba6543210</text>
                                        <text x="242" y="76" fill="#71717a">Running</text>
                                    </g>
                                </svg>
                            </span>
                        </li>
                        <li class="flex gap-3">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-xs font-semibold text-zinc-900" aria-hidden="true">3</span>
                            <span>Paste it below. You'll use it once, then choose your own password.</span>
                        </li>
                    </ol>
                </div>
            @else
                <p class="mb-4 text-sm text-zinc-400">Enter the master password to open the desk.</p>
                @if (config('desk.master_password_hint') && $bootstrap)
                    <p id="master-password-hint" class="mb-4 text-xs text-zinc-500">{{ config('desk.master_password_hint') }}</p>
                @endif
            @endif
            @error('password')
                <p class="mb-3 text-sm text-red-400" role="alert">{{ $message }}</p>
            @enderror
            <label for="password" class="sr-only">{{ $awsGuide ? 'Instance ID' : 'Master password' }}</label>
            <input id="password" type="password" name="password" autofocus autocapitalize="none" autocomplete="{{ $awsGuide ? 'off' : 'current-password' }}" spellcheck="false"
                placeholder="{{ $awsGuide ? 'Paste your Instance ID (i-…)' : 'Master password' }}"
                class="mb-3 w-full rounded-md border border-zinc-700 bg-zinc-950 px-3 py-2 outline-none focus:border-zinc-500" />
            <button class="w-full rounded-md bg-zinc-100 px-3 py-2 font-medium text-zinc-900">Sign in</button>
        </form>
    </div>
</body>
</html>
