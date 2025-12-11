<?php
// dashboard.php

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

// Get user statistics based on role
$total_courses = 0;
$attendance_rate = 0;
$current_gpa = 0;
$pending_tasks = 0;

if ($role == 'student') {
    // Get enrolled courses count
    $query = "SELECT COUNT(*) as total FROM enrollments WHERE student_id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_courses = $result['total'];
    
    // Get average attendance rate
    $query = "SELECT 
                (COUNT(CASE WHEN status = 'present' THEN 1 END) * 100.0 / COUNT(*)) as attendance_rate
              FROM attendance 
              WHERE student_id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $attendance_rate = round($result['attendance_rate'] ?? 0, 1);
    
    // Get current GPA
    $query = "SELECT AVG(percentage) as gpa FROM marks WHERE student_id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $current_gpa = round(($result['gpa'] ?? 0) / 25, 2); // Convert percentage to 4.0 scale
    
    // Get pending assignments (you'll need to create an assignments table)
    $pending_tasks = 3; // Default value
    
} elseif ($role == 'teacher') {
    // Teacher statistics
    $query = "SELECT COUNT(*) as total FROM courses WHERE teacher_id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_courses = $result['total'];
    
    $attendance_rate = 98; // Default for teachers
    $current_gpa = 0; // Not applicable for teachers
    $pending_tasks = 5; // Default value
} else {
    // Admin statistics
    $query = "SELECT COUNT(*) as total FROM users WHERE role = 'student'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_courses = $result['total']; // Repurposed as total students
    
    $attendance_rate = 95; // Default overall attendance
    $current_gpa = 3.6; // Default overall GPA
    $pending_tasks = 12; // Default pending tasks
}

