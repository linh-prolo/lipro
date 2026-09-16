<?php
// Detect trang active
$_sb_current = basename($_SERVER['PHP_SELF']);
$_sb_dir     = basename(dirname($_SERVER['PHP_SELF']));

// Đếm lô chờ duyệt
$_sb_pending = 0;
if (isset($conn) && $conn && !isSupplier()) {
    $r = @$conn->query("SELECT COUNT(*) c FROM shipments WHERE approval_status='pending_approval' AND deleted_at IS NULL");
    $_sb_pending = $r ? intval($r->fetch_assoc()['c'] ?? 0) : 0;
}

// Đếm thông báo chưa đọc
$unread_notif_count = 0;
if (isset($conn) && $conn && !isSupplier()) {
    $unread_notif_count = getUnreadNotificationCount($conn);
}
?>
<!-- Sidebar overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div id="sidebar">
    <!-- Logo -->
    <div class="px-3 py-4 border-bottom" style="border-color:rgba(255,255,255,0.15)!important;">
        <a href="/lipro/dashboard.php" class="text-decoration-none d-flex align-items-center gap-2">
            <div class="rounded-circle bg-white d-flex align-items-center justify-content-center"
                 style="width:38px;height:38px;min-width:38px;">
                <i class="bi bi-box-seam" style="color:var(--lipro-primary);font-size:1.2rem;"></i>
            </div>
            <div>
                <div class="text-white fw-bold" style="font-size:1rem;line-height:1.1;">LIPRO</div>
                <div style="color:rgba(255,255,255,0.6);font-size:.7rem;">LOGISTICS</div>
            </div>
        </a>
    </div>

    <!-- Menu -->
    <nav class="flex-grow-1 py-2">
        <?php if (!isSupplier()): ?>
        <a href="/lipro/dashboard.php"
           class="sidebar-link <?php echo $_sb_current === 'dashboard.php' ? 'active' : ''; ?>">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <a href="/lipro/customers/index.php"
           class="sidebar-link <?php echo $_sb_dir === 'customers' ? 'active' : ''; ?>">
            <i class="bi bi-people"></i> Khách hàng
        </a>
        <a href="/lipro/quotations/index.php"
           class="sidebar-link <?php echo $_sb_dir === 'quotations' ? 'active' : ''; ?>">
            <i class="bi bi-file-earmark-text"></i> Báo Giá
        </a>
        <?php endif; ?>

        <a href="/lipro/shipments/index.php"
           class="sidebar-link <?php echo $_sb_dir === 'shipments' ? 'active' : ''; ?>">
            <i class="bi bi-box"></i> Lô hàng
            <?php if ($_sb_pending > 0): ?>
            <span class="badge bg-warning text-dark ms-auto"><?php echo $_sb_pending; ?></span>
            <?php endif; ?>
        </a>

        <?php if (!isSupplier()): ?>
        <a href="/lipro/debt/debt.php"
           class="sidebar-link <?php echo $_sb_dir === 'debt' ? 'active' : ''; ?>">
            <i class="bi bi-cash-coin"></i> Công Nợ
        </a>
        <a href="/lipro/suppliers/index.php"
           class="sidebar-link <?php echo $_sb_dir === 'suppliers' ? 'active' : ''; ?>">
            <i class="bi bi-truck"></i> Nhà cung cấp
        </a>

        <div class="sidebar-section-title mt-2">Phân tích</div>
        <?php if (!isSupplier()): ?>
        <a href="/lipro/notifications/index.php"
           class="sidebar-link <?php echo $_sb_dir === 'notifications' ? 'active' : ''; ?>">
            <i class="bi bi-bell"></i> Thông báo
            <?php if ($unread_notif_count > 0): ?>
            <span class="badge bg-danger ms-auto"><?php echo $unread_notif_count; ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>
        <a href="/lipro/reports/index.php"
           class="sidebar-link <?php echo $_sb_dir === 'reports' ? 'active' : ''; ?>">
            <i class="bi bi-bar-chart-line"></i> Báo cáo
        </a>
        <a href="/lipro/exchange_rates/index.php"
           class="sidebar-link <?php echo $_sb_dir === 'exchange_rates' ? 'active' : ''; ?>">
            <i class="bi bi-currency-exchange"></i> Tỷ giá
        </a>

        <?php if (isAdmin()): ?>
        <div class="sidebar-section-title mt-2">Quản trị</div>
        <a href="/lipro/accounts/index.php"
           class="sidebar-link <?php echo ($_sb_dir === 'accounts' && !in_array($_sb_current, ['profile.php'])) ? 'active' : ''; ?>">
            <i class="bi bi-person-badge"></i> Tài khoản
        </a>
        <a href="/lipro/cost_codes/index.php"
           class="sidebar-link <?php echo $_sb_dir === 'cost_codes' ? 'active' : ''; ?>">
            <i class="bi bi-tag"></i> Mã chi phí
        </a>
        <a href="/lipro/logs/activity.php"
           class="sidebar-link <?php echo ($_sb_dir === 'logs') ? 'active' : ''; ?>">
            <i class="bi bi-journal-text"></i> Nhật ký HĐ
        </a>
        <?php endif; ?>
        <?php endif; /* !isSupplier */ ?>
    </nav>

    <!-- User info ở dưới -->
    <div class="px-3 py-3 border-top" style="border-color:rgba(255,255,255,0.15)!important;">
        <div class="d-flex align-items-center gap-2 mb-2">
            <div class="rounded-circle bg-white d-flex align-items-center justify-content-center text-primary fw-bold"
                 style="width:34px;height:34px;min-width:34px;font-size:.9rem;color:var(--lipro-primary)!important;">
                <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?>
            </div>
            <div style="min-width:0;">
                <div class="text-white fw-semibold text-truncate" style="font-size:.85rem;">
                    <?php echo htmlspecialchars($_SESSION['full_name'] ?? ''); ?>
                </div>
                <div>
                    <?php if (isAdmin()): ?>
                        <span class="badge bg-danger" style="font-size:.65rem;">Admin</span>
                    <?php elseif (isSupplier()): ?>
                        <span class="badge bg-warning text-dark" style="font-size:.65rem;">NCC</span>
                    <?php else: ?>
                        <span class="badge bg-secondary" style="font-size:.65rem;">Staff</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="/lipro/accounts/profile.php" class="btn btn-sm btn-outline-light flex-grow-1"
               style="font-size:.78rem;">
                <i class="bi bi-person-gear"></i> Hồ sơ
            </a>
            <a href="/lipro/logout.php" class="btn btn-sm btn-outline-light"
               style="font-size:.78rem;">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>
</div>

<script>
(function() {
    const sidebar  = document.getElementById('sidebar');
    const overlay  = document.getElementById('sidebarOverlay');
    const toggle   = document.getElementById('sidebarToggle');

    function openSidebar()  { sidebar.classList.add('show'); overlay.classList.add('show'); }
    function closeSidebar() { sidebar.classList.remove('show'); overlay.classList.remove('show'); }

    if (toggle)  toggle.addEventListener('click', openSidebar);
    if (overlay) overlay.addEventListener('click', closeSidebar);
})();
</script>
