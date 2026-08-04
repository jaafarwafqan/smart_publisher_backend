# Posts, Media, and Publishing API (B4-B6)

## Base
- Prefix: `/api/v1`
- Auth: `Bearer token`

## B4: Posts
- `GET /posts` (filter `status`)
- `POST /posts`
- `GET /posts/{post}`
- `PUT /posts/{post}`
- `DELETE /posts/{post}`
- `POST /posts/{post}/schedule`
- `POST /posts/{post}/publish-now`
- `POST /posts/{post}/draft`

### Post statuses
- `draft`
- `scheduled`
- `published`
- `failed`

## B5: Media Library
- `GET /media`
- `POST /media` (multipart field: `file`)
- `POST /posts/{post}/media/attach`
- `DELETE /posts/{post}/media/{mediaAttachment}`
- `DELETE /media/{mediaAttachment}`

### Upload notes
- Stored on `public` disk under `media/YYYY/MM`
- Thumbnail placeholder is generated as `*_thumb.jpg`
- Supports image/video type detection by MIME

## B6: Publishing Engine
- `POST /publishing/tick`
- `GET /publishing/dead-letters`
- `POST /publishing/circuit-breaker/clear`

### Queue jobs
- `ProcessScheduledPostsJob`: picks scheduled posts that reached time
- `PublishPostJob`: publishes to connected social accounts

### Reliability features
- Retry: controlled by `PUBLISH_MAX_RETRIES`
- Idempotency: per `(post_id, social_account_id)` key in `post_publication_attempts`
- Dead Letter Queue: `dead_letter_jobs` table
- Circuit Breaker: cache-based failure threshold per provider

## Permissions
- Posts: `posts.view`, `posts.create`, `posts.update`, `posts.delete`, `posts.schedule`, `posts.publish`
- Media: `media.view`, `media.upload`, `media.delete`
- Publishing: `publishing.monitor`, `publishing.manage`
