<?php
// $page_title phải được set trước khi include file này
$page_title = $page_title ?? 'LIPRO';
?>
<div id="topbar">
    <!-- Hamburger toggle (mobile) -->
    <button class="btn btn-link text-dark p-0 me-3 d-lg-none" id="sidebarToggle">
        <i class="bi bi-list fs-4"></i>
    </button>

    <!-- Breadcrumb / Tiêu đề trang -->
    <div class="d-flex align-items-center gap-2">
        <span class="text-muted small d-none d-md-inline">
            <a href="/lipro/dashboard.php" class="text-decoration-none text-muted">
                <i class="bi bi-house"></i> LIPRO
            </a>
            <i class="bi bi-chevron-right mx-1" style="font-size:.75rem;"></i>
        </span>
        <span class="fw-semibold" style="color: var(--lipro-primary);">
            <?php echo htmlspecialchars($page_title); ?>
        </span>
    </div>

    <!-- Bên phải: thông báo + user -->
    <div class="d-flex align-items-center gap-3">
        <?php if (!isSupplier() && isset($conn)): ?>
        <?php $_topbar_unread = getUnreadNotificationCount($conn); ?>
        <a href="/lipro/notifications/index.php" class="position-relative text-muted text-decoration-none">
            <i class="bi bi-bell fs-5"></i>
            <?php if ($_topbar_unread > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem;">
                <?php echo $_topbar_unread; ?>
            </span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <div class="d-flex align-items-center gap-2">
            <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center text-white"
                 style="width:32px;height:32px;font-size:.85rem;">
                <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?>
            </div>
            <span class="d-none d-md-inline fw-semibold small" style="color:var(--lipro-primary);">
                <?php echo htmlspecialchars($_SESSION['full_name'] ?? ''); ?>
            </span>
        </div>
    </div>
</div>
