I've reviewed your rewrite strategy and analyzed the current MediaWiki architecture. Here are some critical findings:
Key Observations
Your plan's assumptions don't fully match current reality:
1. Full content storage is already implemented - MW stores complete page content per revision in text.old_text, not diffs
2. Watchlists are already eventually consistent - Job-based cleanup, no immediate consistency guarantees
3. No global table locks during normal ops - Uses row-level locking with SELECT ... FOR UPDATE
4. External storage already exists - SqlBlobStore supports S3/cloud storage via es: prefix
The real bottleneck is likely different:
- Single-writer architecture - ILoadBalancer routes writes to ONE primary database
- Monolithic database - No horizontal sharding, everything on one DB instance
- Site_stats table - Single-row bottleneck for aggregated counters
- Foreign key hotspots - page_latest and similar FKs creating write contention at scale

Questions Before Proceeding
1. What's the actual scale target? You mention "YouTube scale" - do you mean:
   - 100M active users?
   100k active users
   - 10M edits/day?
   no 10M a month
   - 1B pages?
   >1bn pages

2. What are the current failure points? Have you benchmarked the existing system? Where does it break?
no sharding strategy

3. Why Vitess specifically? Is it for:
   - Automatic sharding?
   - Re-sharding without downtime?
   - MySQL compatibility?
all 3 I guess

4. Fork vs. Migration? Your plan suggests forking MW core - why not:
   - Deploy Vitess under existing MW (minimal code changes)?
   - Optimize hotspots first before major rewrite?
sounds good to me

---

Much more reasonable targets! 100k users and 10M edits/month is achievable with Vitess + optimizations. The 1B+ pages is the main challenge.
Before I create the detailed plan, a few more questions:
Architecture Decisions
1. Sharding strategy preference:
   - Hash-based sharding by page_id? (simple, no hotspots, but range queries suffer)
that sounds good


2. Non-enumerable QIDs: Your plan mentions Qre4dfsd4dfs2d4 format. Should we:
   - Keep sequential QIDs internally, expose non-enumerable externally?
yes, generate ULID and external id per shard at creation

3. Watchlist at 1B pages: With 100k users, average watchlist size is tiny. Should we:
   - Keep per-user watchlists (current approach)?
   - Move to per-page "watchers" list (reverse watchlist)?
tell me more about how this would work, right now the system breaks when I have 1M pages in my watchlist, it takes forever to make the db join on latest revision and get an output

4. Deployment timeline:
   - Go straight to production-ready for 1B?
yes

5. Existing MW version:
   - Work with this codebase as-is?
   - Any specific features already removed/not needed?
   comment out everything that is not needed for:
   viewing/editing pages and their history
   viewing/editing watchlist

6. Infrastructure constraints:
   - On-prem or cloud?
   on prem, should be able to start the whole thing with docker compose, later deployment to kubernetes cluster
   
   - Budget considerations?
   no constraints

   - Single region or multi-region?
   single region right now, but the project is important for the whole world

---

Best approach for massive scale:
CREATE TABLE user_recentchanges (
  user_id INT UNSIGNED NOT NULL,
  rc_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, rc_id)
) ENGINE=InnoDB;
Update on page edit:
1. Query SELECT user_id FROM page_watchers WHERE page_id = ?
2. Insert row into user_recentchanges for each watcher
3. Watchlist query: SELECT rc.* FROM recentchanges rc JOIN user_recentchanges ur ON rc.rc_id = ur.rc_id WHERE ur.user_id = ?
Zero joins at query time - just fetch pre-computed results.

---

Based on all our discussions:
1. Scale target: 100k active users, 10M edits/month, >1B pages
2. Hash-based sharding by page_id
3. Internal sequential IDs, external non-enumerable ULIDs
4. Materialized user_recentchanges table for watchlists
5. Go straight to production-ready for 1B
6. Comment out everything except: viewing/editing pages, history, watchlists
7. On-prem with Docker Compose first, then Kubernetes
8. Single region initially
9. Vitess for automatic sharding, re-sharding, MySQL compatibility
The plan should be comprehensive but not overly verbose. I need to:
1. Analyze the current architecture more deeply
2. Identify all components that need to change
3. Plan the sharding strategy
4. Plan the watchlist redesign
5. Plan the simplification (commenting out unused features)
6. Plan the Vitess deployment
7. Plan the ULID generation
8. Create a phased implementation approach
I'm in READ-ONLY mode, so I need to explore the codebase more to understand:
- How page IDs are currently generated and used
- How edits flow through the system
- All the features that need to be disabled
- The database schema in detail
- API endpoints and which ones to keep
Let me create a comprehensive plan. I should explore more of the codebase to understand the architecture better.
I'll create a comprehensive plan. Let me explore the codebase more deeply to understand the full scope.

---

