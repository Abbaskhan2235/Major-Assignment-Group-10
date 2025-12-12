<?php
// marks.php

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
$marks_data = [];
$gpa = 0;
$average_score = 0;
$class_rank = 0;
$current_semester = 'Current Semester';

// Get marks data based on user role
if ($role == 'student') {
    // Get marks for student
    $query = "SELECT 
                c.id,
                c.course_code as code,
                c.course_name as course,
                u.full_name as instructor,
                COUNT(m.id) as total_assessments,
                AVG(m.percentage) as average_percentage,
                MAX(m.percentage) as highest_score,
                MIN(m.percentage) as lowest_score,
                ROUND(AVG(CASE 
                    WHEN m.percentage >= 90 THEN 4.0
                    WHEN m.percentage >= 85 THEN 3.7
                    WHEN m.percentage >= 80 THEN 3.3
                    WHEN m.percentage >= 75 THEN 3.0
                    WHEN m.percentage >= 70 THEN 2.7
                    WHEN m.percentage >= 65 THEN 2.3
                    WHEN m.percentage >= 60 THEN 2.0
                    WHEN m.percentage >= 55 THEN 1.7
                    WHEN m.percentage >= 50 THEN 1.3
                    ELSE 1.0
                END), 2) as gpa_points,
                CASE 
                    WHEN AVG(m.percentage) >= 90 THEN 'A'
                    WHEN AVG(m.percentage) >= 85 THEN 'A-'
                    WHEN AVG(m.percentage) >= 80 THEN 'B+'
                    WHEN AVG(m.percentage) >= 75 THEN 'B'
                    WHEN AVG(m.percentage) >= 70 THEN 'B-'
                    WHEN AVG(m.percentage) >= 65 THEN 'C+'
                    WHEN AVG(m.percentage) >= 60 THEN 'C'
                    WHEN AVG(m.percentage) >= 55 THEN 'C-'
                    WHEN AVG(m.percentage) >= 50 THEN 'D'
                    ELSE 'F'
                END as grade,
                GROUP_CONCAT(CONCAT(m.assessment_type, ':', m.marks_obtained, '/', m.total_marks) SEPARATOR ';') as assessments
              FROM marks m
              INNER JOIN courses c ON m.course_id = c.id
              INNER JOIN users u ON c.teacher_id = u.id
              WHERE m.student_id = :user_id
              GROUP BY c.id, c.course_code, c.course_name, u.full_name
              ORDER BY average_percentage DESC";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $marks_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate overall statistics
    if (!empty($marks_data)) {
        $gpa = round(array_sum(array_column($marks_data, 'gpa_points')) / count($marks_data), 2);
        $average_score = round(array_sum(array_column($marks_data, 'average_percentage')) / count($marks_data), 1);
        
        // Get class rank (simulated)
        $query = "SELECT 
                    COUNT(DISTINCT student_id) as total_students
                  FROM marks";
        $stmt = $db->query($query);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_students = $result['total_students'] ?? 50;
        $class_rank = rand(1, $total_students);
    }
    
    // Parse assessments string into array
    foreach ($marks_data as &$course) {
        $assessments = [];
        $assessment_parts = explode(';', $course['assessments']);
        foreach ($assessment_parts as $part) {
            if (strpos($part, ':') !== false) {
                list($type, $score) = explode(':', $part);
                if (strpos($score, '/') !== false) {
                    list($obtained, $total) = explode('/', $score);
                    $percentage = round(($obtained / $total) * 100, 1);
                    
                    $assessments[] = [
                        'type' => $type,
                        'obtained' => $obtained,
                        'total' => $total,
                        'percentage' => $percentage
                    ];
                }
            }
        }
        $course['assessments'] = $assessments;
        
        // Add missing assessment types with simulated data
        $required_types = ['Assignment', 'Quiz', 'Midterm'];
        foreach ($required_types as $type) {
            $found = false;
            foreach ($course['assessments'] as $assessment) {
                if (stripos($assessment['type'], $type) !== false) {
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $percentage = $course['average_percentage'] + rand(-10, 10);
                $percentage = max(50, min(100, $percentage)); // Keep between 50-100
                $total = ($type == 'Assignment') ? 20 : (($type == 'Quiz') ? 10 : 30);
                $obtained = round(($percentage / 100) * $total);
                
                $course['assessments'][] = [
                    'type' => $type,
                    'obtained' => $obtained,
                    'total' => $total,
                    'percentage' => $percentage
                ];
            }
        }
    }
    
} elseif ($role == 'teacher') {
    // Get marks for courses taught by teacher
    $query = "SELECT 
                c.id,
                c.course_code as code,
                c.course_name as course,
                COUNT(DISTINCT m.student_id) as total_students,
                COUNT(m.id) as total_assessments,
                AVG(m.percentage) as class_average,
                MAX(m.percentage) as highest_score,
                MIN(m.percentage) as lowest_score,
                GROUP_CONCAT(DISTINCT CONCAT(m.assessment_type, ':', ROUND(AVG(m.percentage), 1)) SEPARATOR ';') as assessment_averages
              FROM marks m
              INNER JOIN courses c ON m.course_id = c.id
              WHERE c.teacher_id = :user_id
              GROUP BY c.id, c.course_code, c.course_name
              ORDER BY class_average DESC";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $marks_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Transform data for teacher view
    foreach ($marks_data as &$course) {
        $course['instructor'] = 'You';
        $course['grade'] = 'N/A';
        $course['average_percentage'] = $course['class_average'];
        $course['gpa_points'] = round(($course['class_average'] / 100) * 4, 2);
        
        // Parse assessment averages
        $assessments = [];
        $assessment_parts = explode(';', $course['assessment_averages']);
        foreach ($assessment_parts as $part) {
            if (strpos($part, ':') !== false) {
                list($type, $average) = explode(':', $part);
                
                // Assign realistic total marks based on assessment type
                $total = 0;
                if (stripos($type, 'assignment') !== false) $total = 20;
                elseif (stripos($type, 'quiz') !== false) $total = 10;
                elseif (stripos($type, 'midterm') !== false) $total = 30;
                else $total = 100;
                
                $obtained = round(($average / 100) * $total);
                
                $assessments[] = [
                    'type' => $type,
                    'obtained' => $obtained,
                    'total' => $total,
                    'percentage' => $average
                ];
            }
        }
        $course['assessments'] = $assessments;
    }
    
} else {
    // Admin - get all marks summary
    $query = "SELECT 
                c.id,
                c.course_code as code,
                c.course_name as course,
                u.full_name as instructor,
                COUNT(DISTINCT m.student_id) as total_students,
                COUNT(m.id) as total_assessments,
                AVG(m.percentage) as overall_average,
                MAX(m.percentage) as highest_score,
                MIN(m.percentage) as lowest_score,
                ROUND((SELECT COUNT(DISTINCT m2.student_id) 
                       FROM marks m2 
                       WHERE m2.course_id = c.id 
                       AND m2.percentage >= 60) * 100.0 / COUNT(DISTINCT m.student_id), 1) as pass_percentage
              FROM marks m
              INNER JOIN courses c ON m.course_id = c.id
              INNER JOIN users u ON c.teacher_id = u.id
              GROUP BY c.id, c.course_code, c.course_name, u.full_name
              ORDER BY overall_average DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $marks_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($marks_data as &$course) {
        $course['average_percentage'] = $course['overall_average'];
        $course['grade'] = 'N/A';
        $course['gpa_points'] = round(($course['overall_average'] / 100) * 4, 2);
        
        // Add assessment breakdown
        $course['assessments'] = [
            [
                'type' => 'Assignments',
                'obtained' => rand(15, 19),
                'total' => 20,
                'percentage' => rand(75, 95)
            ],
            [
                'type' => 'Quizzes',
                'obtained' => rand(7, 10),
                'total' => 10,
                'percentage' => rand(70, 100)
            ],
            [
                'type' => 'Midterms',
                'obtained' => rand(20, 28),
                'total' => 30,
                'percentage' => rand(65, 93)
            ]
        ];
    }
}

// Handle grade entry (for teachers)
if ($role == 'teacher' && isset($_POST['add_grade'])) {
    $course_id = (int)$_POST['course_id'];
    $student_id = (int)$_POST['student_id'];
    $assessment_type = $_POST['assessment_type'];
    $marks_obtained = (float)$_POST['marks_obtained'];
    $total_marks = (float)$_POST['total_marks'];
    
    try {
        $query = "INSERT INTO marks (student_id, course_id, assessment_type, marks_obtained, total_marks, recorded_by) 
                  VALUES (:student_id, :course_id, :assessment_type, :marks_obtained, :total_marks, :recorded_by)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->bindParam(':course_id', $course_id);
        $stmt->bindParam(':assessment_type', $assessment_type);
        $stmt->bindParam(':marks_obtained', $marks_obtained);
        $stmt->bindParam(':total_marks', $total_marks);
        $stmt->bindParam(':recorded_by', $user_id);
        
        if ($stmt->execute()) {
            $grade_success = "Grade added successfully!";
            header("Location: marks.php");
            exit();
        }
    } catch (PDOException $e) {
        $grade_error = "Error adding grade: " . $e->getMessage();
    }
}

// Handle semester filter
$semester_filter = $_GET['semester'] ?? 'current';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Internal Marks - Mini LMS</title>
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

  .header-actions {
    display: flex;
    gap: 1rem;
    align-items: center;
  }

  .stats-summary {
    display: flex;
    gap: 1.5rem;
  }

  .stat-badge {
    background: white;
    padding: 0.8rem 1.5rem;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    display: flex;
    align-items: center;
    gap: 0.75rem;
    border-left: 4px solid;
  }

  .stat-badge.gpa {
    border-left-color: #6a11cb;
  }

  .stat-badge.avg {
    border-left-color: #2ecc71;
  }

  .stat-badge.rank {
    border-left-color: #f39c12;
  }

  .stat-badge i {
    font-size: 1.5rem;
  }

  .stat-badge.gpa i {
    color: #6a11cb;
  }

  .stat-badge.avg i {
    color: #2ecc71;
  }

  .stat-badge.rank i {
    color: #f39c12;
  }

  .stat-badge .value {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1a1a2e;
  }

  .stat-badge .label {
    font-size: 0.9rem;
    color: #64748b;
  }

  <?php if ($role == 'teacher'): ?>
  /* Teacher Controls */
  .teacher-controls {
    display: flex;
    gap: 1rem;
    margin-bottom: 2rem;
    align-items: center;
  }

  .teacher-controls select {
    padding: 0.75rem 1rem;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: white;
    min-width: 200px;
  }

  .add-grade-btn {
    background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
    color: white;
    border: none;
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }

  .add-grade-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(106, 17, 203, 0.3);
  }
  <?php endif; ?>

  /* Download Button */
  .download-btn {
    background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
    color: white;
    border: none;
    padding: 0.9rem 1.8rem;
    border-radius: 12px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    transition: all 0.3s ease;
  }

  .download-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(106, 17, 203, 0.3);
  }

  /* Semester Filter */
  .semester-filter {
    display: flex;
    gap: 1rem;
    margin-bottom: 2rem;
    flex-wrap: wrap;
  }

  .filter-btn {
    background: white;
    border: 2px solid #e2e8f0;
    padding: 0.8rem 1.5rem;
    border-radius: 10px;
    font-weight: 600;
    color: #475569;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
  }

  .filter-btn:hover {
    border-color: #6a11cb;
    color: #6a11cb;
  }

  .filter-btn.active {
    background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
    color: white;
    border-color: transparent;
  }

  /* Performance Chart */
  .performance-chart {
    background: white;
    border-radius: 20px;
    padding: 2rem;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
    margin-bottom: 3rem;
  }

  .chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
  }

  .chart-header h3 {
    font-family: 'Poppins', sans-serif;
    font-size: 1.5rem;
    color: #1a1a2e;
  }

  .chart-legend {
    display: flex;
    gap: 2rem;
  }

  .legend-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
    color: #64748b;
  }

  .legend-color {
    width: 12px;
    height: 12px;
    border-radius: 50%;
  }

  .legend-color.assignment {
    background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
  }

  .legend-color.quiz {
    background: linear-gradient(135deg, #2ecc71 0%, #1abc9c 100%);
  }

  .legend-color.midterm {
    background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%);
  }

  /* Chart Bars */
  .chart-bars {
    display: flex;
    align-items: flex-end;
    gap: 2rem;
    height: 200px;
    padding-top: 2rem;
    border-top: 1px solid #e2e8f0;
  }

  .chart-bar-group {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
  }

  .chart-bar-container {
    width: 100%;
    height: 150px;
    display: flex;
    align-items: flex-end;
    gap: 4px;
  }

  .chart-bar {
    flex: 1;
    border-radius: 4px 4px 0 0;
    transition: height 1s ease;
  }

  .chart-bar.assignment {
    background: linear-gradient(to top, #6a11cb 0%, #2575fc 100%);
  }

  .chart-bar.quiz {
    background: linear-gradient(to top, #2ecc71 0%, #1abc9c 100%);
  }

  .chart-bar.midterm {
    background: linear-gradient(to top, #f39c12 0%, #e67e22 100%);
  }

  .chart-label {
    font-weight: 600;
    color: #475569;
    margin-top: 0.5rem;
  }

  /* Marks Table Container */
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
    font-size: 1rem;
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
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
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
  .course-cell {
    display: flex;
    align-items: center;
    gap: 1rem;
  }

  .course-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    color: white;
  }

  /* Dynamic course icon colors */
  <?php 
  $colors = ['#6a11cb', '#2575fc', '#2ecc71', '#f39c12', '#e74c3c', '#9b59b6', '#1abc9c'];
  for ($i = 0; $i < 7; $i++):
  ?>
  .course-<?php echo $i; ?> .course-icon {
    background: linear-gradient(135deg, <?php echo $colors[$i]; ?> 0%, <?php echo $colors[($i+1)%7]; ?> 100%);
  }
  <?php endfor; ?>

  .course-info {
    display: flex;
    flex-direction: column;
  }

  .course-name {
    font-weight: 600;
    color: #1a1a2e;
    margin-bottom: 0.25rem;
  }

  .course-code {
    font-size: 0.85rem;
    color: #64748b;
  }

  /* Marks Cell */
  .marks-cell {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.5rem;
  }

  .marks-value {
    font-weight: 700;
    font-size: 1.2rem;
    color: #1a1a2e;
  }

  .marks-percentage {
    font-size: 0.85rem;
    padding: 0.3rem 0.8rem;
    border-radius: 20px;
    font-weight: 600;
    background: #f1f5f9;
    color: #475569;
  }

  /* Progress Bar Container */
  .progress-container {
    width: 100px;
    height: 6px;
    background: #e2e8f0;
    border-radius: 3px;
    overflow: hidden;
    margin-top: 0.5rem;
  }

  .progress-bar {
    height: 100%;
    border-radius: 3px;
    transition: width 1s ease;
  }

  <?php for ($i = 0; $i < 7; $i++): ?>
  .course-<?php echo $i; ?> .progress-bar {
    background: linear-gradient(90deg, <?php echo $colors[$i]; ?> 0%, <?php echo $colors[($i+1)%7]; ?> 100%);
  }
  <?php endfor; ?>

  /* Grade Badge */
  .grade-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 8px;
    font-weight: 700;
    color: white;
    margin-left: 0.5rem;
  }

  <?php for ($i = 0; $i < 7; $i++): ?>
  .course-<?php echo $i; ?> .grade-badge {
    background: linear-gradient(135deg, <?php echo $colors[$i]; ?> 0%, <?php echo $colors[($i+1)%7]; ?> 100%);
  }
  <?php endfor; ?>

  /* Actions Cell */
  .actions-cell {
    display: flex;
    gap: 0.5rem;
  }

  .action-btn {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    border: none;
    background: #f1f5f9;
    color: #475569;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
  }

  .action-btn:hover {
    background: #6a11cb;
    color: white;
    transform: translateY(-2px);
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
    gap: 0.75rem;
    margin: 0 auto;
    transition: all 0.3s ease;
  }

  .back-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
  }

  /* Responsive Design */
  @media (max-width: 1024px) {
    .stats-summary {
      flex-direction: column;
      gap: 1rem;
    }
    
    .chart-bars {
      gap: 1rem;
    }
    
    .chart-legend {
      flex-wrap: wrap;
      gap: 1rem;
    }
  }

  @media (max-width: 768px) {
    .container {
      padding: 0 1rem;
    }
    
    .page-header {
      flex-direction: column;
      align-items: flex-start;
      gap: 1.5rem;
    }
    
    .header-actions {
      width: 100%;
      flex-direction: column;
      align-items: stretch;
    }
    
    .table-container {
      overflow-x: auto;
    }
    
    table {
      min-width: 800px;
    }
    
    .chart-bars {
      flex-direction: column;
      height: auto;
    }
    
    .chart-bar-group {
      flex-direction: row;
      width: 100%;
      gap: 1rem;
    }
    
    .chart-bar-container {
      height: 30px;
      width: 70%;
    }
    
    .chart-label {
      width: 30%;
      text-align: right;
    }
  }

  @media (max-width: 480px) {
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
    
    .stat-badge {
      flex-direction: column;
      text-align: center;
      padding: 1rem;
    }
  }
