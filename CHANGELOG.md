# Changelog

## 0.3.0 — pending release

- Refuse AWS bootstrap credentials until trusted HTTPS activation matches the
  requested host, including existing sessions and header-based password setup.
- Verify owner passwords without bcrypt suffix truncation, make concurrent
  password changes atomic, and prevent CLI reads of password material.
- Exercise browser password setup, rotation, session and token revocation, CSRF
  and SDK tool execution against disposable local and AWS-style test profiles.
- Run the strategy agent through the pinned PHP LangChain/LangGraph SDK while
  retaining the desk's OpenRouter transport, authorization and usage auditing.
- Preserve complete tool conversations under call limits and tool failures;
  keep hub registration credentials in the Hub panel.
- Complete MCP initialization and pin background backtests and reviews to the
  same immutable strategy version.
- Pin position management to its execution venue and preserve reconciled fills
  without changing positions belonging to a different venue.
- Serialize cycle lock ownership and heartbeat updates across restarts; recover
  stale backtests using primary-key locks and a fresh heartbeat check.
- Keep cycle heartbeats current during scanning and reconciliation, stop new
  work promptly on termination, and fail scheduler startup if mutex cleanup
  cannot complete after bounded retries.
- Fence displaced owners before new entry submissions and paper debits, while
  allowing already-submitted orders to finish reconciliation and bookkeeping.
- Isolate arena positions, fills, cycle statistics and landing data from the
  main desk. Historical cycle ownership is recovered only when linked fills
  establish it; older ambiguous runs retain their previous attribution.
- Include AMI TLS provisioning and renewal hardening, with readable certificate
  guidance on the initial login screen, recovery from interrupted certificate
  layout conversion, and hash-verified certificate-tool dependencies.
- Update Node-based build stages, PHPUnit and dotenv; refresh vulnerable npm
  dependencies and run the complete MariaDB suite without exclusions.

Apply database migrations during upgrade. No AWS Marketplace visibility change,
AMI publication, release tag or production deployment is implied by this entry.