Thinking: The user wants me to proceed with planning the MediaWiki rewrite. I have good analysis from the explore agents about:
1. Current architecture (revision storage, watchlist, etc.)
2. Edit flow and transaction handling
3. Features to keep/disable
Now I need to create a comprehensive plan for the rewrite. Let me think about what I still need to explore before creating the plan:
1. How to implement Vitess integration
2. How to handle ULID generation for external QIDs
3. How to implement the materialized user_recentchanges table
4. How to comment out/disable non-essential features
5. Docker compose setup
Actually, I have enough information now. Let me create the comprehensive plan based on what I've learned and the user's requirements:
- 100k active users
- 10M edits/month
- >1B pages
- Hash-based sharding by page_id
- Internal sequential IDs, external ULID QIDs
- Materialized user_recentchanges for watchlist
- Keep only: viewing/editing pages, history, watchlist
- Docker Compose deployment
- Production-ready for 1B pages
I should prune the old read result since I've already synthesized its key points into my understanding.

---

Thinking: The user has activated plan mode. I need to create a comprehensive plan based on all the research I've done. Let me consolidate everything into a structured plan that addresses:
1. Scale targets: 100k users, 10M edits/month, 1B+ pages
2. Architecture decisions:
   - Hash-based sharding by page_id
   - Internal sequential IDs, external non-enumerable QIDs (ULID)
   - Materialized user_recentchanges table for watchlists
   - Docker Compose for local, Kubernetes for production
   - Keep core: view/edit pages, history, watchlists
3. Infrastructure:
   - On-prem
   - Single region
   - No budget constraints
Based on my research, I now understand:
- Current MW architecture and edit flow
- Vitess integration requirements (minimal code changes, mostly config)
- External S3 storage (already supported via ExternalStoreS3)
- Watchlist bottleneck and solution (materialized user_recentchanges)
- Features to keep vs remove
I need to create a comprehensive plan that includes:
1. High-level architecture
2. Database schema changes
3. Application changes
4. Infrastructure/Docker setup
5. Migration strategy
6. Testing approach
7. Phased implementation
Let me structure this properly and ask any clarifying questions before finalizing.
Based on all the research, I have a clear picture. Let me consolidate the findings and create a comprehensive plan.
Current Situation Summary
What MW already does well:
- Full content storage (not diffs)
- Eventually consistent watchlists
- External S3 storage (ExternalStoreS3 already exists)
- Row-level locking (no global table locks)
Key bottlenecks identified:
- Watchlist query with 1M entries - large JOIN + unindexed page_latest filter
- Single-writer database architecture
- No sharding strategy
What needs to change:
- Hash-based sharding via Vitess
- ULID generation for external IDs
- Materialized user_recentchanges table
- Remove non-core features
Proposed Architecture Plan
┌─────────────────────────────────────────────────────────────┐
│                         Application                          │
│                  (MediaWiki - Stripped Down)                │
├─────────────────────────────────────────────────────────────┤
│  Core: View/Edit Pages, History, Watchlists                  │
│  Removed: Search, Special Pages, Permissions, Files, etc.   │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                      Vitess (VTGate)                        │
│                   MySQL Wire Protocol Proxy                  │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐
│   Shard 1        │  │   Shard 2        │  │   Shard N        │
│   (page_id%8=0)  │  │   (page_id%8=1)  │  │   (page_id%8=7)  │
├──────────────────┤  ├──────────────────┤  ├──────────────────┤
│ • page table     │  │ • page table     │  │ • page table     │
│ • revision table │  │ • revision table │  │ • revision table │
│ • text table     │  │ • text table     │  │ • text table     │
│ • content table  │  │ • content table  │  │ • content table  │
│ • slots table    │  │ • slots table    │  │ • slots table    │
└──────────────────┘  └──────────────────┘  └──────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                  Unsharded Tables (Keyspace)                 │
├─────────────────────────────────────────────────────────────┤
│ • user table         (shard by user_id)                      │
│ • watchlist table    (removed - replaced by watchers)       │
│ • page_watchers table (new - reverse watchlist)             │
│ • user_recentchanges table (new - materialized)            │
│ • actor table        (user normalization)                   │
│ • comment table      (deduped comments)                     │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                    S3 / MinIO Storage                        │
│                   (Page Content Blobs)                       │
└─────────────────────────────────────────────────────────────┘
Implementation Plan
Phase 1: Core Architecture (Foundation)
1.1 Database Schema Changes
- Add page_watchers table (reverse watchlist)
- Add user_recentchanges table (materialized watchlist)
- Add page_external_id column (ULID)
- Add revision_external_id column (ULID)
- Remove watchlist and watchlist_expiry tables
1.2 Vitess VSchema Configuration
- Define hash-based VIndexes:
  - page_id → hash to 8 shards
  - rev_id → same hash as page_id
  - user_id → hash to 4 shards (separate keyspace)
