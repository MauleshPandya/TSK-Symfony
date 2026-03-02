# Fund Transfer API

A production-grade fund transfer API built with **PHP 8.3**, **Symfony 7**, **MySQL 8**, and **Redis 7**.

## Architecture Decisions

### Why Pessimistic Locking?
The core concurrency challenge in fund transfers is preventing a double-spend race condition:
two concurrent transfers from the same account both passing the balance check before either commits.

```
Thread A: Read balance = $500, check OK
Thread B: Read balance = $500, check OK
Thread A: Debit $400 → balance = $100
Thread B: Debit $400 → balance = -$300 ← Money created from nothing
```

**Solution**: `SELECT ... FOR UPDATE` acquires a row-level exclusive lock before any read. Thread B blocks until Thread A commits or rolls back.

### Deadlock Prevention
If Thread A locks Account-1 then Account-2, while Thread B locks Account-2 then Account-1 — deadlock. **Fix**: always acquire locks in ascending ID order, regardless of transfer direction.

### Why bcmath Strings, Not Floats?
```php
var_dump(0.1 + 0.2 === 0.3); // false — float is 0.30000000000000004
var_dump(bcadd('0.1', '0.2', 2) === '0.30'); // true
```
All monetary values are stored and compared as decimal strings using `bcmath`.

### Why Idempotency Keys?
Network failures cause clients to retry. Without idempotency, a retry after a timeout could transfer funds twice. Every request carries a UUID `Idempotency-Key`. Redis caches the response for 24 hours — retries get the original response without re-executing.

### Redis Sliding Window Rate Limiter
Fixed-window rate limiters allow burst at window edges. The sliding window algorithm smooths this: the limit applies to any rolling 60-second period using Redis sorted sets.

---

## Prerequisites

- Docker & Docker Compose
- Git

---

## Setup

```bash
# 1. Clone and enter
git clone https://github.com/your-org/fund-transfer-api.git
cd fund-transfer-api

# 2. Copy environment config
cp .env .env.local
# Edit .env.local — set your API_KEYS (comma-separated)
# e.g. API_KEYS=my-secret-key-1,my-secret-key-2

# 3. Start all services
docker compose up -d

# 4. Install dependencies
docker compose exec app composer install

# 5. Run migrations
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

# 6. (Optional) Load sample data
docker compose exec app php bin/console doctrine:fixtures:load --no-interaction
```

The API is now running at **http://localhost:8080**.

---

## Running Tests

```bash
# All tests
docker compose exec app composer test

# Unit tests only (no database required)
docker compose exec app composer test:unit

# Integration tests
docker compose exec app composer test:integration

# Static analysis (PHPStan level 8)
docker compose exec app composer analyse

# Code style check
docker compose exec app composer cs-check
```

---

## API Reference

### Authentication
All endpoints require the `X-API-Key` header.

```
X-API-Key: your-api-key
```

---

### POST /api/v1/transfers

Initiate a fund transfer. **Idempotent** — safe to retry with the same `Idempotency-Key`.

**Headers**
| Header | Required | Description |
|---|---|---|
| `X-API-Key` | ✅ | API authentication key |
| `Idempotency-Key` | ✅ | Client-generated UUID v4. Retries with the same key return the original response. |
| `Content-Type` | ✅ | `application/json` |

**Request Body**
```json
{
  "from_account_id": "550e8400-e29b-41d4-a716-446655440001",
  "to_account_id":   "550e8400-e29b-41d4-a716-446655440002",
  "amount":          "250.00",
  "currency":        "USD",
  "description":     "Invoice #1042 payment"
}
```

| Field | Type | Validation |
|---|---|---|
| `from_account_id` | UUID | Required, must exist |
| `to_account_id` | UUID | Required, must exist, different from `from_account_id` |
| `amount` | string | Required, positive, max 2 decimal places |
| `currency` | string | Required, one of: `USD`, `EUR`, `GBP`, `JPY`, `CAD`, `AUD` |
| `description` | string | Optional, max 500 chars |

**Response: 201 Created**
```json
{
  "data": {
    "id": "7c9e6679-7425-40de-944b-e07fc1f90ae7",
    "from_account_id": "550e8400-e29b-41d4-a716-446655440001",
    "to_account_id": "550e8400-e29b-41d4-a716-446655440002",
    "amount": "250.00",
    "currency": "USD",
    "status": "completed",
    "description": "Invoice #1042 payment",
    "failure_reason": null,
    "created_at": "2024-01-15T10:30:00+00:00",
    "completed_at": "2024-01-15T10:30:00+00:00"
  },
  "meta": {
    "idempotency_key": "your-idempotency-key"
  }
}
```

**Idempotent Replay Response (200 OK)**

When an identical `Idempotency-Key` is reused, the original response is returned with header:
```
X-Idempotent-Replayed: true
```

---

### GET /api/v1/transfers/{id}

Retrieve a transfer by ID.

