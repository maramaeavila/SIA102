<?php
session_start();
include 'config.php';

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    try {
        $stmt = $conn->prepare("SELECT id, password, role FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            if ($password === $user['password']) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $username;
                $_SESSION['role'] = $user['role'];

                // Debugging: Check what role is being detected
                error_log("User role: " . $user['role']);
                
                switch (strtolower($user['role'])) {  // Convert to lowercase for case-insensitive comparison
                    case 'healthcommittee':
                        header("Location: healthcommittee.php");
                        break;
                    case 'doctor':
                        header("Location: doctor.php");
                        break;
                    case 'midwife':
                        header("Location: midwife.php");
                        break;
                    case 'dental':
                        header("Location: dental.php");
                        break;
                    case 'bhw':
                        header("Location: bhw.php");
                        break;
                    case 'dns':
                        header("Location: dns.php");
                        break;
                    default:
                        header("Location: login.php");
                        break;
                }
                exit();
            } else {
                $error = "Invalid username or password!";
            }
        } else {
            $error = "User not found!";
        }
    } catch(PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangay Old Capitol</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
            background-color: #82d4ed;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            overflow: hidden;
        }

        .right-side {
            flex: 1;
            background-color: whitesmoke;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 10px 10px !important;
            height: 70vh;
            width: 60vh;
            border-radius: 20px;
            margin-left: 60%;
        }

        .right-side form {
            width: 80%;
            max-width: 400px;
        }

        .right-side form input {
            width: 100%;
            padding: 10px 15px;
            margin-bottom: 15px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 16px;
        }

        .password-container {
            position: relative;
        }

        .password-container .eye-icon {
            position: absolute;
            top: 40%;
            right: 10px;
            transform: translateY(-40%);
            cursor: pointer;
            color: #aaa;
        }

        .eye-icon {
            height: 20px;
            width: 20px;
        }

        .right-side form button {
            width: 100%;
            padding: 10px 20px;
            background-color: #007bff;
            color: #fff;
            border: none;
            border-radius: 5px;
            font-size: 18px;
            cursor: pointer;
            transition: background-color 0.3s;
            margin-bottom: 20%;
        }

        .right-side form button:hover {
            background-color: #005fa3;
        }

        .footer {
            margin-top: 15px;
            font-size: 14px;
            color: #777;
            text-align: center;
        }

        .bold {
            font-size: 20px;
            font-weight: bolder;
        }

        p {
            margin-bottom: 10%;
            font-size: 22px;
            font-weight: bolder;
        }

        h1 {
            font-size: 22px;
            font-weight: bolder;
        }

        .logo {
            position: absolute;
            top: 2%;
            left: 5%;
            opacity: 0.1;
            z-index: -1;
        }

        .logo img {
            width: 800px;
            height: auto;
        }

        .error-message {
            color: red;
            margin-bottom: 15px;
            text-align: center;
        }
    </style>
</head>
<body>

    <div class="logo">
        <img src="image/logo.png" alt="logo">
    </div>

    <div class="container">
        <div class="right-side">
            <h1>Barangay Management System</h1>
            <p>Old Capitol Site</p>
            
            <?php if (!empty($error)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <input type="text" name="username" placeholder="Username" required>
                <div class="password-container">
                    <input type="password" name="password" placeholder="Password" required>
                </div>
                <button type="submit">Login</button>
            </form>
            <div class="footer">
                &copy; 2024 Barangay Management System. All rights reserved.
            </div>
        </div>
    </div>

</body>
</html>