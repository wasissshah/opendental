<?php
require __DIR__ . '/lib/bootstrap.php';
end_session();
session_destroy();
redirect('login.php');
