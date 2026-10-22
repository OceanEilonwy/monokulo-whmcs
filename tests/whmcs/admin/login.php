<?php
/** The stand-in's admin sign-in: this browser is now signed in as admin 1. */
require __DIR__ . '/../init.php';

$_SESSION['adminid'] = 1;
header('Location: configgateways.php');
echo 'Signed in.';
