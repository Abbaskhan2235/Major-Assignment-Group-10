<?php
// login.php

session_start();
require_once 'config/database.php';
require_once 'includes/session.php';

// If user is already logged in, redirect to dashboard
if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}

// Initialize variables
$username = $password = $role = "";
$error_message = "";
$success_message = "";

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $role = $_POST['role'] ?? 'student';
    
    // Validate inputs
    if (empty($username) || empty($password)) {
        $error_message = "Please enter both username and password.";
    } else {
        // Database connection
        $database = new Database();
        $db = $database->getConnection();
        
        // Prepare SQL query based on role
        $query = "SELECT id, username, password, role, full_name, email FROM users 
                  WHERE username = :username AND role = :role LIMIT 1";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':role', $role);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Verify password (using password_verify for hashed passwords)
            // For now, we'll use simple comparison. Replace with hashed passwords in production
            if ($password === 'demo123' || password_verify($password, $user['password'])) {
                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['email'] = $user['email'];
                
                // Redirect based on role
                switch ($user['role']) {
                    case 'admin':
                        header("Location: admin_dashboard.php");
                        break;
                    case 'teacher':
                        header("Location: teacher_dashboard.php");
                        break;
                    default:
                        header("Location: dashboard.php");
                }
                exit();
            } else {
                $error_message = "Invalid password. Please try again.";
            }
        } else {
            $error_message = "No user found with these credentials.";
        }
    }
}

