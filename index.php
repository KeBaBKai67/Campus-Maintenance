<?php
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';

// ตั้งค่า CSRF
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function h($v) { 
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); 
}

function csrf_field() { 
    echo '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">'; 
}

function verify_csrf() { 
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) { 
        http_response_code(403); 
        exit('Invalid CSRF token'); 
    } 
}

function valid_id($value) { 
    $n = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]); 
    return $n === false ? null : $n; 
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // [STUDENT] ส่งคำขอแจ้งซ่อม / ยืมอุปกรณ์
    if ($role === 'student' && isset($_POST['create_request'])) {
        $item_id = valid_id($_POST['item_id'] ?? null);
        $description = trim($_POST['description'] ?? '');
        $date = date('Y-m-d');

        if ($item_id && !empty($description)) {
            $stmt = $conn->prepare("INSERT INTO requests (user_id, item_id, description, request_date) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiss", $user_id, $item_id, $description, $date);
            $stmt->execute();
        }
        header("Location: index.php");
        exit();
    }

    // [ADMIN] เพิ่มอุปกรณ์ / สถานที่ใหม่
    if ($role === 'admin' && isset($_POST['create_item'])) {
        $item_name = trim($_POST['item_name'] ?? '');
        $category = trim($_POST['category'] ?? '');

        if (!empty($item_name) && !empty($category)) {
            $stmt = $conn->prepare("INSERT INTO items (name, category, status) VALUES (?, ?, 'available')");
            $stmt->bind_param("ss", $item_name, $category);
            $stmt->execute();
        }
        header("Location: index.php");
        exit();
    }

    // [ADMIN] อัปเดตสถานะคำขอ
    if ($role === 'admin' && isset($_POST['update_status'])) {
        $req_id = valid_id($_POST['req_id'] ?? null);
        $status = trim($_POST['status'] ?? '');

        if ($req_id && in_array($status, ['pending', 'approved', 'completed'])) {
            $stmt = $conn->prepare("UPDATE requests SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $status, $req_id);
            $stmt->execute();
        }
        header("Location: index.php");
        exit();
    }
}

