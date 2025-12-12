<?php
// attendance.php

session_start();
require_once 'config/database.php';
require_once 'includes/session.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header("Location: login.php");
    exit();
}

// Get user data from session
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$full_name = $_SESSION['full_name'];
$role = $_SESSION['role'];
$email = $_SESSION['email'];

// Database connection
$database = new Database();
$db = $database->getConnection();

// Initialize variables
$attendance_data = [];
$overall_percentage = 0;
$best_percentage = 0;
$best_course = '';
$total_courses = 0;

// Get attendance data based on user role
if ($role == 'student') {
    // Get attendance for student
    $query = "SELECT 
                c.id,
                c.course_code as code,
                c.course_name as course,
                u.full_name as instructor,
                COUNT(*) as total_classes,
                COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present,
                COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as absent,
                COUNT(CASE WHEN a.status = 'late' THEN 1 END) as late,
                ROUND((COUNT(CASE WHEN a.status = 'present' THEN 1 END) * 100.0 / COUNT(*)), 1) as percentage,
                MAX(a.date) as last_updated
              FROM attendance a
              INNER JOIN courses c ON a.course_id = c.id
              INNER JOIN users u ON c.teacher_id = u.id
              WHERE a.student_id = :user_id
              GROUP BY c.id, c.course_code, c.course_name, u.full_name
              ORDER BY percentage DESC";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $attendance_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate overall statistics
    if (!empty($attendance_data)) {
        $total_courses = count($attendance_data);
        $total_classes = array_sum(array_column($attendance_data, 'total_classes'));
        $total_present = array_sum(array_column($attendance_data, 'present'));
        $overall_percentage = $total_classes > 0 ? round(($total_present / $total_classes) * 100, 1) : 0;
        
        // Find best attendance
        $best_percentage = 0;
        $best_course = '';
        foreach ($attendance_data as $course) {
            if ($course['percentage'] > $best_percentage) {
                $best_percentage = $course['percentage'];
                $best_course = $course['course'];
            }
        }
    }
    
    // Generate attendance history for each course (for demo)
    foreach ($attendance_data as &$course) {
        $course['attendanceHistory'] = generateAttendanceHistory($course['percentage']);
    }
    
} elseif ($role == 'teacher') {
    // Get courses taught by teacher for attendance management
    $query = "SELECT 
                c.id,
                c.course_code as code,
                c.course_name as course,
                'You' as instructor,
                COUNT(DISTINCT a.date) as total_classes,
                COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present_count,
                COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as absent_count,
                ROUND((COUNT(CASE WHEN a.status = 'present' THEN 1 END) * 100.0 / COUNT(DISTINCT a.student_id) / COUNT(DISTINCT a.date)), 1) as avg_percentage,
                MAX(a.date) as last_updated
              FROM courses c
              LEFT JOIN attendance a ON c.id = a.course_id
              WHERE c.teacher_id = :user_id
              GROUP BY c.id, c.course_code, c.course_name
              ORDER BY c.course_code";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $attendance_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Transform data for teacher view
    foreach ($attendance_data as &$course) {
        $course['percentage'] = $course['avg_percentage'];
        $course['present'] = $course['present_count'];
        $course['absent'] = $course['absent_count'];
        $course['attendanceHistory'] = generateAttendanceHistory($course['percentage']);
    }
    
} else {
    // Admin - get all attendance summary
    $query = "SELECT 
                c.id,
                c.course_code as code,
                c.course_name as course,
                u.full_name as instructor,
                COUNT(DISTINCT a.date) as total_classes,
                COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present_count,
                COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as absent_count,
                ROUND((COUNT(CASE WHEN a.status = 'present' THEN 1 END) * 100.0 / COUNT(*)), 1) as overall_percentage,
                MAX(a.date) as last_updated
              FROM attendance a
              INNER JOIN courses c ON a.course_id = c.id
              INNER JOIN users u ON c.teacher_id = u.id
              GROUP BY c.id, c.course_code, c.course_name, u.full_name
              ORDER BY overall_percentage DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $attendance_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($attendance_data as &$course) {
        $course['percentage'] = $course['overall_percentage'];
        $course['present'] = $course['present_count'];
        $course['absent'] = $course['absent_count'];
        $course['attendanceHistory'] = generateAttendanceHistory($course['percentage']);
    }
    
    if (!empty($attendance_data)) {
        $total_courses = count($attendance_data);
        $best_percentage = $attendance_data[0]['percentage'];
        $best_course = $attendance_data[0]['course'];
        $overall_percentage = array_sum(array_column($attendance_data, 'percentage')) / $total_courses;
    }
}