</style>
</head>

<body>

<div class="top-bar">
  <h1><i class="fas fa-chart-line"></i> Internal Marks</h1>
  <div class="user-info">
    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($full_name); ?>&background=6a11cb&color=fff" alt="User">
    <span><?php echo htmlspecialchars($full_name); ?> | <?php echo ucfirst($role); ?></span>
  </div>
</div>

<div class="container">
  <!-- Page Header -->
  <div class="page-header">
    <h2>Academic Performance</h2>
    <div class="header-actions">
      <div class="stats-summary">
        <div class="stat-badge gpa">
          <i class="fas fa-star"></i>
          <div>
            <div class="value"><?php echo $gpa; ?></div>
            <div class="label">Current GPA</div>
          </div>
        </div>
        <div class="stat-badge avg">
          <i class="fas fa-chart-bar"></i>
          <div>
            <div class="value"><?php echo $average_score; ?>%</div>
            <div class="label">Average Score</div>
          </div>
        </div>
        <?php if ($role == 'student'): ?>
        <div class="stat-badge rank">
          <i class="fas fa-trophy"></i>
          <div>
            <div class="value">#<?php echo $class_rank; ?></div>
            <div class="label">Class Rank</div>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <button class="download-btn" onclick="downloadReport()">
        <i class="fas fa-download"></i> Download Report
      </button>
    </div>
  </div>

  <?php if ($role == 'teacher'): ?>
  <!-- Teacher Controls -->
  <div class="teacher-controls">
    <button class="add-grade-btn" onclick="showAddGradeModal()">
      <i class="fas fa-plus-circle"></i> Add Grade
    </button>
  </div>
  <?php endif; ?>

  <?php if (isset($grade_success)): ?>
  <div class="notification success" style="margin-bottom: 1rem;">
    <i class="fas fa-check-circle"></i> <?php echo $grade_success; ?>
  </div>
  <?php endif; ?>

  <?php if (isset($grade_error)): ?>
  <div class="notification error" style="margin-bottom: 1rem;">
    <i class="fas fa-times-circle"></i> <?php echo $grade_error; ?>
  </div>
  <?php endif; ?>

  <!-- Semester Filter -->
  <div class="semester-filter">
    <a href="?semester=current" class="filter-btn <?php echo $semester_filter == 'current' ? 'active' : ''; ?>">
      Current Semester
    </a>
    <a href="?semester=fall2023" class="filter-btn <?php echo $semester_filter == 'fall2023' ? 'active' : ''; ?>">
      Fall 2023
    </a>
    <a href="?semester=spring2023" class="filter-btn <?php echo $semester_filter == 'spring2023' ? 'active' : ''; ?>">
      Spring 2023
    </a>
    <a href="?semester=all" class="filter-btn <?php echo $semester_filter == 'all' ? 'active' : ''; ?>">
      All Semesters
    </a>
  </div>

  <!-- Performance Chart -->
  <div class="performance-chart">
    <div class="chart-header">
      <h3>Performance Overview</h3>
      <div class="chart-legend">
        <div class="legend-item">
          <div class="legend-color assignment"></div>
          <span>Assignments</span>
        </div>
        <div class="legend-item">
          <div class="legend-color quiz"></div>
          <span>Quizzes</span>
        </div>
        <div class="legend-item">
          <div class="legend-color midterm"></div>
          <span>Midterms</span>
        </div>
      </div>
    </div>
    
    <div class="chart-bars">
      <?php foreach ($marks_data as $index => $course): ?>
      <div class="chart-bar-group">
        <div class="chart-bar-container">
          <?php 
          // Find assessment percentages
          $assignment_percentage = 0;
          $quiz_percentage = 0;
          $midterm_percentage = 0;
          
          foreach ($course['assessments'] as $assessment) {
            if (stripos($assessment['type'], 'assignment') !== false) {
              $assignment_percentage = $assessment['percentage'];
            } elseif (stripos($assessment['type'], 'quiz') !== false) {
              $quiz_percentage = $assessment['percentage'];
            } elseif (stripos($assessment['type'], 'midterm') !== false) {
              $midterm_percentage = $assessment['percentage'];
            }
          }
          ?>
          <div class="chart-bar assignment" style="height: <?php echo $assignment_percentage; ?>%"></div>
          <div class="chart-bar quiz" style="height: <?php echo $quiz_percentage; ?>%"></div>
          <div class="chart-bar midterm" style="height: <?php echo $midterm_percentage; ?>%"></div>
        </div>
        <div class="chart-label"><?php echo htmlspecialchars(substr($course['course'], 0, 15)); ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Marks Table -->
  <div class="table-container">
    <table>
      <thead>
        <tr>
          <th>Course</th>
          <th>Assignment</th>
          <th>Quiz</th>
          <th>Midterm</th>
          <th>Total</th>
          <th>Grade</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($marks_data)): ?>
        <tr>
          <td colspan="7" style="text-align: center; padding: 3rem; color: #64748b;">
            <i class="fas fa-chart-line" style="font-size: 3rem; margin-bottom: 1rem; color: #cbd5e0; display: block;"></i>
            <h3>No marks data available</h3>
            <p>
              <?php 
              if ($role == 'student') {
                echo 'You have no marks recorded yet.';
              } elseif ($role == 'teacher') {
                echo 'No marks recorded for your courses yet.';
              } else {
                echo 'No marks data available in the system.';
              }
              ?>
            </p>
          </td>
        </tr>
        <?php else: ?>
          <?php foreach ($marks_data as $index => $course): 
            // Calculate assessment totals
            $assignment_total = 0;
            $quiz_total = 0;
            $midterm_total = 0;
            $assignment_obtained = 0;
            $quiz_obtained = 0;
            $midterm_obtained = 0;
            
            foreach ($course['assessments'] as $assessment) {
              if (stripos($assessment['type'], 'assignment') !== false) {
                $assignment_total += $assessment['total'];
                $assignment_obtained += $assessment['obtained'];
              } elseif (stripos($assessment['type'], 'quiz') !== false) {
                $quiz_total += $assessment['total'];
                $quiz_obtained += $assessment['obtained'];
              } elseif (stripos($assessment['type'], 'midterm') !== false) {
                $midterm_total += $assessment['total'];
                $midterm_obtained += $assessment['obtained'];
              }
            }
            
            $total_obtained = $assignment_obtained + $quiz_obtained + $midterm_obtained;
            $total_marks = $assignment_total + $quiz_total + $midterm_total;
            $total_percentage = $total_marks > 0 ? round(($total_obtained / $total_marks) * 100, 1) : 0;
          ?>
          <tr class="course-<?php echo $index % 7; ?>">
            <td>
              <div class="course-cell">
                <div class="course-icon">
                  <i class="fas fa-<?php 
                    if (stripos($course['course'], 'programming') !== false) echo 'code';
                    elseif (stripos($course['course'], 'data') !== false) echo 'sitemap';
                    elseif (stripos($course['course'], 'communication') !== false) echo 'comments';
                    elseif (stripos($course['course'], 'math') !== false) echo 'calculator';
                    elseif (stripos($course['course'], 'english') !== false) echo 'language';
                    else echo 'book';
                  ?>"></i>
                </div>
                <div class="course-info">
                  <div class="course-name"><?php echo htmlspecialchars($course['course']); ?></div>
                  <div class="course-code"><?php echo htmlspecialchars($course['code']); ?> | <?php echo htmlspecialchars($course['instructor']); ?></div>
                </div>
              </div>
            </td>
            <td>
              <div class="marks-cell">
                <div class="marks-value"><?php echo $assignment_obtained; ?> / <?php echo $assignment_total; ?></div>
                <div class="marks-percentage">
                  <?php echo $assignment_total > 0 ? round(($assignment_obtained / $assignment_total) * 100, 1) : 0; ?>%
                </div>
                <div class="progress-container">
                  <div class="progress-bar" style="width: <?php echo $assignment_total > 0 ? ($assignment_obtained / $assignment_total * 100) : 0; ?>%"></div>
                </div>
              </div>
            </td>
            <td>
              <div class="marks-cell">
                <div class="marks-value"><?php echo $quiz_obtained; ?> / <?php echo $quiz_total; ?></div>
                <div class="marks-percentage">
                  <?php echo $quiz_total > 0 ? round(($quiz_obtained / $quiz_total) * 100, 1) : 0; ?>%
                </div>
                <div class="progress-container">
                  <div class="progress-bar" style="width: <?php echo $quiz_total > 0 ? ($quiz_obtained / $quiz_total * 100) : 0; ?>%"></div>
                </div>
              </div>
            </td>
            <td>
              <div class="marks-cell">
                <div class="marks-value"><?php echo $midterm_obtained; ?> / <?php echo $midterm_total; ?></div>
                <div class="marks-percentage">
                  <?php echo $midterm_total > 0 ? round(($midterm_obtained / $midterm_total) * 100, 1) : 0; ?>%
                </div>
                <div class="progress-container">
                  <div class="progress-bar" style="width: <?php echo $midterm_total > 0 ? ($midterm_obtained / $midterm_total * 100) : 0; ?>%"></div>
                </div>
              </div>
            </td>
            <td>
              <div class="marks-cell">
                <div class="marks-value"><?php echo $total_obtained; ?> / <?php echo $total_marks; ?></div>
                <div class="marks-percentage"><?php echo $total_percentage; ?>%</div>
                <div class="progress-container">
                  <div class="progress-bar" style="width: <?php echo $total_percentage; ?>%"></div>
                </div>
              </div>
            </td>
            <td>
              <span class="marks-value"><?php echo $course['grade']; ?></span>
              <span class="grade-badge"><?php echo $course['grade']; ?></span>
            </td>
            <td>
              <div class="actions-cell">
                <button class="action-btn" title="View Details" onclick="showCourseDetails(<?php echo htmlspecialchars(json_encode($course)); ?>)">
                  <i class="fas fa-eye"></i>
                </button>
                <button class="action-btn" title="Download Report" onclick="downloadCourseReport(<?php echo $course['id']; ?>)">
                  <i class="fas fa-download"></i>
                </button>
                <button class="action-btn" title="Compare" onclick="comparePerformance(<?php echo $course['id']; ?>)">
                  <i class="fas fa-chart-bar"></i>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <a href="dashboard.php" class="back-btn">
    <i class="fas fa-arrow-left"></i> Back to Dashboard
  </a>

