# Pre-upgrade state snapshot

Generated: 2026-10-15T16:05:18Z
Base commit: 1e4430176baf7fa8f726b93de1d9821707ac0bf6

## Repository
- Files tracked: 347
- PHP files: 147

## Production schema (PostgreSQL) — tables
api_tokens
app_sessions
book_topics
books
categories
contact_messages
content_likes
featured_banners
lesson_collections
lesson_topics
lesson_volumes
lessons
login_limits
media_files
pending_uploads
post_images
post_topics
posts
settings
site_banners
storage_deletions
stored_files
topics
users

## Production schema — column inventory
api_tokens.id bigint
api_tokens.user_id integer
api_tokens.token_hash character varying
api_tokens.device_label character varying
api_tokens.created_at timestamp with time zone
api_tokens.last_used_at timestamp with time zone
api_tokens.expires_at timestamp with time zone
api_tokens.revoked_at timestamp with time zone
app_sessions.id character varying
app_sessions.data text
app_sessions.expires_at timestamp with time zone
book_topics.book_id integer
book_topics.topic_id integer
books.id integer
books.title character varying
books.description text
books.cover_image character varying
books.pdf_file character varying
books.word_file character varying
books.author character varying
books.translator character varying
books.publisher character varying
books.publish_year character varying
books.pages integer
books.toc text
books.is_featured smallint
books.sort_order integer
books.status character varying
books.downloads integer
books.created_at timestamp with time zone
books.updated_at timestamp with time zone
books.slug character varying
categories.id integer
categories.name character varying
categories.slug character varying
categories.description text
categories.post_type character varying
categories.sort_order integer
categories.created_at timestamp with time zone
categories.updated_at timestamp with time zone
contact_messages.id integer
contact_messages.name character varying
contact_messages.email character varying
contact_messages.phone character varying
contact_messages.subject character varying
contact_messages.message text
contact_messages.ip_address character varying
contact_messages.is_read smallint
contact_messages.created_at timestamp with time zone
content_likes.user_id integer
content_likes.ref_type character varying
content_likes.ref_id integer
content_likes.created_at timestamp with time zone
featured_banners.id integer
featured_banners.title character varying
featured_banners.description text
featured_banners.image character varying
featured_banners.link_url character varying
featured_banners.button_text character varying
featured_banners.is_active smallint
featured_banners.sort_order integer
featured_banners.created_at timestamp with time zone
featured_banners.updated_at timestamp with time zone
lesson_collections.id integer
lesson_collections.title character varying
lesson_collections.slug character varying
lesson_collections.description text
lesson_collections.cover_image character varying
lesson_collections.sort_order integer
lesson_collections.is_active smallint
lesson_collections.is_featured smallint
lesson_collections.created_at timestamp with time zone
lesson_collections.updated_at timestamp with time zone
lesson_topics.lesson_id integer
lesson_topics.topic_id integer
lesson_volumes.id integer
lesson_volumes.collection_id integer
lesson_volumes.title character varying
lesson_volumes.slug character varying
lesson_volumes.description text
lesson_volumes.sort_order integer
lesson_volumes.created_at timestamp with time zone
lesson_volumes.updated_at timestamp with time zone
lessons.id integer
lessons.title character varying
lessons.slug character varying
lessons.subject character varying
lessons.teacher character varying
lessons.content text
lessons.summary text
lessons.audio_file character varying
lessons.featured_image character varying
lessons.level character varying
lessons.status character varying
lessons.page_section character varying
lessons.sort_order integer
lessons.lesson_number integer
lessons.collection_id integer
lessons.volume_id integer
lessons.is_featured smallint
lessons.created_by integer
lessons.created_at timestamp with time zone
lessons.updated_at timestamp with time zone
lessons.video_file text
lessons.pdf_file text
lessons.sources text
login_limits.limit_key character varying
login_limits.attempts integer
login_limits.expires_at timestamp with time zone
media_files.id integer
media_files.ref_type character varying
media_files.ref_id integer
media_files.kind character varying
media_files.file_path character varying
media_files.title character varying
media_files.created_at timestamp with time zone
media_files.sort_order integer
media_files.is_featured smallint
media_files.featured_order integer
pending_uploads.reference text
pending_uploads.not_before timestamp with time zone
pending_uploads.created_at timestamp with time zone
post_images.id integer
post_images.post_id integer
post_images.image_path character varying
post_images.alt_text character varying
post_images.sort_order integer
post_images.created_at timestamp with time zone
post_images.is_featured smallint
post_images.featured_order integer
post_topics.post_id integer
post_topics.topic_id integer
post_topics.is_primary smallint
posts.id integer
posts.title character varying
posts.slug character varying
posts.summary text
posts.content text
posts.featured_image character varying
posts.featured_video character varying
posts.post_type character varying
posts.page_section character varying
posts.category_id integer
posts.author_id integer
posts.status character varying
posts.is_featured smallint
posts.published_at timestamp with time zone
posts.created_at timestamp with time zone
posts.updated_at timestamp with time zone
posts.speaker character varying
posts.sources text
posts.author_name character varying
settings.id integer
settings.setting_key character varying
settings.value text
settings.created_at timestamp with time zone
settings.updated_at timestamp with time zone
site_banners.id integer
site_banners.title character varying
site_banners.content text
site_banners.link_url character varying
site_banners.link_text character varying
site_banners.banner_type character varying
site_banners.is_active smallint
site_banners.sort_order integer
site_banners.starts_at timestamp with time zone
site_banners.ends_at timestamp with time zone
site_banners.created_at timestamp with time zone
site_banners.updated_at timestamp with time zone
storage_deletions.reference text
storage_deletions.created_at timestamp with time zone
storage_deletions.not_before timestamp with time zone
stored_files.id integer
stored_files.file_key text
stored_files.url text
stored_files.mime text
stored_files.size bigint
stored_files.created_at timestamp with time zone
topics.id integer
topics.parent_id integer
topics.name character varying
topics.slug character varying
topics.description text
topics.intro text
topics.cover_image character varying
topics.sort_order integer
topics.is_active smallint
topics.is_featured smallint
topics.created_at timestamp with time zone
topics.updated_at timestamp with time zone
users.id integer
users.username character varying
users.email character varying
users.phone character varying
users.phone_normalized character varying
users.country character varying
users.country_code character varying
users.password character varying
users.full_name character varying
users.role character varying
users.is_active smallint
users.must_change_password smallint
users.agreed_terms smallint
users.avatar character varying
users.last_login timestamp with time zone
users.created_at timestamp with time zone
users.updated_at timestamp with time zone
users.auth_version integer

## Row counts
api_tokens: 0
app_sessions: 0
book_topics: 0
books: 0
categories: 8
contact_messages: 0
content_likes: 0
featured_banners: 0
lesson_collections: 0
lesson_topics: 0
lesson_volumes: 0
lessons: 0
login_limits: 0
media_files: 0
pending_uploads: 0
post_images: 0
post_topics: 0
posts: 0
settings: 9
site_banners: 0
storage_deletions: 0
stored_files: 0
topics: 19
users: 0