- Configure Vitess Sequences for auto-increment alternative
1.3 ULID Generation
- Implement ULID generator service
- Hook into page creation flow
- Hook into revision creation flow
- Store both internal (sequential) and external (ULID) IDs
1.4 Watchlist Rewrite
- Replace watchlist table with page_watchers
- Implement user_recentchanges materialization
- Update watchlist query to use materialized table
- Update watch/unwatch actions
Phase 2: Application Code Changes
2.1 Remove Non-Core Features
Disable/comment out:
- Actions: PurgeAction, InfoAction, ProtectAction, DeleteAction, RevertAction, RollbackAction, MarkpatrolledAction, CreditsAction
- API modules: ApiRollback, ApiProtect, ApiDelete, ApiMove, ApiPurge, ApiPatrol, ApiBlock, ApiImport, ApiExport, ApiUpload
- Special pages: All except watchlist
- Deferred updates: SiteStatsUpdate, cache updates
- Job types: RefreshLinksJob, HTMLCacheUpdateJob
- Search functionality
- Category listings
- Backlink tracking
- File handling
- Interwiki links
- Page protection/deletion/move
2.2 Database Connection
- Point $wgDBserver to Vitess VTGate
- Update $wgLBFactoryConf for Vitess
- Configure S3 external storage
2.3 Edit Flow Modifications
- ULID generation on page creation
- ULID generation on revision creation
- Update watchlist notifications to use page_watchers + user_recentchanges
- Remove secondary data updates (links, categories, etc.)
2.4 API Changes
- Expose external ULIDs in API responses
- Update watchlist endpoints to use new schema
- Remove deprecated endpoints
Phase 3: Infrastructure
3.1 Docker Compose Stack
services:
  mediawiki:
    build: .
    ports: ["8080:80"]
  vtctld:
    image: vitess/vtctld:...
  vtgate:
    image: vitess/vtgate:...
    ports: ["15306:15306"]  # MySQL protocol
  mysql-shard-1:
  mysql-shard-2:
  ...
  mysql-shard-8:
  mysql-users:
  mysql-unsharded:
  minio:
    image: minio/minio
    ports: ["9000:9000", "9001:9001"]
3.2 Vitess Configuration
- VSchema JSON for each keyspace
- Sharding topology
- Keyspace sharding parameters
- Health checks
3.3 Kubernetes Deployment (later)
- Helm charts for Vitess
- Helm chart for MediaWiki app
- MinIO StatefulSet
- Service mesh for inter-service communication
Phase 4: Data Migration Strategy
4.1 Clean Start
- Fresh Vitess deployment
- Seed with initial configuration
- No data migration from existing MW (start fresh)
4.2 Import Existing Data (if needed)
- Export from existing MW
- Transform to new schema
- Import via Vitess VTGate
- Generate ULIDs for existing pages/revisions
Phase 5: Testing
5.1 Unit Tests
- ULID generation
- Watchlist materialization
- Database queries through Vitess
5.2 Integration Tests
- Complete edit flow
- Watchlist updates
- Cross-shard queries
5.3 Performance Tests
- 100k users, 10M edits/month simulation
- 1B pages storage
- Large watchlist queries (1M pages)
5.4 Stress Tests
- Concurrent edits
- High watchlist update rate
- Shard rebalancing
Critical Decisions Needed
Before I finalize the detailed implementation steps, I need to clarify a few things:
1. Are you starting with fresh data, or is there an existing MediaWiki installation to migrate?
2. For the 1B pages - should we:
   - Pre-create them all at once?
   - Allow them to be created organically over time?
   - Have a bulk import mechanism?
3. User management - should we:
   - Keep the full user system (accounts, sessions)?
   - Simplify to just basic auth?
   - Support anonymous edits?
4. Watchlist materialization - do we want:
   - Real-time updates (write-through)?
   - Eventual consistency (queue-based)?
   - Batch updates every N seconds?
5. S3 vs MinIO for local development:
   - Use MinIO in Docker Compose (matches production)?
   - Use local file system for dev only?
These decisions will significantly impact the implementation approach. Which would you like to clarify first, or should I proceed with reasonable assumptions for all of them?

---

Remaining Clarifying Questions
1. User Management
Authentication approach for 100k users:
- Keep full MediaWiki user system (accounts, sessions, passwords, email)?
yes

2. Watchlist Materialization
Timing of watchlist updates:
- Real-time: Insert into user_recentchanges immediately when a page is edited

- Eventual consistency: Queue the updates, process in background
how would this work? 
- Batch: Update every N seconds or when batch reaches threshold

which of these are less computationally expensive for the platform as a whole if I'm watching 100M items and the top 1k powerusers all watch a mean of 10M pages? 


3. Data Strategy
Starting point:
- Fresh empty Vitess deployment (no data migration)
yes

4. Page Creation
For 1B pages:
- Start empty and grow organically
yes

---

