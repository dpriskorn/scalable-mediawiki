#!/bin/bash
set -e

SHARDED_KEYSPACE="sharded_keyspace"
USER_KEYSPACE="user_keyspace"

echo "Waiting for Vitess topology..."
until vtctlclient GetKeyspaces >/dev/null 2>&1; do
  echo "  vtgate not ready yet..."
  sleep 2
done

echo "Initializing Vitess for MediaWiki..."

create_keyspace() {
  local ks="$1"
  vtctlclient GetKeyspace "$ks" >/dev/null 2>&1 || \
    vtctlclient CreateKeyspace --sharding_keyspace_type=sharded "$ks"
}

create_shard() {
  local shard="$1"
  vtctlclient GetShard "$shard" >/dev/null 2>&1 || \
    vtctlclient CreateShard "$shard"
}

echo "Creating sharded keyspace..."
create_keyspace "$SHARDED_KEYSPACE"
create_shard "$SHARDED_KEYSPACE/0"
create_shard "$SHARDED_KEYSPACE/1"

echo "Applying VSchema for sharded keyspace..."
vtctlclient ApplyVSchema \
  --vschema="$(cat /vschema/vschema-sharded.json)" \
  "$SHARDED_KEYSPACE"

echo "Creating user keyspace..."
create_keyspace "$USER_KEYSPACE"

# IMPORTANT: match tablets → shards (you only have ONE user tablet)
create_shard "$USER_KEYSPACE/0"

echo "Applying VSchema for user keyspace..."
vtctlclient ApplyVSchema \
  --vschema="$(cat /vschema/vschema-user.json)" \
  "$USER_KEYSPACE"

echo "Vitess initialization complete!"
