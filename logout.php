<?php
require_once 'config/config.php';

// Çıkış işlemi
customerLogout();

// Login sayfasına yönlendir
customerRedirect('login.php?logout=1');
?>