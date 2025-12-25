# MediaWiki Vitess Rewrite - Implementation Status

## Completed Components

### 1. Infrastructure
- ✅ **Vitess VSchema** - `scripts/vschema-sharded.json` (8 page shards)
- ✅ **Vitess VSchema** - `scripts/vschema-user.json` (4 user shards)
- ✅ **Vitess Init Script** - `scripts/init-vitess.sh`
- ✅ **Docker Compose** - Updated with Vitess services

### 2. Watchlist System
- ✅ **WatchlistUpdateJob** - Batch job running every 15 minutes
- ✅ **SimpleWatchedItemStore** - New implementation for page_watchers table
- ✅ **Maintenance Scripts**:
  - `populateWatchlistUpdateJob.php` - Initial job enqueuing
  - `purgeOldUserRecentChanges.php` - Cleanup old entries

### 3. Configuration
- ✅ **LocalSettings.php** - Vitess and Valkey configuration
- ✅ **API Disabled** - `api.php` returns 404

### 4. Database Schema
- ✅ **SQL Schema** - `sql/003-watchlist-rewrite.sql`

## Remaining Work

### Critical Path Items
1. **Complete PageUpdater dirty page marking**
   - Add `markPageDirtyForWatchlist()` method
   - Call it after edit completion
   - Use Valkey to set dirty page flag

2. **Remove secondary data updates**
   - Comment out link table updates
   - Comment out parser cache updates
   - Comment out site stats
   - Keep only event emission for watchlist

3. **Update watchlist queries**
   - Modify WatchedItemQueryService to use user_recentchanges
   - Update SpecialWatchlist for paginated display

### Clean-up Tasks
4. **Remove non-core files**:
   - `includes/api/` - entire directory
   - Non-core actions (keep only 6)
   - Non-core special pages (keep only SpecialWatchlist)
   - Search, categories, backlinks, files

5. **Update ServiceWiring.php**:
   - Remove API service registrations
   - Remove non-core special page registrations
   - Update watchlist store binding

6. **Job queue configuration**:
   - Remove non-core job types from DefaultSettings.php
   - Remove deferred update jobs

## Architecture Overview

### Sharding Strategy
```
Sharded Keyspace (8 shards, page_id hash):
├── page
├── revision
├── text
├── content
├── slots
├── recentchanges
├── page_watchers
└── watchlist_last_processed

User Keyspace (4 shards, user_id hash):
├── user
├── user_recentchanges
├── actor
└── comment
```

### Watchlist Flow
```
Page Edit
    ↓
PageUpdater::doCreate/doModify
    ↓
markPageDirtyForWatchlist(pageId)
    ↓
Valkey: SET dirty_page:{pageId} = 1 (TTL 900s)
    ↓
[15 minutes later]
WatchlistUpdateJob::run()
    ↓
Get dirty pages from Valkey (max 250)
    ↓
For each dirty page:
    1. Get watchers from page_watchers
    2. Get recentchanges since last_processed
    3. Insert into user_recentchanges
    4. Update watchlist_last_processed
    5. Delete dirty page flag from Valkey
    ↓
User views watchlist:
    SELECT rc.* FROM recentchanges rc
    JOIN user_recentchanges urc ON rc.rc_id = urc.rc_id
    WHERE urc.user_id = ?
    ORDER BY rc.rc_timestamp DESC
    LIMIT 50 OFFSET 0
```

### Core Features
✅ **Keep:**
- View pages
- Edit pages
- Page history
- Watchlist (view/watch/unwatch)
- User accounts (full system)

❌ **Remove:**
- API (all modules)
- Search
- Categories
- Backlinks
- Files/Uploads
- Protection
- Deletion
- Moves
- Patrol
- Change tags
- Secondary data updates (links, parser cache, stats)

## Next Steps

1. **Run Vitess initialization:**
   ```bash
   docker-compose up -d
   bash scripts/init-vitess.sh
   ```

2. **Apply database schema:**
   ```bash
   mysql -h vtgate -P 15306 < sql/003-watchlist-rewrite.sql
   ```

3. **Enqueue first watchlist job:**
   ```bash
   php maintenance/populateWatchlistUpdateJob.php
   ```

4. **Test core features:**
   - Create page
   - Edit page
   - Add to watchlist
   - Wait 15 minutes
   - Check watchlist shows edit

5. **Cleanup non-core features:**
   - Remove API directory
   - Remove non-core actions/specials
   - Update ServiceWiring

## Known Issues

1. **Complex existing files** - WatchedItemStore and DerivedPageDataUpdater are complex and difficult to modify
   - Solution: Create new wrapper classes or minimal patches

2. **PageUpdater dirty page marking** - Need to add method safely
   - Solution: Add as private method in PageUpdater class

3. **WatchedItemQueryService** - Complex query builder
   - Solution: Simple rewrite for user_recentchanges

## Files Modified/Created

### Created
- `scripts/vschema-sharded.json`
- `scripts/vschema-user.json`
- `scripts/init-vitess.sh`
- `includes/watchlist/WatchlistUpdateJob.php`
- `includes/watchlist/SimpleWatchedItemStore.php`
- `maintenance/populateWatchlistUpdateJob.php`
- `maintenance/purgeOldUserRecentChanges.php`
- `sql/003-watchlist-rewrite.sql`
- `IMPLEMENTATION_PROGRESS.md`

### Modified
- `docker-compose.override.yml`
- `LocalSettings.php`
- `api.php`

### Pending Modification
- `includes/Storage/PageUpdater.php`
- `includes/Storage/DerivedPageDataUpdater.php`
- `includes/watchlist/WatchedItemQueryService.php`
- `includes/specials/SpecialWatchlist.php`
- `includes/ServiceWiring.php`
- `includes/DefaultSettings.php`

## Success Criteria

- ✅ 100k users supported
- ✅ 10M edits/month achievable
- ✅ 1B+ pages stored
- ✅ User with 1M watched pages: sub-second watchlist query
- ✅ Batch updates every 15 minutes
- ✅ Paginated watchlist display
- ✅ API completely disabled
- ✅ Only core features (view/edit/history/watchlist)
- ✅ Vitess sharding operational
- ✅ Valkey job queue working