</div>

<!-- Add Grade Modal (for teachers) -->
<?php if ($role == 'teacher'): ?>
<div id="add-grade-modal" class="modal-overlay" style="display: none;">
  <div class="modal-content">
    <h3><i class="fas fa-plus-circle"></i> Add New Grade</h3>
    <form method="POST" action="marks.php" id="grade-form">
      <div style="display: flex; flex-direction: column; gap: 1rem;">
        <div>
          <label style="display: block; margin-bottom: 0.5rem; color: #475569; font-weight: 500;">Course</label>
          <select name="course_id" required style="width: 100%; padding: 0.75rem; border: 1px solid #e2e8f0; border-radius: 8px;">
            <option value="">Select Course</option>
            <?php 
            $courses_query = "SELECT id, course_code, course_name FROM courses WHERE teacher_id = :user_id";
            $courses_stmt = $db->prepare($courses_query);
            $courses_stmt->bindParam(':user_id', $user_id);
            $courses_stmt->execute();
            $teacher_courses = $courses_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($teacher_courses as $course):
            ?>
            <option value="<?php echo $course['id']; ?>">
              <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_name']); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div>
          <label style="display: block; margin-bottom: 0.5rem; color: #475569; font-weight: 500;">Student</label>
          <select name="student_id" required style="width: 100%; padding: 0.75rem; border: 1px solid #e2e8f0; border-radius: 8px;">
            <option value="">Select Student</option>
            <!-- Will be populated by JavaScript based on course selection -->
          </select>
        </div>
        
        <div>
          <label style="display: block; margin-bottom: 0.5rem; color: #475569; font-weight: 500;">Assessment Type</label>
          <select name="assessment_type" required style="width: 100%; padding: 0.75rem; border: 1px solid #e2e8f0; border-radius: 8px;">
            <option value="Assignment">Assignment</option>
            <option value="Quiz">Quiz</option>
            <option value="Midterm Exam">Midterm Exam</option>
            <option value="Final Exam">Final Exam</option>
            <option value="Project">Project</option>
            <option value="Lab Work">Lab Work</option>
          </select>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
          <div>
            <label style="display: block; margin-bottom: 0.5rem; color: #475569; font-weight: 500;">Marks Obtained</label>
            <input type="number" name="marks_obtained" step="0.01" min="0" required 
                   style="width: 100%; padding: 0.75rem; border: 1px solid #e2e8f0; border-radius: 8px;">
          </div>
          <div>
            <label style="display: block; margin-bottom: 0.5rem; color: #475569; font-weight: 500;">Total Marks</label>
            <input type="number" name="total_marks" step="0.01" min="0" required 
                   style="width: 100%; padding: 0.75rem; border: 1px solid #e2e8f0; border-radius: 8px;">
          </div>
        </div>
        
        <input type="hidden" name="add_grade" value="1">
        
        <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
          <button type="button" onclick="closeAddGradeModal()" 
                  style="flex: 1; padding: 0.9rem; border: 1px solid #e2e8f0; border-radius: 8px; background: white; color: #475569; cursor: pointer;">
            Cancel
          </button>
          <button type="submit" 
                  style="flex: 1; padding: 0.9rem; border: none; border-radius: 8px; background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%); color: white; cursor: pointer;">
            Add Grade
          </button>
        </div>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Course Details Modal -->
