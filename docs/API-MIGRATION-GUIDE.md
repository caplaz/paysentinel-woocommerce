# PaySentinel API Migration Guide

## Overview

This document outlines the changes made to implement the new 2-step registration process and HMAC authentication requirements for the PaySentinel API.

## Key Changes

### 1. Two-Step Registration Process

The license activation process now requires two distinct steps:

#### Step 1: Activate License (`/activate-license`)

- **No HMAC required** - This is the bootstrap endpoint
- Registers the site with the license
- Returns `site_secret` (64-character hex string) for HMAC signing
- **Endpoint**: `POST https://paysentinel.caplaz.com/api/activate-license`

**Request:**

```json
{
  "license_key": "XXXX-XXXX-XXXX-XXXX",
  "site_url": "https://example.com"
}
```

**Response:**

```json
{
  "site_registration": {
    "registered": true,
    "site_secret": "64-character-hex-string"
  },
  "license_info": {
    "plan": "professional",
    "expires_at": "2025-12-31T23:59:59Z"
  },
  "message": "License is valid. Site is registered."
}
```

#### Step 2: Validate License (`/validate-license`)

- **HMAC required** - Uses `site_secret` from Step 1
- Validates the license and returns plan/features/quota
- **Endpoint**: `POST https://paysentinel.caplaz.com/api/validate-license`

**Request Headers:**

```
Content-Type: application/json
X-PaySentinel-License-Key: XXXX-XXXX-XXXX-XXXX
X-PaySentinel-Timestamp: 1234567890
X-PaySentinel-Signature: hmac-sha256-hex-digest
X-PaySentinel-Site-Url: https://example.com
```

**HMAC Signature Generation:**

```php
$timestamp = time();
$body = json_encode($request_data);
$message = $timestamp . '.' . $body;
$signature = hash_hmac('sha256', $message, $site_secret);
```

### 2. HMAC Authentication Required

All API endpoints now require HMAC authentication **except** `/activate-license`:

- ✅ `/activate-license` - No HMAC (bootstrap)
- 🔒 `/validate-license` - HMAC required
- 🔒 `/sync` - HMAC required
- 🔒 `/alerts` - HMAC required
- 🔒 `/integrations/slack/status` - HMAC required
- 🔒 `/integrations/slack/test` - HMAC required
- 🔒 `/integrations/slack` (DELETE) - HMAC required

### 3. Code Changes

#### New Methods in `WC_Payment_Monitor_License`

1. **`activate_license($license_key, $site_url)`**
   - New method for Step 1 (activation)
   - No HMAC required
   - Returns `site_secret` for subsequent requests

2. **`validate_license($license_key, $site_url, $action)`**
   - Updated to use HMAC authentication
   - Requires `site_secret` to be stored
   - Uses `make_authenticated_request()` helper

3. **`save_and_validate_license($license_key)`**
   - Updated to perform 2-step process:
     1. Call `activate_license()` to get `site_secret`
     2. Call `validate_license()` with HMAC

#### New Constants

```php
public const API_ENDPOINT_ACTIVATE  = 'https://paysentinel.caplaz.com/api/activate-license';
public const API_ENDPOINT_VALIDATE  = 'https://paysentinel.caplaz.com/api/validate-license';
public const API_ENDPOINT_SYNC      = 'https://paysentinel.caplaz.com/api/sync';
public const API_ENDPOINT_ALERTS    = 'https://paysentinel.caplaz.com/api/alerts';
```

#### Updated Methods

- **`make_authenticated_request()`** - Already implemented, now used for all authenticated endpoints
- **`sync_license()`** - Updated to use `API_ENDPOINT_SYNC` constant
- **Alerts class** - Updated to use `API_ENDPOINT_ALERTS` constant

### 4. Database/Options Storage

The following WordPress options are used:

- `wc_payment_monitor_license_key` - License key
- `wc_payment_monitor_license_status` - Status (valid/invalid/unknown)
- `wc_payment_monitor_license_data` - License data (plan, features, quota)
- `wc_payment_monitor_site_secret` - **NEW** - HMAC secret from activation
- `wc_payment_monitor_site_registered` - Site registration status
- `wc_payment_monitor_site_registration_data` - Registration metadata
- `wc_payment_monitor_last_check` - Last validation timestamp

### 5. Error Handling

#### New Error Codes

- **401 Unauthorized** - Invalid HMAC signature
  - User message: "Invalid HMAC signature. Please re-validate your license."
  - Action: Re-activate license to get new `site_secret`

- **403 Forbidden** - License invalid/expired or device limit exceeded
  - User message: Varies based on specific error
  - Action: Check license status or contact support

#### Error Response Format

```json
{
  "error": "Error message",
  "details": "Additional error details (optional)"
}
```

### 6. Migration Path for Existing Installations

For sites that have already activated their license using the old single-step process:

1. **Automatic Re-activation**: The plugin will automatically detect missing `site_secret` and trigger re-activation
2. **User Action**: Users may need to re-enter their license key in settings
3. **Backward Compatibility**: The old `validate_license()` method signature is maintained

### 7. Testing Checklist

- [ ] Test fresh license activation (2-step process)
- [ ] Test license validation with HMAC
- [ ] Test license sync with HMAC
- [ ] Test alert sending with HMAC
- [ ] Test Slack integration endpoints with HMAC
- [ ] Test error handling for invalid HMAC
- [ ] Test error handling for missing `site_secret`
- [ ] Test migration from old to new API

### 8. Security Considerations

1. **`site_secret` Storage**
   - Stored in WordPress options (database)
   - Should be treated as sensitive credential
   - Never exposed in API responses or logs

2. **HMAC Signature**
   - Prevents request tampering
   - Includes timestamp to prevent replay attacks
   - Uses SHA-256 for cryptographic strength

3. **Rate Limiting**
   - All endpoints have rate limits
   - Activation endpoint: 20 req/min per IP
   - Validation endpoint: 20 req/min per IP
   - Other endpoints: Varies (see API docs)

## Implementation Timeline

- ✅ Updated `WC_Payment_Monitor_License` class
- ✅ Added `activate_license()` method
- ✅ Updated `validate_license()` method
- ✅ Updated `save_and_validate_license()` method
- ✅ Updated `sync_license()` method
- ✅ Updated alerts class to use HMAC
- ✅ Updated API documentation
- ⏳ Testing and validation
- ⏳ Deployment to production

## Support

For questions or issues related to the API migration:

- Review the [API Specification](API-SPECIFICATION.md)
- Check the [Testing Documentation](TESTING.md)
- Contact support at support@paysentinel.com

---

_Last updated: February 5, 2026_
