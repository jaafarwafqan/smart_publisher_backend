# Social Accounts API (Sprint B3)

## Base
- Prefix: `/api/v1`
- Auth: `Authorization: Bearer <token>`

## Providers Catalog
- `GET /catalog/social-providers`
- Permission: `social-accounts.view`

## List Accounts
- `GET /users/{user}/social-accounts`
- Permission: `social-accounts.view`

## Start OAuth Authorization
- `POST /users/{user}/social-accounts/authorize`
- Permission: `social-accounts.create`
- Body:
```json
{
  "provider": "facebook",
  "redirect_uri": "https://your-app/callback",
  "scopes": ["pages_manage_posts", "pages_read_engagement"]
}
```
- Response contains `state` and `authorize_url`.

## Complete OAuth Callback
- `POST /users/{user}/social-accounts/callback`
- Permission: `social-accounts.create`
- Body:
```json
{
  "provider": "facebook",
  "code": "authorization_code",
  "state": "state_from_authorize",
  "scopes": ["pages_manage_posts", "pages_read_engagement"]
}
```

## Manual Create/Link
- `POST /users/{user}/social-accounts`
- Permission: `social-accounts.create`

## Update Account
- `PUT /users/{user}/social-accounts/{socialAccount}`
- Permission: `social-accounts.update`

## Delete Account
- `DELETE /users/{user}/social-accounts/{socialAccount}`
- Permission: `social-accounts.delete`

## Refresh OAuth Token (Queued)
- `POST /users/{user}/social-accounts/{socialAccount}/refresh-token`
- Permission: `social-accounts.refresh-token`
- Dispatches queue job `RefreshSocialAccountTokenJob`.

## Set Account Status
- `POST /users/{user}/social-accounts/{socialAccount}/status`
- Permission: `social-accounts.change-status`
- Body:
```json
{
  "status": "connected",
  "is_active": true
}
```

## Returned Status Values
- `connected`
- `pending`
- `expired`
- `revoked`
- `failed`

## Flutter Guard Notes
- Read `roles` and `permissions` from:
  - `POST /login`
  - `GET /me`
- For social feature toggles, check permissions:
  - `social-accounts.view`
  - `social-accounts.create`
  - `social-accounts.update`
  - `social-accounts.delete`
  - `social-accounts.refresh-token`
  - `social-accounts.change-status`