Complete Implementation Plan
Overview
- Scale target: 100k users, 10M edits/month, 1B+ pages
- Architecture: Vitess sharding + Valkey (Redis) + SeaweedFS (existing)
- Watchlist: Batch updates every 15 minutes via existing jobrunner
- Features: Core only (view/edit pages, history, watchlists)
---
Phase 1: Database Schema Changes
1.1 New Tables (Sharded Keyspace - 8 shards by page_id)
Create table: page_watchers
CREATE TABLE /*_*/page_watchers (
  page_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (page_id, user_id),
  INDEX (user_id)
) /*$wgDBTableOptions*/;
Create table: user_recentchanges (unsharded user keyspace - 4 shards)
CREATE TABLE /*_*/user_recentchanges (
  user_id INT UNSIGNED NOT NULL,
  rc_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, rc_id),
  INDEX (rc_id)
) /*$wgDBTableOptions*/;
Create table: watchlist_last_processed (state tracking)
CREATE TABLE /*_*/watchlist_last_processed (
  page_id INT UNSIGNED NOT NULL PRIMARY KEY,
  last_processed INT UNSIGNED NOT NULL,
  last_rc_id BIGINT UNSIGNED NOT NULL
) /*$wgDBTableOptions*/;
1.2 Drop Tables
DROP TABLE watchlist;
DROP TABLE watchlist_expiry;
1.3 Update Existing Tables
Add dirty page tracking via Valkey:
- No schema changes - use Valkey dirty_page:$pageId keys with TTL
---
Phase 2: Vitess Configuration
2.1 Sharded Keyspace (pages) - 8 shards
File: vschema-sharded.json
{
  sharded: true,
  vindexes: {
    page_hash: {
      type: hash,
      params: {num_shards: 8}
    }
  },
  tables: {
    page: {
      column_vindexes: [{column: page_id, name: page_hash}]
    },
    revision: {
      column_vindexes: [{column: rev_id, name: page_hash}]
    },
    text: {
      column_vindexes: [{column: old_id, name: page_hash}]
    },
    content: {
      column_vindexes: [{column: content_id, name: page_hash}]
    },
    slots: {
      column_vindexes: [{column: slot_revision_id, name: page_hash}]
    },
    recentchanges: {
      column_vindexes: [{column: rc_cur_id, name: page_hash}]
    },
    page_watchers: {
      column_vindexes: [{column: page_id, name: page_hash}]
    },
    watchlist_last_processed: {
      column_vindexes: [{column: page_id, name: page_hash}]
    }
  }
}
2.2 Unsharded User Keyspace - 4 shards
File: vschema-user.json
{
  sharded: true,
  vindexes: {
    user_hash: {
      type: hash,
      params: {num_shards: 4}
    }
  },
  tables: {
    user: {
      column_vindexes: [{column: user_id, name: user_hash}]
    },
    user_recentchanges: {
      column_vindexes: [{column: user_id, name: user_hash}]
    },
    actor: {
      column_vindexes: [{column: actor_id, name: user_hash}]
    },
    comment: {
      column_vindexes: [{column: comment_id, name: user_hash}]
    }
  }
}
---
Phase 3: Application Code Changes
3.1 Files to Create
File: includes/watchlist/WatchlistUpdateJob.php
- Batch job that runs every 15 minutes
- Reads dirty pages from Valkey
- Queries watchers for each dirty page
- Queries recentchanges since last processed
- Inserts into user_recentchanges for each watcher
- Self-requeues with delay
File: maintenance/populateWatchlistUpdateJob.php
- One-time maintenance script to enqueue the first WatchlistUpdateJob
3.2 Files to Modify (Watchlist System)
includes/watchlist/WatchedItemStore.php
- Replace watchlist queries with page_watchers queries
- Implement getWatchersForPage($pageId) for batch job
- Remove watchlist_expiry functionality
includes/watchlist/WatchlistManager.php
- Update watch/unwatch operations to use page_watchers
- Update notification logic for user_recentchanges
includes/watchlist/WatchedItemQueryService.php
- Replace watchlist query with user_recentchanges join
- Query becomes instant:
    SELECT rc.*
  FROM recentchanges rc
  JOIN user_recentchanges urc ON rc.rc_id = urc.rc_id
  WHERE urc.user_id = ?
  ORDER BY rc.rc_timestamp DESC
  LIMIT 50
  
includes/Storage/PageUpdater.php
- After edit completes: mark page as dirty in Valkey
    $cache->set("dirty_page:$pageId", 1, 900); // 15 min TTL
  
includes/Storage/DerivedPageDataUpdater.php
- Remove all secondary data updates:
  - Link tables (pagelinks, templatelinks, imagelinks, externallinks)
  - Category links
  - Parser cache
  - Site stats
  - CDN cache