// DELETE: ลบคำขอ
if (isset($_GET['delete'])) {
    $del_id = valid_id($_GET['delete']);
    if ($del_id) {
        if ($role === 'admin') {
            $stmt = $conn->prepare("DELETE FROM requests WHERE id = ?");
            $stmt->bind_param("i", $del_id);
        } else {
            $stmt = $conn->prepare("DELETE FROM requests WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $del_id, $user_id);
        }
        $stmt->execute();
    }
    header("Location: index.php");
    exit();
}

// READ Data
if ($role === 'admin') {
    $sql = "SELECT requests.*, users.fullname, items.name as item_name 
            FROM requests 
            JOIN users ON requests.user_id = users.id 
            JOIN items ON requests.item_id = items.id ORDER BY requests.id DESC";
    $requests = $conn->query($sql);
} else {
    $stmt = $conn->prepare("SELECT requests.*, users.fullname, items.name as item_name 
                            FROM requests 
                            JOIN users ON requests.user_id = users.id 
                            JOIN items ON requests.item_id = items.id 
                            WHERE requests.user_id = ? ORDER BY requests.id DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $requests = $stmt->get_result();
}

$items = $conn->query("SELECT * FROM items WHERE status = 'available'");
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CampServe - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark px-3 shadow-sm">
    <span class="navbar-brand fw-bold">CampServe (Role: <?= h(strtoupper($role)) ?>)</span>
    <div>
        <span class="text-white me-3">ผู้ใช้งาน: <?= h($_SESSION['fullname']) ?></span>
        <a href="logout.php" class="btn btn-outline-danger btn-sm">Logout</a>
    </div>
</nav>

<div class="container mt-4">
    <div class="row">
        
        <!-- [STUDENT FORM] -->
        <?php if ($role === 'student'): ?>
        <div class="col-md-4 mb-4">
            <div class="card p-3 shadow-sm border-success">
                <h5 class="text-success fw-bold">ยื่นเรื่องแจ้งซ่อม / ยืมอุปกรณ์</h5>
                <form method="POST">
                    <?php csrf_field(); ?>
                    <div class="mb-3">
                        <label class="form-label">เลือกรายการอุปกรณ์/สถานที่</label>
                        <select name="item_id" class="form-select" required>
                            <?php while($item = $items->fetch_assoc()): ?>
                                <option value="<?= h($item['id']) ?>">[<?= h(strtoupper($item['category'])) ?>] <?= h($item['name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">รายละเอียด / อาการชำรุด</label>
                        <textarea name="description" class="form-control" rows="3" required></textarea>
                    </div>
                    <button type="submit" name="create_request" class="btn btn-success w-100 fw-bold">ส่งคำขอ</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- [ADMIN FORM] -->
        <?php if ($role === 'admin'): ?>
        <div class="col-md-4 mb-4">
            <div class="card p-3 shadow-sm border-primary">
                <h5 class="text-primary fw-bold">+ เพิ่มรายการอุปกรณ์ / สถานที่</h5>
                <form method="POST">
                    <?php csrf_field(); ?>
                    <div class="mb-3">
                        <label class="form-label">ชื่อรายการ</label>
                        <input type="text" name="item_name" class="form-control" placeholder="เช่น ไมโครโฟน, ปลั๊กพ่วง" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">หมวดหมู่</label>
                        <select name="category" class="form-select">
                            <option value="equipment">อุปกรณ์ (Equipment)</option>
                            <option value="facility">สถานที่/ครุภัณฑ์ (Facility)</option>
                        </select>
                    </div>
                    <button type="submit" name="create_item" class="btn btn-primary w-100 fw-bold">บันทึกเพิ่มลงระบบ</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- [DATA TABLE] -->
        <div class="col-md-8">
            <div class="card p-3 shadow-sm">
                <h5 class="fw-bold">รายการคำขอในระบบ</h5>
                <table class="table table-bordered table-hover mt-2 align-middle">
                    <thead class="table-secondary">
                        <tr>
                            <th>#</th>
                            <th>ผู้ยื่นเรื่อง</th>
                            <th>รายการ</th>
                            <th>รายละเอียด</th>
                            <th>วันที่ยื่น</th>
                            <th>สถานะ</th>
                            <th>จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($requests && $requests->num_rows > 0): ?>
                            <?php while($row = $requests->fetch_assoc()): ?>
                            <tr>
                                <td><?= h($row['id']) ?></td>
                                <td><?= h($row['fullname']) ?></td>
                                <td><?= h($row['item_name']) ?></td>
                                <td><?= h($row['description']) ?></td>
                                <td><?= h($row['request_date']) ?></td>
                                <td>
                                    <span class="badge bg-<?= $row['status'] === 'completed' ? 'success' : ($row['status'] === 'approved' ? 'info' : 'warning') ?>">
                                        <?= h(strtoupper($row['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($role === 'admin'): ?>
                                    <form method="POST" class="d-inline">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="req_id" value="<?= h($row['id']) ?>">
                                        <select name="status" onchange="this.form.submit()" class="form-select form-select-sm d-inline w-auto mb-1">
                                            <option value="pending" <?= $row['status']==='pending'?'selected':'' ?>>Pending</option>
                                            <option value="approved" <?= $row['status']==='approved'?'selected':'' ?>>Approved</option>
                                            <option value="completed" <?= $row['status']==='completed'?'selected':'' ?>>Completed</option>
                                        </select>
                                        <input type="hidden" name="update_status" value="1">
                                    </form>
                                    <?php endif; ?>
                                    <a href="index.php?delete=<?= h($row['id']) ?>" class="btn btn-danger btn-sm" onclick="return confirm('ยืนยันการลบรายการนี้?')">ลบ</a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted">ยังไม่มีรายการคำขอในขณะนี้</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>
</body>
</html>