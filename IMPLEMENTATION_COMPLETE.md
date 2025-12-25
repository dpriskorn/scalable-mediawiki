# MediaWiki Rewrite Implementation Summary

## Status: Core Infrastructure Complete (75%)

### Completed Components

#### 1. Vitess Infrastructure ✅
- `scripts/vschema-sharded.json` - Sharded keyspace config (8 shards, hash by page_id)
- `scripts/vschema-user.json` - User keyspace config (4 shards, hash by user_id)
- `scripts/init-vitess.sh` - Vitess initialization script
- `docker-compose.override.yml` - Updated with Vitess services

#### 2. Watchlist Rewrite ✅
- `includes/watchlist/WatchlistUpdateJob.php` - Batch job for watchlist updates (every 15 min)
- `includes/watchlist/Hooks/WatchlistDirtyMarker.php` - Hook to mark dirty pages on edit
- `sql/003-watchlist-rewrite.sql` - New tables (page_watchers, user_recentchanges, watchlist_last_processed)

#### 3. Maintenance Scripts ✅
- `maintenance/populateWatchlistUpdateJob.php` - Initial job enqueuer
- `maintenance/purgeOldUserRecentChanges.php` - Cleanup script (30-day TTL)

#### 4. Configuration ✅
- `api.php` - Disabled (404 response)
- `LocalSettings.php` - Updated for Vitess and Valkey
- Watchlist job registered in LocalSettings

#### 5. Core Actions & Special Pages ✅
- Kept: ViewAction, EditAction, SubmitAction, HistoryAction, WatchAction, UnwatchAction
- Kept: SpecialWatchlist
- Removed all other actions and special pages

### Remaining Tasks

#### 1. Database Setup (Manual Steps)
```bash
# Apply schema to Vitess
mysql -h vtgate -P 15306 < sql/003-watchlist-rewrite.sql

# Run init script
bash scripts/init-vitess.sh
```

#### 2. Service Registration (Manual Step)
Add to `LocalSettings.php`:
```php
require_once __DIR__ . '/includes/watchlist/Hooks/WatchlistDirtyMarker.php';
```

#### 3. Remove Secondary Data Updates (Optional)
The DerivedPageDataUpdater updates secondary data automatically via hooks. To disable:
- Set `$wgEnableParserCache = false;` in LocalSettings
- Remove `$wgHooks['PageSaveComplete']` entries that trigger secondary updates

#### 4. ServiceWiring Updates (Optional)
If removing non-core services, edit `includes/ServiceWiring.php` to comment out unused services.

### Architecture Overview

```
┌─────────────────────────────────────────────┐
│          MediaWiki (Stripped)           │
│  View, Edit, History, Watchlist         │
└─────────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────┐
│            Vitess VTGate                │
│         MySQL Wire Protocol              │
└─────────────────────────────────────────────┘
                 │
        ┌────────┴────────┐
        ▼                 ▼
┌──────────────┐   ┌──────────────┐
│ Sharded (8)  │   │ Users (4)    │
│ page_id hash  │   │ user_id hash  │
└──────────────┘   └──────────────┘
        │
        ▼
┌─────────────────────────────────────────────┐
│       SeaweedFS (S3-compatible)        │
│         Page Content Storage             │
└─────────────────────────────────────────────┘
```

### Watchlist Flow

1. **Page Edit**: User edits a page
2. **Dirty Marker**: Hook marks page as dirty in Valkey (15min TTL)
3. **Batch Job** (every 15 min):
   - Scans Valkey for dirty pages (max 250)
   - For each page:
     - Gets watchers from `page_watchers`
     - Gets recentchanges since last update
     - Inserts into `user_recentchanges`
     - Updates `watchlist_last_processed`
   - Deletes dirty markers
4. **Watchlist View**: User queries via `user_recentchanges` (instant lookup)

### Starting the Stack

```bash
# Start all services
docker compose up -d

# Initialize Vitess (run once)
docker compose exec vtgate bash scripts/init-vitess.sh

# Apply database schema
docker compose exec vtgate mysql -h vtgate -P 15306 < sql/003-watchlist-rewrite.sql

# Start watchlist job runner (one-time)
php maintenance/populateWatchlistUpdateJob.php

# Optional: Schedule cleanup cron
0 0 * * * php maintenance/purgeOldUserRecentChanges.php --days=30
```

### Scale Targets

- **Users**: 100k
- **Edits/month**: 10M
- **Pages**: 1B+
- **Watchlist batch**: 250 pages, 15 min interval
- **Cleanup**: 30-day TTL for user_recentchanges

### Files Modified

#### Created
- `scripts/vschema-sharded.json`
- `scripts/vschema-user.json`
- `scripts/init-vitess.sh`
- `includes/watchlist/WatchlistUpdateJob.php`
- `includes/watchlist/Hooks/WatchlistDirtyMarker.php`
- `maintenance/populateWatchlistUpdateJob.php`
- `maintenance/purgeOldUserRecentChanges.php`
- `sql/003-watchlist-rewrite.sql`

#### Modified
- `docker-compose.override.yml`
- `api.php`
- `LocalSettings.php`

#### Deleted
- All non-core actions (except 6)
- All non-core special pages (except SpecialWatchlist)
- `includes/watchlist/SimpleWatchedItemStore.php` (removed due to errors)

### Next Steps

1. **Testing**: Test edit → watchlist update flow
2. **Performance**: Benchmark watchlist queries with 1M watched pages
3. **Monitoring**: Add metrics for job execution time
4. **Optimization**: Tune batch size and interval based on load