**Response: 200 OK** — same structure as create response.

---

### Error Responses

All errors follow a consistent structure:
```json
{
  "error": {
    "code": "INSUFFICIENT_FUNDS",
    "message": "Account 550e8400... has insufficient funds. Available: 50.00 USD, Required: 250.00 USD."
  }
}
```

| HTTP Status | Error Code | Description |
|---|---|---|
| 400 | `INVALID_JSON` | Malformed JSON body |
| 401 | `UNAUTHORIZED` | Missing or invalid API key |
| 402 | `INSUFFICIENT_FUNDS` | Source account balance too low |
| 404 | `ACCOUNT_NOT_FOUND` | Account ID does not exist |
| 404 | `TRANSFER_NOT_FOUND` | Transfer ID does not exist |
| 409 | `CONCURRENT_REQUEST` | Same idempotency key already in-flight |
| 422 | `MISSING_IDEMPOTENCY_KEY` | `Idempotency-Key` header absent |
| 422 | `INVALID_IDEMPOTENCY_KEY` | Key is not a valid UUID |
| 422 | `VALIDATION_ERROR` | Request body validation failed |
| 422 | `ACCOUNT_INACTIVE` | Account is deactivated |
| 429 | `RATE_LIMIT_EXCEEDED` | Too many requests from this account |
| 500 | `INTERNAL_ERROR` | Unexpected server error |

---

## Example cURL Commands

```bash
# Create a transfer
curl -X POST http://localhost:8080/api/v1/transfers \
  -H "Content-Type: application/json" \
  -H "X-API-Key: 6ad27511260e31c6377a2be8533d6fec9f0c4e0e19c72ec8" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{
    "from_account_id": "550e8400-e29b-41d4-a716-446655440001",
    "to_account_id":   "550e8400-e29b-41d4-a716-446655440002",
    "amount":          "100.00",
    "currency":        "USD",
    "description":     "Test transfer"
  }'

# Get a transfer by ID
curl http://localhost:8080/api/v1/transfers/TRANSFER-UUID-HERE \
  -H "X-API-Key: 6ad27511260e31c6377a2be8533d6fec9f0c4e0e19c72ec8"
```

---

## Project Structure

```
src/
├── Domain/                      Pure business logic — no framework dependencies
│   ├── Account/
│   │   ├── Account.php          Aggregate root — enforces all balance invariants
│   │   ├── Money.php            Immutable value object — bcmath precision
│   │   ├── AccountRepository.php   Interface (implemented in Infrastructure)
│   │   ├── InsufficientFundsException.php
│   │   ├── AccountInactiveException.php
│   │   └── AccountNotFoundException.php
│   └── Transfer/
│       ├── Transfer.php         Immutable ledger record
│       ├── TransferStatus.php   Enum: pending | completed | failed
│       └── TransferRepository.php
│
├── Application/                 Use case orchestration
│   └── Transfer/
│       ├── TransferCommand.php  Input DTO (readonly)
│       └── TransferHandler.php  Core logic — locks, transacts, retries on deadlock
│
├── Infrastructure/              Framework + external service implementations
│   ├── Persistence/
│   │   ├── DoctrineAccountRepository.php   Pessimistic locking implementation
│   │   └── DoctrineTransferRepository.php
│   ├── Redis/
│   │   ├── IdempotencyService.php   SET NX distributed lock + response cache
│   │   └── RedisRateLimiter.php     Sliding window via sorted sets
│   └── Security/
│       └── ApiKeyAuthenticator.php  Constant-time key comparison
│
└── UI/
    └── Api/
        ├── TransferController.php   Thin controller — auth, validate, delegate
        ├── Request/TransferRequest.php   Symfony Validator constraints
        └── Response/TransferResponse.php

tests/
├── Unit/Domain/         MoneyTest, AccountTest — fast, no DB
├── Unit/Application/    TransferHandlerTest — mocked dependencies
└── Integration/
    ├── Api/             TransferApiTest — full HTTP stack
    └── Transfer/        ConcurrentTransferTest — balance conservation
```

---

## Load Testing

For production load testing under concurrent conditions:

```bash
# Install k6 (https://k6.io)
# Then run a concurrent transfer scenario:
k6 run --vus 50 --duration 30s - <<'EOF'
import http from 'k6/http';
import { uuidv4 } from 'https://jslib.k6.io/k6-utils/1.4.0/index.js';

export default function () {
  http.post('http://localhost:8080/api/v1/transfers',
    JSON.stringify({
      from_account_id: '550e8400-e29b-41d4-a716-446655440001',
      to_account_id:   '550e8400-e29b-41d4-a716-446655440002',
      amount: '1.00',
      currency: 'USD'
    }),
    {
      headers: {
        'Content-Type': 'application/json',
        'X-API-Key': '6ad27511260e31c6377a2be8533d6fec9f0c4e0e19c72ec8',
        'Idempotency-Key': uuidv4(),
      }
    }
  );
}
EOF
```