# Security Policy

## Reporting a vulnerability

Email **security@shoemoney.com** with details and reproduction steps. Do not open a public issue for security problems. You will get an acknowledgement within 72 hours.

## Scope

- Credential handling (exchange keys, OpenRouter keys, session tokens)
- Order execution paths that could place unintended live orders
- Authentication and authorization on the web and API surfaces
- Anything that lets one user read or act on another user's data

## Your own keys

- Never commit `.env`. It is ignored by default.
- Use exchange API keys with the narrowest permissions your use requires. Paper trading needs read-only market data.
- Rotate keys immediately if you suspect exposure.
