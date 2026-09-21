# Starter Templates

These ten strategies are generic, textbook starting points written against strategy schema v2. Each one is a self-contained technique, not anyone's personal trading edge, meant to be copied into your own strategy file and tuned. See [`docs/STRATEGY_SCHEMA_V2.md`](STRATEGY_SCHEMA_V2.md) for the full field-by-field spec.

### RSI Mean Reversion (`smx-rsi-mean-reversion-v2.json`)

A 1-hour mean-reversion play. It enters when RSI(14) drops under 30 alongside a liquidity filter, betting on a bounce back toward the mean. It exits on a two-rung ladder at +1.5% and +3% (50/50), and gets out regardless via a 3%-from-average stop or a 48-hour time stop. No re-entry.

### Breakout + Volume Confirmation (`smx-breakout-volume-v2.json`)

Enters when the 6-hour price change exceeds +5% with a 2x volume surge and buyers clearly dominant (buy/sell ratio above 1.2), the combination that separates a real breakout from a fakeout. It exits on a partial ladder at +3%/+6% (20/20) plus a trailing runner (activates at 6%, gives back 2%) to let the rest of the move run. The stop sits at 4% from average with a 72-hour time stop. No re-entry, this is a clean breakout play.

### EMA Cross Trend Follow (`smx-ema-cross-trend-v2.json`)

Enters when the fast EMA(9) crosses above the slow EMA(21) while ADX(14) above 20 confirms real trend strength. It banks a little on a small ladder at +4%/+8% (15/15), lets a trailing runner ride the rest (activate 8%, giveback 3%), and closes early if the EMAs cross back down. The stop is 5% from average. No re-entry.

### Bollinger Band Reversion (`smx-bollinger-reversion-v2.json`)

Enters when price tags the lower Bollinger Band (bb(20,2).pos under 0.05) with RSI(14) under 40 confirming oversold conditions. It exits on a ladder at +1.2%/+2.4% (40/60), with a 2.5%-from-average stop and a 36-hour time stop. No re-entry.

### VWAP Reversion (`smx-vwap-reversion-v2.json`)

A 15-minute intraday play. It enters when price drops below the session VWAP with RSI(14) under 35, then exits at a single +1% target that sells the whole position, or earlier if price crosses back above VWAP. The stop is 1.5% from average with a 12-hour time stop. No re-entry.

### MACD Momentum Flip (`smx-macd-momentum-v2.json`)

Enters the moment the MACD(12,26,9) line crosses above its signal line, catching momentum as the histogram flips positive. It exits on a ladder at +2.5%/+5% (30/30) plus a trailing runner (activate 5%, giveback 1.5%), and closes early on the reverse cross. The stop sits at 3.5% from average with a 60-hour time stop. No re-entry.

### Volume Spike Scanner (`smx-volume-spike-v2.json`)

A 15-minute scanner. It enters on a volume surge above 3x normal with buyers dominant (buy/sell ratio above 1.5), unusual activity worth acting on fast. It exits on a tight ladder at +1.5%/+3% (50/50) because volume spikes fade quickly, backed by a 2%-from-average stop and a short 6-hour time stop.  No re-entry.

### Trend Follow (Trailing Stop Only) (`smx-trend-trailing-stop-v2.json`)

A 6-hour trend-follower. It enters when EMA(20) crosses above EMA(50) with ADX(14) above 25 confirming the trend. There is no ladder at all, the entire position rides a trailing stop (activate at 3%, giveback 2%), letting winners run uncapped. A 6%-from-average stop serves as the fail-safe. No re-entry.

### DCA Grid Buyer (`smx-dca-grid-v2.json`)

A 6-hour grid strategy. It enters a small position on a -3% 24-hour pullback, then uses `adds[]` (not `reentry`) to average down further at -3%/-6%/-9% from the average, each add sized 100% of the initial cost. It exits with a single target at +2% from the average, selling the whole position at once. The stop is wide at 15% from average, since the grid expects drawdown along the way. No re-entry, this is what `adds[]` is for.

### Re-Entry 101 (Teaching Template) (`smx-reentry-101-v2.json`)

Deliberately minimal: one liquidity signal, one ladder rung at +2% that sells half the position, then one re-entry rule that buys back a $50 lot once price retraces 1% toward the average, and cashes that lot back out the moment it turns green after fees. The stop is 3% from average. It exists to teach the entry-to-re-entry-to-cash-out mechanic in isolation, with nothing else competing for attention.

## Summary

| Template | Technique | Entry signal | Exit style | Re-entry? |
|---|---|---|---|---|
| RSI Mean Reversion | Mean reversion | RSI(14) < 30 | Ladder +1.5%/+3% (50/50) | No |
| Breakout + Volume Confirmation | Breakout | 6h change > +5%, 2x volume surge, buy/sell ratio > 1.2 | Ladder +3%/+6% (20/20) + trailing runner | No |
| EMA Cross Trend Follow | Trend following | EMA(9) crosses above EMA(21), ADX(14) > 20 | Ladder +4%/+8% (15/15) + trailing runner | No |
| Bollinger Band Reversion | Mean reversion | bb(20,2).pos < 0.05, RSI(14) < 40 | Ladder +1.2%/+2.4% (40/60) | No |
| VWAP Reversion | Mean reversion | Price below VWAP, RSI(14) < 35 | Single target +1% (100%) | No |
| MACD Momentum Flip | Momentum | MACD(12,26,9) crosses above signal | Ladder +2.5%/+5% (30/30) + trailing runner | No |
| Volume Spike Scanner | Unusual activity | Volume surge > 3x, buy/sell ratio > 1.5 | Ladder +1.5%/+3% (50/50) | No |
| Trend Follow (Trailing Stop Only) | Trend following | EMA(20) crosses above EMA(50), ADX(14) > 25 | Trailing runner only, no ladder | No |
| DCA Grid Buyer | DCA / grid | -3% 24h pullback | Single target +2% (100%) | No (uses `adds[]`) |
| Re-Entry 101 | Education | Liquidity filter only | Ladder +2% (50%) | Yes |
