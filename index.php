<?php
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';

// ตั้งค่า CSRF
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// 🟢 ฟังก์ชัน Helper (วางไว้ส่วนบนสุด เพื่อให้ทุกจุดเรียกใช้ได้)
if (!function_exists('h')) {
    function h($v) { 
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); 
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field() { 
        echo '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">'; 
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf() { 
        if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) { 
            http_response_code(403); 
            exit('Invalid CSRF token'); 
        } 
    }
}

if (!function_exists('valid_id')) {
    function valid_id($value) { 
        $n = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]); 
        return $n === false ? null : $n; 
    }
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // [STUDENT] ส่งคำขอแจ้งซ่อม / ยืมอุปกรณ์
    if ($role == 'student' && isset($_POST['create_request'])) {
        $item_id = valid_id($_POST['item_id']);
        $request_type = trim($_POST['request_type'] ?? 'แจ้งซ่อม');
        $description = trim($_POST['description'] ?? '');
        $date = date('Y-m-d');

        if ($item_id) {
            // 1. ดึงชื่ออุปกรณ์
            $stmt_item = $conn->prepare("SELECT name FROM items WHERE id = ?");
            $stmt_item->bind_param("i", $item_id);
            $stmt_item->execute();
            $item_result = $stmt_item->get_result()->fetch_assoc();
            $item_name = $item_result['name'] ?? 'ไม่ระบุ';

            // 2. บันทึกลง Database
            $stmt = $conn->prepare("INSERT INTO requests (user_id, item_id, request_type, description, request_date) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("iisss", $user_id, $item_id, $request_type, $description, $date);
            $stmt->execute();

            // 3. 🔔 ส่ง LINE Notification
            $msg = "\n🔔 มีคำขอใหม่ [" . $request_type . "] - Campus Maintenance"
                 . "\n👤 ผู้ยื่นคำขอ: " . ($_SESSION['fullname'] ?? 'นิสิต')
                 . "\n📦 อุปกรณ์/รายการ: " . $item_name
                 . "\n📝 รายละเอียด: " . $description
                 . "\n📅 วันที่: " . $date;
            send_line_message($msg);
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
    if ($role == 'admin' && isset($_POST['update_status'])) {
        $req_id = valid_id($_POST['req_id']);
        $status = trim($_POST['status'] ?? '');

        if ($req_id) {
            // 1. ดึงข้อมูลคำขอและ item_id
            $stmt_info = $conn->prepare("
                SELECT r.item_id, r.request_type, u.fullname AS student_name, i.name AS item_name 
                FROM requests r 
                JOIN users u ON r.user_id = u.id 
                JOIN items i ON r.item_id = i.id 
                WHERE r.id = ?
            ");
            $stmt_info->bind_param("i", $req_id);
            $stmt_info->execute();
            $info = $stmt_info->get_result()->fetch_assoc();

            $item_id = $info['item_id'];
            $student_name = $info['student_name'] ?? 'ไม่ระบุชื่อ';
            $item_name = $info['item_name'] ?? 'ไม่ระบุอุปกรณ์';
            $req_type = $info['request_type'] ?? 'คำขอ';

            // 2. อัปเดตสถานะคำขอในตาราง requests
            $stmt = $conn->prepare("UPDATE requests SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $status, $req_id);
            $stmt->execute();

            // 3. อัปเดตสถานะอุปกรณ์ในตาราง items ตามสถานะคำขอ
            if ($status === 'approved') {
                $stmt_item = $conn->prepare("UPDATE items SET status = 'borrowed' WHERE id = ?");
                $stmt_item->bind_param("i", $item_id);
                $stmt_item->execute();
            } else if ($status === 'completed') {
                $stmt_item = $conn->prepare("UPDATE items SET status = 'available' WHERE id = ?");
                $stmt_item->bind_param("i", $item_id);
                $stmt_item->execute();
            }

            // 4. 🔔 ส่ง LINE Notification
            $msg = "\n📢 อัปเดตสถานะคำขอ #" . $req_id . " - Campus Maintenance"
                 . "\n📌 ประเภท: " . $req_type
                 . "\n👤 คำขอของ: " . $student_name
                 . "\n📦 อุปกรณ์: " . $item_name
                 . "\n🔄 สถานะใหม่: " . strtoupper($status)
                 . "\n👮 อัปเดตโดย: " . ($_SESSION['fullname'] ?? 'Admin');
            send_line_message($msg);
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

// [ADMIN ONLY] DELETE ITEM: ลบอุปกรณ์ออกจากระบบ
if ($role === 'admin' && isset($_GET['delete_item'])) {
    $del_item_id = valid_id($_GET['delete_item']);
    if ($del_item_id) {
        $stmt = $conn->prepare("DELETE FROM items WHERE id = ?");
        $stmt->bind_param("i", $del_item_id);
        $stmt->execute();
    }
    header("Location: index.php");
    exit();
}

// READ Data: ดึงคำขอ
if ($role === 'admin') {
    $sql = "SELECT requests.*, users.fullname, items.name as item_name 
            FROM requests 
            JOIN users ON requests.user_id = users.id 
            JOIN items ON requests.item_id = items.id ORDER BY requests.id DESC";
    $requests = $conn->query($sql);
    
    // ดึงรายการอุปกรณ์ทั้งหมดในระบบ (สำหรับ Admin)
    $all_items = $conn->query("SELECT * FROM items ORDER BY id DESC");
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

// ดึงรายการอุปกรณ์ทั้งหมดให้เลือกยื่น (รวมแอร์และสถานที่)
$available_items = $conn->query("SELECT * FROM items ORDER BY name ASC");
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Campus Maintenance - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark px-3 shadow-sm">
    <span class="navbar-brand fw-bold">Campus Maintenance (Role: <?= h(strtoupper($role)) ?>)</span>
    <div>
        <span class="text-white me-3">ผู้ใช้งาน: <?= h($_SESSION['fullname']) ?></span>
        <a href="logout.php" class="btn btn-outline-danger btn-sm">Logout</a>
    </div>
</nav>

<div class="container-fluid px-4 mt-4">
    <div class="row">
        
        <!-- ==================== LEFT COLUMN: FORMS ==================== -->
        <div class="<?= $role === 'admin' ? 'col-lg-4' : 'col-lg-4 mx-auto' ?> mb-4">
            
            <!-- [STUDENT FORM] -->
            <?php if ($role === 'student'): ?>
            <div class="card shadow-sm border-0">
                <div class="card-header bg-primary text-white fw-bold">
                    ยื่นคำขอใหม่ (Campus Maintenance)
                </div>
                <div class="card-body">
                    <form action="index.php" method="POST">
                        <?php csrf_field(); ?>
                
                        <!-- 1. Dropdown เลือกประเภทรายการ -->
                        <div class="mb-3">
                            <label for="request_type" class="form-label fw-bold">ประเภทคำขอ</label>
                            <select name="request_type" id="request_type" class="form-select" required>
                                <option value="แจ้งซ่อม">🛠️ แจ้งอุปกรณ์ชำรุด/แจ้งซ่อม</option>
                                <option value="ยืมอุปกรณ์">📦 ยืมอุปกรณ์</option>
                            </select>
                        </div>

                        <!-- 2. เลือกอุปกรณ์ -->
                        <div class="mb-3">
                            <label for="item_id" class="form-label fw-bold">เลือกอุปกรณ์</label>
                            <select name="item_id" id="item_id" class="form-select" required>
                                <?php if ($available_items && $available_items->num_rows > 0): ?>
                                    <?php while ($item = $available_items->fetch_assoc()): ?>
                                        <option value="<?= h($item['id']) ?>"><?= h($item['name']) ?></option>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <option value="">-- ไม่พบรายการอุปกรณ์ --</option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <!-- 3. รายละเอียด -->
                        <div class="mb-3">
                            <label for="description" class="form-label fw-bold">รายละเอียดเพิ่มเติม</label>
                            <textarea name="description" id="description" class="form-control" rows="3" placeholder="ระบุอาการชำรุด หรือวัตถุประสงค์ในการยืม..."></textarea>
                        </div>

                        <button type="submit" name="create_request" class="btn btn-primary w-100 fw-bold">ส่งคำขอ</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- [ADMIN FORM: เพิ่มอุปกรณ์] -->
            <?php if ($role === 'admin'): ?>
            <div class="card p-3 shadow-sm border-0 mb-4">
                <h5 class="text-primary fw-bold mb-3">+ เพิ่มรายการอุปกรณ์ / สถานที่</h5>
                <form method="POST">
                    <?php csrf_field(); ?>
                    <div class="mb-3">
                        <label class="form-label fw-bold">ชื่อรายการ</label>
                        <input type="text" name="item_name" class="form-control" placeholder="เช่น ไมโครโฟน, เครื่องปรับอากาศ 401" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">หมวดหมู่</label>
                        <select name="category" class="form-select">
                            <option value="equipment">อุปกรณ์ (Equipment)</option>
                            <option value="facility">สถานที่/ครุภัณฑ์ (Facility)</option>
                        </select>
                    </div>
                    <button type="submit" name="create_item" class="btn btn-primary w-100 fw-bold">บันทึกเพิ่มลงระบบ</button>
                </form>
            </div>
            <?php endif; ?>

        </div>

        <!-- ==================== RIGHT COLUMN: DATA TABLES ==================== -->
        <div class="<?= $role === 'admin' ? 'col-lg-8' : 'col-lg-8' ?>">
            
            <!-- [DATA TABLE 1: รายการคำขอทั้งหมด] -->
            <div class="card p-3 shadow-sm border-0 mb-4">
                <h5 class="fw-bold text-dark">📋 รายการคำขอในระบบ</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover mt-2 align-middle">
                        <thead class="table-secondary">
                            <tr>
                                <th>#</th>
                                <th>ประเภท</th>
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
                                    <td>
                                        <span class="badge bg-<?= ($row['request_type'] ?? '') === 'ยืมอุปกรณ์' ? 'info text-dark' : 'warning text-dark' ?>">
                                            <?= h($row['request_type'] ?? 'แจ้งซ่อม') ?>
                                        </span>
                                    </td>
                                    <td><?= h($row['fullname']) ?></td>
                                    <td><?= h($row['item_name']) ?></td>
                                    <td><?= h($row['description']) ?></td>
                                    <td><?= h($row['request_date']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $row['status'] === 'completed' ? 'success' : ($row['status'] === 'approved' ? 'primary' : 'secondary') ?>">
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
                                    <td colspan="8" class="text-center text-muted">ยังไม่มีรายการคำขอในขณะนี้</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- [DATA TABLE 2: รายการอุปกรณ์ในระบบ (ADMIN ONLY)] -->
            <?php if ($role === 'admin'): ?>
            <div class="card p-3 shadow-sm border-0 mb-4">
                <h5 class="fw-bold text-dark">📦 รายการอุปกรณ์/สถานที่ในระบบ ทั้งหมด</h5>
                <div class="table-responsive">
                    <table class="table table-striped table-hover mt-2 align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>ชื่ออุปกรณ์ / รายการ</th>
                                <th>หมวดหมู่</th>
                                <th>สถานะ</th>
                                <th>จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (isset($all_items) && $all_items->num_rows > 0): ?>
                                <?php while($item = $all_items->fetch_assoc()): ?>
                                <tr>
                                    <td><?= h($item['id']) ?></td>
                                    <td class="fw-bold"><?= h($item['name']) ?></td>
                                    <td>
                                        <span class="badge bg-outline-secondary text-dark border">
                                            <?= h($item['category'] === 'equipment' ? 'อุปกรณ์' : 'สถานที่/ครุภัณฑ์') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $item['status'] === 'available' ? 'success' : ($item['status'] === 'borrowed' ? 'warning text-dark' : 'danger') ?>">
                                            <?= h(strtoupper($item['status'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="index.php?delete_item=<?= h($item['id']) ?>" class="btn btn-outline-danger btn-sm" onclick="return confirm('ยืนยันการลบอุปกรณ์นี้ออกจากระบบ?')">ลบอุปกรณ์</a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted">ยังไม่มีอุปกรณ์ในระบบ</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        </div>

    </div>
</div>
</body>
</html>
