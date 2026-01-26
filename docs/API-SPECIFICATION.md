# PaySentinel API Specification

## For Centralized Alert Delivery & License Management

**Version:** 1.0  
**Last Updated:** January 26, 2026  
**Author:** Technical Team

---

## Overview

This document specifies the API endpoints required on `paysentinel.caplaz.com` to support the PRO alert system with centralized SMS/Slack delivery and enhanced license management.

### Architecture Summary

```
┌─────────────────────────────────────────┐
│     WordPress Plugin                     │
│  (WooCommerce Payment Monitor)          │
│                                          │
│  1. Detects payment failure              │
│  2. Checks license tier                  │
│  3. POSTs to paysentinel.caplaz.com/api │
└────────────────┬─────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────┐
│   paysentinel.caplaz.com API            │
│                                          │
│  1. Validates license + quota            │
│  2. Routes to Twilio/Slack APIs          │
│  3. Tracks usage + updates quota         │
│  4. Returns delivery status              │
└────────────────┬─────────────────────────┘
                 │
         ┌───────┴────────┐
         ▼                ▼
   ┌─────────┐      ┌─────────┐
   │ Twilio  │      │  Slack  │
   │   API   │      │   API   │
   └─────────┘      └─────────┘
```

---

## API Endpoints

### 1. POST `/api/alerts`

**Purpose:** Centralized alert delivery for SMS, Slack, and enhanced email.

**Authentication:** License key in request body (validated against database)

**Rate Limiting:** 100 requests/minute per license key

#### Request

**Headers:**

```
Content-Type: application/json
```

**Body:**

```json
{
  "license_key": "XXXX-XXXX-XXXX-XXXX",
  "site_url": "https://example.com",
  "contact": {
    "email": "admin@example.com",
    "phone": "+1234567890",
    "slack_workspace": "T01234567"
  },
  "channels": ["email", "sms", "slack"],
  "alert": {
    "type": "low_success_rate",
    "gateway": "stripe",
    "severity": "high",
    "success_rate": 45.2,
    "failed_count": 65,
    "total_count": 120,
    "timestamp": "2026-01-26T10:30:00Z",
    "message": "Stripe success rate dropped to 45.20% (65/120 transactions failed)"
  }
}
```

**Field Descriptions:**

- `license_key` (string, required): Plugin license key
- `site_url` (string, required): WordPress site URL for tracking
- `contact` (object, required): Contact information
  - `email` (string, optional): Email address
  - `phone` (string, optional): Phone number with country code (E.164 format)
  - `slack_workspace` (string, optional): Slack workspace ID or webhook URL
- `channels` (array, required): Channels to send alert through
  - Valid values: `"email"`, `"sms"`, `"slack"`
- `alert` (object, required): Alert details
  - `type` (string): Alert type (`low_success_rate`, `gateway_down`, `gateway_error`)
  - `gateway` (string): Gateway ID (`stripe`, `paypal`, `square`, `wc_payments`)
  - `severity` (string): Severity level (`high`, `warning`, `info`, `critical`)
  - `success_rate` (float, optional): Success rate percentage
  - `failed_count` (int): Number of failed transactions
  - `total_count` (int): Total transactions
  - `timestamp` (string): ISO 8601 timestamp
  - `message` (string): Human-readable alert message

#### Response

**Success (200 OK):**

```json
{
  "success": true,
  "delivered": {
    "email": {
      "status": "sent",
      "message_id": "msg_abc123"
    },
    "sms": {
      "status": "sent",
      "message_id": "SM123abc"
    },
    "slack": {
      "status": "sent",
      "channel": "#alerts"
    }
  },
  "quota": {
    "sms_remaining": 85,
    "sms_limit": 100,
    "sms_reset_date": "2026-02-01"
  }
}
```

**Field Descriptions:**

- `success` (bool): Overall success status
- `delivered` (object): Per-channel delivery status
  - Each channel returns `status` (sent/failed) and optional metadata
- `quota` (object): Current quota information
  - `sms_remaining` (int): SMS remaining this billing period
  - `sms_limit` (int): Total SMS limit per billing period
  - `sms_reset_date` (string): Date when quota resets

