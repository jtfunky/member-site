<?php
// Central bootstrap — loads the common includes used by page scripts.
// auth.php transitively pulls in config.php, db.php, and security.php.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/geo.php';
require_once __DIR__ . '/mail.php';
