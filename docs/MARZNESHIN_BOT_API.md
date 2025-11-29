# Marzneshin Bot API Compatibility Guide

This document describes the API endpoints compatible with the [marzneshin.php](https://github.com/xshayank/mirza_pro/blob/main/marzneshin.php) bot script and other Marzneshin-style clients.

## Overview

VPNMarket provides a Marzneshin-compatible API layer that allows existing Marzneshin bot scripts and clients to work with VPNMarket panels. The API supports both:

1. **Legacy API Key Flow**: Using the plaintext API key as both username and password
2. **Admin Credential Flow**: Using generated admin credentials (username/password)

## Authentication

### POST /api/admins/token

Authenticates and returns an access token.

**Request Format:**
- Content-Type: `application/x-www-form-urlencoded`
- Accept: `application/json`

**Request Body:**
```
username=<username>&password=<password>
```

**Authentication Methods:**

#### Method 1: Legacy API Key (recommended for simplicity)
Set both `username` and `password` to the same API key value:
```
username=vpnm_abc123...&password=vpnm_abc123...
```

**Response:**
```json
{
  "access_token": "vpnm_abc123...",
  "token_type": "bearer"
}
```

#### Method 2: Admin Credentials
Use the generated admin username and password:
```
username=mz_abc123&password=your_admin_password
```

**Response:**
```json
{
  "access_token": "mzsess_...",
  "token_type": "bearer",
  "expires_in": 3600
}
```

**Note:** Session tokens (`mzsess_*`) are stored in the server cache and expire after 60 minutes.

### Error Responses

| Status | Response | Cause |
|--------|----------|-------|
| 401 | `{"detail": "Invalid credentials"}` | Invalid username/password |
| 401 | `{"detail": "API key has been revoked"}` | The API key was revoked |
| 401 | `{"detail": "API key has expired"}` | The API key has expired |
| 403 | `{"detail": "This endpoint requires a Marzneshin-style API key"}` | Using Falco-style key |
| 403 | `{"detail": "API access is not enabled for this account"}` | Reseller API access disabled |
| 403 | `{"detail": "Reseller account is not active"}` | Reseller account suspended |

## Using the Access Token

All subsequent API calls must include the access token in the Authorization header:

```
Authorization: Bearer <access_token>
```

## API Endpoints

### User Management

#### GET /api/users/{username}
Get user details.

**Response:**
```json
{
  "username": "user123",
  "data_limit": 10737418240,
  "used_traffic": 1073741824,
  "expire_date": "2024-12-31T23:59:59Z",
  "status": "active",
  "note": "User note",
  "service_ids": [1, 2]
}
```

#### POST /api/users
Create a new user.

**Request Body Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `username` | string | Yes | User identifier (alphanumeric, underscore, hyphen) |
| `data_limit` | integer | Yes | Traffic limit in bytes (e.g., 10737418240 for 10 GB) |
| `expire_strategy` | string | No | One of: `fixed_date` (default), `start_on_first_use`, `never` |
| `expire_date` | string | If `expire_strategy` = `fixed_date` | Expiry date in ISO-8601 format (e.g., "2024-12-31T23:59:59Z") |
| `expire` | integer | Alternative to `expire_date` | Unix timestamp (seconds) for expiry |
| `usage_duration` | integer | If `expire_strategy` = `start_on_first_use` | Duration in seconds from first connection |
| `service_ids` | array | No | **MUST be an array** (use `[]` if empty, **never** `null`) |
| `data_limit_reset_strategy` | string | No | Reset strategy: `no_reset` (default), `day`, `week`, `month`, `year` |
| `note` | string | No | User note (max 500 chars), forwarded to the panel |

**Example: fixed_date Strategy with expire_date**
```json
{
  "username": "newuser",
  "data_limit": 10737418240,
  "expire_strategy": "fixed_date",
  "expire_date": "2024-12-31T23:59:59Z",
  "service_ids": [1, 2],
  "data_limit_reset_strategy": "no_reset",
  "note": "Created via API"
}
```

**Example: fixed_date Strategy with unix timestamp**
```json
{
  "username": "newuser",
  "data_limit": 10737418240,
  "expire_strategy": "fixed_date",
  "expire": 1735689599,
  "service_ids": [],
  "note": "User with timestamp expiry"
}
```

**Example: start_on_first_use Strategy**
```json
{
  "username": "newuser",
  "data_limit": 5368709120,
  "expire_strategy": "start_on_first_use",
  "usage_duration": 2592000,
  "service_ids": [],
  "note": "30 days from first use"
}
```

**Example: never Strategy**
```json
{
  "username": "newuser",
  "data_limit": 0,
  "expire_strategy": "never",
  "service_ids": [],
  "note": "Unlimited user"
}
```

**⚠️ Important Notes:**
- **`service_ids` MUST be an array.** If you don't want to specify services, use an empty array `[]`. Sending `null` will cause a 500 error on the remote panel.
- For `fixed_date` strategy, you must provide either `expire_date` (ISO-8601 string) or `expire` (unix timestamp in seconds).
- For `start_on_first_use` strategy, `usage_duration` (in seconds) is required.
- The `note` field is forwarded to the remote panel's note field.
- `data_limit` is cast to integer internally.

**Expire Strategies:**
- `fixed_date`: Expires on the specified `expire_date` or `expire` timestamp
- `start_on_first_use`: Expires after `usage_duration` seconds from first connection
- `never`: Never expires (translated to 10 years for Eylandoo panels)

#### PUT /api/users/{username}
Update user settings.

**Request:**
```json
{
  "data_limit": 21474836480,
  "expire_date": "2025-01-31T23:59:59Z",
  "service_ids": [1, 2],
  "note": "Updated limits"
}
```

#### DELETE /api/users/{username}
Delete a user.

**Response:**
```json
{
  "message": "User deleted successfully"
}
```

### User Actions

#### POST /api/users/{username}/enable
Enable a disabled user.

#### POST /api/users/{username}/disable
Disable a user.

#### POST /api/users/{username}/reset
Reset user traffic usage to 0.

#### POST /api/users/{username}/revoke_sub
Revoke and regenerate subscription URL.

**Alias:** `POST /api/users/{username}/revoke_subscription`

#### GET /api/users/{username}/usage
Get user traffic usage details.

**Response:**
```json
{
  "username": "user123",
  "used_traffic": 1073741824,
  "node_usages": []
}
```

#### GET /api/users/{username}/subscription
Get user subscription URL.

**Response:**
```json
{
  "username": "user123",
  "subscription_url": "https://panel.example.com/sub/abc123"
}
```

### System Stats

#### GET /api/system/stats/users
Get aggregate user statistics.

**Response:**
```json
{
  "total": 100,
  "active": 85,
  "disabled": 10,
  "total_used_traffic": 1099511627776
}
```

#### GET /api/system
Get system-wide statistics.

**Response:**
```json
{
  "version": "1.0.0",
  "total_user": 100,
  "users_active": 85,
  "users_disabled": 10,
  "users_limited": 3,
  "users_expired": 2,
  "incoming_bandwidth": 1099511627776
}
```

### Services

#### GET /api/services
List available services (nodes for Eylandoo panels, services for Marzneshin panels).

**Response:**
```json
{
  "items": [
    {
      "id": 1,
      "name": "Service 1"
    }
  ],
  "total": 1
}
```

### Admin Info

#### GET /api/admin
#### GET /api/admins/current
Get current admin information.

**Response:**
```json
{
  "username": "admin_prefix",
  "is_sudo": false,
  "users_usage": 1099511627776
}
```

## Bulk Operations

#### GET /api/users/expired
List expired users.

#### DELETE /api/users/expired
Delete all expired users.

#### POST /api/users/reset
Reset all users' traffic usage.

## Username Prefix Lookup

The API supports a fallback lookup mechanism for usernames. If a user is not found by their exact `external_username`, the API will search by the stored `prefix` field. This is useful when:

1. The panel generates a different username than requested
2. The bot stores the original requested username

The lookup order:
1. Exact match on `external_username`
2. Fallback match on `prefix` (returns most recent if multiple matches)

## Bot Configuration

To configure the marzneshin.php bot to work with VPNMarket:

1. **Legacy Flow (Recommended):**
   - Set `username_panel` to your VPNMarket API key
   - Set `password_panel` to the same API key

2. **Admin Credential Flow:**
   - Set `username_panel` to your admin username (starts with `mz_`)
   - Set `password_panel` to your admin password

## Troubleshooting

### "Invalid credentials" after successful token
- Ensure the cache driver is properly configured (Redis recommended for production)
- Check that the session token hasn't expired (60-minute TTL)
- For server restarts, clients need to obtain a new token

### Bot returns null
- Check that the API key has the required scopes
- Verify the reseller account is active with API enabled
- Confirm the panel is properly configured

### Username not found
- The API uses prefix fallback lookup
- Check if the user was created with a different external username
- Verify the user belongs to your reseller account

## API Key Scopes

Marzneshin-style API keys support the following scopes:

| Scope | Description |
|-------|-------------|
| `services:list` | List services/nodes |
| `users:create` | Create users |
| `users:read` | Read user details |
| `users:update` | Update user settings |
| `users:delete` | Delete users |
| `subscription:read` | Read subscription URLs |
| `nodes:list` | List nodes |

## Rate Limiting

API keys have configurable rate limits (default: 60 requests per minute). When exceeded:

**Response:** HTTP 429
```json
{
  "detail": "Rate limit exceeded. Please try again later."
}
```

The `Retry-After` header indicates when to retry.