3.3 Files to Remove/Disable
Remove entire directory:
- includes/api/* - All API modules
Remove actions (keep only 6):
- Delete all files in includes/actions/ except:
  - ViewAction.php
  - EditAction.php
  - SubmitAction.php
  - HistoryAction.php
  - WatchAction.php
  - UnwatchAction.php
Remove special pages (keep only 1):
- Delete all files in includes/specials/ except:
  - SpecialWatchlist.php
Update includes/ServiceWiring.php:
- Remove API service registrations
- Remove non-core special page registrations
- Remove non-core action registrations
Update api.php:
- Return 404 or redirect to index.php
Remove deferred updates:
- SiteStatsUpdate
- RefreshSecondaryDataUpdate
- HtmlFileCacheUpdate
- CdnCacheUpdate
Remove job types from includes/DefaultSettings.php:
- Remove all $wgJobClasses except watchlistUpdate
- Remove all job-related deferred updates
3.4 Configuration Changes
LocalSettings.php
// Database configuration for Vitess
$wgDBtype = 'mysql';
$wgDBserver = 'vtgate:15306';
$wgDBname = 'sharded_keyspace';
$wgDBuser = 'wikiuser';
$wgDBpassword = 'wikidbpass';
// Keep existing S3 config
$wgExternalStores = ['S3'];
$wgDefaultExternalStore = 'S3://testbucket';
// ... existing S3 config stays
// Valkey configuration for job queue
$wgJobTypeConf = [
    'default' => [
        'class' => 'MediaWiki\\JobQueue\\JobQueueRedis',
        'redisServer' => 'valkey:6379',
        'redisConfig' => [
            'password' => 'valkeypass',
            'persistent' => true,
        ],
        'daemonized' => true,
    ],
];
// Register watchlist update job
$wgJobClasses['watchlistUpdate'] = 'MediaWiki\\Watchlist\\WatchlistUpdateJob';
// Disable non-core features
$wgUseDatabaseMessages = false;
$wgEnableUploads = false;
$wgDisableLangConversion = true;
$wgSearchType = null; // Disable search
---
Phase 4: Infrastructure
4.1 Docker Compose Updates
Add to existing docker-compose.override.yml:
services:
  # Vitess stack
  etcd:
    image: quay.io/coreos/etcd:v3.5.9
    command: etcd --listen-client-urls http://0.0.0.0:2379 --advertise-client-urls http://etcd:2379
  vtctld:
    image: vitess/vitess:latest
    command: vtctld --topo_implementation etcd --topo_global_server_address etcd:2379 --workflow_manager_init --workflow_manager_use_election
  vtgate:
    image: vitess/vitess:latest
    command: vtgate --topo_implementation etcd --topo_global_server_address etcd:2379 --grpc_port 15991 --mysql_server_port 15306
    ports: ["15306:15306", "15991:15991"]
    depends_on: [etcd]
  zookeeper:
    image: zookeeper:3.8
  # Sharded MySQL (8 shards for pages)
  mysql-shard-0:
    image: vitess/vitess:latest
  mysql-shard-1:
    image: vitess/vitess:latest
  # ... shards 2-7
  # Unsharded user DB (4 shards)
  mysql-user-0:
    image: vitess/vitess:latest
  mysql-user-1:
    image: vitess/vitess:latest
  mysql-user-2:
    image: vitess/vitess:latest
  mysql-user-3:
    image: vitess/vitess:latest
  # Valkey already exists - use for job queue
4.2 Vitess Init Script
Create scripts/init-vitess.sh:
- Create keyspace for sharded (8 shards)
- Create keyspace for user (4 shards)
- Apply VSchema files
- Initialize Vitess Sequences
---
Phase 5: Implementation Order
Step 1: Infrastructure Setup (1-2 days)
1. Update docker-compose.override.yml with Vitess services
2. Create Vitess configuration files (vschema-*.json)
3. Test Vitess connectivity
4. Create init-vitess.sh script
Step 2: Database Schema (1 day)
1. Create new tables (page_watchers, user_recentchanges, watchlist_last_processed)
2. Drop old tables (watchlist, watchlist_expiry)
3. Test schema in Vitess
Step 3: Watchlist System Rewrite (2-3 days)
1. Create WatchlistUpdateJob
2. Rewrite WatchedItemStore
3. Rewrite WatchlistManager
4. Rewrite WatchedItemQueryService
5. Test watch/unwatch operations
6. Test batch update job
Step 4: Remove Non-Core Features (2-3 days)
1. Remove API modules
2. Remove non-core actions
3. Remove non-core special pages
4. Remove secondary data updates
5. Update api.php to 404
6. Test core functionality (view, edit, history, watchlist)
Step 5: Configuration (1 day)
1. Update LocalSettings.php
2. Configure Valkey for job queue
3. Register watchlist update job
4. Test configuration
Step 6: Initial Job Setup (0.5 day)
1. Run populateWatchlistUpdateJob.php
2. Verify WatchlistUpdateJob is queued
3. Verify jobrunner processes job
4. Verify 15-minute cycle works
Step 7: Testing (2-3 days)
1. Unit tests for watchlist system
2. Integration tests for core features
3. Performance tests (100k users, 1B pages simulation)
4. Stress tests (concurrent edits, large watchlists)
---
Phase 6: Testing Strategy
6.1 Unit Tests
- WatchlistUpdateJob batch logic
- WatchedItemStore CRUD operations
- WatchlistManager watch/unwatch
- Valkey dirty page tracking
6.2 Integration Tests
- User watches page → edit page → batch job runs → watchlist shows edit
- Multiple users watch same page → edit → all see in watchlist
- Unwatch page → no longer appears in watchlist
- View page history
6.3 Performance Tests
- 1M pages in watchlist → query performance
- 100k users watching 10M pages each → batch job performance
- 10M edits/month simulation
6.4 Stress Tests
- Concurrent edits on same page (edit conflicts)
- Popular page (watched by 1k users) edited repeatedly
- Shard rebalancing under load
---
Questions Before Finalizing
1. Watchlist display: Should SpecialWatchlist show ALL recent changes (paginated) or just the most recent 50? The batch job will insert ALL rc_ids, but the view can limit to 50 or show paginated.
always paginate

2. Job queue partitioning: Should the WatchlistUpdateJob be job-queue partitioned (run on specific workers) or use default queue? Default queue is fine but may compete with other jobs if any remain.
default

3. Batch size for WatchlistUpdateJob: How many dirty pages should be processed per job run? Suggest 100-1000 to keep job runtime reasonable.
250

4. Error handling: If the batch job fails (e.g., Valkey down), should it retry or skip? retry with exponential backoff.

5. Cleanup of old user_recentchanges: Should we purge old entries? Suggest TTL of 30 days to keep table size manageable.
sounds good

---

Final Implementation Plan
Project Goals
- Scale: 100k users, 10M edits/month, 1B+ pages
- Architecture: Vitess sharding (8 page shards, 4 user shards) + Valkey + SeaweedFS
- Features: Core only (view/edit pages, history, watchlists)
- API: Disabled entirely
- Watchlist: Batch updates every 15 minutes via jobrunner, paginated display
---
Phase 1: Database Schema
1.1 Create New Tables
Table: page_watchers (sharded, page_id%8)
CREATE TABLE /*_*/page_watchers (
  page_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (page_id, user_id),
  INDEX (user_id)
) /*$wgDBTableOptions*/;
Table: user_recentchanges (unsharded user keyspace, user_id%4)
CREATE TABLE /*_*/user_recentchanges (
  user_id INT UNSIGNED NOT NULL,
  rc_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, rc_id),
  INDEX (rc_id)
) /*$wgDBTableOptions*/;
Table: watchlist_last_processed (sharded, page_id%8)
CREATE TABLE /*_*/watchlist_last_processed (
  page_id INT UNSIGNED NOT NULL PRIMARY KEY,
  last_processed INT UNSIGNED NOT NULL,
  last_rc_id BIGINT UNSIGNED NOT NULL
) /*$wgDBTableOptions*/;
1.2 Drop Old Tables
DROP TABLE watchlist;
DROP TABLE watchlist_expiry;
1.3 Cleanup Policy
- Add scheduled job to purge user_recentchanges entries older than 30 days
---
Phase 2: Vitess Configuration
2.1 Sharded Keyspace (vschema-sharded.json)
- 8 shards, hash by page_id
- Tables: page, revision, text, content, slots, recentchanges, page_watchers, watchlist_last_processed
2.2 User Keyspace (vschema-user.json)
- 4 shards, hash by user_id
- Tables: user, user_recentchanges, actor, comment
2.3 Init Script
- scripts/init-vitess.sh to create keyspaces, apply VSchema, initialize Sequences
---
Phase 3: Application Code
3.1 Create New Files
includes/watchlist/WatchlistUpdateJob.php
- Extends Job class
- Batch size: 250 dirty pages per run
- Error handling: Retry with exponential backoff
- Self-requeues with 15-minute delay
- Logic:
  1. Scan Valkey for dirty_page:* keys (limit 250)
  2. For each dirty page:
     - Get watchers from page_watchers
     - Get recentchanges since last_processed
     - Batch insert into user_recentchanges for each watcher
     - Update watchlist_last_processed
     - Delete dirty_page:* key from Valkey
  3. Requeue with jobReleaseTimestamp = now + 900
