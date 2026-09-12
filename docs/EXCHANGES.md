# Exchanges

Everything the desk does against a venue goes through `app/Exchange/Contracts/Exchange.php` and its
`MarketData`, `Account`, and `Credentials` contracts. There are two kinds of adapter behind that door.

The app's own `/exchanges` page (`GET /api/exchanges`) lists every registered adapter — native and
enabled ccxt — with its capabilities and a conformance badge. See CONTRIBUTING.md's "Badge rules"
section for exactly what earns `passed` versus `unverified`.

## Native adapters

One hand-written class tree per venue, under `app/Exchange/<Venue>/`, registered in
`config/exchanges.php` under `drivers`. Coinbase is the only native adapter today. Native adapters
get the full surface: perps, shorts, a websocket tape, and venue-specific behaviour the unified
layer cannot express.

## The ccxt adapter

`app/Exchange/Ccxt/CcxtExchange.php` is one generic adapter that speaks
[ccxt](https://github.com/ccxt/ccxt)'s unified REST API, so every REST exchange ccxt supports (**110** as of ccxt 4.5.57, full roster below) is reachable without
writing a class. The ccxt exchange id *is* the
adapter id, so `kraken` in ccxt is `kraken` here.

### Enabling one

`CCXT_EXCHANGES` is a comma-separated list of ccxt ids the registry will hand out. Anything not on
the list is unknown, so a typo fails loudly instead of silently reaching a venue nobody chose.

```dotenv
CCXT_EXCHANGES=binance,kraken,bybit,okx,kucoin
EXCHANGE=kraken
```

`EXCHANGE` picks which adapter is live. It accepts a native driver id or an enabled ccxt id; a
native driver always wins over a ccxt id of the same name.

### Commands

```bash
php artisan exchange:list                 # every registered adapter and what it supports
php artisan exchange:credentials kraken   # prompt for and store API credentials, encrypted
```

`exchange:credentials` writes one row per exchange to `exchange_credentials`, with the values
encrypted at rest by Laravel's `encrypted:array` cast. ccxt takes three fields: `apiKey` and
`secret` are required, `password` is the passphrase only some venues use — leave it blank
otherwise. Market data needs none of them: an adapter with no stored row builds a keyless client
and public endpoints keep working, which is what paper mode runs on.

### What v1 does not do

The ccxt adapter is **spot only**.

- `capabilities()` reports `perps: false` and `shorts: false`. ccxt's `has['swap']` is true for
  several of these venues, but reaching swap markets needs per-venue market selection and a
  position model this adapter does not have, so claiming the capability would send callers down a
  path that throws.
- `executor(true)` throws rather than returning something that cannot fill.
- `perpSpec()` returns null, and `futuresBalance()` / `futuresPositions()` return empty.
- `capabilities()` reports `websocket: false` always. This is a REST adapter; there is no tape.
- `keyPermissions()` returns empty. ccxt has no unified permissions endpoint, and inventing one
  would be worse than admitting the gap.

Anything that needs perps, shorts, or a live websocket tape wants a native adapter.

### Behaviour worth knowing

- **Errors.** Every ccxt call is wrapped, so callers catch one type, `App\Exchange\ExchangeException`,
  instead of importing ccxt's error tree. The venue's own message is preserved.
- **Book depth.** `book()` never forwards the requested depth to the venue, because venues disagree
  about which values are legal — KuCoin rejects anything but 20 or 100. The venue's own book is
  fetched and cut to the requested depth locally.
- **Precision.** ccxt reports precision either as a tick size or as a count of decimal places,
  depending on the venue's `precisionMode`. Both are normalised to the tick string
  (`base_increment` / `quote_increment`) that `ProductSync` stores.
- **Trade windows.** ccxt's `since`/`limit` pair has no upper bound, so `trades()` trims the far
  edge of the window itself.
- **Products.** `products()` returns active spot markets only, with `price` and `volume_24h` left
  null — filling them would cost one network call per product.
- **Geo-blocks are real.** Some venues refuse whole regions at the HTTP layer. Binance answers a US
  address with `451`, which surfaces as an `ExchangeException` and a `healthy()` of false.

## Supported out of the box via ccxt 🌐

Generated from `\ccxt\Exchange::$exchanges` in the pinned ccxt release. Spot market data and spot orders work on every one of these once you add its id to `CCXT_EXCHANGES`. Native adapters (Coinbase) are listed separately above.

<details>
<summary>All 110 ccxt exchanges</summary>

| id | Exchange |
|---|---|
| `aftermath` | AftermathFinance |
| `alpaca` | Alpaca |
| `apex` | Apex |
| `arkham` | ARKHAM |
| `ascendex` | AscendEX |
| `aster` | Aster |
| `backpack` | Backpack |
| `bequant` | Bequant |
| `bigone` | BigONE |
| `binance` | Binance |
| `binancecoinm` | Binance COIN-M |
| `binanceus` | Binance US |
| `binanceusdm` | Binance USDⓈ-M |
| `bingx` | BingX |
| `bit2c` | Bit2C |
| `bitbank` | bitbank |
| `bitbns` | Bitbns |
| `bitfinex` | Bitfinex |
| `bitflyer` | bitFlyer |
| `bitget` | Bitget |
| `bithumb` | Bithumb |
| `bitmart` | BitMart |
| `bitmex` | BitMEX |
| `bitopro` | BitoPro |
| `bitrue` | Bitrue |
| `bitso` | Bitso |
| `bitstamp` | Bitstamp |
| `bitteam` | BIT.TEAM |
| `bittrade` | BitTrade |
| `bitvavo` | Bitvavo |
| `blockchaincom` | Blockchain.com |
| `blofin` | BloFin |
| `btcbox` | BtcBox |
| `btcmarkets` | BTC Markets |
| `btcturk` | BTCTurk |
| `bullish` | Bullish |
| `bybit` | Bybit |
| `bybiteu` | Bybit EU |
| `bydfi` | BYDFi |
| `cex` | CEX.IO |
| `coinbase` | Coinbase Advanced |
| `coinbaseadvanced` | Coinbase Advanced |
| `coinbaseexchange` | Coinbase Exchange |
| `coinbaseinternational` | Coinbase International |
| `coincheck` | coincheck |
| `coinex` | CoinEx |
| `coinmate` | CoinMate |
| `coinmetro` | Coinmetro |
| `coinone` | CoinOne |
| `coinsph` | Coins.ph |
| `coinspot` | CoinSpot |
| `cryptocom` | Crypto.com |
| `cryptomus` | Cryptomus |
| `deepcoin` | DeepCoin |
| `delta` | Delta Exchange |
| `deribit` | Deribit |
| `derive` | derive |
| `digifinex` | DigiFinex |
| `dydx` | dYdX |
| `exmo` | EXMO |
| `extended` | Extended |
| `fmfwio` | FMFW.io |
| `foxbit` | Foxbit |
| `gate` | Gate |
| `gemini` | Gemini |
| `grvt` | GRVT |
| `hashkey` | HashKey Global |
| `hibachi` | Hibachi |
| `hitbtc` | HitBTC |
| `hollaex` | HollaEx |
| `htx` | HTX |
| `huobi` | HTX |
| `hyperliquid` | Hyperliquid |
| `independentreserve` | Independent Reserve |
| `indodax` | INDODAX |
| `kraken` | Kraken |
| `krakenfutures` | Kraken Futures |
| `kucoin` | KuCoin |
| `kucoinfutures` | KuCoin Futures |
| `latoken` | Latoken |
| `lbank` | LBank |
| `lighter` | Lighter |
| `luno` | luno |
| `mercado` | Mercado Bitcoin |
| `mexc` | MEXC Global |
| `modetrade` | Mode Trade |
| `myokx` | MyOKX (EEA) |
| `ndax` | NDAX |
| `novadax` | NovaDAX |
| `okx` | OKX |
| `okxus` | OKX (US) |
| `onetrading` | One Trading |
| `p2b` | p2b |
| `pacifica` | Pacifica |
| `paradex` | Paradex |
| `paymium` | Paymium |
| `phemex` | Phemex |
| `poloniex` | Poloniex |
| `tokocrypto` | Tokocrypto |
| `toobit` | Toobit |
| `upbit` | Upbit |
| `wavesexchange` | Waves.Exchange |
| `weex` | Weex |
| `whitebit` | WhiteBit |
| `woo` | WOO X |
| `woofipro` | WOOFI PRO |
| `xt` | XT |
| `yobit` | YoBit |
| `zaif` | Zaif |
| `zebpay` | Zebpay |

</details>
