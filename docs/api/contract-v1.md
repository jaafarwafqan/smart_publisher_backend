# API Contract v1

## Authentication

### POST /api/v1/auth/login
Request body:
```json
{
  "email": "admin@smartpublisher.local",
  "password": "Admin@123456",
  "device_name": "flutter-app"
}
```

Response body:
```json
{
  "message": "Login successful.",
  "access_token": "...",
  "refresh_token": "...",
  "expires_in": 3600,
  "token_type": "Bearer",
  "scope": "*",
  "user": {},
  "roles": [],
  "permissions": []
}
```

### POST /api/v1/auth/refresh
Request body:
```json
{
  "refresh_token": "...",
  "device_name": "flutter-app"
}
```

Response body:
```json
{
  "message": "Token refreshed successfully.",
  "access_token": "...",
  "refresh_token": "...",
  "expires_in": 3600,
  "token_type": "Bearer",
  "scope": "*",
  "user": {},
  "roles": [],
  "permissions": []
}
```

### GET /api/v1/auth/me
Response body:
```json
{
  "user": {},
  "roles": [],
  "permissions": [],
  "access_token": "...",
  "refresh_token": null,
  "expires_in": 1200,
  "scope": "*"
}
```

## Dashboard Endpoints

### GET /api/v1/analytics
```json
{
  "total_posts": 0,
  "published": 0,
  "failed": 0,
  "scheduled": 0,
  "draft": 0,
  "engagement": {
    "score": 0,
    "trend": "stable"
  },
  "updated_at": "..."
}
```

### GET /api/v1/notifications
```json
{
  "unread": 1,
  "items": [
    {
      "id": "notif-1",
      "type": "info",
      "title": "Welcome",
      "body": "Your dashboard is ready.",
      "read": false,
      "created_at": "..."
    }
  ]
}
```

### GET /api/v1/calendar
```json
{
  "items": [
    {
      "id": "event-1",
      "post_id": 1,
      "title": "...",
      "status": "scheduled",
      "scheduled_at": "...",
      "branch": null
    }
  ]
}
```

### GET /api/v1/settings
```json
{
  "locale": "en",
  "timezone": "UTC",
  "date_format": "Y-m-d",
  "time_format": "H:i",
  "features": {
    "analytics": true,
    "notifications": true,
    "calendar": true,
    "social_accounts": true
  }
}
```
