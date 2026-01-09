<?php
// public/logout.php
require_once __DIR__ . '/../config/db.php';

session_unset();
session_destroy();

header('Location: login.php');
exit;
