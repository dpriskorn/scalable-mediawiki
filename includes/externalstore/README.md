%ExternalStore {#externalstorearch}
========================

%ExternalStore is an optional feature that enables persistent object storage
outside the main database, primarily for revision text (also known as a "blob").

The main public interface for interacting with %ExternalStore is ExternalStoreAccess.
Though note that higher-level concepts like {@link MediaWiki\Revision\RevisionRecord} and
text blobs have their own dedicated interface: {@link MediaWiki\Revision\RevisionStore}, and
{@link MediaWiki\Storage\BlobStore}.

## Concepts

### URL

Objects in external stores are internally identified by a special URL.
The URL is of the form `<store protocol>://<location>/<object name>`.

### Protocol

The protocol represents which ExternalStoreMedium class is used. The following protocols are
supported by default:

- `DB`: ExternalStoreDB
- `http`: ExternalStoreHttp
- `mwstore`: ExternalStoreMwstore

Multiple protocols may be enabled at the same time. For example, to support reading older data
while using a different protocol for new data.

Protocols are configured via {@link $wgExternalStores}. The ExternalStoreMedium class is decided
based on concatenating the value from $wgExternalStores to the string `ExternalStore`, with a
ucfirst transformation applied as-needed.

A custom protocol called "foobar" could be configured by implementing ExternalStoreMedium in a
subclass called `ExternalStoreFoobar`.

### Location

The location identifies a particular instance of given store protocol.

In the case of ExternalStoreDB, the location represents a database cluster (one or more database
servers that hold the same data).

When using the default of {@link Wikimedia::Rdbms::LBFactorySimple LBFactorySimple}, these
clusters can be configured via {@link $wgExternalServers}. Otherwise, external clusters must be
configured via {@link $wgLBFactoryConf}.

## New insertions

The destination of newly stored text blobs is configured via {@link $wgDefaultExternalStore}.
To enable use of %ExternalStore for new blobs, this must be set to a non-empty array. This can
be disabled to store new blobs in the main database instead, it does not affect how existing
blobs are read.

Each destination uses a partial URL of the form `<store protocol>://<location>`.
When a blob is inserted, we randomly pick an available protocol/location pair from this list.
Insertions will fail-over to another default destination if the chosen one is unavailable.

## Append-only {#externalstore-appendonly}

%ExternalStore is designed as an append-only system, to persist data in a way that is highly
reliable and immutable. As such, the interface is restricted to fetch and insert operations,
and specifically does not permit modification or deletion once data is stored.

This design benefits MediaWiki in a number of ways:

* The limited interface provides flexibility to each protocol implementation.
* Caching is trivial and safe.
* Stable references to external store can be kept outside of it, in the core database and anywhere
  else in caching or other storage layers, without needing to track of propagate changes.
* Historical data can be stored with high reliability guarantees and operational safety:
  * External database clusters may be operated in read-only mode, directly through MySQL.
  * Each replica within the cluster may operate as independent static backup.
  * Database replication between hosts may be turned off.
  * Even command-line access from outside MediaWiki can't accidentally affect historical data.

In case of maintenance tasks such as recompression, we generally iterate through known blobs
and write new blobs as-needed and gracefully update pointers accordingly. If an entire cluster
has been copied or recompressed to a new location, it can be taken out of rotation, with any
storage space freed at that time. Note that multiple locations may be physically colocated
on the same hardware, e.g. by running multiple instances of MySQL. Although it may be simpler
to free space by doing recompression during other routine maintenance, such as when migrating
data from old to new hardware.

## S3-Compatible Storage {#externalstore-s3}

MediaWiki supports storing text blobs in any S3-compatible object storage service via the
`ExternalStoreS3` class. This includes:

- Amazon S3
- MinIO
- Ceph RADOS Gateway
- DigitalOcean Spaces
- Backblaze B2
- Wasabi
- Any other S3-compatible service

### Configuration

To enable S3 storage, add the following to your `LocalSettings.php`:

```php
// Enable the S3 external store protocol
$wgExternalStores = [ 'S3' ];

// Set S3 as the default storage for new blobs
// The bucket name is specified after S3://
$wgDefaultExternalStore = [ 'S3://mediawiki-blobs' ];

// Configure S3 connection settings
$wgExternalStoreS3Config = [
    'endpoint' => 'https://s3.example.com',
    'region' => 'us-east-1',
    'accessKey' => 'your-access-key',
    'secretKey' => 'your-secret-key',
    'usePathStyle' => true,  // Required for MinIO, Ceph, etc.
];
```

### Configuration Options

| Option | Required | Default | Description |
|--------|----------|---------|-------------|
| `endpoint` | Yes | - | S3 endpoint URL (e.g., `https://s3.amazonaws.com` or `http://localhost:9000`) |
| `region` | No | `us-east-1` | AWS region for signature calculation |
| `accessKey` | Yes | - | S3 access key ID |
| `secretKey` | Yes | - | S3 secret access key |
| `usePathStyle` | No | `true` | Use path-style URLs (`endpoint/bucket/key`) instead of virtual-hosted style (`bucket.endpoint/key`). Must be `true` for MinIO, Ceph, and most self-hosted S3-compatible services |
| `readOnly` | No | `false` | Set to `true` to prevent writes to this store |

### Security: Keeping Credentials Safe

Never commit credentials to version control. Instead, use one of these approaches:

**Option 1: Environment Variables (Recommended)**

```php
$wgExternalStoreS3Config = [
    'endpoint' => getenv( 'S3_ENDPOINT' ) ?: 'http://localhost:9000',
    'region' => getenv( 'S3_REGION' ) ?: 'us-east-1',
    'accessKey' => getenv( 'S3_ACCESS_KEY' ),
    'secretKey' => getenv( 'S3_SECRET_KEY' ),
    'usePathStyle' => true,
];
```

**Option 2: External Secrets File**

```php
// Load credentials from a file outside the webroot
$secrets = require '/etc/mediawiki/secrets.php';

$wgExternalStoreS3Config = [
    'endpoint' => 'https://s3.example.com',
    'region' => 'us-east-1',
    'accessKey' => $secrets['s3_access_key'],
    'secretKey' => $secrets['s3_secret_key'],
    'usePathStyle' => true,
];
```

### Provider-Specific Examples

#### Amazon S3

```php
$wgExternalStoreS3Config = [
    'endpoint' => 'https://s3.us-east-1.amazonaws.com',
    'region' => 'us-east-1',
    'accessKey' => getenv( 'AWS_ACCESS_KEY_ID' ),
    'secretKey' => getenv( 'AWS_SECRET_ACCESS_KEY' ),
    'usePathStyle' => false,  // AWS supports virtual-hosted style
];
```

#### MinIO

```php
$wgExternalStoreS3Config = [
    'endpoint' => 'http://localhost:9000',
    'region' => 'us-east-1',
    'accessKey' => getenv( 'MINIO_ROOT_USER' ),
    'secretKey' => getenv( 'MINIO_ROOT_PASSWORD' ),
    'usePathStyle' => true,  // Required for MinIO
];
```

#### Ceph RADOS Gateway

```php
$wgExternalStoreS3Config = [
    'endpoint' => 'https://ceph-rgw.example.com',
    'region' => 'default',
    'accessKey' => getenv( 'CEPH_ACCESS_KEY' ),
    'secretKey' => getenv( 'CEPH_SECRET_KEY' ),
    'usePathStyle' => true,
];
```

#### DigitalOcean Spaces

```php
$wgExternalStoreS3Config = [
    'endpoint' => 'https://nyc3.digitaloceanspaces.com',
    'region' => 'nyc3',
    'accessKey' => getenv( 'DO_SPACES_KEY' ),
    'secretKey' => getenv( 'DO_SPACES_SECRET' ),
    'usePathStyle' => false,
];
```

### URL Format

Blobs stored in S3 use URLs of the form:

```
S3://<bucket>/<key>
```

For example: `S3://mediawiki-blobs/ab/20231215123456-abcdef1234567890...`

Keys are automatically generated using a hash-based sharding scheme for optimal S3 performance:
- First 2 characters of the content SHA-1 hash (for partition distribution)
- Timestamp
- Full SHA-1 hash

### Prerequisites

The S3 bucket must exist before MediaWiki can store blobs. Create it using your S3 provider's
tools or CLI:

```bash
# AWS CLI
aws s3 mb s3://mediawiki-blobs

# MinIO Client
mc mb myminio/mediawiki-blobs
```

### Migrating Existing Data

If you have existing revision text in the database's `text` table and want to move it to S3,
you can use the `maintenance/storage/moveToExternal.php` script. See the storage maintenance
documentation for details.

### PostgreSQL Compatibility

The S3 external store is fully compatible with PostgreSQL. Since the actual blob data is stored
in S3 (not in the database), only the blob address string (e.g., `es:S3://bucket/key`) is stored
in the `content.content_address` column, which works identically across all database backends.