// Get enrolled courses for students
$enrolled_courses = [];
if ($role == 'student') {
    $query = "SELECT c.* FROM courses c
              INNER JOIN enrollments e ON c.id = e.course_id
              WHERE e.student_id = :user_id
              LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $enrolled_courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get upcoming events (timetable)
$upcoming_events = [];
$query = "SELECT 
            c.course_code, 
            c.course_name,
            t.day_of_week,
            t.start_time,
            t.end_time,
            t.room
          FROM timetable t
          INNER JOIN courses c ON t.course_id = c.id
          WHERE t.day_of_week IN ('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday')
          ORDER BY 
            FIELD(t.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'),
            t.start_time
          LIMIT 4";
$stmt = $db->prepare($query);
$stmt->execute();
$upcoming_events = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent activity
$recent_activity = [];
if ($role == 'student') {
    $query = "SELECT 
                'Grade Updated' as title,
                CONCAT('Your grade for ', c.course_code, ' is now ', 
                       CASE 
                         WHEN m.percentage >= 90 THEN 'A'
                         WHEN m.percentage >= 80 THEN 'B'
                         WHEN m.percentage >= 70 THEN 'C'
                         WHEN m.percentage >= 60 THEN 'D'
                         ELSE 'F'
                       END) as description,
                DATE_FORMAT(m.recorded_at, '%H:%i') as time_ago
              FROM marks m
              INNER JOIN courses c ON m.course_id = c.id
              WHERE m.student_id = :user_id
              ORDER BY m.recorded_at DESC
              LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo ucfirst($role); ?> Dashboard - Mini LMS</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
</head>

<body>

<div class="dashboard-container">
  <!-- Sidebar Navigation -->
  <div class="sidebar">
    <div class="sidebar-header">
      <div class="logo">
        <i class="fas fa-graduation-cap"></i>
        <h2>Mini<span>LMS</span></h2>
      </div>
      <button class="menu-toggle" id="menu-toggle">
        <i class="fas fa-bars"></i>
      </button>
    </div>
    
    <nav class="sidebar-nav">
      <ul>
        <li class="active">
          <a href="dashboard.php">
            <i class="fas fa-home"></i>
            <span>Dashboard</span>
          </a>
        </li>
        <li>
          <a href="courses.php">
            <i class="fas fa-book-open"></i>
            <span>Courses</span>
            <span class="badge"><?php echo $total_courses; ?></span>
          </a>
        </li>
        <li>
          <a href="timetable.php">
            <i class="fas fa-calendar-alt"></i>
            <span>Timetable</span>
          </a>
        </li>
        <li>
          <a href="attendance.php">
            <i class="fas fa-chart-bar"></i>
            <span>Attendance</span>
            <span class="badge"><?php echo $attendance_rate; ?>%</span>
          </a>
        </li>
        <li>
          <a href="marks.php">
            <i class="fas fa-chart-line"></i>
            <span>Grades</span>
          </a>
        </li>
        <li>
          <a href="#">
            <i class="fas fa-file-alt"></i>
            <span>Assignments</span>
            <span class="badge badge-warning"><?php echo $pending_tasks; ?></span>
          </a>
        </li>
        <?php if ($role == 'admin'): ?>
        <li>
          <a href="admin.php">
            <i class="fas fa-users-cog"></i>
            <span>Admin Panel</span>
          </a>
        </li>
        <?php endif; ?>
        <li>
          <a href="settings.php">
            <i class="fas fa-cog"></i>
            <span>Settings</span>
          </a>
        </li>
      </ul>
    </nav>
    
    <div class="sidebar-footer">
      <div class="help-box">
        <i class="fas fa-question-circle"></i>
        <h4>Need Help?</h4>
        <p>Contact support 24/7</p>
        <button class="help-btn">
          <i class="fas fa-headset"></i> Get Help
        </button>
      </div>
    </div>
  </div>

  <!-- Main Content -->
  <div class="main-content">
    <!-- Top Bar -->
    <div class="top-bar">
      <div class="top-bar-left">
        <h1><?php echo ucfirst($role); ?> Dashboard</h1>
        <div class="breadcrumb">
          <span>Home</span> / <span>Dashboard</span>
        </div>
      </div>
      
      <div class="top-bar-right">
        <div class="search-box">
          <i class="fas fa-search"></i>
          <input type="text" placeholder="Search courses, assignments...">
        </div>
        
        <div class="notifications">
          <button class="notification-btn">
            <i class="fas fa-bell"></i>
            <span class="notification-count">4</span>
          </button>
        </div>
        
        <div class="user-dropdown">
          <div class="user-avatar">
            <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($full_name); ?>&background=6a11cb&color=fff&size=128" alt="User Avatar">
          </div>
          <div class="user-info">
            <span class="user-name"><?php echo htmlspecialchars($full_name); ?></span>
            <span class="user-role"><?php echo ucfirst($role); ?></span>
          </div>
          <i class="fas fa-chevron-down"></i>
        </div>
        
        <a href="?logout=true" class="logout-btn" onclick="return confirm('Are you sure you want to logout?');">
          <i class="fas fa-sign-out-alt"></i>
        </a>
      </div>
    </div>

    <!-- Main Dashboard Content -->
    <div class="dashboard-content">
      <!-- Welcome Profile Box -->
      <div class="profile-box">
        <div class="profile-background">
          <div class="bg-gradient"></div>
        </div>
        
        <div class="profile-content">
          <div class="profile-avatar">
            <div class="avatar-container">
              <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($full_name); ?>&background=6a11cb&color=fff&size=256" alt="User Avatar">
              <span class="status-indicator active"></span>
            </div>
            <button class="edit-avatar-btn">
              <i class="fas fa-camera"></i>
            </button>
          </div>
          
          <div class="profile-info">
            <h2 id="displayName"><?php echo htmlspecialchars($full_name); ?></h2>
            <p class="student-id">ID: <?php echo $user_id; ?></p>
            <div class="profile-meta">
              <div class="meta-item">
                <i class="fas fa-envelope"></i>
                <span><?php echo htmlspecialchars($email); ?></span>
              </div>
              <div class="meta-item">
                <i class="fas fa-user-tag"></i>
                <span>Role: <?php echo ucfirst($role); ?></span>
              </div>
              <?php if ($role == 'student'): ?>
              <div class="meta-item">
                <i class="fas fa-star"></i>
                <span>GPA: <?php echo $current_gpa; ?></span>
              </div>
              <?php endif; ?>
            </div>
          </div>
          
          <div class="profile-actions">
            <button class="action-btn primary-btn" onclick="window.location.href='profile.php'">
              <i class="fas fa-id-card"></i> View Profile
            </button>
            <button class="action-btn secondary-btn" onclick="window.location.href='settings.php'">
              <i class="fas fa-cog"></i> Settings
            </button>
          </div>
        </div>
        
        <div class="profile-stats">
          <div class="stat-item">
            <div class="stat-value"><?php echo $total_courses; ?></div>
            <div class="stat-label">
              <?php echo ($role == 'admin') ? 'Total Students' : (($role == 'teacher') ? 'Teaching Courses' : 'Active Courses'); ?>
            </div>
          </div>
          <div class="stat-item">
            <div class="stat-value"><?php echo $attendance_rate; ?>%</div>
            <div class="stat-label">
              <?php echo ($role == 'student') ? 'Attendance' : 'Overall Attendance'; ?>
            </div>
          </div>
          <?php if ($role == 'student'): ?>
          <div class="stat-item">
            <div class="stat-value"><?php echo $current_gpa; ?></div>
            <div class="stat-label">Current GPA</div>
          </div>
          <?php else: ?>
          <div class="stat-item">
            <div class="stat-value"><?php echo $current_gpa; ?></div>
            <div class="stat-label">
              <?php echo ($role == 'admin') ? 'Avg GPA' : 'Students'; ?>
            </div>
          </div>
          <?php endif; ?>
          <div class="stat-item">
            <div class="stat-value"><?php echo $pending_tasks; ?></div>
            <div class="stat-label">Pending Tasks</div>
          </div>
        </div>
      </div>

      <!-- Quick Stats -->
      <div class="quick-stats">
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #6a11cb20, #2575fc20); color: #6a11cb;">
            <i class="fas fa-book-open"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?php echo $total_courses; ?></div>
            <div class="stat-title">
              <?php echo ($role == 'admin') ? 'Total Students' : (($role == 'teacher') ? 'Teaching Courses' : 'Enrolled Courses'); ?>
            </div>
            <div class="stat-trend up">
              <i class="fas fa-arrow-up"></i> 
              <?php echo ($role == 'student') ? 'This semester' : 'Active'; ?>
            </div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #2ecc7120, #1abc9c20); color: #2ecc71;">
            <i class="fas fa-calendar-check"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?php echo $attendance_rate; ?>%</div>
            <div class="stat-title">
              <?php echo ($role == 'student') ? 'Attendance Rate' : 'Overall Attendance'; ?>
            </div>
            <div class="stat-trend up">
              <i class="fas fa-arrow-up"></i> 
              <?php echo ($role == 'student') ? 'Good standing' : 'System wide'; ?>
            </div>
          </div>
        </div>
        
        <?php if ($role == 'student'): ?>
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #3498db20, #2980b920); color: #3498db;">
            <i class="fas fa-chart-line"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?php echo $current_gpa; ?></div>
            <div class="stat-title">Current GPA</div>
            <div class="stat-trend up">
              <i class="fas fa-arrow-up"></i> Good performance
            </div>
          </div>
        </div>
        <?php else: ?>
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #3498db20, #2980b920); color: #3498db;">
            <i class="fas fa-users"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number">
              <?php 
              if ($role == 'admin') {
                echo '100+';
              } else {
                echo '50+';
              }
              ?>
            </div>
            <div class="stat-title">
              <?php echo ($role == 'admin') ? 'Total Users' : 'Students'; ?>
            </div>
            <div class="stat-trend up">
              <i class="fas fa-arrow-up"></i> Active
            </div>
          </div>
        </div>
        <?php endif; ?>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #f39c1220, #e67e2220); color: #f39c12;">
            <i class="fas fa-clock"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?php echo $pending_tasks; ?></div>
            <div class="stat-title">Pending Tasks</div>
            <div class="stat-trend down">
              <i class="fas fa-arrow-down"></i> To be completed
            </div>
          </div>
        </div>
      </div>

      <!-- Dashboard Modules -->
      <div class="modules-section">
        <div class="section-header">
          <h2>Quick Access</h2>
          <p>Access your most important resources</p>
        </div>
        
        <div class="modules">
          <div class="card" onclick="window.location.href='courses.php'">
            <div class="card-icon" style="background: linear-gradient(135deg, #6a11cb, #2575fc);">
              <i class="fas fa-book-open"></i>
            </div>
            <h3>📘 Courses</h3>
            <p>View <?php echo ($role == 'student') ? 'enrolled' : (($role == 'teacher') ? 'teaching' : 'all'); ?> courses and materials</p>
            <div class="card-footer">
              <span class="card-badge"><?php echo $total_courses; ?> <?php echo ($role == 'admin') ? 'students' : 'courses'; ?></span>
              <button class="card-action">
                <i class="fas fa-arrow-right"></i>
              </button>
            </div>
          </div>

          <div class="card" onclick="window.location.href='timetable.php'">
            <div class="card-icon" style="background: linear-gradient(135deg, #2ecc71, #1abc9c);">
              <i class="fas fa-calendar-alt"></i>
            </div>
            <h3>🕒 Timetable</h3>
            <p>Your <?php echo ($role == 'student') ? 'class schedule' : 'teaching schedule'; ?> and upcoming sessions</p>
            <div class="card-footer">
              <span class="card-badge">
                <?php if (!empty($upcoming_events)): ?>
                Next: <?php echo $upcoming_events[0]['course_code']; ?>
                <?php else: ?>
                No upcoming
                <?php endif; ?>
              </span>
              <button class="card-action">
                <i class="fas fa-arrow-right"></i>
              </button>
            </div>
          </div>

          <div class="card" onclick="window.location.href='attendance.php'">
            <div class="card-icon" style="background: linear-gradient(135deg, #3498db, #2980b9);">
              <i class="fas fa-chart-bar"></i>
            </div>
            <h3>📊 Attendance</h3>
            <p>
              <?php if ($role == 'student'): ?>
              Your attendance record and analytics
              <?php elseif ($role == 'teacher'): ?>
              Mark and manage student attendance
              <?php else: ?>
              View attendance reports
              <?php endif; ?>
            </p>
            <div class="card-footer">
              <span class="card-badge"><?php echo $attendance_rate; ?>% <?php echo ($role == 'student') ? 'overall' : 'rate'; ?></span>
              <button class="card-action">
                <i class="fas fa-arrow-right"></i>
              </button>
            </div>
          </div>

          <div class="card" onclick="window.location.href='marks.php'">
            <div class="card-icon" style="background: linear-gradient(135deg, #9b59b6, #8e44ad);">
              <i class="fas fa-chart-line"></i>
            </div>
            <h3>
              <?php if ($role == 'student'): ?>
              📝 Grades
              <?php elseif ($role == 'teacher'): ?>
              📝 Grade Students
              <?php else: ?>
              📝 Grade Reports
              <?php endif; ?>
            </h3>
            <p>
              <?php if ($role == 'student'): ?>
              Your marks and performance
              <?php elseif ($role == 'teacher'): ?>
              Enter and manage student grades
              <?php else: ?>
              View grade analytics
              <?php endif; ?>
            </p>
            <div class="card-footer">
              <span class="card-badge">
                <?php if ($role == 'student'): ?>
                <?php echo $current_gpa; ?> GPA
                <?php else: ?>
                Manage
                <?php endif; ?>
              </span>
              <button class="card-action">
                <i class="fas fa-arrow-right"></i>
              </button>
            </div>
          </div>
          
          <?php if ($role == 'admin'): ?>
          <div class="card" onclick="window.location.href='admin.php'">
            <div class="card-icon" style="background: linear-gradient(135deg, #e74c3c, #c0392b);">
              <i class="fas fa-users-cog"></i>
            </div>
            <h3>⚙️ Admin Panel</h3>
            <p>Manage users, courses, and system settings</p>
            <div class="card-footer">
              <span class="card-badge">System Control</span>
              <button class="card-action">
                <i class="fas fa-arrow-right"></i>
              </button>
            </div>
          </div>
          <?php else: ?>
          <div class="card" onclick="window.location.href='#'">
            <div class="card-icon" style="background: linear-gradient(135deg, #e74c3c, #c0392b);">
              <i class="fas fa-file-alt"></i>
            </div>
            <h3>📄 Assignments</h3>
            <p>View and <?php echo ($role == 'student') ? 'submit' : 'grade'; ?> assignments</p>
            <div class="card-footer">
              <span class="card-badge badge-warning"><?php echo $pending_tasks; ?> pending</span>
              <button class="card-action">
                <i class="fas fa-arrow-right"></i>
              </button>
            </div>
          </div>
          <?php endif; ?>
          
          <div class="card" onclick="window.location.href='settings.php'">
            <div class="card-icon" style="background: linear-gradient(135deg, #1abc9c, #16a085);">
              <i class="fas fa-cog"></i>
            </div>
            <h3>⚙️ Settings</h3>
            <p>Manage your profile and account settings</p>
            <div class="card-footer">
              <span class="card-badge">Account Settings</span>
              <button class="card-action">
                <i class="fas fa-arrow-right"></i>
              </button>
            </div>
          </div>
          
          <?php if ($role == 'student'): ?>
          <div class="card" onclick="window.location.href='#'">
            <div class="card-icon" style="background: linear-gradient(135deg, #f39c12, #e67e22);">
              <i class="fas fa-graduation-cap"></i>
            </div>
            <h3>🎓 Progress</h3>
            <p>Check your academic progress and standing</p>
            <div class="card-footer">
              <span class="card-badge">View progress</span>
              <button class="card-action">
                <i class="fas fa-arrow-right"></i>
              </button>
            </div>
          </div>
          
          <div class="card" onclick="window.location.href='#'">
            <div class="card-icon" style="background: linear-gradient(135deg, #34495e, #2c3e50);">
              <i class="fas fa-download"></i>
            </div>
            <h3>📥 Resources</h3>
            <p>Access course materials and study resources</p>
            <div class="card-footer">
              <span class="card-badge">Download</span>
              <button class="card-action">
                <i class="fas fa-arrow-right"></i>
              </button>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
      
      <!-- Upcoming Events & Recent Activity -->
      <div class="dashboard-bottom">
        <div class="upcoming-events">
          <div class="section-header">
            <h3>Upcoming Events</h3>
            <button class="view-all-btn" onclick="window.location.href='timetable.php'">View All</button>
          </div>
          
          <div class="events-list">
            <?php if (!empty($upcoming_events)): ?>
              <?php foreach ($upcoming_events as $index => $event): ?>
              <div class="event-item">
                <div class="event-date">
                  <div class="event-day"><?php echo substr($event['day_of_week'], 0, 3); ?></div>
                  <div class="event-num"><?php echo ($index + 15) % 30 + 1; ?></div>
                </div>
                <div class="event-details">
                  <h4><?php echo htmlspecialchars($event['course_code']); ?> - <?php echo htmlspecialchars($event['course_name']); ?></h4>
                  <p><i class="fas fa-clock"></i> <?php echo date('g:i A', strtotime($event['start_time'])); ?> - <?php echo date('g:i A', strtotime($event['end_time'])); ?></p>
                  <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($event['room']); ?></p>
                </div>
                <div class="event-status active"></div>
              </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="event-item">
                <div class="event-details">
                  <h4>No upcoming events</h4>
                  <p>Check back later for scheduled sessions</p>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>
        
        <div class="recent-activity">
          <div class="section-header">
            <h3>Recent Activity</h3>
            <button class="view-all-btn">View All</button>
          </div>
          
          <div class="activity-list">
            <?php if (!empty($recent_activity)): ?>
              <?php foreach ($recent_activity as $activity): ?>
              <div class="activity-item">
                <div class="activity-icon">
                  <i class="fas fa-chart-line"></i>
                </div>
                <div class="activity-details">
                  <h4><?php echo htmlspecialchars($activity['title']); ?></h4>
                  <p><?php echo htmlspecialchars($activity['description']); ?></p>
                  <span class="activity-time"><?php echo $activity['time_ago']; ?> ago</span>
                </div>
              </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="activity-item">
                <div class="activity-icon">
                  <i class="fas fa-info-circle"></i>
                </div>
                <div class="activity-details">
                  <h4>Welcome to Mini LMS!</h4>
                  <p>You have successfully logged in. Start exploring your dashboard.</p>
                  <span class="activity-time">Just now</span>
                </div>
              </div>
              <div class="activity-item">
                <div class="activity-icon">
                  <i class="fas fa-calendar-check"></i>
                </div>
                <div class="activity-details">
                  <h4>System Updated</h4>
                  <p>New features have been added to your dashboard</p>
                  <span class="activity-time">Today</span>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      
      <!-- Footer -->
      <div class="dashboard-footer">
        <div class="footer-content">
          <div class="footer-logo">
            <i class="fas fa-graduation-cap"></i>
            <span>MiniLMS</span>
          </div>
          <div class="footer-links">
            <a href="#">Privacy Policy</a>
            <a href="#">Terms of Service</a>
            <a href="#">Help Center</a>
            <a href="#">Contact Us</a>
          </div>
          <div class="footer-copyright">
            © <?php echo date('Y'); ?> MiniLMS. All rights reserved.
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
  /* Reset and Base Styles */
  * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  }

  body {
    background-color: #f8fafc;
    color: #333;
    line-height: 1.6;
    overflow-x: hidden;
  }

  .dashboard-container {
    display: flex;
    min-height: 100vh;
  }

  /* Sidebar Styles */
  .sidebar {
    width: 260px;
    background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%);
    color: white;
    display: flex;
    flex-direction: column;
    box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
    z-index: 100;
    transition: all 0.3s ease;
  }

  .sidebar-header {
    padding: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  }

  .logo {
    display: flex;
    align-items: center;
    gap: 0.75rem;
  }

  .logo i {
    font-size: 2rem;
    color: #6a11cb;
  }

  .logo h2 {
    font-family: 'Poppins', sans-serif;
    font-size: 1.5rem;
    font-weight: 700;
  }

  .logo span {
    color: #6a11cb;
  }

  .menu-toggle {
    background: none;
    border: none;
    color: rgba(255, 255, 255, 0.7);
    font-size: 1.2rem;
    cursor: pointer;
    display: none;
  }

  .sidebar-nav {
    flex: 1;
    padding: 1.5rem 0;
  }

  .sidebar-nav ul {
    list-style: none;
  }

  .sidebar-nav li {
    margin-bottom: 0.5rem;
  }

  .sidebar-nav a {
    display: flex;
    align-items: center;
    padding: 0.9rem 1.5rem;
    color: rgba(255, 255, 255, 0.7);
    text-decoration: none;
    transition: all 0.3s ease;
    position: relative;
  }

  .sidebar-nav a:hover {
    background: rgba(106, 17, 203, 0.1);
    color: white;
    padding-left: 1.75rem;
  }

  .sidebar-nav a.active {
    background: linear-gradient(90deg, rgba(106, 17, 203, 0.2) 0%, rgba(106, 17, 203, 0.05) 100%);
    color: white;
    border-left: 4px solid #6a11cb;
  }

  .sidebar-nav a i {
    font-size: 1.2rem;
    margin-right: 1rem;
    width: 24px;
    text-align: center;
  }

  .sidebar-nav a span {
    flex-grow: 1;
    font-weight: 500;
  }

  .badge {
    background: rgba(255, 255, 255, 0.1);
    color: white;
    font-size: 0.75rem;
    padding: 0.2rem 0.6rem;
    border-radius: 12px;
    font-weight: 600;
  }

  .badge-warning {
    background: #f39c12;
  }

  .badge-primary {
    background: #6a11cb;
  }

  .sidebar-footer {
    padding: 1.5rem;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
  }

  .help-box {
    background: rgba(255, 255, 255, 0.05);
    border-radius: 12px;
    padding: 1.25rem;
    text-align: center;
  }

  .help-box i {
    font-size: 2rem;
    color: #6a11cb;
    margin-bottom: 0.75rem;
  }

  .help-box h4 {
    font-size: 1.1rem;
    margin-bottom: 0.5rem;
  }

  .help-box p {
    font-size: 0.85rem;
    color: rgba(255, 255, 255, 0.6);
    margin-bottom: 1rem;
  }

  .help-btn {
    background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 0.7rem 1.2rem;
    font-weight: 500;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    width: 100%;
    transition: all 0.3s ease;
  }

  .help-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(106, 17, 203, 0.3);
  }

  /* Main Content */
  .main-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }

  /* Top Bar */
  .top-bar {
    background: white;
    padding: 1.2rem 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    z-index: 50;
  }

  .top-bar-left h1 {
    font-family: 'Poppins', sans-serif;
    font-size: 1.8rem;
    font-weight: 700;
    color: #2d3748;
    margin-bottom: 0.25rem;
  }

  .breadcrumb {
    font-size: 0.9rem;
    color: #718096;
  }

  .breadcrumb span:last-child {
    color: #6a11cb;
    font-weight: 500;
  }

  .top-bar-right {
    display: flex;
    align-items: center;
    gap: 1.5rem;
  }

  .search-box {
    position: relative;
  }

  .search-box i {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: #a0aec0;
  }

  .search-box input {
    padding: 0.7rem 1rem 0.7rem 2.5rem;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    width: 250px;
    font-size: 0.95rem;
    transition: all 0.3s;
  }

  .search-box input:focus {
    outline: none;
    border-color: #6a11cb;
    box-shadow: 0 0 0 3px rgba(106, 17, 203, 0.1);
  }

  .notifications {
    position: relative;
  }

  .notification-btn {
    background: none;
    border: none;
    font-size: 1.3rem;
    color: #4a5568;
    cursor: pointer;
    position: relative;
    padding: 0.5rem;
  }

  .notification-count {
    position: absolute;
    top: 0;
    right: 0;
    background: #e74c3c;
    color: white;
    font-size: 0.7rem;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .user-dropdown {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.5rem 1rem;
    border-radius: 8px;
    cursor: pointer;
    transition: background 0.2s;
  }

  .user-dropdown:hover {
    background: #f7fafc;
  }

  .user-avatar img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #6a11cb;
  }

  .user-info {
    display: flex;
    flex-direction: column;
  }

  .user-name {
    font-weight: 600;
    color: #2d3748;
  }

  .user-role {
    font-size: 0.8rem;
    color: #718096;
  }

  .user-dropdown i {
    color: #a0aec0;
    font-size: 0.9rem;
  }

  .logout-btn {
    background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
    color: white;
    border: none;
    width: 40px;
    height: 40px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    transition: all 0.3s ease;
    text-decoration: none;
  }

  .logout-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(106, 17, 203, 0.3);
  }

  /* Dashboard Content */
  .dashboard-content {
    flex: 1;
    padding: 2rem;
    overflow-y: auto;
  }

  /* Profile Box */
  .profile-box {
    background: white;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
    margin-bottom: 2rem;
    position: relative;
  }

  .profile-background {
    height: 160px;
    position: relative;
    overflow: hidden;
  }

  .bg-gradient {
    height: 100%;
    background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
  }

  .profile-content {
    padding: 0 3rem 2rem;
    margin-top: -60px;
    display: flex;
    align-items: flex-end;
    gap: 2rem;
  }

  .profile-avatar {
    position: relative;
  }

  .avatar-container {
    width: 140px;
    height: 140px;
    border-radius: 50%;
    overflow: hidden;
    border: 5px solid white;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    position: relative;
  }

  .avatar-container img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .status-indicator {
    position: absolute;
    bottom: 10px;
    right: 10px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    border: 3px solid white;
  }

  .status-indicator.active {
    background: #2ecc71;
  }

  .edit-avatar-btn {
    position: absolute;
    bottom: 5px;
    right: 5px;
    background: white;
    border: none;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    color: #6a11cb;
    cursor: pointer;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
    transition: all 0.3s ease;
  }

  .edit-avatar-btn:hover {
    background: #6a11cb;
    color: white;
    transform: scale(1.1);
  }

  .profile-info {
    flex: 1;
  }

  .profile-info h2 {
    font-family: 'Poppins', sans-serif;
    font-size: 2.2rem;
    font-weight: 700;
    color: #2d3748;
    margin-bottom: 0.5rem;
  }

  .student-id {
    font-size: 1.1rem;
    color: #718096;
    margin-bottom: 1.5rem;
  }

  .profile-meta {
    display: flex;
    gap: 2rem;
  }

  .meta-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #4a5568;
  }

  .meta-item i {
    color: #6a11cb;
    font-size: 1.1rem;
  }

  .profile-actions {
    display: flex;
    gap: 1rem;
  }

  .action-btn {
    padding: 0.8rem 1.5rem;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.3s ease;
    border: none;
  }

  .primary-btn {
    background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
    color: white;
  }

  .primary-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(106, 17, 203, 0.3);
  }

  .secondary-btn {
    background: #edf2f7;
    color: #4a5568;
  }

  .secondary-btn:hover {
    background: #e2e8f0;
    transform: translateY(-2px);
  }

  .profile-stats {
    display: flex;
    border-top: 1px solid #e2e8f0;
  }

  .stat-item {
    flex: 1;
    padding: 1.5rem 3rem;
    text-align: center;
    border-right: 1px solid #e2e8f0;
  }

  .stat-item:last-child {
    border-right: none;
  }

  .stat-value {
    font-size: 2.5rem;
    font-weight: 700;
    color: #6a11cb;
    margin-bottom: 0.5rem;
  }

  .stat-label {
    font-size: 0.95rem;
    color: #718096;
  }

  /* Quick Stats */
  .quick-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2.5rem;
  }

  .stat-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 6px 15px rgba(0, 0, 0, 0.05);
    display: flex;
    align-items: center;
    gap: 1.5rem;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
  }

  .stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 20px rgba(0, 0, 0, 0.1);
  }

  .stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
  }

  .stat-details {
    flex: 1;
  }

  .stat-number {
    font-size: 2rem;
    font-weight: 700;
    color: #2d3748;
    margin-bottom: 0.25rem;
  }

  .stat-title {
    font-size: 0.95rem;
    color: #718096;
    margin-bottom: 0.5rem;
  }

  .stat-trend {
    font-size: 0.85rem;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 0.25rem;
  }

  .stat-trend.up {
    color: #2ecc71;
  }

  .stat-trend.down {
    color: #e74c3c;
  }

  /* Modules Section */
  .modules-section {
    margin-bottom: 2.5rem;
  }

  .section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
  }

  .section-header h2 {
    font-family: 'Poppins', sans-serif;
    font-size: 1.8rem;
    font-weight: 700;
    color: #2d3748;
  }

  .section-header p {
    color: #718096;
  }

  .modules {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.5rem;
  }

  .card {
    background: white;
    border-radius: 12px;
    padding: 1.8rem;
    box-shadow: 0 6px 15px rgba(0, 0, 0, 0.05);
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    border-top: 4px solid transparent;
    cursor: pointer;
  }

  .card:hover {
    transform: translateY(-8px);
    box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1);
    border-top-color: #6a11cb;
  }

  .card-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1.5rem;
    color: white;
    font-size: 1.8rem;
  }

  .card h3 {
    font-family: 'Poppins', sans-serif;
    font-size: 1.4rem;
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 0.75rem;
  }

  .card p {
    color: #718096;
    line-height: 1.6;
    margin-bottom: 1.5rem;
    flex: 1;
  }

  .card-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .card-badge {
    background: #edf2f7;
    color: #4a5568;
    padding: 0.4rem 0.8rem;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 500;
  }

  .card-action {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #f7fafc;
    border: none;
    color: #4a5568;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    transition: all 0.3s ease;
  }

  .card-action:hover {
    background: #6a11cb;
    color: white;
    transform: rotate(90deg);
  }

  /* Dashboard Bottom */
  .dashboard-bottom {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
    margin-bottom: 2rem;
  }

  .upcoming-events, .recent-activity {
    background: white;
    border-radius: 16px;
    padding: 1.8rem;
    box-shadow: 0 6px 15px rgba(0, 0, 0, 0.05);
  }

  .view-all-btn {
    background: none;
    border: none;
    color: #6a11cb;
    font-weight: 600;
    cursor: pointer;
    font-size: 0.95rem;
    transition: all 0.2s;
  }

  .view-all-btn:hover {
    text-decoration: underline;
  }

  .events-list, .activity-list {
    margin-top: 1.5rem;
  }

  .event-item, .activity-item {
    display: flex;
    padding: 1.2rem 0;
    border-bottom: 1px solid #f1f5f9;
    align-items: center;
  }

  .event-item:last-child, .activity-item:last-child {
    border-bottom: none;
  }

  .event-date {
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-right: 1.5rem;
    min-width: 60px;
  }

  .event-day {
    font-size: 0.85rem;
    color: #718096;
  }

  .event-num {
    font-size: 1.8rem;
    font-weight: 700;
    color: #6a11cb;
  }

  .event-details {
    flex: 1;
  }

  .event-details h4 {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 0.5rem;
  }

  .event-details p {
    font-size: 0.9rem;
    color: #718096;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.25rem;
  }

  .event-status {
    width: 12px;
    height: 12px;
    border-radius: 50%;
  }

  .event-status.active {
    background: #2ecc71;
  }

  .event-status.warning {
    background: #f39c12;
  }

  .event-status.urgent {
    background: #e74c3c;
  }

  .activity-item {
    gap: 1.2rem;
  }

  .activity-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: #f7fafc;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    color: #6a11cb;
  }

  .activity-details {
    flex: 1;
  }

  .activity-details h4 {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 0.25rem;
  }

  .activity-details p {
    font-size: 0.9rem;
    color: #718096;
    margin-bottom: 0.5rem;
  }

  .activity-time {
    font-size: 0.8rem;
    color: #a0aec0;
  }

  /* Footer */
  .dashboard-footer {
    background: white;
    border-radius: 16px;
    padding: 1.5rem 2rem;
    box-shadow: 0 6px 15px rgba(0, 0, 0, 0.05);
    margin-top: 1rem;
  }

  .footer-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .footer-logo {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 1.2rem;
    font-weight: 700;
    color: #2d3748;
  }

  .footer-logo i {
    color: #6a11cb;
  }

  .footer-links {
    display: flex;
    gap: 2rem;
  }

  .footer-links a {
    color: #718096;
    text-decoration: none;
    font-size: 0.9rem;
    transition: color 0.2s;
  }

  .footer-links a:hover {
    color: #6a11cb;
  }

  .footer-copyright {
    color: #a0aec0;
    font-size: 0.85rem;
  }

  /* Responsive Styles */
  @media (max-width: 1200px) {
    .dashboard-bottom {
      grid-template-columns: 1fr;
    }
    
    .profile-content {
      flex-direction: column;
      align-items: center;
      text-align: center;
      gap: 1.5rem;
    }
    
    .profile-meta {
      justify-content: center;
    }
    
    .profile-stats {
      flex-wrap: wrap;
    }
    
    .stat-item {
      flex: 0 0 50%;
      border-bottom: 1px solid #e2e8f0;
    }
    
    .stat-item:nth-child(2) {
      border-right: none;
    }
    
    .stat-item:nth-child(3), .stat-item:nth-child(4) {
      border-bottom: none;
    }
  }

  @media (max-width: 992px) {
    .sidebar {
      width: 80px;
      overflow: hidden;
    }
    
    .sidebar-header {
      justify-content: center;
      padding: 1.5rem 0.5rem;
    }
    
    .logo h2, .sidebar-nav a span, .badge, .help-box h4, .help-box p, .help-btn span {
      display: none;
    }
    
    .sidebar-nav a {
      justify-content: center;
      padding: 1rem;
    }
    
    .sidebar-nav a i {
      margin-right: 0;
      font-size: 1.4rem;
    }
    
    .sidebar-nav a.active {
      border-left: none;
      border-right: 4px solid #6a11cb;
    }
    
    .help-box {
      padding: 1rem 0.5rem;
    }
    
    .help-btn {
      padding: 0.7rem;
    }
    
    .help-btn i {
      margin: 0;
    }
    
    .top-bar-right {
      gap: 1rem;
    }
    
    .search-box input {
      width: 200px;
    }
    
    .user-info {
      display: none;
    }
    
    .user-dropdown i {
      display: none;
    }
  }

  @media (max-width: 768px) {
    .sidebar {
      position: fixed;
      left: -260px;
      height: 100%;
    }
    
    .sidebar.active {
      left: 0;
    }
    
    .menu-toggle {
      display: block;
      position: fixed;
      top: 1.5rem;
      left: 1.5rem;
      z-index: 99;
      background: #6a11cb;
      color: white;
      width: 50px;
      height: 50px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
    }
    
    .main-content {
      margin-left: 0;
    }
    
    .top-bar {
      flex-direction: column;
      align-items: flex-start;
      gap: 1rem;
      padding: 1.5rem;
    }
    
    .top-bar-right {
      width: 100%;
      justify-content: space-between;
    }
    
    .search-box input {
      width: 100%;
    }
    
    .dashboard-content {
      padding: 1.5rem;
    }
    
    .profile-content {
      padding: 0 1.5rem 1.5rem;
    }
    
    .profile-actions {
      flex-direction: column;
      width: 100%;
    }
    
    .action-btn {
      width: 100%;
      justify-content: center;
    }
    
    .modules {
      grid-template-columns: 1fr;
    }
    
    .footer-content {
      flex-direction: column;
      gap: 1rem;
      text-align: center;
    }
    
    .footer-links {
      flex-wrap: wrap;
      justify-content: center;
      gap: 1rem;
    }
  }

  @media (max-width: 576px) {
    .quick-stats {
      grid-template-columns: 1fr;
    }
    
    .profile-stats {
      flex-direction: column;
    }
    
    .stat-item {
      flex: 1;
      border-right: none;
      border-bottom: 1px solid #e2e8f0;
    }
    
    .stat-item:last-child {
      border-bottom: none;
    }
    
    .profile-meta {
      flex-direction: column;
      gap: 1rem;
    }
    
    .avatar-container {
      width: 120px;
      height: 120px;
    }
    
    .profile-info h2 {
      font-size: 1.8rem;
    }
  }
</style>

<script>
  // Sidebar toggle for mobile
  document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.getElementById('menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    
    menuToggle.addEventListener('click', function() {
      sidebar.classList.toggle('active');
    });
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(event) {
      if (window.innerWidth <= 768 && 
          !sidebar.contains(event.target) && 
          !menuToggle.contains(event.target) && 
          sidebar.classList.contains('active')) {
        sidebar.classList.remove('active');
      }
    });
    
    // Auto-hide sidebar on mobile when clicking a link
    const sidebarLinks = document.querySelectorAll('.sidebar-nav a');
    sidebarLinks.forEach(link => {
      link.addEventListener('click', function() {
        if (window.innerWidth <= 768) {
          sidebar.classList.remove('active');
        }
      });
    });
    
    // Add hover effects to cards
    const cards = document.querySelectorAll('.card');
    cards.forEach(card => {
      const cardAction = card.querySelector('.card-action');
      card.addEventListener('mouseenter', function() {
        cardAction.style.transform = 'rotate(90deg)';
      });
      card.addEventListener('mouseleave', function() {
        cardAction.style.transform = 'rotate(0deg)';
      });
    });
  });
</script>

</body>
</html>