maintenance/populateWatchlistUpdateJob.php
- One-time script to enqueue first WatchlistUpdateJob
- Run once to start the 15-minute cycle
maintenance/purgeOldUserRecentChanges.php
- Scheduled job to delete user_recentchanges entries older than 30 days
3.2 Modify Watchlist System
includes/watchlist/WatchedItemStore.php
- Replace all watchlist table queries with page_watchers
- Implement:
  - addWatch($userId, $pageId) - Insert into page_watchers
  - removeWatch($userId, $pageId) - Delete from page_watchers
  - getWatchersForPage($pageId) - Return array of user_ids
  - isWatched($userId, $pageId) - Check page_watchers
includes/watchlist/WatchlistManager.php
- Update watch/unwatch to use WatchedItemStore
- Update notification logic for user_recentchanges
includes/watchlist/WatchedItemQueryService.php
- Replace watchlist query with:
    SELECT rc.*
  FROM recentchanges rc
  JOIN user_recentchanges urc ON rc.rc_id = urc.rc_id
  WHERE urc.user_id = ?
  ORDER BY rc.rc_timestamp DESC
  LIMIT 50
  - Implement pagination (OFFSET)
includes/specials/SpecialWatchlist.php
- Use paginated query (offset + limit)
- Keep existing display logic
3.3 Modify Edit Flow
includes/Storage/PageUpdater.php
- After edit completes (line ~1610):
    $cache->set("dirty_page:$pageId", 1, 900); // 15 min TTL
  
