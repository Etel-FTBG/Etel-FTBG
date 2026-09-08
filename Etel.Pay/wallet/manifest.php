<?php
// Serve manifest.json with correct MIME type (LiteSpeed workaround)
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=0');
readfile(__DIR__ . '/manifest.json');
