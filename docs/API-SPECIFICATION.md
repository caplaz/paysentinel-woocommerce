# PaySentinel API Reference

## Overview

PaySentinel provides a REST API for license management, device tracking, alerts, and subscription management.

**Base URL**: `https://paysentinel.caplaz.com/api`

**Authentication**: Two methods supported:

- **HMAC**: For WordPress plugin requests (X-PaySentinel-Signature) - Required for all endpoints except `/activate-license`
- **SessionAuth**: For dashboard web requests (Supabase session cookie)

---

## Authentication

### HMAC Authentication (WordPress Plugin)

All API endpoints except `/activate-license` require HMAC-SHA256 authentication.

**Required headers:**

- `X-PaySentinel-License-Key`: The license key
- `X-PaySentinel-Timestamp`: Unix timestamp in seconds
- `X-PaySentinel-Signature`: HMAC-SHA256 hex digest
- `X-PaySentinel-Site-Url`: Site URL (optional, depends on endpoint)

**Signature generation:**

```
message = timestamp + (request_body ? "." + request_body : "")
signature = HMAC-SHA256(site_secret, message)
```

_Note: If request_body is empty, do not include the dot._

**2-Step Registration Process:**

1. **Step 1: Activate License** (`POST /activate-license`)
   - No HMAC required (bootstrap endpoint)
   - Returns `site_secret` for subsequent requests
   - Registers site with license

2. **Step 2: Validate License** (`POST /validate-license`)
   - HMAC required using `site_secret` from Step 1
   - Validates license and returns plan/features/quota

### Session Authentication (Dashboard)

Dashboard endpoints require an authenticated user session via Supabase JWT token (automatically handled by browser cookies).

### Error Responses

```json
{
  "error": "Error message",
  "details": "Additional error details (optional)"
}
```

---

## Endpoints

### 1. Activate License (Step 1)

Register a site URL to a license key and receive an HMAC secret. This is a one-time bootstrap operation.

**Endpoint**: `POST /activate-license`

**Authentication**: None required (bootstrap endpoint)

**Rate Limit**: 20 requests/minute per IP

**Request Body**:

```json
{
  "license_key": "XXXX-XXXX-XXXX-XXXX",
  "site_url": "https://example.com"
}
```

**Response** (200):

```json
{
  "site_registration": {
    "registered": true,
    "site_secret": "64-character-hex-string-for-hmac"
  },
  "license_info": {
    "plan": "professional",
    "expires_at": "2025-12-31T23:59:59Z"
  },
  "message": "License is valid. Site is registered."
}
```

**Error Responses**:

- `400`: Bad request (missing fields or invalid URL)
- `403`: License invalid, expired, or device limit exceeded
- `429`: Rate limit exceeded
- `500`: Internal server error

---

### 2. Validate License (Step 2)

Validate an existing license activation. Requires HMAC authentication using the `site_secret` from activation.

**Endpoint**: `POST /validate-license`

**Authentication**: HMAC required

**Rate Limit**: 20 requests/minute per IP

**Request Body**:

```json
{
  "license_key": "XXXX-XXXX-XXXX-XXXX",
  "site_url": "https://example.com"
}
```

**Response** (200):

```json
{
  "expiration_ts": "2026-02-20T20:57:11.000Z",
  "plan": "professional",
  "features": {
    "email_alerts": true,
    "sms_alerts": true,
    "slack_alerts": true,
    "max_sites": 10
  },
  "quota": {
    "sms": {
      "used": 45,
      "limit": 100,
      "remaining": 55,
      "reset_date": "2026-02-01T00:00:00Z"
    }
  },
  "message": "License is valid."
}
```

**Error Responses**:

- `401`: Unauthorized (Invalid HMAC signature)
- `403`: License is invalid or expired
- `429`: Rate limit exceeded
- `500`: Internal server error

---

### 3. Sync License & Quotas

Fetches current plan, features, and quota limits for a registered site.

**Endpoint**: `GET /sync`

**Authentication**: HMAC required

**Rate Limit**: 30 requests/minute

**Response** (200):