// Generate attendance history array (for demo)
function generateAttendanceHistory($percentage) {
    $history = [];
    $total_days = 30;
    $present_days = round(($percentage / 100) * $total_days);
    
    for ($i = 0; $i < $total_days; $i++) {
        if ($i < $present_days) {
            $history[] = 1; // Present
        } else {
            $history[] = 0; // Absent
        }
    }
    
    // Shuffle to make it look realistic
    shuffle($history);
    
    // Ensure last few days are present (recent attendance)
    for ($i = $total_days - 5; $i < $total_days; $i++) {
        if (rand(0, 10) > 2) { // 80% chance of being present recently
            $history[$i] = 1;
        }
    }
    
    return $history;
}

// Handle attendance marking (for teachers)
if ($role == 'teacher' && isset($_POST['mark_attendance'])) {
    $course_id = (int)$_POST['course_id'];
    $student_id = (int)$_POST['student_id'];
    $status = $_POST['status'];
    $date = date('Y-m-d');
    $remarks = $_POST['remarks'] ?? '';
    
    try {
        // Check if attendance already marked for today
        $query = "SELECT id FROM attendance 
                  WHERE course_id = :course_id 
                  AND student_id = :student_id 
                  AND date = :date";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':course_id', $course_id);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->bindParam(':date', $date);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            // Update existing attendance
            $query = "UPDATE attendance 
                      SET status = :status, remarks = :remarks 
                      WHERE course_id = :course_id 
                      AND student_id = :student_id 
                      AND date = :date";
        } else {
            // Insert new attendance
            $query = "INSERT INTO attendance (course_id, student_id, date, status, remarks) 
                      VALUES (:course_id, :student_id, :date, :status, :remarks)";
        }
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':course_id', $course_id);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->bindParam(':date', $date);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':remarks', $remarks);
        
        if ($stmt->execute()) {
            $attendance_success = "Attendance marked successfully!";
        }
    } catch (PDOException $e) {
        $attendance_error = "Error marking attendance: " . $e->getMessage();
    }
}

