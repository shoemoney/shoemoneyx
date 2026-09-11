#!/usr/bin/env bash
# Synthesizes the "new champion" voice clips for the Optimizer page in Jeremy's cloned voice.
# TTS host: 192.168.1.5, mlx-audio Qwen3-TTS server on :8200 (see the jeremy-voiceover-video skill).
# Never starts the server — it must already be up.
set -euo pipefail

TTS_HOST="shoemoney@192.168.1.5"
TTS_URL="http://192.168.1.5:8200"
REMOTE_DIR="Services/VoiceStudioLaravel"
REMOTE_OUT="out/champion"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="$REPO_ROOT/public/audio/champion"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

if ! curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$TTS_URL/v1/models" | grep -q 200; then
    echo "TTS server at $TTS_URL is not reachable. Not starting it — bring it up manually first." >&2
    exit 1
fi

declare -A COINS=(
    [BTC]="Bitcoin" [ETH]="Ethereum" [SOL]="Solana" [XRP]="XRP" [LINK]="Chainlink"
    [DOGE]="Dogecoin" [ADA]="Cardano" [AVAX]="Avalanche" [SUI]="Sui" [LTC]="Litecoin"
    [ZEC]="Zcash" [HYPE]="Hyperliquid" [NEAR]="Near" [BCH]="Bitcoin Cash" [ENA]="Ethena"
    [XLM]="Stellar" [HBAR]="Hedera" [ONDO]="Ondo"
)
SIDES=(long short)

# Build a task list of "<remote wav filename>\t<text>" and send it to the TTS host as one job.
TASKS="$TMP_DIR/tasks.tsv"
: > "$TASKS"
for ticker in "${!COINS[@]}"; do
    name="${COINS[$ticker]}"
    slug="$(echo "$ticker" | tr '[:upper:]' '[:lower:]')"
    for side in "${SIDES[@]}"; do
        printf '%s-%s.wav\tOH HELL YA! %s %s, new champion!\n' "$slug" "$side" "$name" "$side" >> "$TASKS"
    done
done
printf 'generic.wav\tOH HELL YA! New champion!\n' >> "$TASKS"

echo "Synthesizing $(wc -l < "$TASKS" | tr -d ' ') clips on $TTS_HOST..."
scp -q "$TASKS" "$TTS_HOST:$REMOTE_DIR/champion-tasks.tsv"

ssh "$TTS_HOST" "cd $REMOTE_DIR && mkdir -p $REMOTE_OUT && \
    while IFS=\$'\t' read -r fname text; do \
        echo \"  \$fname\"; \
        ./say jeremy \"\$text\" --no-play --out $REMOTE_OUT/\$fname; \
    done < champion-tasks.tsv"

echo "Pulling clips back..."
mkdir -p "$TMP_DIR/wav"
scp -q "$TTS_HOST:$REMOTE_DIR/$REMOTE_OUT/*.wav" "$TMP_DIR/wav/"

mkdir -p "$OUT_DIR"
count=0
for wav in "$TMP_DIR"/wav/*.wav; do
    base="$(basename "$wav" .wav)"
    ffmpeg -nostdin -loglevel error -y -i "$wav" -ac 1 -codec:a libmp3lame -b:a 48k "$OUT_DIR/$base.mp3"
    count=$((count + 1))
done

total_kb=$(du -ck "$OUT_DIR"/*.mp3 | tail -1 | cut -f1)
echo "Wrote $count clips to $OUT_DIR (total $((total_kb / 1024)) MB, ${total_kb} KB)."
