<?php
/**
 * Thumbnail Handler Disabled
 *
 * The thumbnail handler has been disabled for this MediaWiki installation.
 * File uploads and thumbnails are not available.
 */

http_response_code( 404 );
header( 'Content-Type: text/html; charset=utf-8' );
echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>404 Not Found - Thumbnails Disabled</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Fira Sans', 'Droid Sans', 'Helvetica Neue', sans-serif;
            max-width: 600px;
            margin: 100px auto;
            padding: 40px 20px;
            background: #f5f5f5;
        }
        .error-container {
            background: white;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        h1 {
            color: #d33;
            margin-top: 0;
        }
        p {
            color: #666;
            line-height: 1.6;
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background: #36c;
            color: white;
            text-decoration: none;
            border-radius: 4px;
        }
        .back-link:hover {
            background: #2a5b;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <h1>Thumbnails Disabled</h1>
        <p>The thumbnail handler has been disabled for this MediaWiki installation.</p>
        <p>File uploads and image thumbnails are not available.</p>
        <p><a href="/" class="back-link">Return to Wiki</a></p>
    </div>
</body>
</html>
HTML;
