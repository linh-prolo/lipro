<?php
// Dùng chung cho tất cả các trang
// $conn phải được tạo trước khi include file này
$_unread = 0;
if (!isSupplier() && isset($conn) && $conn) {
    $_unread = getUnreadNotificationCount($conn);
}

// Detect trang active
$_current = basename($_SERVER['PHP_SELF']);
$_dir     = basename(dirname($_SERVER['PHP_SELF']));
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top shadow">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="/lipro/dashboard.php">
            <i class="bi bi-box-seam"></i> Forwarder System
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">

                <?php if (!isSupplier()): ?>
                <!-- Admin / Staff menu -->
                <li class="nav-item">
                    <a class="nav-link <?php echo ($_current === 'dashboard.php') ? 'active' : ''; ?>"
                       href="/lipro/dashboard.php">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo ($_dir === 'customers') ? 'active' : ''; ?>"
                       href="/lipro/customers/index.php">
                        <i class="bi bi-people"></i> Khách hàng
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo ($_dir === 'quotations') ? 'active' : ''; ?>"
                       href="/lipro/quotations/index.php">
                        <i class="bi bi-file-earmark-text"></i> Báo Giá
                    </a>
                </li>
                <?php endif; ?>

                <!-- Lô hàng — tất cả đều thấy -->
                <li class="nav-item">
                    <a class="nav-link <?php echo ($_dir === 'shipments') ? 'active' : ''; ?>"
                       href="/lipro/shipments/index.php">
                        <i class="bi bi-box"></i> Lô hàng
                        <?php if (!isSupplier()): ?>
                        <?php
                        // Đếm lô chờ duyệt
                        $_pending_count = 0;
                        if (isset($conn) && $conn) {
                            $r = @$conn->query("SELECT COUNT(*) c FROM shipments WHERE approval_status='pending_approval' AND deleted_at IS NULL");
                            $_pending_count = $r ? intval($r->fetch_assoc()['c'] ?? 0) : 0;
                        }
                        if ($_pending_count > 0): ?>
                            <span class="badge bg-warning text-dark ms-1"><?php echo $_pending_count; ?></span>
                        <?php endif; ?>
                        <?php endif; ?>
                    </a>
                </li>

                <?php if (!isSupplier()): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo ($_dir === 'debt') ? 'active' : ''; ?>"
                       href="/lipro/debt/debt.php">
                        <i class="bi bi-cash-coin"></i> Công Nợ
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo ($_dir === 'suppliers') ? 'active' : ''; ?>"
                       href="/lipro/suppliers/index.php">
                        <i class="bi bi-truck"></i> Nhà cung cấp
                    </a>
                </li>

                
                <li class="nav-item">
                    <a class="nav-link <?php echo ($_dir === 'reports') ? 'active' : ''; ?>"
                       href="/lipro/reports/index.php">
                        <i class="bi bi-bar-chart-line"></i> Báo cáo
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo ($_dir === 'exchange_rates') ? 'active' : ''; ?>"
                       href="/lipro/exchange_rates/index.php">
                        <i class="bi bi-currency-exchange"></i> Tỷ giá
                    </a>
                </li>

                <?php if (isAdmin()): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-gear"></i> Quản trị
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="/lipro/accounts/index.php">
                            <i class="bi bi-person-badge"></i> Tài khoản
                        </a></li>
                        <li><a class="dropdown-item" href="/lipro/cost_codes/index.php">
                            <i class="bi bi-tag"></i> Mã chi phí
                        </a></li>
                        <li><a class="dropdown-item" href="/lipro/logs/activity.php">
                            <i class="bi bi-journal-text"></i> Nhật ký hoạt động
                        </a></li>
                    </ul>
                </li>
                <?php endif; ?>
                <?php endif; /* end !isSupplier */ ?>

            </ul>

            <!-- Search bar toàn cục -->
            <?php if (!isSupplier()): ?>
            <form class="d-flex mx-3" role="search" id="globalSearchForm" autocomplete="off">
                <div class="input-group input-group-sm" style="width:240px;">
                    <input type="text" class="form-control" id="globalSearchInput"
                           placeholder="Tìm lô hàng, KH, NCC..." aria-label="Tìm kiếm">
                    <span class="input-group-text bg-white">
                        <i class="bi bi-search text-muted"></i>
                    </span>
                </div>
                <div class="position-absolute bg-white border rounded shadow-sm mt-5 w-auto"
                     id="searchResults" style="display:none;z-index:9999;min-width:300px;max-width:420px;"></div>
            </form>
            <?php endif; ?>

            <ul class="navbar-nav align-items-center">
                <?php if (!isSupplier() && $_unread > 0): ?>
                <li class="nav-item me-2">
                    <a href="/lipro/notifications/index.php" class="btn btn-warning btn-sm position-relative">
                        <i class="bi bi-bell-fill"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            <?php echo $_unread; ?>
                        </span>
                    </a>
                </li>
                <?php endif; ?>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i>
                        <?php echo htmlspecialchars($_SESSION['full_name'] ?? ''); ?>
                        <?php if (isSupplier()): ?>
                            <span class="badge bg-warning text-dark ms-1">NCC</span>
                        <?php elseif (isAdmin()): ?>
                            <span class="badge bg-danger ms-1">Admin</span>
                        <?php else: ?>
                            <span class="badge bg-secondary ms-1">Staff</span>
                        <?php endif; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <?php if (isSupplier()): ?>
                        <li><span class="dropdown-item-text text-muted small">
                            <i class="bi bi-building"></i>
                            <?php echo htmlspecialchars($_SESSION['supplier_name'] ?? ''); ?>
                        </span></li>
                        <li><hr class="dropdown-divider"></li>
                        <?php endif; ?>
                        <li><a class="dropdown-item" href="/lipro/accounts/profile.php">
                            <i class="bi bi-person-gear"></i> Hồ sơ cá nhân
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/lipro/logout.php">
                            <i class="bi bi-box-arrow-right"></i> Đăng xuất
                        </a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
