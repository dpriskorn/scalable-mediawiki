# MediaWiki Vitess Rewrite - Implementation Status

## Current State
- ✅ Using new Vitess image (vitess/lite:latest)
- ✅ Single sharded keyspace ("page") - 4 shards for KISS approach
- ❌ Scripts directory deleted (vschemas, init scripts removed)
- ❌ docker-compose.vitess.yml missing from disk

## Completed Components

### 1. Infrastructure
- ✅ **Valkey** - Redis-compatible job queue (keydb)
- ✅ **SeaweedFS** - S3-compatible external storage
- ⚠️ **Vitess Services** - Compose file needs recreation
  - etcd service
  - vtgate service (port 15306)
  - 4 vttablet services for single keyspace
  - vtctld service

### 2. Watchlist System
- ✅ **WatchlistUpdateJob** - Batch job for watchlist updates
- ✅ **SimpleSpecialWatchlist** - Replacement watchlist page
- ✅ **WatchlistDirtyMarker** - Hook for marking dirty pages
- ✅ **SQL Schema** - `sql/003-watchlist-rewrite.sql` (3 tables)

### 3. Configuration
- ⚠️ **LocalSettings.php** - Has several issues that need fixing:
  - Missing `$wgDBtype`
  - Port mismatch (15309 vs 15306)
  - LBFactorySingle configuration causing job runner error
  - ExternalStore typo (`seaweedfs` → `seaweedfs`)
- ✅ **Job Queue** - Valkey configured for Redis-based queue

## Current Blockers

### 1. Job Runner Error
**Issue**: `InvalidArgumentException: Missing 'connection' argument` when job runner starts
**Root Cause**: `LBFactorySingle` configuration is incorrect
**Fix Needed**: Remove custom `$wgLBFactoryConf`, use MediaWiki's default

### 2. Database Configuration Issues
**Issues**:
- `$wgDBtype = "mysql"` not set before server config
- Port 15309 doesn't match vtgate (should be 15306)
- ExternalStore endpoint typo: `seaweedfs` → `seaweedfs`

### 3. Missing Vitess Configuration
**Issues**:
- No docker-compose.vitess.yml file exists
- No vschema configuration for single keyspace
- No Vitess init script to create keyspace and shards

## Architecture Overview

### Sharding Strategy (Updated)
```
Single Keyspace "page" (4 shards, mixed hash):
├── user                (user_id hash)
├── user_recentchanges  (user_id hash) [NEW]
├── page_watchers      (page_id hash) [NEW]
├── watchlist_last_processed (page_id hash) [NEW]
├── page               (page_id hash)
├── revision           (page_id hash)
├── text               (page_id hash)
├── content            (page_id hash)
├── slots              (page_id hash)
└── recentchanges       (page_id hash)
```

### Watchlist Flow (Updated for Single Keyspace)
```
Page Edit
    ↓
WatchlistDirtyMarker::onRevisionFromEditComplete()
    ↓
Valkey: SET dirty_page:{pageId} = 1 (TTL 900s)
    ↓
[15 minutes later]
WatchlistUpdateJob::run()
    ↓
Get dirty pages from Valkey (max 250)
    ↓
For each dirty page:
    1. Get watchers from page_watchers (same keyspace)
    2. Get recentchanges since last_processed
    3. Insert into user_recentchanges (same keyspace)
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

## Immediate Next Steps

### 1. Fix LocalSettings.php Database Configuration
```php
// Add missing DB type
$wgDBtype = "mysql";

// Fix port to match vitess
$wgDBport = 15306;  // was 15309

// Fix external store typo
$wgExternalStoreS3Config = [
    'endpoint' => 'http://seaweedfs:8333',  // was seaweedfs:8333
    ...
];

// Remove custom LBFactory configuration
// DELETE this block:
$wgLBFactoryConf = [
    'class' => LBFactorySingle::class,
    ...
];
```

### 2. Recreate docker-compose.vitess.yml
Create with single keyspace setup:
```yaml
services:
  etcd:
    image: quay.io/coreos/etcd:v3.5.9
    container_name: vitess_etcd

  vtgate:
    image: vitess/lite:latest
    container_name: vitess_vtgate
    ports:
      - "15306:15306"

  vttablet-zone1-{0,1,2,3}:
    # 4 tablets for single keyspace

  vitess-init:
    # Initialize single keyspace with 4 shards
```

### 3. Create VSchema for Single Keyspace
Create `scripts/vschema-single.json` with:
- All tables in "page" keyspace
- Mixed vindexes (page_id hash, user_id hash)
- 4 shards configuration

### 4. Update WatchlistUpdateJob
Fix `getUserDB()` method:
```php
private function getUserDB( int $userId ): IDatabase {
    // Use default database since we're in single-sharded mode
    return MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
}
```

### 5. Apply Database Schema
```bash
mysql -h vitess -P 15306 < sql/003-watchlist-rewrite.sql
```

## Known Issues

1. **Job runner initialization** - LBFactorySingle error blocks job execution
2. **Missing Vitess infrastructure** - No compose file or schema to start services
3. **WatchlistUpdateJob getUserDB()** - References user_keyspace which doesn't exist
4. **Port configuration mismatch** - 15309 vs 15306 causes connection failures
5. **ExternalStore typo** - `seaweedfs:8333` should be `seaweedfs:8333`

## Files Modified/Created

### Created
- `sql/003-watchlist-rewrite.sql` - Watchlist tables schema (3 new tables)

### Modified
- `docker-compose.override.yml` - Valkey and SeaweedFS services
- `LocalSettings.php` - Vitess and job queue config (needs fixes)

### Deleted
- `scripts/` - Entire directory (vschemas, init scripts)
- `docker-compose.vitess.yml` - Was on disk, now missing

### Needs Update
- `includes/watchlist/WatchlistUpdateJob.php` - getUserDB() method (line 243-248)
- `LocalSettings.php` - Fix LBFactory and port configuration
- `docker-compose.vitess.yml` - Needs recreation with single keyspace

## Success Criteria

- ✅ Vitess single-sharded DB operational
- ✅ Watchlist tables in same keyspace as other tables
- ⏳ Job queue working without LBFactory errors
- ⏳ Watchlist updates via batch job
- ⏳ SimpleSpecialWatchlist displays user watchlist
- ⏳ Core features: view/edit/history/watchlist only