```json
{
  "valid": true,
  "plan": "professional",
  "features": {
    "email_alerts": true,
    "sms_alerts": true,
    "slack_alerts": true,
    "max_sites": 10
  },
  "quota": {
    "sms": {
      "used": 45,
      "limit": 100,
      "remaining": 55,
      "reset_date": "2026-02-01T00:00:00Z"
    }
  },
  "expires_at": "2025-12-31T23:59:59Z",
  "integrations": {
    "slack": {
      "id": "550e8400-e29b-41d4-a716-446655440000"
    }
  }
}
```

**Error Responses**:

- `401`: Unauthorized (invalid HMAC signature)
- `403`: Invalid or expired license
- `500`: Internal server error

---

### 4. Send Alerts

Triggers an SMS or Slack alert based on the provided data.

**Endpoint**: `POST /alerts`

**Authentication**: HMAC required

**Rate Limit**: Varies based on plan and quota

**Request Body** (SMS):

```json
{
  "license_key": "XXXX-XXXX-XXXX-XXXX",
  "alert_type": "SMS",
  "recipient": "+1234567890",
  "message": "Payment failed for Order #12345",
  "site_url": "https://example.com",
  "data": {
    "order_id": "12345",
    "error_code": "PAYMENT_DECLINED"
  }
}
```

**Request Body** (Slack):

```json
{
  "license_key": "XXXX-XXXX-XXXX-XXXX",
  "alert_type": "SLACK",
  "integration_id": "550e8400-e29b-41d4-a716-446655440000",
  "message": "Payment failed for Order #12345",
  "site_url": "https://example.com"
}
```

**Response** (200) - SMS:

```json
{
  "success": true,
  "message": "Alert delivered successfully",
  "quota": {
    "sms": {
      "used": 46,
      "limit": 100,
      "remaining": 54,
      "reset_date": "2026-02-01T00:00:00Z"
    }
  }
}
```

**Response** (200) - Slack:

```json
{
  "success": true,
  "message": "Alert delivered successfully"
}
```

**Error Responses**:

- `400`: Bad request (missing required fields)
- `401`: Unauthorized (invalid HMAC signature)
- `403`: Quota exceeded or invalid license
- `404`: Slack integration not found
- `429`: Rate limit exceeded
- `500`: Internal server error
- `502`: Integration service error (Slack/SMS provider failure)

---

### 5. Check Slack Integration Status

Verifies if a Slack integration is still valid and active.

**Endpoint**: `GET /integrations/slack/status?integration_id={id}`

**Authentication**: HMAC required

**Rate Limit**: 30 requests/minute

**Response** (200):

```json
{
  "active": true,
  "workspace_name": "My Workspace",
  "channel_name": "#alerts",
  "connected_at": "2026-01-15T10:30:00Z",
  "last_alert_sent": "2026-01-30T14:20:00Z"
}
```

**Error Responses**:

- `400`: Missing integration_id parameter
- `401`: Unauthorized (invalid HMAC signature)
- `403`: Unauthorized access to this integration
- `404`: Integration not found
- `500`: Internal server error

---

### 6. Test Slack Integration

Sends a test message to verify Slack integration is working.

**Endpoint**: `POST /integrations/slack/test`

**Authentication**: HMAC required

**Request Body**:

```json
{
  "integration_id": "550e8400-e29b-41d4-a716-446655440000",
  "message": "This is a test alert from PaySentinel"
}
```

**Response** (200):

```json
{
  "success": true,
  "message": "Test alert sent successfully"
}
```

**Error Responses**:

- `400`: Missing required fields
- `401`: Unauthorized (invalid HMAC signature)
- `403`: Unauthorized access to this integration
- `404`: Integration not found
- `500`: Internal server error or integration secret missing
- `502`: Slack API error

---

### 7. Disconnect Slack Integration

Removes a Slack integration and deletes its secure tokens.

**Endpoint**: `DELETE /integrations/slack`

**Authentication**: HMAC required

**Request Body**:

