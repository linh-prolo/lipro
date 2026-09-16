<?php
require_once '../config/database.php';
checkLogin();

if (isSupplier()) {
    header("Location: /lipro/shipments/index.php?error=no_permission");
    exit();
}

$conn = getDBConnection();
$id   = intval($_GET['id'] ?? 0);

if ($id <= 0) {
    header("Location: index.php");
    exit();
}

// Load quotation
$stmt = $conn->prepare(
    "SELECT q.*, c.company_name FROM quotations q
     LEFT JOIN customers c ON q.customer_id = c.id
     WHERE q.id = ?"
);
$stmt->bind_param("i", $id);
$stmt->execute();
$quot = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$quot) {
    header("Location: index.php");
    exit();
}

if ($quot['status'] !== 'accepted') {
    header("Location: view.php?id=$id&error=not_accepted");
    exit();
}

// Load quotation items
$stmt = $conn->prepare("SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY sort_order, id");
$stmt->bind_param("i", $id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Tạo lô hàng mới
$job_no      = generateJobNo($conn);
$customer_id = intval($quot['customer_id']);
$pol         = $quot['pol']      ?? null;
$pod         = $quot['pod']      ?? null;
$shipper     = $quot['shipper']  ?? null;
$packages    = isset($quot['packages']) && $quot['packages'] !== '' ? floatval($quot['packages']) : null;
$gw          = isset($quot['gw'])  && $quot['gw']  !== '' ? floatval($quot['gw'])  : null;
$cw          = isset($quot['cw'])  && $quot['cw']  !== '' ? floatval($quot['cw'])  : null;
$created_by  = intval($_SESSION['user_id']);

$stmt = $conn->prepare(
    "INSERT INTO shipments (job_no, customer_id, pol, pod, shipper, packages, gw, cw, status, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)"
);
$stmt->bind_param("sisssdddi", $job_no, $customer_id, $pol, $pod, $shipper, $packages, $gw, $cw, $created_by);
if (!$stmt->execute()) {
    $stmt->close();
    header("Location: view.php?id=$id&error=shipment_failed");
    exit();
}
$new_shipment_id = $conn->insert_id;
$stmt->close();

// Insert sell lines từ quotation_items
$exchange_rate = floatval($quot['exchange_rate'] ?? 1);
if ($exchange_rate <= 0) $exchange_rate = 1;

foreach ($items as $item) {
    $cost_code   = trim($item['cost_code'] ?? '');
    $description = $item['description'] ?? '';
    $quantity    = floatval($item['quantity'] ?? 1);
    $currency    = strtoupper(trim($item['currency'] ?? 'VND'));
    $unit_price  = floatval($item['unit_price'] ?? 0);

    // Quy đổi về VND
    if ($currency !== 'VND') {
        $unit_price_vnd = $unit_price * $exchange_rate;
    } else {
        $unit_price_vnd = $unit_price;
    }

    $total_amount = $quantity * $unit_price_vnd;

    // Tìm cost_code_id từ bảng cost_codes
    $cost_code_id = null;
    if ($cost_code !== '') {
        $st2 = $conn->prepare("SELECT id FROM cost_codes WHERE code = ? LIMIT 1");
        $st2->bind_param("s", $cost_code);
        $st2->execute();
        $row2 = $st2->get_result()->fetch_assoc();
        $st2->close();
        if ($row2) {
            $cost_code_id = intval($row2['id']);
        }
    }

    $st3 = $conn->prepare(
        "INSERT INTO shipment_sells
             (shipment_id, cost_code_id, description, quantity, unit_price, vat, total_amount, is_pob, from_arrival, created_by)
         VALUES (?, ?, ?, ?, ?, 0, ?, 0, 0, ?)"
    );
    $st3->bind_param("iisdddi", $new_shipment_id, $cost_code_id, $description, $quantity, $unit_price_vnd, $total_amount, $created_by);
    $st3->execute();
    $st3->close();
}

// Log hoạt động
$quot_no = $quot['quotation_no'] ?? "ID-$id";
logActivity($conn, 'create', 'shipments', "Tạo lô hàng $job_no từ báo giá $quot_no");

// Tạo thông báo cho admin (nếu hàm tồn tại)
if (function_exists('createNotification')) {
    // Lấy tất cả admin
    $admin_res = $conn->query("SELECT id FROM accounts WHERE role = 'admin'");
    if ($admin_res) {
        while ($adm = $admin_res->fetch_assoc()) {
            createNotification(
                $conn,
                intval($adm['id']),
                'shipment',
                "Lô hàng mới: $job_no",
                "Tạo từ báo giá $quot_no",
                $new_shipment_id,
                'shipment'
            );
        }
    }
}

header("Location: ../shipments/view.php?id=$new_shipment_id&success=from_quotation");
exit();
