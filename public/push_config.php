<?php
require "config.php";
header('Content-Type: application/json');
echo json_encode(['vapid_public' => getenv('VAPID_PUBLIC')]);
