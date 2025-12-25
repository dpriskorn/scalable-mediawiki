{
	"comment": "Add tables for watchlist rewrite using materialized view pattern",
	"before": {},
	"after": {
		"tables": [
			{
				"name": "user_recentchanges",
				"comment": "Materialized watchlist data for instant watchlist queries. Each row represents a recent change watched by a user.",
				"columns": [
					{
						"name": "user_id",
						"comment": "User ID who is watching this change",
						"type": "integer",
						"options": { "unsigned": true, "notnull": true }
					},
					{
						"name": "rc_id",
						"comment": "Reference to recentchanges.rc_id",
						"type": "bigint",
						"options": { "unsigned": true, "notnull": true }
					}
				],
				"indexes": [
					{
						"name": "user_rc",
						"columns": [ "user_id", "rc_id" ],
						"unique": true
					}
				],
				"pk": [ "user_id", "rc_id" ]
			},
			{
				"name": "page_watchers",
				"comment": "Index of which users watch which pages. Enables efficient lookup of watchers for a given page.",
				"columns": [
					{
						"name": "page_id",
						"comment": "Page ID being watched",
						"type": "integer",
						"options": { "unsigned": true, "notnull": true }
					},
					{
						"name": "user_id",
						"comment": "User ID watching this page",
						"type": "integer",
						"options": { "unsigned": true, "notnull": true }
					}
				],
				"indexes": [
					{
						"name": "page_user",
						"columns": [ "page_id", "user_id" ],
						"unique": true
					},
					{
						"name": "user_page",
						"columns": [ "user_id", "page_id" ],
						"unique": false
					}
				],
				"pk": [ "page_id", "user_id" ]
			},
			{
				"name": "watchlist_last_processed",
				"comment": "Tracks the last recent change processed for each page. Used to avoid re-processing changes.",
				"columns": [
					{
						"name": "page_id",
						"comment": "Page ID",
						"type": "integer",
						"options": { "unsigned": true, "notnull": true }
					},
					{
						"name": "last_processed",
						"comment": "Unix timestamp of last processed change",
						"type": "integer",
						"options": { "unsigned": true, "notnull": false }
					},
					{
						"name": "last_rc_id",
						"comment": "Last processed recentchanges ID",
						"type": "bigint",
						"options": { "unsigned": true, "notnull": false }
					}
				],
				"indexes": [
					{
						"name": "page_last_processed",
						"columns": [ "page_id" ],
						"unique": true
					}
				],
				"pk": [ "page_id" ]
			}
		]
	}
}
