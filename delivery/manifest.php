<?php
header('Content-Type: application/manifest+json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
readfile(__DIR__ . '/manifest.json');
