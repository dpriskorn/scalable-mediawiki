## Summary: MediaWiki + Wikibase Scaling Proposal (Dec 2025)

**Problem**

* MediaWiki (MW) and Wikibase do not scale to YouTube-level workloads (e.g. Wikidata, Scholia, WDQS).
* Core blockers are **revision storage**, **watchlists**, **global counters**, and **monolithic DB design**.
* Wikibase scalability cannot be solved without first fixing MW core.
* WDQS and current Wikibase architecture are no longer viable for “all of science”.
* MongoDB is unsuitable due to weak relational guarantees and poor queryability.

**Key Insight**

* Scaling MW to YouTube size effectively requires **rebuilding the data foundation**.
* Incremental fixes may not be enough; economics resemble *tearing down and rebuilding a house*.
* Long-term options:

  1. Fork MediaWiki and heavily rewrite core DB architecture
  2. Rewrite Wikibase entirely without PHP/MW
* GLM 4.6 suggested a **Vitess-based architecture**, which appears promising vs Yugabyte.

---

## Proposed Architecture

**Storage & Infrastructure**

* MariaDB shards + S3 (bottom layer)
* Vitess as sharding middleware (read/write distribution)
* Legacy MediaWiki + Wikibase PHP frontend talking to Vitess + S3
* Append-only revision storage (no stored diffs)

**Already Done**

* Proof-of-concept: MW content stored in S3 (successful ML-assisted change)

---

## Core MediaWiki Changes (Must-Have)

Focus ONLY on core functionality required for scale:

**Supported features**

* Create/view/edit pages
* Page revision history
* Create users
* Watch pages
* View watchlists (eventually consistent)

**Explicitly out of scope (initially)**

* Search
* “List all pages”
* Permissions
* Most Special pages
* Non-essential extensions

**Required architectural changes**

* Remove global revision counters → count per page only
* Redesign watchlists for **eventual consistency**

  * Async per-page revision checks
  * UI only shows latest N (e.g. 10) changes
* Eliminate global table locks and DB hot spots
* IDs must not be enumerable (hostile scraping resistance)

**Goal**

> YouTube-scale page, revision, and watchlist handling

---

## Revision & Diff Strategy

* Backend stores **full page content per revision** (append-only)
* Backend serves:

  * revision ID
  * timestamp
  * content
* **Diffs computed client-side (JS)** instead of backend diff3
* Candidate JS library: `npm diff`
* Fallback: new API endpoint returning two revisions if needed

---

## Wikibase on Top of Scalable MW

After MW core is scalable:

1. Install Wikibase
2. Disable broken code/tests
3. Restore minimal Wikibase functionality:

   * Item creation
   * Item viewing
   * Item editing
   * Item history
   * JSON endpoints
4. Redesign DB architecture for YouTube scale
5. Write tests
6. Provide Docker Compose for contributors
7. Release **Scalable Wikibase Suite v0.1.0 (MVP)**

**Post-MVP**

* Validate at ~1 billion items
* Reintroduce missing features as extensions
* Future work includes RDF streams

---

## Identifiers

* New external QIDs should be **non-enumerable**, e.g.:

  ```
  Qre4dfsd4dfs2d4
  ```
* Properties, Lexemes, etc. can keep current format (lower cardinality)

---

## Archiving / Locking at Scale

**Problem**

* Need scalable, eventually consistent locking of large item sets

**Solution**

* New `archive` flag per page, stored with page metadata in shard
* API to archive/unarchive items
* Archived items are read-only in UI
* Admin-driven, community-approved archival
* Support bulk unarchive via:

  * SPARQL subgraph
  * Line-delimited QID lists
* Similar to GitHub repository archiving, but community-governed

---

## Execution Strategy

Two paths forward:

1. Apply for grants (slow, stable)
2. Small hacker team, fast iteration (preferred)

**Immediate needs**

* Small cloud budget (~$100)
* Independent donation infra (e.g. Liberapay)
* Shared dev wiki (mediawiki.org)
* Contributors willing to hack on MW/Wikibase core

**Author stance**

* Community and institutional inertia likely blocked progress historically
* If MW+Wikibase cannot scale, Scholia and similar tools will need a new platform

---

**Core Thesis**

> MediaWiki’s monolithic architecture fundamentally blocks extreme scale.
> A Vitess-based, shard-first rewrite of MW core (then Wikibase) is the most realistic path to hosting all human knowledge at YouTube scale.