// Get students for teacher to mark attendance
$students_list = [];
if ($role == 'teacher' && isset($_GET['course_id'])) {
    $course_id = (int)$_GET['course_id'];
    $query = "SELECT 
                u.id, 
                u.full_name, 
                u.username,
                (SELECT status FROM attendance 
                 WHERE student_id = u.id 
                 AND course_id = :course_id 
                 AND date = CURDATE()) as today_status
              FROM users u
              INNER JOIN enrollments e ON u.id = e.student_id
              WHERE e.course_id = :course_id
              AND u.role = 'student'";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':course_id', $course_id);
    $stmt->execute();
    $students_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance - Mini LMS</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  /* Reset and Base Styles */
  * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  }

  body {
    background: linear-gradient(135deg, #f8fafc 0%, #e9ecef 100%);
    color: #333;
    line-height: 1.6;
    min-height: 100vh;
  }

  /* Top Bar */
  .top-bar {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    color: white;
    padding: 1.2rem 2rem;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    position: sticky;
    top: 0;
    z-index: 100;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .top-bar h1 {
    font-family: 'Poppins', sans-serif;
    font-size: 1.8rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 1rem;
  }

  .top-bar h1 i {
    color: #6a11cb;
  }

  .user-info {
    display: flex;
    align-items: center;
    gap: 1rem;
    background: rgba(255, 255, 255, 0.1);
    padding: 0.5rem 1.2rem;
    border-radius: 50px;
    backdrop-filter: blur(10px);
  }

  .user-info img {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: 2px solid rgba(106, 17, 203, 0.3);
  }

  /* Container */
  .container {
    max-width: 1400px;
    margin: 2rem auto;
    padding: 0 2rem;
  }

  /* Page Header */
  .page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2.5rem;
    padding: 1.5rem 0;
    border-bottom: 1px solid #e2e8f0;
  }

  .page-header h2 {
    font-family: 'Poppins', sans-serif;
    font-size: 2.2rem;
    font-weight: 700;
    color: #1a1a2e;
    position: relative;
    padding-bottom: 0.5rem;
  }

  .page-header h2::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 60px;
    height: 4px;
    background: linear-gradient(90deg, #6a11cb 0%, #2575fc 100%);
    border-radius: 2px;
  }

  <?php if ($role == 'teacher'): ?>
  /* Teacher Controls */
  .teacher-controls {
    display: flex;
    gap: 1rem;
    margin-bottom: 2rem;
  }

  .teacher-controls select {
    padding: 0.75rem 1rem;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: white;
    min-width: 200px;
  }

  .teacher-controls button {
    padding: 0.75rem 1.5rem;
    background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
    color: white;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }

  /* Attendance Marking Form */
  .attendance-form-container {
    background: white;
    border-radius: 16px;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
  }

  .attendance-form-container h3 {
    color: #1a1a2e;
    margin-bottom: 1.5rem;
    font-size: 1.3rem;
  }

  .students-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
  }

  .student-item {
    background: #f8fafc;
    padding: 1rem;
    border-radius: 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .student-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
  }

  .student-info img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
  }

  .attendance-status {
    display: flex;
    gap: 0.5rem;
  }

  .status-btn {
    padding: 0.4rem 0.8rem;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    background: white;
    cursor: pointer;
    font-size: 0.85rem;
  }

  .status-btn.active {
    background: #2ecc71;
    color: white;
    border-color: #2ecc71;
  }

  .status-btn.absent {
    background: #e74c3c;
    color: white;
    border-color: #e74c3c;
  }

  .status-btn.late {
    background: #f39c12;
    color: white;
    border-color: #f39c12;
  }
  <?php endif; ?>

  /* Summary Cards */
  .attendance-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.5rem;
    margin-bottom: 3rem;
  }

  .summary-card {
    background: white;
    border-radius: 16px;
    padding: 2rem;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
    transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    position: relative;
    overflow: hidden;
    border-top: 4px solid transparent;
  }

  .summary-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.12);
  }

  .summary-card:nth-child(1) {
    border-top-color: #6a11cb;
  }

  .summary-card:nth-child(2) {
    border-top-color: #2ecc71;
  }

  .summary-card:nth-child(3) {
    border-top-color: #f39c12;
  }

  .summary-card h3 {
    font-size: 1rem;
    color: #64748b;
    font-weight: 600;
    margin-bottom: 1rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  .summary-card .value {
    font-size: 2.8rem;
    font-weight: 800;
    color: #1a1a2e;
    margin-bottom: 1.5rem;
    line-height: 1;
  }

  .summary-card .value::after {
    content: '';
    display: block;
    width: 40px;
    height: 4px;
    background: linear-gradient(90deg, #6a11cb 0%, #2575fc 100%);
    border-radius: 2px;
    margin-top: 0.5rem;
  }

  .summary-card:nth-child(2) .value::after {
    background: linear-gradient(90deg, #2ecc71 0%, #1abc9c 100%);
  }

  .summary-card:nth-child(3) .value::after {
    background: linear-gradient(90deg, #f39c12 0%, #e67e22 100%);
  }

  /* Progress Bar */
  .progress-bar {
    height: 8px;
    background-color: #e2e8f0;
    border-radius: 4px;
    overflow: hidden;
    margin-top: 1rem;
  }

  .progress-fill {
    height: 100%;
    border-radius: 4px;
    background: linear-gradient(90deg, #6a11cb 0%, #2575fc 100%);
    transition: width 1.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    position: relative;
    overflow: hidden;
  }

  .progress-fill::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(90deg, 
      rgba(255, 255, 255, 0.1) 0%, 
      rgba(255, 255, 255, 0.3) 50%, 
      rgba(255, 255, 255, 0.1) 100%);
    animation: shimmer 2s infinite;
  }

  @keyframes shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
  }

  /* Table Container */
  .table-container {
    background: white;
    border-radius: 20px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
    overflow: hidden;
    margin-bottom: 3rem;
  }

  /* Table Styling */
  table {
    width: 100%;
    border-collapse: collapse;
  }

  thead {
    background: linear-gradient(135deg, #f8fafc 0%, #edf2f7 100%);
  }

  th {
    padding: 1.5rem;
    text-align: left;
    font-weight: 600;
    color: #475569;
    font-size: 0.95rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e2e8f0;
  }

  th:first-child {
    padding-left: 2.5rem;
  }

  th:last-child {
    padding-right: 2.5rem;
  }

  tbody tr {
    transition: all 0.3s ease;
    border-bottom: 1px solid #f1f5f9;
  }

  tbody tr:last-child {
    border-bottom: none;
  }

  tbody tr:hover {
    background: #f8fafc;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.05);
  }

  td {
    padding: 1.5rem;
    color: #475569;
  }

  td:first-child {
    padding-left: 2.5rem;
    font-weight: 600;
    color: #1a1a2e;
  }

  td:last-child {
    padding-right: 2.5rem;
  }

  /* Course Cell */
  .course-title {
    display: flex;
    align-items: center;
    gap: 1rem;
    cursor: pointer;
    transition: all 0.2s;
  }

  .course-title:hover {
    color: #6a11cb;
    transform: translateX(5px);
  }

  .course-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.1rem;
  }

  /* Dynamic course icon colors based on course */
  td:first-child .course-title[data-course-id="1"] .course-icon {
    background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
  }

  td:first-child .course-title[data-course-id="2"] .course-icon {
    background: linear-gradient(135deg, #2ecc71 0%, #1abc9c 100%);
  }

  td:first-child .course-title[data-course-id="3"] .course-icon {
    background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%);
  }

  td:first-child .course-title[data-course-id="4"] .course-icon {
    background: linear-gradient(135deg, #9b59b6 0%, #8e44ad 100%);
  }

  td:first-child .course-title[data-course-id="5"] .course-icon {
    background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
  }

  /* Percentage Cell */
  .percentage-cell {
    font-weight: 700;
    font-size: 1.2rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
  }

  .percentage-cell.high {
    color: #2ecc71;
  }

  .percentage-cell.medium {
    color: #f39c12;
  }

  .percentage-cell.low {
    color: #e74c3c;
  }

  .percentage-badge {
    padding: 0.4rem 0.8rem;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
    background: rgba(46, 204, 113, 0.1);
    color: #2ecc71;
  }

  .percentage-cell.high .percentage-badge {
    background: rgba(46, 204, 113, 0.1);
    color: #2ecc71;
  }

  .percentage-cell.medium .percentage-badge {
    background: rgba(243, 156, 18, 0.1);
    color: #f39c12;
  }

  .percentage-cell.low .percentage-badge {
    background: rgba(231, 76, 60, 0.1);
    color: #e74c3c;
  }

  /* Progress Indicator */
  .progress-indicator {
    width: 100px;
    height: 6px;
    background: #e2e8f0;
    border-radius: 3px;
    overflow: hidden;
    margin-top: 0.5rem;
  }

  .progress-indicator-fill {
    height: 100%;
    border-radius: 3px;
    transition: width 1s ease;
  }

  /* Back Button */
  .back-btn {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    color: white;
    border: none;
    padding: 1rem 2rem;
    border-radius: 12px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    margin: 0 auto;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
  }

  .back-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
    transition: left 0.7s;
  }

  .back-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 15px 30px rgba(0, 0, 0, 0.2);
  }

  .back-btn:hover::before {
    left: 100%;
  }

  /* Modal Overlay */
  .modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
    backdrop-filter: blur(8px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    padding: 1rem;
    animation: fadeIn 0.3s ease;
  }

  @keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
  }

  /* Modal Content */
  .modal-content {
    background: white;
    border-radius: 20px;
    padding: 2.5rem;
    max-width: 500px;
    width: 100%;
    box-shadow: 0 30px 60px rgba(0, 0, 0, 0.3);
    animation: modalSlideIn 0.3s ease;
  }

  @keyframes modalSlideIn {
    from { opacity: 0; transform: translateY(-20px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
  }

  .modal-content h3 {
    font-family: 'Poppins', sans-serif;
    font-size: 1.5rem;
    color: #1a1a2e;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #6a11cb;
  }

  /* Modal Close Button */
  .modal-close-btn {
    background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
    color: white;
    border: none;
    padding: 0.9rem 1.8rem;
    border-radius: 12px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    margin-top: 1.5rem;
    transition: all 0.3s ease;
  }

  .modal-close-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(106, 17, 203, 0.3);
  }

  /* Notification */
  .notification {
    position: fixed;
    top: 1rem;
    right: 1rem;
    background: white;
    color: #1a1a2e;
    padding: 1.2rem 1.8rem;
    border-radius: 12px;
    box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
    z-index: 1001;
    display: flex;
    align-items: center;
    gap: 1rem;
    border-left: 4px solid #6a11cb;
    animation: slideInRight 0.5s ease;
    max-width: 400px;
  }

  @keyframes slideInRight {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
  }

  .notification.success {
    border-left-color: #2ecc71;
  }

  .notification.warning {
    border-left-color: #f39c12;
  }

  .notification.error {
    border-left-color: #e74c3c;
  }

  .notification.info {
    border-left-color: #3498db;
  }

  /* Animation for table rows */
  @keyframes fadeInRow {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
  }

  tbody tr {
    animation: fadeInRow 0.5s ease forwards;
  }

  tbody tr:nth-child(1) { animation-delay: 0.1s; }
  tbody tr:nth-child(2) { animation-delay: 0.2s; }
  tbody tr:nth-child(3) { animation-delay: 0.3s; }
  tbody tr:nth-child(4) { animation-delay: 0.4s; }
  tbody tr:nth-child(5) { animation-delay: 0.5s; }

  /* Responsive Design */
  @media (max-width: 1024px) {
    .attendance-summary {
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    }
    
    .table-container {
      overflow-x: auto;
    }
    
    table {
      min-width: 900px;
    }
  }

  @media (max-width: 768px) {
    .container {
      padding: 0 1rem;
    }
    
    .top-bar {
      flex-direction: column;
      gap: 1rem;
      padding: 1rem;
    }
    
    .user-info {
      align-self: stretch;
      justify-content: center;
    }
    
    .page-header h2 {
      font-size: 1.8rem;
    }
    
    .summary-card {
      padding: 1.5rem;
    }
    
    .summary-card .value {
      font-size: 2.2rem;
    }
    
    .modal-content {
      padding: 1.5rem;
    }
    
    .notification {
      left: 1rem;
      right: 1rem;
      max-width: none;
    }
  }

  @media (max-width: 480px) {
    .attendance-summary {
      grid-template-columns: 1fr;
    }
    
    .percentage-cell {
      flex-direction: column;
      align-items: flex-start;
      gap: 0.5rem;
    }
    
    .back-btn {
      width: 100%;
      padding: 1.2rem;
    }
  }
</style>
</head>

<body>

<div class="top-bar">
  <h1><i class="fas fa-calendar-check"></i> 
    <?php 
    if ($role == 'student') echo 'My Attendance Record';
    elseif ($role == 'teacher') echo 'Attendance Management';
    else echo 'Attendance Overview';
    ?>
  </h1>
  <div class="user-info">
    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($full_name); ?>&background=6a11cb&color=fff" alt="User">
    <span><?php echo htmlspecialchars($full_name); ?> | <?php echo ucfirst($role); ?></span>
  </div>
</div>

<div class="container">
  <!-- Page Header -->
  <div class="page-header">
    <h2>
      <?php 
      if ($role == 'student') echo 'My Course-wise Attendance';
      elseif ($role == 'teacher') echo 'Mark Student Attendance';
      else echo 'System Attendance Report';
      ?>
    </h2>
  </div>
  
  <?php if (isset($attendance_success)): ?>
  <div class="notification success">
    <i class="fas fa-check-circle"></i> <?php echo $attendance_success; ?>
  </div>
  <?php endif; ?>

  <?php if (isset($attendance_error)): ?>
  <div class="notification error">
    <i class="fas fa-times-circle"></i> <?php echo $attendance_error; ?>
  </div>
  <?php endif; ?>

  <?php if ($role == 'teacher'): ?>
  <!-- Teacher Controls -->
  <div class="teacher-controls">
    <form method="GET" action="attendance.php" style="display: flex; gap: 1rem; align-items: center;">
      <select name="course_id" onchange="this.form.submit()">
        <option value="">Select Course</option>
        <?php 
        $courses_query = "SELECT id, course_code, course_name FROM courses WHERE teacher_id = :user_id";
        $courses_stmt = $db->prepare($courses_query);
        $courses_stmt->bindParam(':user_id', $user_id);
        $courses_stmt->execute();
        $teacher_courses = $courses_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($teacher_courses as $course):
        ?>
        <option value="<?php echo $course['id']; ?>" 
                <?php echo (isset($_GET['course_id']) && $_GET['course_id'] == $course['id']) ? 'selected' : ''; ?>>
          <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_name']); ?>
        </option>
        <?php endforeach; ?>
      </select>
      
      <?php if (isset($_GET['course_id'])): ?>
      <button type="button" onclick="showMarkAttendanceModal()">
        <i class="fas fa-user-check"></i> Mark Today's Attendance
      </button>
      <?php endif; ?>
    </form>
  </div>

  <?php if (isset($_GET['course_id']) && !empty($students_list)): ?>
  <!-- Attendance Marking Form -->
  <div class="attendance-form-container">
    <h3>Mark Attendance for <?php echo date('F j, Y'); ?></h3>
    <form method="POST" action="attendance.php">
      <input type="hidden" name="course_id" value="<?php echo $_GET['course_id']; ?>">
      <div class="students-list">
        <?php foreach ($students_list as $student): ?>
        <div class="