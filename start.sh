#!/bin/bash

# Start complete MediaWiki + Vitess stack
# This script starts all services in the correct order

set -e

echo "🚀 Starting MediaWiki + Vitess stack..."
echo ""

# Check for docker compose
if command -v docker-compose &> /dev/null; then
    DC="docker-compose"
elif command -v docker &> /dev/null; then
    DC="docker compose"
else
    echo "❌ Neither docker-compose nor docker compose found"
    exit 1
fi

echo "Using: $DC"
echo ""

# Stop existing containers
echo "🛑 Stopping existing containers..."
$DC down

# Start all services with Vitess compose file
echo "📦 Starting all services..."
$DC -f docker-compose.yml -f docker-compose.vitess.yml -f docker-compose.override.yml up -d --build

echo ""
echo "⏳ Waiting for services to be ready..."

# Wait for etcd (Vitess requires it)
echo "   - Waiting for etcd..."
for i in {1..30}; do
    if docker exec mediawiki-etcd-1 etcdctl endpoint health &> /dev/null; then
        echo "   ✅ etcd is ready"
        break
    fi
    sleep 2
done

# Wait for vtctld
echo "   - Waiting for vtctld..."
for i in {1..30}; do
    if curl -s http://localhost:15999/debug/vars &> /dev/null; then
        echo "   ✅ vtctld is ready"
        break
    fi
    sleep 2
done

# Wait for vtgate
echo "   - Waiting for vtgate..."
for i in {1..30}; do
    if mysql -h127.0.0.1 -P15306 -e "SELECT 1" &> /dev/null 2>&1 || true; then
        echo "   ✅ vtgate is ready"
        break
    fi
    sleep 2
done

# Wait for vttablets
echo "   - Waiting for vttablets..."
for i in {1..30}; do
    if curl -s http://localhost:16100/debug/vars &> /dev/null; then
        echo "   ✅ vttablets are ready"
        break
    fi
    sleep 2
done

# Wait for mediawiki
echo "   - Waiting for MediaWiki..."
for i in {1..30}; do
    if curl -s -o /dev/null -w "%{http_code}" http://localhost:8080 | grep -q "200"; then
        echo "   ✅ MediaWiki is ready"
        break
    fi
    sleep 2
done

echo ""
echo "🎉 All services are ready!"
echo ""
echo "Access points:"
echo "  - MediaWiki:    http://localhost:8080"
echo "  - Vitess VTGate: localhost:15306"
echo "  - Vitess UI:     http://localhost:15999"
echo "  - SeaweedFS:     http://localhost:8333"
echo ""
echo "📊 Stack status:"
$DC ps
echo ""
echo "View logs with:"
echo "  $DC logs -f [service-name]"
echo ""
echo "Stop stack with:"
echo "  $DC down"
