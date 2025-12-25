# MediaWiki Rewrite Progress

## Completed ✅

### Infrastructure
- [x] scripts/vschema-sharded.json - Vitess VSchema for sharded keyspace (8 shards, page_id hash)
- [x] scripts/vschema-user.json - Vitess VSchema for user keyspace (4 shards, user_id hash)
- [x] scripts/init-vitess.sh - Init script for Vitess
- [x] docker-compose.override.yml - Added Vitess services (etcd, vtctld, vtgate, zookeeper, 12 MySQL shards)
- [x] LocalSettings.php - Updated for Vitess and Valkey configuration

### Watchlist System
- [x] includes/watchlist/WatchlistUpdateJob.php - Batch job for watchlist updates (15 min intervals, 250 pages/batch)
- [x] includes/watchlist/SimpleWatchedItemStore.php - New watchlist store for page_watchers table
- [x] maintenance/populateWatchlistUpdateJob.php - One-time job to start watchlist cycle
- [x] maintenance/purgeOldUserRecentChanges.php - Cleanup old user_recentchanges entries (30 day TTL)

### API
- [x] api.php - Disabled (returns 404)

## Remaining Work ⏳

### High Priority (Core Functionality)

1. **Integrate SimpleWatchedItemStore** - Replace WatchedItemStore in ServiceWiring
   - Update ServiceWiring.php to use SimpleWatchedItemStore
   - Rename to replace existing WatchedItemStore or wire as new service

2. **Mark pages dirty on edit** - Add dirty page marking to edit flow
   - Need clean hook into PageUpdater after edit
   - Mark dirty page in Valkey: "dirty_page:$pageId" with 15 min TTL
   - Page ID available from $this->page or event context

3. **Update watchlist query** - Replace watchlist query in WatchedItemQueryService
   - Current query joins watchlist with recentchanges
   - New query joins user_recentchanges with recentchanges (instant query)

### Medium Priority (Cleanup)

4. **Disable non-core features** - Remove/disable unused code
   - Actions: Keep only View, Edit, Submit, History, Watch, Unwatch
   - Special Pages: Keep only SpecialWatchlist
   - Search, categories, backlinks, files, protection, deletion, moves
   - Job types: Keep only watchlistUpdate

5. **Remove secondary data updates** - Cleanup DerivedPageDataUpdater
   - Disable link table updates
   - Disable parser cache updates
   - Disable site stats updates
   - Keep only event emission for watchlist

6. **Update ServiceWiring** - Remove non-core service registrations
   - Remove API service registrations
   - Remove non-core special page registrations
   - Remove non-core action registrations

## Architecture Notes

### New Tables
- `page_watchers` (sharded): page_id, user_id - who watches each page
- `user_recentchanges` (user keyspace): user_id, rc_id - materialized watchlist
- `watchlist_last_processed` (sharded): page_id, last_processed, last_rc_id - state tracking

### Watchlist Flow
1. User edits page → Page marks dirty in Valkey (dirty_page:$pageId)
2. Every 15 minutes → WatchlistUpdateJob runs
3. Job scans dirty pages (up to 250 per batch)
4. For each dirty page:
   - Get watchers from page_watchers
   - Get recentchanges since last_processed
   - Insert into user_recentchanges for each watcher
   - Update watchlist_last_processed
   - Delete dirty page key from Valkey
5. User views watchlist → Instant query on user_recentchanges

### Scaling Characteristics
- **Write amplification**: Eliminated - only dirty pages processed
- **Read performance**: Instant - pre-materialized user_recentchanges
- **Batch size**: 250 pages per job run
- **Cleanup**: user_recentchanges purged after 30 days

## Next Steps

To continue implementation:
1. Fix PageUpdater dirty page marking (need clean hook point)
2. Fix DerivedPageDataUpdater secondary update removal (need clean approach)
3. Integrate SimpleWatchedItemStore into ServiceWiring
4. Update WatchedItemQueryService for new query pattern
5. Remove/disable non-core features
