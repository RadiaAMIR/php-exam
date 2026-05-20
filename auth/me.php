<?php
header('Content-Type: application/json');
session_start();

if (isset($_SESSION['user_id'])) {
    echo json_encode([
        'logged_in' => true,
        'user' => [
            'name'  => $_SESSION['user_name'],
            'email' => $_SESSION['user_email'],
            'role'  => $_SESSION['user_role'],
        ],
    ]);
} else {
    echo json_encode(['logged_in' => false]);
}