```json
{
  "integration_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

**Response** (200):

```json
{
  "success": true,
  "message": "Integration removed successfully"
}
```

**Error Responses**:

- `400`: Missing integration_id
- `401`: Unauthorized (invalid HMAC signature)
- `403`: Unauthorized access to this integration
- `404`: Integration not found
- `500`: Internal server error

---

## Dashboard Endpoints (Session Auth)

### 8. List User Licenses

Retrieve all licenses associated with the authenticated user.

**Endpoint**: `GET /licenses`

**Authentication**: Required (user session)

**Rate Limit**: 30 requests/minute

**Response** (200):

```json
{
  "licenses": [
    {
      "id": "lic_1234567890",
      "key": "XXXX-XXXX-XXXX-XXXX",
      "status": "active",
      "expires_at": "2026-12-31T23:59:59Z",
      "price_id": "price_1234567890",
      "product_id": "prod_1234567890",
      "created_at": "2026-01-01T00:00:00Z",
      "activated_devices": [
        {
          "id": "device_123",
          "name": "https://example.com",
          "created_at": "2026-01-15T10:30:00Z"
        }
      ]
    }
  ]
}
```

**Error Responses**:

- `401`: Unauthorized (not logged in)
- `500`: Internal server error

---

### 9. Deactivate Site

Deactivates a specific site (device) from a license.

**Endpoint**: `DELETE /sites`

**Authentication**: Required (user session)

**Rate Limit**: 10 requests/minute

**Request Body**:

```json
{
  "device_id": "https://example.com",
  "license_key": "XXXX-XXXX-XXXX-XXXX"
}
```

**Response** (200):

```json
{
  "success": true
}
```

**Error Responses**:

- `400`: Missing device_id or license_key
- `401`: Unauthorized
- `404`: Device not found
- `500`: Internal server error

---

### 10. List User Integrations

Returns all integrations (Slack, etc.) for the authenticated user.

**Endpoint**: `GET /integrations`

**Authentication**: Required (user session)

**Rate Limit**: 30 requests/minute

**Response** (200):

```json
{
  "integrations": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "name": "Production Alerts",
      "type": "SLACK",
      "site_url": "https://example.com",
      "license_key": "XXXX-XXXX-XXXX-XXXX",
      "is_active": true,
      "created_at": "2026-01-15T10:30:00Z"
    }
  ]
}
```

**Error Responses**:

- `401`: Unauthorized
- `500`: Internal server error

---

### 11. Create Checkout Session

Create a Stripe checkout session for subscription purchase.

**Endpoint**: `POST /checkout`

**Authentication**: Required (user session)

**Rate Limit**: 5 requests/minute

**Request Body**:

```json
{
  "priceId": "price_1234567890"
}
```

**Response** (200):

```json
{
  "url": "https://checkout.stripe.com/pay/cs_test_...",
  "session_id": "cs_test_1234567890"
}
```

**Error Responses**:

- `400`: Missing priceId
- `401`: Unauthorized
- `409`: User already has an active subscription
- `500`: Checkout creation failed

---

## Rate Limiting

All endpoints implement rate limiting to prevent abuse:

| Endpoint                | Limit      | Window      |
| ----------------------- | ---------- | ----------- |
| `/activate-license`     | 20 req/min | Per IP      |
| `/validate-license`     | 20 req/min | Per IP      |
| `/sync`                 | 30 req/min | Per license |
| `/alerts`               | 10 req/min | Per license |
| `/integrations/slack/*` | 30 req/min | Per license |
| `/licenses`             | 30 req/min | Per user    |
| `/sites` (DELETE)       | 10 req/min | Per user    |
| `/integrations` (GET)   | 30 req/min | Per user    |
| `/checkout`             | 5 req/min  | Per user    |

Rate limit headers are included in responses:

```
X-RateLimit-Limit: 30
X-RateLimit-Remaining: 29
X-RateLimit-Reset: 1640995200
Retry-After: 60 (when exceeded)
```

---

## Error Codes

### HTTP Status Codes

- `200`: Success
- `201`: Created
- `400`: Bad Request (validation error)
- `401`: Unauthorized (invalid auth/HMAC)
- `403`: Forbidden (insufficient permissions/plan or invalid license)
- `404`: Not Found
- `429`: Too Many Requests (rate limited)
- `500`: Internal Server Error
- `502`: Bad Gateway (integration service error)

### Common Error Messages

- `"Missing required fields: field1, field2"`
- `"Invalid license key"`
- `"License expired"`
- `"Invalid HMAC signature"`
- `"Rate limit exceeded. Maximum X requests per minute"`
- `"Alert type not available on your plan"`
- `"SMS quota exceeded"`
- `"Integration not found"`

---

## WordPress Plugin Integration Example

### Initial Setup (2-Step Process)

```php
// Step 1: Activate license (no HMAC required)
$activation_response = wp_remote_post('https://paysentinel.caplaz.com/api/activate-license', [
    'body' => json_encode([
        'license_key' => 'XXXX-XXXX-XXXX-XXXX',
        'site_url' => 'https://example.com'
    ]),
    'headers' => ['Content-Type' => 'application/json']
]);

$activation_data = json_decode(wp_remote_retrieve_body($activation_response), true);
$site_secret = $activation_data['site_registration']['site_secret'];

// Save site_secret for future requests
update_option('site_secret', $site_secret);

// Step 2: Validate license (HMAC required)
$timestamp = time();
$body = json_encode([
    'license_key' => 'XXXX-XXXX-XXXX-XXXX',
    'site_url' => 'https://example.com'
]);
$message = $timestamp . '.' . $body;
$signature = hash_hmac('sha256', $message, $site_secret);

$validation_response = wp_remote_post('https://paysentinel.caplaz.com/api/validate-license', [
    'body' => $body,
    'headers' => [
        'Content-Type' => 'application/json',
        'X-PaySentinel-License-Key' => 'XXXX-XXXX-XXXX-XXXX',
        'X-PaySentinel-Timestamp' => $timestamp,
        'X-PaySentinel-Signature' => $signature,
        'X-PaySentinel-Site-Url' => 'https://example.com'
    ]
]);
```

---

## Support

For API support or questions:

- Check the [Testing Documentation](TESTING.md) for endpoint test examples
- Review the [Project Overview](PROJECT_OVERVIEW.md) for architecture details
- Contact support at support@paysentinel.com

---

_Last updated: February 5, 2026_

---

## Authentication

### Requirements

- **Session-based**: Most endpoints require an authenticated user session
- **License Validation**: Some endpoints require a valid license key
- **Rate Limiting**: Applied per endpoint (see individual endpoint docs)

### Error Responses

```json
{
  "error": "Error message",
  "status": 401
}
```

---

## Endpoints

### 1. Get User Licenses

Retrieve all licenses associated with the authenticated user.

**Endpoint**: `GET /api/licenses`

**Authentication**: Required (user session)

**Rate Limit**: 30 requests/minute

**Response** (200):

```json
{
  "licenses": [
    {
      "id": "lic_1234567890",
      "key": "XXXX-XXXX-XXXX-XXXX",
      "status": "active",
      "expires_at": "2026-12-31T23:59:59Z",
      "price_id": "price_1234567890",
      "product_id": "prod_1234567890",
      "created_at": "2026-01-01T00:00:00Z",
      "sites": [
        {
          "id": "site_123",
          "name": "example.com",
          "created_at": "2026-01-15T10:30:00Z"
        }
      ]
    }
  ]
}
```

**Error Responses**:

- `401`: Unauthorized (not logged in)
- `500`: Internal server error

---

### 2. Validate License

Validate a license key and register a site URL.

**Endpoint**: `POST /api/validate-license`

**Authentication**: None required

**Rate Limit**: 20 requests/minute per IP

**Request Body**:

```json
{
  "license_key": "XXXX-XXXX-XXXX-XXXX",
  "site_url": "https://example.com"
}
```

**Response** (200):

```json
{
  "expiration_ts": "2026-02-20T20:57:11.000Z",
  "plan": "pro",
  "features": {
    "sites_limit": 10,
    "sms_alerts": true,
    "slack_alerts": true,
    "api_access": true
  },
  "quota": {
    "sms": {
      "used": 45,
      "limit": 100,
      "remaining": 55,
      "reset_date": "2026-02-01T00:00:00Z"
    }
  },
  "site_registration": {
    "registered": true
  },
  "message": "License is valid. Site is registered."
}
```

**Notes on Response Format**:

- `expiration_ts`: ISO 8601 date string (not Unix timestamp as per specification)
- No top-level `valid` field - success (200) indicates license is valid
- `plan`: Lowercase plan name derived from `price_id`
- `quota`: SMS usage tracking (null if no quota initialized)

**Error Responses**:

- `400`: Missing required fields or invalid URL format
- `403`: Invalid license key, expired license, or device limit exceeded
- `429`: Rate limit exceeded
- `500`: Internal server error

**Error Response Format**:

```json
{
  "error": "License expired",
  "details": "Devices registered: 5" // Optional additional info
}
```

---

### 3. Manage Connected Sites

**Endpoint**: `DELETE /api/sites`

**Authentication**: Required (user session)

**Rate Limit**: 10 requests/minute

**Request Body**:

```json
{
  "device_id": "device_123",
  "license_key": "XXXX-XXXX-XXXX-XXXX"
}
```

**Response** (200):

```json
{
  "success": true
}
```

**Error Responses**:

- `400`: Missing device_id or license_key
- `401`: Unauthorized
- `404`: Device not found (via PayBee API)
- `500`: Internal server error

---

### 4. Send Alerts

Send SMS or Slack notifications for license events.

**Endpoint**: `POST /api/alerts`

**Authentication**: License key validation

**Rate Limit**: 10 requests/minute per license

**Request Body**:

```json
{
  "license_key": "XXXX-XXXX-XXXX-XXXX",
  "alert_type": "SMS",
  "recipient": "+1234567890",
  "message": "License validation failed for site.com",
  "site_url": "https://site.com",
  "data": {
    "error_code": "INVALID_LICENSE",
    "attempt_count": 3
  }
}
```

**SMS Alert Body** (alternative):

```json
{
  "license_key": "XXXX-XXXX-XXXX-XXXX",
  "alert_type": "SMS",
  "recipient": "+1234567890",
  "message": "Alert message here"
}
```

**Slack Alert Body** (alternative):

```json
{
  "license_key": "XXXX-XXXX-XXXX-XXXX",
  "alert_type": "SLACK",
  "integration_id": "int_1234567890",
  "message": "Alert message here"
}
```

**Response** (200) - SMS:

```json
{
  "delivered": true,
  "channel": "SMS",
  "message_id": "SM1234567890",
  "quota": {
    "used": 1,
    "limit": 100,
    "remaining": 99,
    "reset_date": "2026-02-01T00:00:00Z"
  }
}
```

**Response** (200) - Slack:

```json
{
  "delivered": true,
  "channel": "SLACK"
}
```

**Error Responses**:

- `400`: Missing required fields or invalid alert_type
- `401`: Invalid license key or expired license
- `403`: Alert type not available on current plan, or Slack integration disabled
- `404`: Slack integration not found
- `429`: Rate limit exceeded
- `500`: Alert delivery failed

---

### 5. Create Checkout Session

Create a Stripe checkout session for subscription purchase.

**Endpoint**: `POST /api/checkout`

**Authentication**: Required (user session)

**Rate Limit**: 5 requests/minute

**Request Body**:

```json
{
  "priceId": "price_1234567890"
}
```

**Response** (200):

```json
{
  "url": "https://checkout.stripe.com/pay/cs_test_...",
  "session_id": "cs_test_1234567890"
}
```

**Error Responses**:

- `400`: Missing priceId
- `401`: Unauthorized
- `500`: Checkout creation failed

---

### 6. Update Subscription

Change the user's subscription plan.

**Endpoint**: `POST /api/update-subscription`

**Authentication**: Required (user session)

**Rate Limit**: 3 requests/minute

**Request Body**:

```json
{
  "priceId": "price_1234567890"
}
```

**Response** (200):

```json
{
  "message": "Subscription updated",
  "subscriptionId": "sub_1234567890",
  "licenseId": "lic_1234567890",
  "status": "active"
}
```

**Error Responses**:

- `400`: Missing priceId
- `401`: Unauthorized
- `500`: Subscription update failed

---

### 7. Manage Integrations

Get or create external service integrations (Slack webhooks, etc.).

**Endpoint**: `GET /api/integrations`

**Authentication**: Required (user session)

**Rate Limit**: 30 requests/minute

**Response** (200):

```json
{
  "integrations": [
    {
      "id": "int_1234567890",
      "name": "Production Alerts",
      "type": "slack",
      "webhook_url": "https://hooks.slack.com/...",
      "created_at": "2026-01-15T10:30:00Z",
      "last_used": "2026-01-30T14:20:00Z"
    }
  ]
}
```

**Endpoint**: `POST /api/integrations`

**Request Body**:

```json
{
  "name": "Production Alerts",
  "type": "slack",
  "webhook_url": "https://hooks.slack.com/services/..."
}
```

**Response** (200):

```json
{
  "integration": {
    "id": "int_1234567890",
    "name": "Production Alerts",
    "type": "slack",
    "webhook_url": "https://hooks.slack.com/...",
    "created_at": "2026-01-31T12:00:00Z"
  }
}
```

**Error Responses**:

- `400`: Missing required fields
- `401`: Unauthorized
- `500`: Database error

---

### 8. Email Subscription

Subscribe to the PaySentinel waitlist (marketing endpoint).

**Endpoint**: `POST /api/subscribe`

**Authentication**: None required

**Rate Limit**: 5 requests/minute per IP

**Request Body**:

```json
{
  "email": "user@example.com"
}
```

**Response** (200):

```json
{
  "success": true,
  "message": "Successfully subscribed!"
}
```

**Error Responses**:

- `400`: Invalid email address
- `500`: Email sending failed

---

## Rate Limiting

All endpoints implement rate limiting to prevent abuse:

| Endpoint                   | Limit      | Window      |
| -------------------------- | ---------- | ----------- |
| `/api/licenses`            | 30 req/min | Per user    |
| `/api/validate-license`    | 20 req/min | Per IP      |
| `/api/alerts`              | 10 req/min | Per license |
| `/api/checkout`            | 5 req/min  | Per user    |
| `/api/update-subscription` | 3 req/min  | Per user    |
| `/api/integrations`        | 30 req/min | Per user    |
| `/api/subscribe`           | 5 req/min  | Per IP      |

Rate limit headers are included in responses:

```
X-RateLimit-Limit: 30
X-RateLimit-Remaining: 29
X-RateLimit-Reset: 1640995200
Retry-After: 60 (when exceeded)
```

---

## Error Codes

### HTTP Status Codes

- `200`: Success
- `201`: Created
- `400`: Bad Request (validation error)
- `401`: Unauthorized (invalid auth/license)
- `403`: Forbidden (insufficient permissions/plan)
- `404`: Not Found
- `429`: Too Many Requests (rate limited)
- `500`: Internal Server Error

### Common Error Messages

- `"Missing required fields: field1, field2"`
- `"Invalid license key"`
- `"License expired"`
- `"Rate limit exceeded. Maximum X requests per minute"`
- `"Alert type not available on your plan"`
- `"Failed to send email"`

---

## SDK Examples

### JavaScript/Node.js

```javascript
// Validate a license
const response = await fetch("/api/validate-license", {
  method: "POST",
  headers: { "Content-Type": "application/json" },
  body: JSON.stringify({
    license_key: "XXXX-XXXX-XXXX-XXXX",
    site_url: "https://mysite.com",
  }),
});

const result = await response.json();
if (result.valid) {
  console.log("License is valid!", result.license);
}
```

### cURL Examples

```bash
# Validate license
curl -X POST /api/validate-license \
  -H "Content-Type: application/json" \
  -d '{
    "license_key": "XXXX-XXXX-XXXX-XXXX",
    "site_url": "https://mysite.com"
  }'

# Send SMS alert
curl -X POST /api/alerts \
  -H "Content-Type: application/json" \
  -d '{
    "license_key": "XXXX-XXXX-XXXX-XXXX",
    "alert_type": "SMS",
    "recipient": "+1234567890",
    "message": "License validation failed"
  }'
```

---

## Webhooks

PaySentinel can send webhooks to your application for license events:

### Supported Events

- `license.created`
- `license.expired`
- `license.renewed`
- `device.activated`
- `device.deactivated`
- `quota.exceeded`

### Webhook Payload

```json
{
  "event": "license.created",
  "data": {
    "license_id": "lic_1234567890",
    "license_key": "XXXX-XXXX-XXXX-XXXX",
    "user_email": "user@example.com",
    "price_id": "price_1234567890",
    "created_at": "2026-01-31T12:00:00Z"
  },
  "timestamp": "2026-01-31T12:00:00Z"
}
```

Configure webhooks in your PayBee dashboard or through the integrations API.

---

## Support

For API support or questions:

- Check the [Testing Documentation](TESTING.md) for endpoint test examples
- Review the [Project Overview](PROJECT_OVERVIEW.md) for architecture details
- Contact support at support@paysentinel.com

---

_Last updated: January 31, 2026_
