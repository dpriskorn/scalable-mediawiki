# MediaWiki Rewrite Implementation

## Overview

This implementation rewrites MediaWiki core for massive scale (100k users, 10M edits/month, 1B+ pages) using Vitess sharding and a KISS watchlist architecture.

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│              MediaWiki Core (Web Only)                   │
│              View/Edit Pages, History, Watchlists          │
│              API: Disabled (404)                        │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                      Vitess (VTGate)                      │
│                   MySQL Wire Protocol Proxy                   │
└─────────────────────────────────────────────────────────────┘
                              │
         ┌────────────────────┼────────────────────┐
         ▼                    ▼                    ▼
┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐
│ Sharded Keyspace│  │                  │  │                  │
│   (2 shards)   │  │                  │  │                  │
│   page_id%2    │  │                  │  │                  │
├─────────────────┤  ├─────────────────┤  ├─────────────────┤
│ page           │  │ page           │  │ page            │
│ revision       │  │ revision       │  │ revision        │
│ text           │  │ text           │  │ text            │
│ content        │  │ content        │  │ content         │
│ slots          │  │ slots          │  │ slots           │
│ recentchanges  │  │ recentchanges  │  │ recentchanges    │
│ page_watchers  │  │ page_watchers  │  │ page_watchers  │
│ watchlist_last_│  │ watchlist_last_│  │ watchlist_last_  │
│   processed    │  │   processed    │  │   processed     │
└─────────────────┘  └─────────────────┘  └─────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│               User Keyspace (1 shard)                   │
│                     user_id%1                             │
├─────────────────────────────────────────────────────────────┤
│ user                                                       │
│ user_recentchanges (materialized watchlist)            │
│ actor                                                      │
│ comment                                                    │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│               Valkey (Job Queue + Dirty Pages)             │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                    SeaweedFS (S3)                         │
│                   (Page Content Blobs)                      │
└─────────────────────────────────────────────────────────────┘
```

## Watchlist System

### Design

**Old approach (bottleneck):**
```sql
-- Slow query with 1M watched pages
SELECT rc.* 
FROM recentchanges rc
JOIN watchlist wl ON rc_cur_id = wl_namespace, wl_title
WHERE wl_user = ? AND rc_this_oldid = page_latest
ORDER BY rc_timestamp DESC
LIMIT 50
```

**New approach (instant):**
```sql
-- Instant query with materialized table
SELECT rc.*
FROM recentchanges rc
JOIN user_recentchanges urc ON rc.rc_id = urc.rc_id
WHERE urc.user_id = ?
ORDER BY rc.rc_timestamp DESC
LIMIT 50
```

### Flow

1. **User watches page** → Insert into `page_watchers`
2. **Page edited** → Hook marks dirty in Valkey (15min TTL)
3. **15 min later** → WatchlistUpdateJob runs:
   - Scans Valkey for dirty pages (limit 250)
   - For each dirty page:
     - Gets watchers from `page_watchers`
     - Gets recentchanges since last processed
     - Inserts into `user_recentchanges` for each watcher
     - Updates `watchlist_last_processed`
     - Deletes dirty page marker from Valkey
   - Self-requeues with 15 min delay
4. **User views watchlist** → Instant query on `user_recentchanges`

## Files Created

### Core Watchlist System

| File | Lines | Purpose |
|-------|--------|----------|
| `includes/watchlist/MinimalWatchedItemStore.php` | ~200 | KISS replacement for 1845-line WatchedItemStore |
| `includes/watchlist/MinimalWatchlistManager.php` | ~150 | Simple watch/unwatch operations |
| `includes/specials/SimpleSpecialWatchlist.php` | ~180 | Watchlist display (queries user_recentchanges) |
| `includes/watchlist/WatchlistUpdateJob.php` | ~250 | Batch job (250 pages, 15min cycle, exponential backoff) |
| `includes/watchlist/Hooks/WatchlistDirtyMarker.php` | ~40 | Marks pages dirty on edit |

### Maintenance Scripts

| File | Purpose |
|-------|----------|
| `maintenance/populateWatchlistUpdateJob.php` | Initialize first WatchlistUpdateJob |
| `maintenance/purgeOldUserRecentChanges.php` | Purge 30+ day old user_recentchanges entries |

### Infrastructure

| File | Purpose |
|-------|----------|
| `scripts/vschema-sharded.json` | Vitess VSchema (2 shards, page_id hash) |
| `scripts/vschema-user.json` | Vitess VSchema (1 shard, user_id hash) |
| `scripts/init-vitess.sh` | Initialize Vitess keyspaces and shards |
| `docker-compose.override.yml` | All Vitess services |
| `sql/003-watchlist-rewrite.sql` | New database tables |

### Tests

| File | Purpose |
|-------|----------|
| `tests/phpunit/unit/includes/watchlist/MinimalWatchedItemStoreTest.php` | Store operations |
| `tests/phpunit/unit/includes/watchlist/MinimalWatchlistManagerTest.php` | Manager operations |
| `tests/phpunit/unit/includes/watchlist/WatchlistUpdateJobTest.php` | Batch job logic |
| `tests/phpunit/unit/includes/watchlist/SimpleSpecialWatchlistTest.php` | Watchlist display |

### Configuration

| File | Changes |
|-------|----------|
| `LocalSettings.php` | Vitess DB, Valkey job queue, hooks |
| `api.php` | Returns 404 (API disabled) |
| `includes/ServiceWiring.php` | Uses MinimalWatchedItemStore |

## Running Tests

```bash
# Run all watchlist tests
composer phpunit tests/phpunit/unit/includes/watchlist/

