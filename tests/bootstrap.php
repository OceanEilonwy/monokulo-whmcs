<?php
// Composer's autoloader (PHPUnit, Laravel's database, the tests' own classes
// and the stand-in WHMCS's), then the gateway's library from src/, exactly
// as the gateway loads it.
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/modules/gateways/monokulo/autoload.php';
