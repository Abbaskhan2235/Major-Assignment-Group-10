<?php
// includes/session.php

session_start();

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isTeacher() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'teacher';
}

function isStudent() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'student';
}

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: /mini-lms/login.html");
        exit();
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header("Location: /mini-lms/dashboard.html");
        exit();
    }
}

function requireTeacher() {
    requireLogin();
    if (!isTeacher() && !isAdmin()) {
        header("Location: /mini-lms/dashboard.html");
        exit();
    }
}
?>