**Error Responses:**

**401 Unauthorized:**

```json
{
  "error": "invalid_license",
  "message": "License key is invalid or expired",
  "code": "LICENSE_INVALID"
}
```

**402 Payment Required (Quota Exceeded):**

```json
{
  "error": "quota_exceeded",
  "message": "SMS quota exceeded for this billing period",
  "quota": {
    "sms_used": 100,
    "sms_limit": 100,
    "sms_reset_date": "2026-02-01"
  },
  "upgrade_url": "https://paysentinel.caplaz.com/upgrade"
}
```

**400 Bad Request:**

```json
{
  "error": "missing_contact_info",
  "message": "Phone number required for SMS alerts",
  "field": "contact.phone"
}
```

**403 Forbidden (Feature Not Available):**

```json
{
  "error": "feature_not_available",
  "message": "Slack alerts require Pro plan or higher",
  "current_plan": "starter",
  "required_plan": "pro",
  "upgrade_url": "https://paysentinel.caplaz.com/upgrade"
}
```

**500 Internal Server Error:**

```json
{
  "error": "delivery_failed",
  "message": "Failed to deliver alerts",
  "details": {
    "sms": "Twilio API error: Invalid phone number",
    "slack": "success",
    "email": "success"
  }
}
```

#### Processing Logic

1. **Validate License:**
   - Check license key exists in database
   - Verify license is active and not expired
   - Get license plan tier (starter/pro/agency)

2. **Check Feature Access:**
   - Email: Always allowed (Free+)
   - SMS: Starter+ plans
   - Slack: Pro+ plans
   - Return 403 if feature not available in plan

3. **Check Quota:**
   - If SMS requested, check monthly quota
   - Return 402 if quota exceeded
   - Allow grace period (e.g., 5% overage)

4. **Validate Contact Info:**
   - Ensure required contact info is present for requested channels
   - Validate phone number format (E.164)
   - Return 400 if contact info missing/invalid

5. **Send Alerts:**
   - **Email:** Send via your transactional email service (SendGrid/Mailgun/SES)
   - **SMS:** Send via Twilio API using your Twilio account
   - **Slack:** Post to Slack webhook or use Slack API

6. **Update Quota:**
   - Increment SMS usage counter for this license
   - Store delivery metadata (timestamp, status, message_id)

7. **Return Status:**
   - Return per-channel delivery status
   - Include updated quota information
   - Log any partial failures

#### Database Schema Recommendations

**Table: `license_alert_usage`**

```sql
CREATE TABLE license_alert_usage (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  license_key VARCHAR(255) NOT NULL,
  site_url VARCHAR(500),
  channel VARCHAR(50),
  alert_type VARCHAR(100),
  delivered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  status VARCHAR(50),
  message_id VARCHAR(255),
  error_message TEXT,
  INDEX idx_license_key (license_key),
  INDEX idx_delivered_at (delivered_at)
);
```

**Table: `license_quota`**