includes/Storage/DerivedPageDataUpdater.php
- Remove in doUpdates():
  - emitEvents() - Keep (PageRevisionUpdatedEvent)
  - Remove: triggerParserCacheUpdate()
  - Remove: doSecondaryDataUpdates() (links, categories, etc.)
  - Remove: SiteStatsUpdate
  - Remove: WikiPage::onArticleEdit/onArticleCreate
  - Remove: emitCdnPurgeUpdates()
3.4 Remove Non-Core Features
Remove directory: includes/api/*
Remove actions (keep only):
- includes/actions/ViewAction.php
- includes/actions/EditAction.php
- includes/actions/SubmitAction.php
- includes/actions/HistoryAction.php
- includes/actions/WatchAction.php
- includes/actions/UnwatchAction.php
Remove special pages (keep only):
- includes/specials/SpecialWatchlist.php
Update includes/ServiceWiring.php:
- Remove API service registrations
- Remove non-core special page registrations
- Remove non-core action registrations
Update api.php:
<?php
http_response_code(404);
echo "API disabled";
exit;
Remove deferred updates from includes/deferred/:
- SiteStatsUpdate.php
- RefreshSecondaryDataUpdate.php
- HtmlFileCacheUpdate.php
- CdnCacheUpdate.php
Remove job types from includes/DefaultSettings.php:
- Keep only: $wgJobClasses['watchlistUpdate']
- Remove all other job class registrations
Remove search, categories, backlinks, files, protection, deletion, moves:
- Remove includes/search/ directory
- Remove includes/category/ directory
- Disable related special pages and API
3.5 Configuration
LocalSettings.php
// Vitess database
$wgDBtype = 'mysql';
$wgDBserver = 'vtgate:15306';
$wgDBname = 'sharded_keyspace';
$wgDBuser = 'wikiuser';
$wgDBpassword = 'wikidbpass';
// Keep existing S3 config
$wgExternalStores = ['S3'];
$wgDefaultExternalStore = 'S3://testbucket';
// ... (existing S3 config stays)
// Valkey for job queue
$wgJobTypeConf = [
    'default' => [
        'class' => 'MediaWiki\\JobQueue\\JobQueueRedis',
        'redisServer' => 'valkey:6379',
        'redisConfig' => [
            'password' => 'valkeypass',
            'persistent' => true,
        ],
        'daemonized' => true,
    ],
];
// Watchlist update job
$wgJobClasses['watchlistUpdate'] = 'MediaWiki\\Watchlist\\WatchlistUpdateJob';
$wgJobBackoffThrottling['watchlistUpdate'] = [0, 100];
// Disable non-core features
$wgUseDatabaseMessages = false;
$wgEnableUploads = false;
$wgDisableLangConversion = true;
$wgSearchType = null;
---
Phase 4: Infrastructure
4.1 Update docker-compose.override.yml
services:
  # Vitess stack
  etcd:
    image: quay.io/coreos/etcd:v3.5.9
    command: etcd --listen-client-urls http://0.0.0.0:2379 --advertise-client-urls http://etcd:2379
    ports: ["2379:2379"]
  vtctld:
    image: vitess/vitess:latest
    command: vtctld --topo_implementation etcd --topo_global_server_address etcd:2379 --workflow_manager_init --workflow_manager_use_election
    depends_on: [etcd]
  vtgate:
    image: vitess/vitess:latest
    command: vtgate --topo_implementation etcd --topo_global_server_address etcd:2379 --grpc_port 15991 --mysql_server_port 15306
    ports: ["15306:15306", "15991:15991"]
    depends_on: [etctld]
  zookeeper:
    image: zookeeper:3.8
  # Sharded MySQL (8 shards)
  mysql-shard-0:
    image: vitess/vitess:latest
  mysql-shard-1:
    image: vitess/vitess:latest
  mysql-shard-2:
    image: vitess/vitess:latest
  mysql-shard-3:
    image: vitess/vitess:latest
  mysql-shard-4:
    image: vitess/vitess:latest
  mysql-shard-5:
    image: vitess/vitess:latest
  mysql-shard-6:
    image: vitess/vitess:latest
  mysql-shard-7:
    image: vitess/vitess:latest
  # Unsharded user DB (4 shards)
  mysql-user-0:
    image: vitess/vitess:latest
  mysql-user-1:
    image: vitess/vitess:latest
  mysql-user-2:
    image: vitess/vitess:latest
  mysql-user-3:
    image: vitess/vitess:latest
  # Existing services (keep)
  # mediawiki
  # valkey (rename from redis)
  # minio
  # jobrunner
4.2 Vitess Init Script
scripts/init-vitess.sh
#!/bin/bash
# Create keyspaces, shards, apply VSchema, initialize Sequences
vtctlclient CreateKeyspace --sharding_keyspace_type=sharded sharded_keyspace
vtctlclient CreateShard ... # 8 shards for sharded_keyspace
vtctlclient ApplyVSchema --vschema=vschema-sharded.json sharded_keyspace
vtctlclient CreateKeyspace --sharding_keyspace_type=sharded user_keyspace
vtctlclient CreateShard ... # 4 shards for user_keyspace
vtctlclient ApplyVSchema --vschema=vschema-user.json user_keyspace
---
Phase 5: Implementation Order
Week 1: Infrastructure Setup
1. Update docker-compose.override.yml with Vitess services
2. Create vschema-sharded.json and vschema-user.json
3. Create scripts/init-vitess.sh
4. Start Vitess stack and verify connectivity
5. Test basic Vitess queries
Week 2: Database Schema
1. Create new SQL migration script
2. Apply to Vitess keyspaces
3. Test new tables
4. Verify foreign key relationships work through Vitess
Week 3: Watchlist System Rewrite
1. Create WatchlistUpdateJob
2. Rewrite WatchedItemStore (page_watchers)
3. Rewrite WatchlistManager
4. Rewrite WatchedItemQueryService (user_recentchanges)
5. Test watch/unwatch operations
6. Create populateWatchlistUpdateJob.php
7. Test batch update job cycle
Week 4: Remove Non-Core Features
1. Remove includes/api/ directory
2. Remove non-core actions
3. Remove non-core special pages
4. Remove secondary data updates
5. Update api.php to 404
6. Remove search, categories, backlinks, files, protection, deletion, moves
7. Test core features (view, edit, history, watchlist)
Week 5: Configuration & Final Testing
1. Update LocalSettings.php
2. Configure Valkey for job queue
3. Configure cron for purgeOldUserRecentChanges
4. Run populateWatchlistUpdateJob.php
5. Verify 15-minute cycle works
6. Unit tests
7. Integration tests
8. Performance tests
9. Stress tests
---
Phase 6: Testing Strategy
6.1 Unit Tests
- WatchlistUpdateJob batch logic
- WatchedItemStore CRUD operations
- WatchlistManager watch/unwatch
- Valkey dirty page tracking
6.2 Integration Tests
- User watches page → edit page → batch job runs → watchlist shows edit (paginated)
- Multiple users watch same page → edit → all see in watchlist
- Unwatch page → no longer appears
- View page history
- Edit with concurrent edits (conflict detection)
6.3 Performance Tests
- User with 1M watched pages → query performance
- 100k users watching 10M pages each → batch job performance
- 10M edits/month simulation
- Pagination performance (deep pagination)
6.4 Stress Tests
- Popular page (watched by 1k users) edited 10 times/day
- Concurrent edits on same page
- Shard rebalancing under load
- Valkey failure → job retry with backoff
---
File Summary
Create
- includes/watchlist/WatchlistUpdateJob.php
- maintenance/populateWatchlistUpdateJob.php
- maintenance/purgeOldUserRecentChanges.php
- scripts/init-vitess.sh
- scripts/vschema-sharded.json
- scripts/vschema-user.json
- migrations/003-watchlist-rewrite.sql
Modify
- includes/watchlist/WatchedItemStore.php
- includes/watchlist/WatchlistManager.php
- includes/watchlist/WatchedItemQueryService.php
- includes/specials/SpecialWatchlist.php
- includes/Storage/PageUpdater.php
- includes/Storage/DerivedPageDataUpdater.php
- includes/ServiceWiring.php
- api.php
- LocalSettings.php
- docker-compose.override.yml
- includes/DefaultSettings.php
Remove
- includes/api/ (entire directory)
- All actions except 6 listed
- All special pages except SpecialWatchlist
- includes/deferred/SiteStatsUpdate.php
- includes/deferred/RefreshSecondaryDataUpdate.php
- includes/deferred/HtmlFileCacheUpdate.php
- includes/deferred/CdnCacheUpdate.php
- includes/search/ (directory)
- includes/category/ (directory)
---
Success Criteria
1. ✅ Core features work (view, edit, history, watchlist)
2. ✅ API is completely disabled (404)
3. ✅ Watchlist updates every 15 minutes via batch job
4. ✅ Watchlist display is paginated
5. ✅ User with 1M watched pages has sub-second watchlist query
6. ✅ Popular page edits trigger efficient batch updates
7. ✅ User_recentchanges auto-purged after 30 days
8. ✅ Vitess sharding distributes data across 8 page shards + 4 user shards
9. ✅ Job失败时自动重试（指数退避）
10. ✅ 10M edits/month target achievable
---
Estimated Timeline
- Weeks 1-2: Infrastructure + Database schema
- Weeks 3-4: Watchlist rewrite + Remove non-core
- Week 5: Testing + Final integration
Total: ~5 weeks for production-ready deployment