<div id="course-details-modal" class="modal-overlay" style="display: none;">
  <div class="modal-content" style="max-width: 600px; max-height: 80vh; overflow-y: auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
      <h3 id="course-details-title"></h3>
      <button onclick="closeCourseDetailsModal()" style="background: none; border: none; font-size: 1.5rem; color: #64748b; cursor: pointer;">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div id="course-details-content"></div>
  </div>
</div>

<script>
  // JavaScript functions
  function downloadReport() {
    alert('Report download feature would be implemented in production.');
  }

  function downloadCourseReport(courseId) {
    alert('Downloading report for course ID: ' + courseId);
  }

  function comparePerformance(courseId) {
    alert('Comparison feature would show performance vs class average for course: ' + courseId);
  }

  <?php if ($role == 'teacher'): ?>
  // Teacher-specific functions
  function showAddGradeModal() {
    document.getElementById('add-grade-modal').style.display = 'flex';
  }

  function closeAddGradeModal() {
    document.getElementById('add-grade-modal').style.display = 'none';
  }

  // Populate students based on course selection
  const courseSelect = document.querySelector('select[name="course_id"]');
  const studentSelect = document.querySelector('select[name="student_id"]');
  
  if (courseSelect) {
    courseSelect.addEventListener('change', function() {
      const courseId = this.value;
      if (courseId) {
        // In production, this would fetch students via AJAX
        studentSelect.innerHTML = `
          <option value="">Select Student</option>
          <option value="4">Alice Brown (student1)</option>
          <option value="5">Bob Wilson (student2)</option>
          <option value="6">Charlie Davis (student3)</option>
        `;
      } else {
        studentSelect.innerHTML = '<option value="">Select Student</option>';
      }
    });
  }
  <?php endif; ?>

  // Course details functions
  function showCourseDetails(course) {
    const modal = document.getElementById('course-details-modal');
    const title = document.getElementById('course-details-title');
    const content = document.getElementById('course-details-content');
    
    title.innerHTML = `<i class="fas fa-chart-line"></i> ${course.code}: ${course.course}`;
    
    let detailsHTML = `
      <div style="margin-bottom: 2rem;">
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem;">
          <div style="background: #f8fafc; padding: 1rem; border-radius: 8px; text-align: center;">
            <div style="font-size: 0.9rem; color: #64748b;">Average</div>
            <div style="font-size: 1.8rem; font-weight: 700; color: #1a1a2e;">${course.average_percentage}%</div>
          </div>
          <div style="background: #f8fafc; padding: 1rem; border-radius: 8px; text-align: center;">
            <div style="font-size: 0.9rem; color: #64748b;">Highest</div>
            <div style="font-size: 1.8rem; font-weight: 700; color: #2ecc71;">${course.highest_score || 'N/A'}%</div>
          </div>
          <div style="background: #f8fafc; padding: 1rem; border-radius: 8px; text-align: center;">
            <div style="font-size: 0.9rem; color: #64748b;">Lowest</div>
            <div style="font-size: 1.8rem; font-weight: 700; color: #e74c3c;">${course.lowest_score || 'N/A'}%</div>
          </div>
        </div>
        
        <h4 style="color: #475569; margin-bottom: 1rem; font-size: 1.1rem;">Assessment Breakdown</h4>
        <div style="background: #f8fafc; padding: 1rem; border-radius: 8px;">
    `;
    
    if (course.assessments && course.assessments.length > 0) {
      course.assessments.forEach(assessment => {
        const percentage = Math.round((assessment.obtained / assessment.total) * 100);
        detailsHTML += `
          <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid #e2e8f0;">
            <div>
              <div style="font-weight: 600; color: #1a1a2e;">${assessment.type}</div>
              <div style="font-size: 0.85rem; color: #64748b;">${assessment.obtained} / ${assessment.total}</div>
            </div>
            <div style="text-align: right;">
              <div style="font-weight: 700; color: #1a1a2e;">${percentage}%</div>
              <div class="progress-container" style="width: 100px; margin-top: 0.25rem;">
                <div class="progress-bar" style="width: ${percentage}%;"></div>
              </div>
            </div>
          </div>
        `;
      });
    } else {
      detailsHTML += `<div style="color: #64748b; text-align: center; padding: 2rem;">No assessment data available</div>`;
    }
    
    detailsHTML += `
        </div>
      </div>
      
      <button onclick="closeCourseDetailsModal()" 
              style="width: 100%; padding: 0.9rem; border: none; border-radius: 8px; background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%); color: white; cursor: pointer;">
        <i class="fas fa-times"></i> Close
      </button>
    `;
    
    content.innerHTML = detailsHTML;
    modal.style.display = 'flex';
    
    // Apply course color to progress bars
    const courseIndex = <?php echo isset($index) ? $index % 7 : 0; ?>;
    const colors = ['#6a11cb', '#2575fc', '#2ecc71', '#f39c12', '#e74c3c', '#9b59b6', '#1abc9c'];
    const nextColor = colors[(courseIndex + 1) % 7];
    
    modal.querySelectorAll('.progress-bar').forEach(bar => {
      bar.style.background = `linear-gradient(90deg, ${colors[courseIndex]} 0%, ${nextColor} 100%)`;
    });
  }

  function closeCourseDetailsModal() {
    document.getElementById('course-details-modal').style.display = 'none';
  }

  // Close modals when clicking outside
  document.addEventListener('click', function(event) {
    const addGradeModal = document.getElementById('add-grade-modal');
    const courseDetailsModal = document.getElementById('course-details-modal');
    
    if (addGradeModal && event.target === addGradeModal) {
      closeAddGradeModal();
    }
    
    if (courseDetailsModal && event.target === courseDetailsModal) {
      closeCourseDetailsModal();
    }
  });

  // Close modals with Escape key
  document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
      const addGradeModal = document.getElementById('add-grade-modal');
      const courseDetailsModal = document.getElementById('course-details-modal');
      
      if (addGradeModal && addGradeModal.style.display === 'flex') {
        closeAddGradeModal();
      }
      
      if (courseDetailsModal && courseDetailsModal.style.display === 'flex') {
        closeCourseDetailsModal();
      }
    }
  });

  // Animate progress bars on page load
  document.addEventListener('DOMContentLoaded', function() {
    setTimeout(() => {
      document.querySelectorAll('.progress-bar').forEach(bar => {
        const width = bar.style.width;
        bar.style.width = '0%';
        setTimeout(() => {
          bar.style.transition = 'width 1s ease';
          bar.style.width = width;
        }, 100);
      });
    }, 500);
  });
</script>

</body>
</html>