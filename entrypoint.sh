#!/bin/bash
set -e

cd /var/www/html/w

if [ ! -f LocalSettings.php ]; then
    php maintenance/run.php install.php \
        --dbname testwiki \
        --dbtype sqlite \
        --dbpath cache/sqlite \
        --installdbuser root \
        --installdbpass '' \
        --scriptpath /w \
        --pass dockerpass \
        TestWiki Admin
fi

# echo "==> Ensuring ExternalStore protocols are enabled"
# if ! grep -q "wgExternalStores" LocalSettings.php; then
#     cat >> LocalSettings.php <<'EOF'

# # ExternalStore
# $wgExternalStores = [ 'S3' ];
# # $wgExternalStoreThreshold = 0; // enable for testing if needed
# # Skin
# $wgDefaultSkin = "Vector";
# EOF
# fi


exec "$@"