```sql
CREATE TABLE license_quota (
  license_key VARCHAR(255) PRIMARY KEY,
  sms_limit INT NOT NULL,
  sms_used INT DEFAULT 0,
  quota_reset_date DATE NOT NULL,
  last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

---

### 2. POST `/api/validate-license` (Enhanced)

**Purpose:** Validate license and return plan features + quota information.

**Changes from Current Implementation:**

- Add `plan` field in response
- Add `features` object with feature flags
- Add `quota` object with current usage

#### Request

**Headers:**

```
Content-Type: application/json
```

**Body:**

```json
{
  "license_key": "XXXX-XXXX-XXXX-XXXX",
  "site_url": "https://example.com"
}
```

#### Response

**Success (200 OK):**

```json
{
  "valid": true,
  "expiration_ts": 1740528000,
  "plan": "pro",
  "features": {
    "sms_alerts": 500,
    "slack_alerts": true,
    "better_email_delivery": true,
    "per_gateway_config": true,
    "advanced_analytics": true,
    "unlimited_gateways": true,
    "history_days": 90,
    "industry_benchmarks": true,
    "proactive_gateway_alerts": true
  },
  "quota": {
    "sms_used_this_month": 15,
    "sms_limit": 500,
    "sms_reset_date": "2026-02-01"
  }
}
```

**Field Descriptions:**

- `valid` (bool): License validity status
- `expiration_ts` (int): Unix timestamp of license expiration
- `plan` (string): Plan tier (`free`, `starter`, `pro`, `agency`)
- `features` (object): Feature flags and limits
  - Boolean features: `true` if available, `false` if not
  - Numeric features: Integer limit (e.g., `sms_alerts: 500`)
- `quota` (object): Current usage and limits
  - `sms_used_this_month` (int): SMS sent this billing period
  - `sms_limit` (int): Total SMS allowed per period
  - `sms_reset_date` (string): Date quota resets (YYYY-MM-DD)

**Error (401 Unauthorized):**

```json
{
  "valid": false,
  "error": "invalid_license",
  "message": "License key is invalid or expired"
}
```

#### Feature Matrix by Plan

| Feature                    | Free  | Starter | Pro   | Agency        |
| -------------------------- | ----- | ------- | ----- | ------------- |
| `sms_alerts`               | 0     | 100     | 500   | 1000 (shared) |
| `slack_alerts`             | false | false   | true  | true          |
| `better_email_delivery`    | false | true    | true  | true          |
| `per_gateway_config`       | false | false   | true  | true          |
| `advanced_analytics`       | false | false   | true  | true          |
| `unlimited_gateways`       | false | false   | true  | true          |
| `history_days`             | 30    | 30      | 90    | 90            |
| `industry_benchmarks`      | false | false   | true  | true          |
| `proactive_gateway_alerts` | false | false   | true  | true          |
| `multi_site_dashboard`     | false | false   | false | true          |
| `white_label_reports`      | false | false   | false | true          |

---

## Implementation Priority

### Phase 1 (MVP)

1. ✅ Enhanced `/api/validate-license` with plan and features
2. ✅ Basic `/api/alerts` endpoint with SMS delivery via Twilio
3. ✅ Quota tracking and enforcement

### Phase 2

4. Slack delivery via webhooks
5. Enhanced email delivery via SendGrid/Mailgun
6. Admin dashboard for monitoring delivery status

### Phase 3

7. Discord/Teams integration
8. Delivery analytics and reporting
9. Webhook for alert notifications

---

## Security Considerations

### License Validation

- Store hashed license keys in database
- Validate site_url matches licensed domain
- Implement brute-force protection on validation endpoint

### API Authentication

- License key is sufficient for MVP
- Consider adding HMAC signature in Phase 2
- Rate limit by IP + license key

### Data Privacy

- Do not log customer PII (transaction IDs, amounts, customer names)
- Only store aggregate metrics (success rates, failure counts)
- Allow opt-out of analytics via plugin setting

### Quota Management

- Enforce hard limits to prevent abuse
- Send warning emails at 80% quota usage
- Provide grace period for accidental overages

---

## Testing Endpoints

### POST `/api/alerts/test`

Test alert delivery without consuming quota.

**Request:**

```json
{
  "license_key": "XXXX-XXXX-XXXX-XXXX",
  "channel": "sms",
  "contact": {
    "phone": "+1234567890"
  }
}
```

**Response:**

```json
{
  "success": true,
  "message": "Test SMS sent successfully",
  "details": {
    "message_id": "SMtest123",
    "delivered_at": "2026-01-26T10:30:00Z"
  }
}
```

---

## Error Codes Reference

| Code                    | HTTP Status | Description                                |
| ----------------------- | ----------- | ------------------------------------------ |
| `LICENSE_INVALID`       | 401         | License key is invalid or expired          |
| `LICENSE_SUSPENDED`     | 403         | License suspended (payment failure, etc.)  |
| `FEATURE_NOT_AVAILABLE` | 403         | Feature not included in current plan       |
| `QUOTA_EXCEEDED`        | 402         | Monthly quota limit reached                |
| `MISSING_CONTACT_INFO`  | 400         | Required contact information missing       |
| `INVALID_PHONE_NUMBER`  | 400         | Phone number format invalid                |
| `DELIVERY_FAILED`       | 500         | Alert delivery failed (external API error) |
| `RATE_LIMIT_EXCEEDED`   | 429         | Too many requests                          |

---

## Monitoring & Observability

### Metrics to Track

- Alert delivery success/failure rates per channel
- Average API response time
- Quota usage per license
- License validation request volume
- Failed deliveries by reason

### Alerts for Operations

- SMS delivery failure rate > 5%
- Twilio API errors
- Quota exceeded for > 10% of licenses
- API response time > 2 seconds

---

## Migration from Direct Integration

### Plugin Changes Already Made

1. ✅ Removed Twilio credential fields from settings
2. ✅ Removed Slack webhook field from settings
3. ✅ Added `alert_phone_number` field
4. ✅ Added `alert_slack_workspace` field
5. ✅ Refactored `send_notifications()` to use `send_to_api()`
6. ✅ Added tier-based feature gating

### Backward Compatibility

- Legacy `send_sms_notification()` and `send_slack_notification()` methods maintained for test endpoints
- Existing test endpoints in plugin will call new API
- Old settings will be migrated on plugin update

---

## Next Steps

### For Website Development

1. Implement `/api/validate-license` enhancement
2. Implement `/api/alerts` endpoint
3. Set up Twilio account and obtain credentials
4. Create database tables for quota tracking
5. Implement quota reset cron job (monthly)
6. Set up monitoring and alerting

### For Plugin Development

1. ✅ Plugin code changes complete
2. Test API integration with staging environment
3. Add error handling for API failures
4. Implement quota warning UI in admin dashboard
5. Add "Connect Slack" OAuth flow UI
6. Update user documentation

---

## Questions for Discussion

1. **Slack Integration UX:** Should users enter Slack webhook URL directly, or implement OAuth flow through your website?
   - **Recommendation:** Start with webhook URL (simpler), add OAuth in Phase 2

2. **Email Delivery:** Should Free tier email also go through API for better deliverability?
   - **Recommendation:** Keep Free tier local (`wp_mail()`), route Starter+ through API

3. **Hard vs Soft Quota Limits:** Should API reject requests at quota, or allow overage with warning?
   - **Recommendation:** Hard limit with 5% grace period, email warnings at 80% usage

4. **Quota Reset:** Monthly on calendar date, or rolling 30-day window?
   - **Recommendation:** Calendar month reset (simpler billing)

5. **Delivery Retries:** Should API retry failed deliveries, or just return error to plugin?
   - **Recommendation:** API does single attempt, plugin shows admin notice for failures

---

## Appendix: Sample Alert Messages

### SMS Format (160 chars max)

```
HIGH ALERT: Stripe gateway success rate dropped to 45.2% (65/120 failed). Check dashboard: https://example.com/wp-admin
```

### Slack Format

```json
{
  "text": "🚨 *HIGH Payment Alert* 🚨",
  "attachments": [
    {
      "color": "#dc3545",
      "title": "Stripe Gateway Issue Detected",
      "fields": [
        { "title": "Success Rate", "value": "45.2%", "short": true },
        { "title": "Failed", "value": "65/120", "short": true },
        { "title": "Gateway", "value": "Stripe", "short": true },
        { "title": "Severity", "value": "HIGH", "short": true }
      ],
      "actions": [
        {
          "type": "button",
          "text": "View Dashboard",
          "url": "https://example.com/wp-admin/admin.php?page=wc-payment-monitor"
        }
      ]
    }
  ]
}
```

### Email Format (HTML)

```html
<h2>Payment Gateway Alert - HIGH</h2>
<p>Your Stripe payment gateway is experiencing issues.</p>
<div
  style="background: #f8d7da; padding: 15px; border-left: 4px solid #dc3545;">
  <strong>Success Rate:</strong> 45.2%<br />
  <strong>Failed Transactions:</strong> 65 out of 120<br />
  <strong>Time:</strong> January 26, 2026 10:30 AM
</div>
<a href="https://example.com/wp-admin/admin.php?page=wc-payment-monitor"
  >View Dashboard</a
>
```
