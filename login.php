<?php
include 'config/db.php';
include 'config/auth.php';

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    check_auth_token();
    
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? AND password = ?");
    $stmt->bind_param("ss", $username, $password);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['fullname'] = $user['fullname'];
        header("Location: index.php");
        exit();
    } else {
        $error = "Username หรือ Password ไม่ถูกต้อง";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login - CampServe</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5" style="max-width: 420px;">
    <div class="card p-4 shadow-sm">
        <h3 class="text-center mb-3 fw-bold text-primary">CampServe</h3>
        <p class="text-center text-muted mb-4">เข้าสู่ระบบแจ้งซ่อมและยืมอุปกรณ์</p>
        
        <?php if(isset($error)): ?>
            <div class="alert alert-danger"><?= auth_escape($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="auth_csrf" value="<?= auth_token() ?>">
            <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control" required autocomplete="off">
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" name="login" class="btn btn-primary w-100 fw-bold">Login</button>
        </form>
    </div>
</div>
</body>
</html>