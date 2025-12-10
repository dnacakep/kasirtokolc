<?php

require_once __DIR__ . '/../config/auth.php';

logout_user();
header('Location: ' . BASE_URL . '/pages/login.php');
exit;

