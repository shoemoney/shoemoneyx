<?php

use Illuminate\Support\Facades\Broadcast;

// Optimizer/backtest firehose: reachable only through /broadcasting/auth, which AuthorizeDeskBroadcast has already
// gated on the desk session (see bootstrap/app.php).
Broadcast::channel('optimizer', fn ($user) => $user !== null);
