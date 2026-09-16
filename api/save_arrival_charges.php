<?php
require_once '../config/database.php';
checkLogin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    // Fallback nếu gửi dạng form
    $input = $_POST;
}

$shipment_id = intval($input['shipment_id'] ?? 0);
$usd_rate    = floatval($input['usd_rate'] ?? 25000);
$eur_rate    = floatval($input['eur_rate'] ?? 27000);
$charges     = $input['charges'] ?? [];

if ($shipment_id === 0) {
    echo json_encode(['success' => false, 'message' => 'Thiếu shipment_id']);
    exit;
}

$conn = getDBConnection();

// Cập nhật tỷ giá vào bảng shipments
$stmt = $conn->prepare("UPDATE shipments SET arrival_usd_rate=?, arrival_eur_rate=? WHERE id=?");
$stmt->bind_param("ddi", $usd_rate, $eur_rate, $shipment_id);
$stmt->execute();

// Xóa toàn bộ phí cũ rồi insert lại
$stmt_del = $conn->prepare("DELETE FROM arrival_notice_charges WHERE shipment_id=?");
$stmt_del->bind_param("i", $shipment_id);
$stmt_del->execute();

$stmt_ins = $conn->prepare("
    INSERT INTO arrival_notice_charges
        (shipment_id, charge_type, cost_code, description, currency, unit_price, quantity, amount, exchange_rate, amount_vnd, vat, total_vnd, notes, sort_order)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$sort = 0;
foreach ($charges as $c) {
    $type          = in_array($c['charge_type'] ?? '', ['foreign','domestic']) ? $c['charge_type'] : 'foreign';
    $cost_code     = substr($c['cost_code'] ?? '', 0, 50);
    $description   = substr($c['description'] ?? '', 0, 255);
    $currency      = in_array($c['currency'] ?? '', ['USD','EUR','VND']) ? $c['currency'] : 'USD';
    $unit_price    = floatval($c['unit_price'] ?? 0);
    $quantity      = floatval($c['quantity'] ?? 1);
    $amount        = floatval($c['amount'] ?? ($unit_price * $quantity));
    $exchange_rate = floatval($c['exchange_rate'] ?? ($currency === 'USD' ? $usd_rate : ($currency === 'EUR' ? $eur_rate : 1)));
    $amount_vnd    = floatval($c['amount_vnd'] ?? ($currency === 'VND' ? $amount : $amount * $exchange_rate));
    $vat           = floatval($c['vat'] ?? 0);
    $total_vnd     = floatval($c['total_vnd'] ?? ($amount_vnd * (1 + $vat / 100)));
    $notes         = $c['notes'] ?? '';

    $stmt_ins->bind_param("issssdddddddsi",
        $shipment_id, $type, $cost_code, $description, $currency,
        $unit_price, $quantity, $amount, $exchange_rate,
        $amount_vnd, $vat, $total_vnd, $notes, $sort
    );
    $stmt_ins->execute();
    $sort++;
}

// ============================================================
// Tự động cập nhật shipment_sells
// ============================================================
// 1. Lấy thông tin lô hàng
$s = $conn->query("SELECT job_no, pol, pod, vessel_flight, hawb FROM shipments WHERE id=$shipment_id")->fetch_assoc();

// 2. Lấy cost_code_id cho "FREIGHT" (hoặc tạo mới nếu chưa có)
$cc_row = $conn->query("SELECT id FROM cost_codes WHERE code='FREIGHT' LIMIT 1")->fetch_assoc();
$freight_code_id = $cc_row ? intval($cc_row['id']) : null;

if ($freight_code_id) {
    // Tổng phí nước ngoài
    $foreign_total = $conn->query("SELECT COALESCE(SUM(total_vnd),0) t FROM arrival_notice_charges WHERE shipment_id=$shipment_id AND charge_type='foreign'")->fetch_assoc()['t'];

    // Kiểm tra có EXW không (để quyết định nội dung diễn giải)
    $has_exw = $conn->query("SELECT COUNT(*) c FROM arrival_notice_charges WHERE shipment_id=$shipment_id AND charge_type='foreign' AND cost_code='EXW'")->fetch_assoc()['c'];

    $pol = $s['pol'] ?? '';
    $pod = $s['pod'] ?? '';
    $vsl = $s['vessel_flight'] ?? '';

    if ($has_exw > 0) {
        $sell_desc_foreign = "Cước vận chuyển quốc tế ({$pol} - {$pod} // Chuyến Tàu/bay: {$vsl} và Phí tại đầu ({$pol}))";
    } else {
        $sell_desc_foreign = "Cước vận chuyển quốc tế ({$pol} - {$pod} // Chuyến Tàu/bay: {$vsl})";
    }

    // Kiểm tra đã có dòng [ARRIVAL_FOREIGN] chưa
    $existing = $conn->query("SELECT id FROM shipment_sells WHERE shipment_id=$shipment_id AND notes='[ARRIVAL_FOREIGN]' LIMIT 1")->fetch_assoc();
    if ($existing) {
        $sell_id = intval($existing['id']);
        $stmt_upd = $conn->prepare("UPDATE shipment_sells SET description=?, unit_price=?, quantity=1, vat=0, total_amount=? WHERE id=?");
        $stmt_upd->bind_param("sddi", $sell_desc_foreign, $foreign_total, $foreign_total, $sell_id);
        $stmt_upd->execute();
    } elseif ($foreign_total > 0) {
        $stmt_sell = $conn->prepare("INSERT INTO shipment_sells (shipment_id, cost_code_id, description, quantity, unit_price, vat, total_amount, is_pob, notes) VALUES (?, ?, ?, 1, ?, 0, ?, 0, '[ARRIVAL_FOREIGN]')");
        $stmt_sell->bind_param("iisdd", $shipment_id, $freight_code_id, $sell_desc_foreign, $foreign_total, $foreign_total);
        $stmt_sell->execute();
    }
}

// 3. Phí trong nước — thêm từng dòng riêng hoặc cập nhật
$domestic_charges = $conn->query("SELECT * FROM arrival_notice_charges WHERE shipment_id=$shipment_id AND charge_type='domestic' ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);

// Xóa các dòng sells cũ từ arrival domestic
$stmt_del_dom = $conn->prepare("DELETE FROM shipment_sells WHERE shipment_id=? AND notes LIKE '[ARRIVAL\\_DOM\\_%'");
$stmt_del_dom->bind_param("i", $shipment_id);
$stmt_del_dom->execute();
$stmt_del_dom->close();

foreach ($domestic_charges as $dc) {
    $stmt_dc = $conn->prepare("SELECT id FROM cost_codes WHERE code=? LIMIT 1");
    $stmt_dc->bind_param("s", $dc['cost_code']);
    $stmt_dc->execute();
    $dc_cc = $stmt_dc->get_result()->fetch_assoc();
    $stmt_dc->close();
    $dc_code_id = $dc_cc ? intval($dc_cc['id']) : null;
    if (!$dc_code_id && $freight_code_id) $dc_code_id = $freight_code_id;
    if (!$dc_code_id) continue;

    $dc_desc  = $dc['description'];
    $dc_total = floatval($dc['total_vnd']);
    $dc_vat   = floatval($dc['vat']);
    $dc_note  = '[ARRIVAL_DOM_' . $dc['id'] . ']';

    if ($dc_total > 0) {
        $stmt_dsell = $conn->prepare("INSERT INTO shipment_sells (shipment_id, cost_code_id, description, quantity, unit_price, vat, total_amount, is_pob, notes) VALUES (?, ?, ?, 1, ?, ?, ?, 0, ?)");
        $stmt_dsell->bind_param("iisddds", $shipment_id, $dc_code_id, $dc_desc, $dc_total, $dc_vat, $dc_total, $dc_note);
        $stmt_dsell->execute();
    }
}

$conn->close();
echo json_encode(['success' => true, 'message' => 'Đã lưu thành công và cập nhật doanh thu SELL!']);