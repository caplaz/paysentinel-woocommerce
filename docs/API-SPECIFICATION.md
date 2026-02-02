# PaySentinel API Reference

## Overview

PaySentinel provides a REST API for license management, device tracking, alerts, and subscription management. All API endpoints require authentication and are rate-limited.

**Base URL**: `https://your-domain.com/api`

**Authentication**: Supabase JWT token (automatically handled by browser cookies for dashboard users)

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
