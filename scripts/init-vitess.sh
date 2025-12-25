#!/bin/bash

set -e

VITESS_CELLS="zone1"
SHARDED_KEYSPACE="sharded_keyspace"
USER_KEYSPACE="user_keyspace"

echo "Initializing Vitess for MediaWiki..."

echo "Creating sharded keyspace..."
vtctlclient CreateKeyspace \
  --sharding_keyspace_type=sharded \
  --force \
  "$SHARDED_KEYSPACE"

echo "Creating 2 shards for sharded keyspace..."
for i in {0..1}; do
  vtctlclient CreateShard \
    --force \
    "$SHARDED_KEYSPACE/${i}"
done

echo "Creating user keyspace..."
vtctlclient CreateKeyspace \
  --sharding_keyspace_type=sharded \
  --force \
  "$USER_KEYSPACE"

echo "Creating 1 shard for user keyspace..."
vtctlclient CreateShard \
    --force \
    "$USER_KEYSPACE/0"

echo "Applying VSchema for sharded keyspace..."
vtctlclient ApplyVSchema \
  --vschema="$(cat /vschema/vschema-sharded.json)" \
  "$SHARDED_KEYSPACE"

echo "Creating user keyspace..."
vtctlclient CreateKeyspace \
  --sharding_keyspace_type=sharded \
  --force \
  "$USER_KEYSPACE"

echo "Creating 4 shards for user keyspace..."
for i in {0..3}; do
  vtctlclient CreateShard \
    --force \
    "$USER_KEYSPACE/${i}"
done

echo "Applying VSchema for user keyspace..."
vtctlclient ApplyVSchema \
  --vschema="$(cat /vschema/vschema-user.json)" \
  "$USER_KEYSPACE"

echo "Vitess initialization complete!"
