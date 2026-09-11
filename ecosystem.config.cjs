// pm2 process file for the desk host:  pm2 start ecosystem.config.cjs && pm2 save && pm2 startup
//   feeder   — Coinbase websocket → Redis/MySQL (restarts on crash, 5s backoff)
//   desk     — trading loop (desk:run)
//   queue    — default queue (backtests launched from the dashboard)
//   workers  — backtest sweep workers on this box (WORKERS env, default 4; the farm adds more)
//   optimizer — desk:optimize daemon: keeps the farm busy, promotes walk-forward-validated params per coin
//   serve    — php artisan serve (dashboard) — swap for nginx/Valet later
const cwd = __dirname;
const n = Number(process.env.WORKERS || 4);
module.exports = {
  apps: [
    { name: 'shoemoneyx-feeder', cwd, script: 'feeder/feed.mjs', interpreter: 'node', autorestart: true, restart_delay: 5000, max_restarts: 1000, out_file: 'storage/logs/feeder.log', error_file: 'storage/logs/feeder.log', merge_logs: true, time: true },
    { name: 'shoemoneyx-desk', cwd, script: 'artisan', args: 'desk:run', interpreter: 'php', autorestart: true, restart_delay: 10000, out_file: 'storage/logs/desk-run.log', error_file: 'storage/logs/desk-run.log', merge_logs: true, time: true },
    { name: 'shoemoneyx-queue', cwd, script: 'artisan', args: 'queue:work redis --tries=1 --timeout=3600 --sleep=2', interpreter: 'php', autorestart: true, out_file: 'storage/logs/queue.log', error_file: 'storage/logs/queue.log', merge_logs: true, time: true },
    { name: 'shoemoneyx-worker', cwd, script: 'artisan', args: `queue:work redis --queue=${process.env.WORKER_QUEUES || 'backtests,backtests-light'} --tries=1 --timeout=3600 --sleep=2 --memory=1024 --max-jobs=200`, interpreter: 'php', instances: n, exec_mode: 'fork', autorestart: true, out_file: 'storage/logs/worker.log', error_file: 'storage/logs/worker.log', merge_logs: true, time: true },
    { name: 'shoemoneyx-optimizer', cwd, script: 'artisan', args: 'desk:optimize --tag=long --per-round=192 --train=23 --test=7 --tfs=1m,2m,3m --pause=60 --space=wide --mutate=3 --target=7.5 --plateau=0.75', interpreter: 'php', autorestart: true, restart_delay: 30000, out_file: 'storage/logs/optimizer.log', error_file: 'storage/logs/optimizer.log', merge_logs: true, time: true },
    // report-only: same search at the live account's buying power, so the rounds table shows which coins survive lot sizing — --dry means it never promotes
    { name: 'shoemoneyx-optimizer-small', cwd, script: 'artisan', args: 'desk:optimize --tag=small --per-round=192 --train=23 --test=7 --tfs=1m,2m,3m --pause=60 --space=wide --mutate=3 --cash=3000 --dry', interpreter: 'php', autorestart: true, restart_delay: 30000, out_file: 'storage/logs/optimizer-small.log', error_file: 'storage/logs/optimizer-small.log', merge_logs: true, time: true },
    // fast boxes that macOS won't let run LAN daemons: workers live inside an ssh session held from here
    ...(process.env.REMOTE_WORKERS || '').split(',').filter(Boolean).map(spec => { const [host, count] = spec.split(':'); return { name: 'shoemoneyx-workers-' + host, cwd, script: 'ops/remote-workers.sh', args: host + ' ' + (count || 24), interpreter: 'bash', autorestart: true, restart_delay: 10000, out_file: 'storage/logs/workers-' + host + '.log', error_file: 'storage/logs/workers-' + host + '.log', merge_logs: true, time: true }; }),
    { name: 'shoemoneyx-optimizer-short', cwd, script: 'artisan', args: 'desk:optimize --tag=short --per-round=192 --train=23 --test=7 --side=short --space=short --mutate=3 --pause=60 --target=7.5', interpreter: 'php', autorestart: true, restart_delay: 30000, out_file: 'storage/logs/optimizer-short.log', error_file: 'storage/logs/optimizer-short.log', merge_logs: true, time: true },
// Strategy/entry/short-entry review experiments (2026-09-05): one idea per space under config/spaces/exp/, mutated around each
// coin's champion, NO --target override so a promotion must beat the incumbent on the unseen test window. The plain long/short
    { name: 'shoemoneyx-reverb', cwd, script: 'artisan', args: 'reverb:start --host=0.0.0.0 --port=8812', interpreter: 'php', autorestart: true, restart_delay: 5000, out_file: 'storage/logs/reverb.log', error_file: 'storage/logs/reverb.log', merge_logs: true, time: true },
    { name: 'shoemoneyx-serve', cwd, script: 'artisan', args: 'serve --host=0.0.0.0 --port=8811', interpreter: 'php', autorestart: true, out_file: 'storage/logs/serve.log', error_file: 'storage/logs/serve.log', merge_logs: true, time: true },
  ],
};
