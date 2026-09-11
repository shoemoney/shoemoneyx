SUI logo: https://github.com/trustwallet/assets/blob/master/blockchains/sui/info/logo.png

Other currency icons are supplied by the existing `cryptocurrency-icons` package. Interface and AI icons use the existing licensed Font Awesome Pro installation.

## Coinbase asset artwork — 2026-09-06

All five source PNGs were downloaded from the image links on Coinbase’s official asset pages and are stored as unmodified source bytes. Native artwork and colors are preserved; UI halos are rendered separately. The public Coinbase product endpoint confirms each symbol/name, but returned an empty `icon_url`, so the page-linked Coinbase CDN images are the recorded source.

- `hype.png` — Hyperliquid (HYPE), 320×320, 8,607 bytes. [Coinbase asset page](https://www.coinbase.com/price/hyperliquid); [original PNG](https://asset-metadata-service-production.s3.amazonaws.com/asset_icons/6a8c816c50549afbdb1a73933132c71d7aa26ba900d285e624d5a24ce9b068c4.png). SHA-256: `6a8c816c50549afbdb1a73933132c71d7aa26ba900d285e624d5a24ce9b068c4`.
- `near.png` — NEAR Protocol (NEAR), 96×96, 1,231 bytes. [Coinbase asset page](https://www.coinbase.com/price/near-protocol); [original PNG](https://dynamic-assets.coinbase.com/5ea79a9a3931318ea33126c7a8a0ff557dcfee5162010e148c271484810b3f512ca99f04d0f0d33c4dd857efb036edd1b7f6f5f3d8f9a977f513a8b3a3a3af64/asset_icons/59d6d2f03f37990397656133cf91c9397017f69652e0232f3f3b8850d18367cb.png). SHA-256: `13116a8c7754e72a980d442d2b568b99f203c214e4e0248466cad6a58f3b316b`.
- `ena.png` — Ethena (ENA), 320×320, 11,521 bytes. [Coinbase asset page](https://www.coinbase.com/price/ethena); [original PNG](https://asset-metadata-service-production.s3.amazonaws.com/asset_icons/b66fb3eb247864c68889a672ff33b4cf6b6d3269628517d28b6ea74fa264ae36.png). SHA-256: `b66fb3eb247864c68889a672ff33b4cf6b6d3269628517d28b6ea74fa264ae36`.
- `hbar.png` — Hedera (HBAR), 96×96, 1,348 bytes. [Coinbase asset page](https://www.coinbase.com/price/hedera); [original PNG](https://dynamic-assets.coinbase.com/9723f1c6d5f27b06241e43255beb6f36cd78640c20699eec1c4efe40c85f6fd78705ad1b41217f3cd88f933c408855bda98e2ff6f2c640e8b11f50138951bbc4/asset_icons/ad9328a7d74c7faf3c051f50ec614a58e6b4e04cd171c638a84d43d7c6d0f5ec.png). SHA-256: `937050208525a669202523a2f4de23aab5f422a465f503678fc8199fcb14e04d`.
- `ondo.png` — Ondo (ONDO), 800×800, 25,529 bytes. [Coinbase asset page](https://www.coinbase.com/price/ondo); [original PNG](https://asset-metadata-service-production.s3.amazonaws.com/asset_icons/622f85f43188981186c99e7307d84b6549285c1dec7afa1dd9a30c86e7c6d101.png). SHA-256: `622f85f43188981186c99e7307d84b6549285c1dec7afa1dd9a30c86e7c6d101`.

`resources/js/coinIcons.js` resolves these alongside the existing SUI override and bundled `cryptocurrency-icons` PNGs, for both DOM icons and Three.js textures. No runtime request to Coinbase is required.

ONDO’s original black mark is transparent, as is its [official favicon](https://ondo.finance/favicon.ico). A separate white circular backing in the interface/scene keeps that unchanged artwork readable on the black landing page. The PNG itself is not modified.
