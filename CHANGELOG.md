# Changelog

All notable changes to this SDK are documented in this file.

## 0.2.0 — 2026-04-26

**Breaking change** in `verify_webhook_signature` / `verifyWebhookSignature` /
`verifySignature` (function name varies by language) to support the v2 webhook
envelope.

### What changed
The FintraPay v2 webhook envelope (April 23, 2026) signs
`timestamp + "\n" + body` with HMAC-SHA256, **not just the body**. Versions
prior to 0.2.0 of this SDK signed the raw body only and would reject every
legitimate v2 delivery. **0.2.0 fixes this**.

### Migration
- Add a `timestamp` argument when verifying a webhook. Pass the
  `X-FintraPay-Timestamp` header value.
- The verifier also rejects deliveries older than 5 minutes by default to
  defeat replay attacks. Tunable per-language (see below).

### Per-language signatures (v0.2.0)

**Python**
```python
from xfintrapay import verify_webhook_signature

verify_webhook_signature(
    raw_body=request.data,
    signature=request.headers["X-FintraPay-Signature"],
    webhook_secret=WEBHOOK_SECRET,
    timestamp=request.headers["X-FintraPay-Timestamp"],   # NEW
    max_age_seconds=300,                                  # default 5 min
)
```

**Node.js**
```js
const { verifyWebhookSignature } = require('fintrapay');

verifyWebhookSignature(
  rawBody,
  req.headers['x-fintrapay-signature'],
  WEBHOOK_SECRET,
  { timestamp: req.headers['x-fintrapay-timestamp'], maxAgeSeconds: 300 }
);
```

**Go**
```go
import "github.com/Fintra-Ltd/fintrapay-go"

ok := fintrapay.VerifyWebhookSignature(
    body,
    r.Header.Get("X-FintraPay-Signature"),
    webhookSecret,
    r.Header.Get("X-FintraPay-Timestamp"),  // NEW; pass "" only for legacy v1
)
```

**PHP**
```php
use FintraPay\Webhook;

Webhook::verifySignature(
    $rawBody,
    $_SERVER['HTTP_X_FINTRAPAY_SIGNATURE'],
    $webhookSecret,
    $_SERVER['HTTP_X_FINTRAPAY_TIMESTAMP'],   // NEW
    300                                       // max age in seconds
);
```

**Java**
```java
import io.fintrapay.Webhook;

Webhook.verifySignature(
    rawBody,
    request.getHeader("X-FintraPay-Signature"),
    WEBHOOK_SECRET,
    request.getHeader("X-FintraPay-Timestamp")   // NEW
);
```

### Backwards compatibility
For each language, passing an empty/absent timestamp falls back to legacy
v1 (raw-body) signing. Use this only if you're verifying historical webhook
deliveries; all live FintraPay deliveries are v2.

## 0.1.0 — 2026-03-29

Initial release.