# Run specific test
composer phpunit tests/phpunit/unit/includes/watchlist/MinimalWatchedItemStoreTest.php

# Run with coverage
composer phpunit --coverage-text --coverage-html=coverage tests/phpunit/unit/includes/watchlist/
```

## Starting the Stack

```bash
# Start all services
docker compose up -d

# Initialize Vitess
./scripts/init-vitess.sh

# Apply database schema
mysql -h vtgate -P 15306 -u wikiuser -p wikidbpass sharded_keyspace < sql/003-watchlist-rewrite.sql

# Initialize watchlist job
php maintenance/populateWatchlistUpdateJob.php

# Access MediaWiki
open http://localhost:8080
```

## Schema Changes

### New Tables

**page_watchers** (sharded, page_id%2)
```sql
CREATE TABLE page_watchers (
  page_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (page_id, user_id),
  INDEX (user_id)
);
```

**user_recentchanges** (unsharded user keyspace)
```sql
CREATE TABLE user_recentchanges (
  user_id INT UNSIGNED NOT NULL,
  rc_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, rc_id),
  INDEX (rc_id)
);
```

**watchlist_last_processed** (sharded, page_id%2)
```sql
CREATE TABLE watchlist_last_processed (
  page_id INT UNSIGNED NOT NULL PRIMARY KEY,
  last_processed INT UNSIGNED NOT NULL,
  last_rc_id BIGINT UNSIGNED NOT NULL
);
```

### Removed Tables

- `watchlist` - Replaced by `page_watchers`
- `watchlist_expiry` - No longer supported

## Performance Characteristics

### Watchlist Operations

| Operation | Old Complexity | New Complexity | Improvement |
|-----------|----------------|----------------|-------------|
| Watch page | O(log N) | O(log N) | Same |
| Unwatch page | O(log N) | O(log N) | Same |
| Query watchlist | O(N) where N=watched pages | O(limit) | **Massive** |
| Update on edit | O(1) | O(watchers) | Trade-off |

### Batch Update Job

- **Batch size**: 250 pages per run
- **Frequency**: Every 15 minutes
- **Backoff**: Exponential retry on failure
- **Locking**: Distributed lock prevents concurrent runs

### Scalability

| Metric | Old Limit | New Limit |
|--------|-----------|-----------|
| Watched pages per user | Slow at 100K | Fast at 10M+ |
| Concurrent edits | Lock contention | No lock contention |
| Horizontal scaling | Single DB | Unlimited shards |
| Write amplification | None | Popular pages trigger more writes (amortized) |

## Development Notes

### Running Locally

```bash
# Start minimal stack (2 page shards + 1 user shard)
docker compose up vitess valkey mediawiki jobrunner

# Logs
docker compose logs -f mediawiki
docker compose logs -f jobrunner
```

### Testing Batch Job

```bash
# Manually trigger batch job
php maintenance/runJobs.php --type=watchlistUpdate

# Watch logs
docker compose logs -f jobrunner | grep watchlistUpdate
```

### Debugging

```bash
# Check dirty pages in Valkey
redis-cli -h valkey -a valkeypass KEYS "dirty_page:*"

# Check watchers for a page
mysql -h vtgate -P 15306 -u wikiuser -p wikidbpass -e "SELECT user_id FROM page_watchers WHERE page_id = 123"

# Check user_recentchanges for a user
mysql -h vtgate -P 15306 -u wikiuser -p wikidbpass -e "SELECT rc_id FROM user_recentchanges WHERE user_id = 456"
```

## Next Steps

1. **Production Sharding** - Increase shards (e.g., 16 page shards, 8 user shards)
2. **Monitoring** - Add metrics for batch job performance
3. **Optimization** - Tune batch size and frequency
4. **Wikibase Integration** - Add ULID generation for external QIDs
5. **Caching** - Add page content caching layer
