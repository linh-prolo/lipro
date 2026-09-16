<?php
require_once '../config/database.php';
checkLogin();

$conn = getDBConnection();

$user_id = intval($_SESSION['user_id']);
$per_page = 20;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

// Xử lý POST — đánh dấu tất cả đã đọc
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
    header("Location: index.php?success=marked_all");
    exit();
}

// Đếm tổng
$stmt = $conn->prepare("SELECT COUNT(*) c FROM notifications WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$total_count = intval($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$total_pages = $total_count > 0 ? ceil($total_count / $per_page) : 1;

// Lấy danh sách thông báo
$stmt = $conn->prepare(
    "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?"
);
$stmt->bind_param("iii", $user_id, $per_page, $offset);
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$unread_count = getUnreadNotificationCount($conn);

// Helper: thời gian thân thiện
function timeAgo($datetime) {
    $now  = new DateTime();
    $past = new DateTime($datetime);
    $diff = $now->diff($past);
    if ($diff->y > 0) return $diff->y . ' năm trước';
    if ($diff->m > 0) return $diff->m . ' tháng trước';
    if ($diff->d > 0) return $diff->d . ' ngày trước';
    if ($diff->h > 0) return $diff->h . ' giờ trước';
    if ($diff->i > 0) return $diff->i . ' phút trước';
    return 'Vừa xong';
}

// Icon theo loại thông báo
function notifIcon($type) {
    $icons = [
        'general'  => 'bi-info-circle text-info',
        'shipment' => 'bi-box text-primary',
        'debt'     => 'bi-cash-coin text-warning',
        'system'   => 'bi-gear text-secondary',
        'alert'    => 'bi-exclamation-triangle text-danger',
    ];
    return $icons[$type] ?? 'bi-bell text-secondary';
}

$page_title = 'Thông báo';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thông báo - LIPRO LOGISTICS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/lipro/assets/css/custom.css">
</head>
<body>
    <?php include '../partials/sidebar.php'; ?>

    <div id="main-content">
        <?php include '../partials/topbar.php'; ?>

        <div class="container-fluid px-4 py-3">

            <?php if (isset($_GET['success']) && $_GET['success'] === 'marked_all'): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> Đã đánh dấu tất cả thông báo là đã đọc.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0">
                    <i class="bi bi-bell text-primary"></i> Thông báo
                    <?php if ($unread_count > 0): ?>
                    <span class="badge bg-danger ms-1"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </h4>
                <?php if ($unread_count > 0): ?>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-check2-all"></i> Đánh dấu tất cả đã đọc
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <!-- Danh sách thông báo -->
            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <?php if (empty($notifications)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-bell-slash" style="font-size:3rem;color:#ccc;"></i>
                        <p class="text-muted mt-3 mb-1 fw-semibold">Chưa có thông báo nào</p>
                        <small class="text-muted">Các thông báo về lô hàng, công nợ sẽ hiện ở đây</small>
                    </div>
                    <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($notifications as $notif): ?>
                        <?php
                        $isUnread = !$notif['is_read'];
                        $link = '#';
                        if ($notif['related_type'] === 'shipment' && $notif['related_id']) {
                            $link = '/lipro/shipments/view.php?id=' . intval($notif['related_id']);
                        }
                        ?>
                        <li class="list-group-item list-group-item-action px-4 py-3
                            <?php echo $isUnread ? 'bg-light' : ''; ?>"
                            style="cursor:pointer;"
                            onclick="markRead(<?php echo $notif['id']; ?>, '<?php echo htmlspecialchars($link, ENT_QUOTES); ?>')">
                            <div class="d-flex align-items-start gap-3">
                                <div class="mt-1">
                                    <i class="bi <?php echo notifIcon($notif['type']); ?> fs-5"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <span class="fw-<?php echo $isUnread ? 'bold' : 'normal'; ?>">
                                            <?php echo htmlspecialchars($notif['title']); ?>
                                        </span>
                                        <div class="d-flex align-items-center gap-2 ms-3 flex-shrink-0">
                                            <small class="text-muted"><?php echo timeAgo($notif['created_at']); ?></small>
                                            <?php if ($isUnread): ?>
                                            <span class="badge bg-danger rounded-pill" style="width:8px;height:8px;padding:0;"></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if ($notif['message']): ?>
                                    <p class="mb-0 mt-1 text-muted small" style="max-width:600px;">
                                        <?php echo htmlspecialchars(mb_strimwidth($notif['message'], 0, 120, '...')); ?>
                                    </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <nav aria-label="Phân trang" class="mt-3">
                <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted">
                        Hiển thị <?php echo $offset + 1; ?>–<?php echo min($offset + $per_page, $total_count); ?> trong tổng <?php echo $total_count; ?> thông báo
                    </small>
                    <ul class="pagination pagination-sm mb-0">
                        <?php if ($page > 1): ?>
                        <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">‹</a></li>
                        <?php endif; ?>
                        <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>"><?php echo $p; ?></a>
                        </li>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                        <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">›</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </nav>
            <?php endif; ?>

        </div>
    </div>

    <?php include '../partials/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function markRead(id, link) {
        fetch('/lipro/notifications/mark_read.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'id=' + id
        }).then(() => {
            if (link && link !== '#') {
                window.location.href = link;
            } else {
                location.reload();
            }
        }).catch(() => {
            if (link && link !== '#') window.location.href = link;
        });
    }
    </script>
</body>
</html>