// Demo credentials for testing
$demo_credentials = [
    'student' => ['username' => 'student1', 'password' => 'demo123'],
    'teacher' => ['username' => 'teacher1', 'password' => 'demo123'],
    'admin' => ['username' => 'admin', 'password' => 'demo123']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mini LMS - Premium Login</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
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
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
    padding: 2rem;
    position: relative;
    overflow-x: hidden;
  }

  /* Animated Background Elements */
  .bg-element {
    position: absolute;
    border-radius: 50%;
    background: linear-gradient(135deg, rgba(106, 17, 203, 0.05) 0%, rgba(37, 117, 252, 0.05) 100%);
    z-index: -1;
  }

  .bg-element-1 {
    width: 300px;
    height: 300px;
    top: -150px;
    right: -150px;
  }

  .bg-element-2 {
    width: 200px;
    height: 200px;
    bottom: -100px;
    left: -100px;
  }

  /* Login Container */
  .login-container {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 28px;
    padding: 3.5rem;
    width: 100%;
    max-width: 520px;
    box-shadow: 
      0 25px 50px -12px rgba(0, 0, 0, 0.08),
      0 0 0 1px rgba(255, 255, 255, 0.2);
    animation: fadeInUp 0.8s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    position: relative;
    overflow: hidden;
  }

  .login-container::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #6a11cb 0%, #2575fc 100%);
    border-radius: 28px 28px 0 0;
  }

  @keyframes fadeInUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
  }

  /* Logo */
  .logo {
    text-align: center;
    margin-bottom: 2.5rem;
  }

  .logo h1 {
    font-family: 'Poppins', sans-serif;
    font-size: 2.8rem;
    font-weight: 800;
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    display: inline-block;
    position: relative;
  }

  .logo span {
    background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
  }

  .logo::after {
    content: '';
    display: block;
    width: 60px;
    height: 4px;
    background: linear-gradient(90deg, #6a11cb 0%, #2575fc 100%);
    border-radius: 2px;
    margin: 1rem auto 0;
  }

  /* Header */
  .login-header {
    text-align: center;
    margin-bottom: 3rem;
  }

  .login-header h2 {
    font-family: 'Poppins', sans-serif;
    font-size: 2rem;
    font-weight: 700;
    color: #1a1a2e;
    margin-bottom: 0.75rem;
    letter-spacing: -0.5px;
  }

  .login-header p {
    color: #64748b;
    font-size: 1.05rem;
    font-weight: 500;
  }

  /* Error/Success Messages */
  .message-container {
    margin-bottom: 1.5rem;
  }

  .error-message {
    background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
    color: white;
    padding: 1rem 1.5rem;
    border-radius: 14px;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    animation: slideIn 0.5s ease;
  }

  .success-message {
    background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
    color: white;
    padding: 1rem 1.5rem;
    border-radius: 14px;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    animation: slideIn 0.5s ease;
  }

  @keyframes slideIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
  }

  /* Role Selection */
  .role-selection {
    margin-bottom: 3rem;
  }

  .role-selection h3 {
    font-family: 'Poppins', sans-serif;
    font-size: 1.1rem;
    color: #475569;
    text-align: center;
    margin-bottom: 1.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
  }

  /* Circular Role Options */
  .role-options {
    display: flex;
    justify-content: space-between;
    gap: 1.2rem;
    margin-bottom: 2rem;
  }

  /* Hide the radio buttons */
  .role-option input[type="radio"] {
    display: none;
  }

  /* Role Circle Container */
  .role-circle-container {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    cursor: pointer;
    flex: 1;
  }

  /* Role Circle */
  .role-circle {
    width: 110px;
    height: 110px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.95rem;
    color: #1a1a2e;
    background: white;
    border: 2px solid #f1f5f9;
    transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    position: relative;
    z-index: 2;
    overflow: hidden;
  }

  /* Circular Background Glow */
  .role-circle-bg {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 130px;
    height: 130px;
    border-radius: 50%;
    background: linear-gradient(135deg, #6a11cb15 0%, #2575fc15 100%);
    opacity: 0;
    transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
    z-index: 1;
  }

  /* Elegant Shadow Container */
  .elegant-shadow {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 140px;
    height: 140px;
    border-radius: 50%;
    background: linear-gradient(135deg, #6a11cb08 0%, #2575fc08 100%);
    opacity: 0;
    transition: all 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
    z-index: 0;
    filter: blur(15px);
  }

  /* Icon Styling */
  .role-circle::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    border-radius: 50%;
    background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
    opacity: 0;
    transform: scale(0.9);
    transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    z-index: -1;
  }

  .role-circle span {
    position: relative;
    z-index: 2;
    font-weight: 700;
    font-size: 1rem;
    transition: all 0.3s ease;
  }

  /* Hover Effects */
  .role-circle-container:hover .role-circle {
    transform: translateY(-8px) scale(1.05);
    border-color: transparent;
    box-shadow: 0 15px 30px rgba(106, 17, 203, 0.15);
  }

  .role-circle-container:hover .role-circle::before {
    opacity: 0.08;
    transform: scale(1);
  }

  .role-circle-container:hover .role-circle-bg {
    opacity: 1;
    width: 140px;
    height: 140px;
  }

  .role-circle-container:hover .elegant-shadow {
    opacity: 0.8;
    width: 160px;
    height: 160px;
  }

  .role-circle-container:hover .role-name {
    color: #6a11cb;
    transform: translateY(2px);
  }

  /* Selected State */
  .role-option input[type="radio"]:checked + .role-circle-container .role-circle {
    transform: translateY(-8px) scale(1.08);
    border-color: transparent;
    color: white;
    box-shadow: 
      0 20px 40px rgba(106, 17, 203, 0.25),
      0 0 0 2px rgba(106, 17, 203, 0.1);
  }

  .role-option input[type="radio"]:checked + .role-circle-container .role-circle::before {
    opacity: 1;
    transform: scale(1);
  }

  .role-option input[type="radio"]:checked + .role-circle-container .role-circle-bg {
    opacity: 1;
    width: 150px;
    height: 150px;
    background: linear-gradient(135deg, #6a11cb30 0%, #2575fc30 100%);
    animation: gentlePulse 2s infinite;
  }

  .role-option input[type="radio"]:checked + .role-circle-container .elegant-shadow {
    opacity: 1;
    width: 180px;
    height: 180px;
    background: linear-gradient(135deg, #6a11cb15 0%, #2575fc15 100%);
    filter: blur(25px);
  }

  /* Gentle Pulse Animation */
  @keyframes gentlePulse {
    0%, 100% {
      transform: translate(-50%, -50%) scale(1);
    }
    50% {
      transform: translate(-50%, -50%) scale(1.05);
    }
  }

  /* Role Name */
  .role-name {
    margin-top: 1.2rem;
    font-weight: 600;
    color: #475569;
    font-size: 0.95rem;
    transition: all 0.3s ease;
    text-align: center;
    width: 100%;
    padding: 0 0.5rem;
  }

  .role-option input[type="radio"]:checked + .role-circle-container .role-name {
    color: #6a11cb;
    font-weight: 700;
    transform: translateY(2px);
  }

  /* Login Form */
  .login-form {
    margin-top: 3rem;
    padding-top: 3rem;
    border-top: 1px solid #f1f5f9;
  }

  .form-group {
    margin-bottom: 1.8rem;
  }

  .input-group {
    position: relative;
  }

  .input-group input {
    width: 100%;
    padding: 1.1rem 1.5rem;
    border: 2px solid #f1f5f9;
    border-radius: 14px;
    font-size: 1rem;
    transition: all 0.3s;
    background: white;
    color: #1a1a2e;
    font-weight: 500;
  }

  .input-group input:focus {
    outline: none;
    border-color: #6a11cb;
    box-shadow: 0 0 0 4px rgba(106, 17, 203, 0.1);
    transform: translateY(-1px);
  }

  .input-group input::placeholder {
    color: #94a3b8;
    font-weight: 400;
  }

  /* Input Icons */
  .input-icon {
    position: absolute;
    right: 1.5rem;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    font-size: 1.1rem;
    transition: all 0.3s;
  }

  .input-group input:focus + .input-icon {
    color: #6a11cb;
  }

  /* Login Button */
  .login-btn {
    width: 100%;
    padding: 1.2rem;
    background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
    color: white;
    border: none;
    border-radius: 14px;
    font-size: 1.1rem;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    margin-top: 2rem;
    position: relative;
    overflow: hidden;
  }

  .login-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
    transition: left 0.7s;
  }

  .login-btn:hover {
    transform: translateY(-3px);
    box-shadow: 
      0 20px 40px rgba(106, 17, 203, 0.3),
      0 0 0 1px rgba(106, 17, 203, 0.1);
  }

  .login-btn:hover::before {
    left: 100%;
  }

  .login-btn:active {
    transform: translateY(-1px);
  }

  /* Demo Credentials */
  .demo-credentials {
    background: linear-gradient(135deg, #f8fafc 0%, #e9ecef 100%);
    padding: 1.5rem;
    border-radius: 14px;
    margin-top: 2rem;
    border: 1px solid #f1f5f9;
  }

  .demo-credentials h4 {
    color: #6a11cb;
    margin-bottom: 1rem;
    font-size: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }

  .demo-credentials ul {
    list-style: none;
  }

  .demo-credentials li {
    margin-bottom: 0.75rem;
    padding: 0.75rem;
    background: white;
    border-radius: 10px;
    border: 1px solid #f1f5f9;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .cred-role {
    font-weight: 600;
    color: #6a11cb;
  }

  .cred-user, .cred-pass {
    font-family: monospace;
    background: #f8fafc;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.9rem;
  }

  /* Links */
  .login-links {
    text-align: center;
    margin-top: 2.5rem;
  }

  .login-links a {
    color: #6a11cb;
    text-decoration: none;
    font-weight: 700;
    font-size: 0.95rem;
    display: inline-block;
    margin-bottom: 1rem;
    transition: all 0.3s;
    position: relative;
  }

  .login-links a::after {
    content: '';
    position: absolute;
    bottom: -3px;
    left: 0;
    width: 0;
    height: 2px;
    background: linear-gradient(90deg, #6a11cb 0%, #2575fc 100%);
    transition: width 0.3s;
  }

  .login-links a:hover {
    color: #2575fc;
  }

  .login-links a:hover::after {
    width: 100%;
  }

  .login-links p {
    color: #64748b;
    font-size: 0.9rem;
    line-height: 1.7;
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid #f1f5f9;
  }

  .login-links b {
    background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    font-weight: 700;
  }

  /* Responsive Design */
  @media (max-width: 768px) {
    body {
      padding: 1.5rem;
    }
    
    .login-container {
      padding: 2.5rem 2rem;
    }
    
    .role-options {
      flex-direction: column;
      align-items: center;
      gap: 2.5rem;
    }
    
    .role-circle {
      width: 130px;
      height: 130px;
    }
    
    .role-circle-bg {
      width: 150px;
      height: 150px;
    }
    
    .elegant-shadow {
      width: 160px;
      height: 160px;
    }
    
    .role-circle-container:hover .role-circle-bg {
      width: 160px;
      height: 160px;
    }
    
    .role-circle-container:hover .elegant-shadow {
      width: 180px;
      height: 180px;
    }
    
    .role-option input[type="radio"]:checked + .role-circle-container .role-circle-bg {
      width: 170px;
      height: 170px;
    }
    
    .role-option input[type="radio"]:checked + .role-circle-container .elegant-shadow {
      width: 200px;
      height: 200px;
    }
  }

  @media (max-width: 480px) {
    .login-container {
      padding: 2rem 1.5rem;
    }
    
    .logo h1 {
      font-size: 2.2rem;
    }
    
    .login-header h2 {
      font-size: 1.7rem;
    }
    
    .role-circle {
      width: 110px;
      height: 110px;
    }
    
    .role-circle span {
      font-size: 0.95rem;
    }
  }

  /* Accessibility Focus Styles */
  .role-circle-container:focus-within .role-circle {
    outline: 2px solid #6a11cb;
    outline-offset: 4px;
  }
</style>
</head>

<body>
  <!-- Background Elements -->
  <div class="bg-element bg-element-1"></div>
  <div class="bg-element bg-element-2"></div>

  <div class="login-container">
    <div class="logo">
      <h1>Mini<span>LMS</span></h1>
    </div>

    <div class="login-header">
      <h2>Welcome Back</h2>
      <p>Sign in to continue to your educational journey</p>
    </div>

    <?php if ($error_message): ?>
    <div class="message-container">
      <div class="error-message">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo htmlspecialchars($error_message); ?>
      </div>
    </div>
    <?php endif; ?>

    <form class="login-form" method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
      <div class="role-selection">
        <h3>Select Your Role</h3>
        
        <div class="role-options">
          <!-- Student Role -->
          <label class="role-option">
            <input type="radio" name="role" value="student" id="student-role" checked>
            <div class="role-circle-container">
              <div class="elegant-shadow"></div>
              <div class="role-circle-bg"></div>
              <div class="role-circle">
                <span>Student</span>
              </div>
              <div class="role-name">Student</div>
            </div>
          </label>

          <!-- Instructor Role -->
          <label class="role-option">
            <input type="radio" name="role" value="teacher" id="instructor-role">
            <div class="role-circle-container">
              <div class="elegant-shadow"></div>
              <div class="role-circle-bg"></div>
              <div class="role-circle">
                <span>Instructor</span>
              </div>
              <div class="role-name">Instructor</div>
            </div>
          </label>

          <!-- Administrator Role -->
          <label class="role-option">
            <input type="radio" name="role" value="admin" id="admin-role">
            <div class="role-circle-container">
              <div class="elegant-shadow"></div>
              <div class="role-circle-bg"></div>
              <div class="role-circle">
                <span>Admin</span>
              </div>
              <div class="role-name">Admin</div>
            </div>
          </label>
        </div>
      </div>

      <div class="form-group">
        <div class="input-group">
          <input id="username" name="username" type="text" 
                 placeholder="Username (e.g., student1)" 
                 value="<?php echo htmlspecialchars($username); ?>" required>
          <span class="input-icon">
            <i class="fas fa-user"></i>
          </span>
        </div>
      </div>

      <div class="form-group">
        <div class="input-group">
          <input id="password" name="password" type="password" 
                 placeholder="Password (demo123 for testing)" required>
          <span class="input-icon">
            <i class="fas fa-lock"></i>
          </span>
        </div>
      </div>

      <button type="submit" class="login-btn">
        <i class="fas fa-sign-in-alt"></i> Sign In
      </button>
    </form>

    <!-- Demo Credentials for Testing -->
    <div class="demo-credentials">
      <h4><i class="fas fa-key"></i> Demo Credentials</h4>
      <ul>
        <li>
          <span class="cred-role">Student</span>
          <span class="cred-user">User: <?php echo $demo_credentials['student']['username']; ?></span>
          <span class="cred-pass">Pass: <?php echo $demo_credentials['student']['password']; ?></span>
        </li>
        <li>
          <span class="cred-role">Teacher</span>
          <span class="cred-user">User: <?php echo $demo_credentials['teacher']['username']; ?></span>
          <span class="cred-pass">Pass: <?php echo $demo_credentials['teacher']['password']; ?></span>
        </li>
        <li>
          <span class="cred-role">Admin</span>
          <span class="cred-user">User: <?php echo $demo_credentials['admin']['username']; ?></span>
          <span class="cred-pass">Pass: <?php echo $demo_credentials['admin']['password']; ?></span>
        </li>
      </ul>
    </div>

    <div class="login-links">
      <a href="#">Forgot Password?</a>
      <p>For any query email us at <b>minilms@gmail.com</b></p>
    </div>
  </div>

  <script>
    // JavaScript to enhance the login experience
    document.addEventListener('DOMContentLoaded', function() {
      const roleRadios = document.querySelectorAll('input[name="role"]');
      const usernameInput = document.getElementById('username');
      
      // Set default username based on selected role
      function updateUsernamePlaceholder() {
        const selectedRole = document.querySelector('input[name="role"]:checked').value;
        const placeholders = {
          'student': 'Username (e.g., student1)',
          'teacher': 'Username (e.g., teacher1)',
          'admin': 'Username (e.g., admin)'
        };
        usernameInput.placeholder = placeholders[selectedRole] || 'Username';
      }
      
      // Add event listeners to role radios
      roleRadios.forEach(radio => {
        radio.addEventListener('change', updateUsernamePlaceholder);
      });
      
      // Pre-fill demo credentials when role is selected
      roleRadios.forEach(radio => {
        radio.addEventListener('change', function() {
          const role = this.value;
          const demoUsers = {
            'student': 'student1',
            'teacher': 'teacher1',
            'admin': 'admin'
          };
          
          if (demoUsers[role]) {
            usernameInput.value = demoUsers[role];
            usernameInput.focus();
          }
        });
      });
      
      // Initialize placeholder
      updateUsernamePlaceholder();
      
      // Auto-focus username field
      usernameInput.focus();
    });
  </script>
</body>
</html>