<?php if (!isSupplier()): ?>
<script>
(function() {
    const input   = document.getElementById('globalSearchInput');
    const results = document.getElementById('searchResults');
    if (!input || !results) return;

    let timer;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        const q = this.value.trim();
        if (q.length < 2) { results.style.display = 'none'; return; }
        timer = setTimeout(function () {
            fetch('/lipro/api/search.php?q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    if (!data.length) {
                        results.innerHTML = '<div class="p-3 text-muted small">Không tìm thấy kết quả</div>';
                    } else {
                        const icons = { shipment: 'bi-box', customer: 'bi-people', supplier: 'bi-truck' };
                        results.innerHTML = data.map(item => {
                            const a   = document.createElement('a');
                            a.href    = item.url;
                            a.className = 'd-flex align-items-center gap-2 px-3 py-2 text-decoration-none text-dark border-bottom search-item';
                            const icon = document.createElement('i');
                            icon.className = 'bi ' + (icons[item.type] || 'bi-search') + ' text-primary';
                            const div = document.createElement('div');
                            const title = document.createElement('div');
                            title.className = 'fw-semibold small';
                            title.textContent = item.label;
                            const sub = document.createElement('div');
                            sub.className = 'text-muted';
                            sub.style.fontSize = '.75rem';
                            sub.textContent = item.type;
                            div.appendChild(title);
                            div.appendChild(sub);
                            a.appendChild(icon);
                            a.appendChild(div);
                            return a.outerHTML;
                        }).join('');
                    }
                    results.style.display = 'block';
                })
                .catch(() => { results.style.display = 'none'; });
        }, 300);
    });

    document.addEventListener('click', function (e) {
        if (!input.contains(e.target) && !results.contains(e.target)) {
            results.style.display = 'none';
        }
    });
})();
</script>
<?php endif; ?>
