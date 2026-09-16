<?php
require_once '../config/database.php';
checkLogin();

if (isSupplier()) {
    header("Location: /forwarder/shipments/index.php?error=no_permission");
    exit();
}

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) { header("Location: index.php"); exit(); }

$conn = getDBConnection();

// Load báo giá gốc
$stmt = $conn->prepare("SELECT * FROM quotations WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$quot = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$quot) { header("Location: index.php"); exit(); }

// Load items gốc
$stmt = $conn->prepare("SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY sort_order, id");
$stmt->bind_param("i", $id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Sinh số báo giá mới
$year = date('Y');
$like = "BG-$year-%";
$stmt = $conn->prepare("SELECT MAX(quotation_no) AS max_no FROM quotations WHERE quotation_no LIKE ?");
$stmt->bind_param("s", $like);
$stmt->execute();
$row_no = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($row_no['max_no']) {
    $parts   = explode('-', $row_no['max_no']);
    $counter = intval($parts[2] ?? 0) + 1;
} else {
    $counter = 1;
}
$new_no = sprintf("BG-%s-%03d", $year, $counter);

// Kiểm tra cột tồn tại
$hasShipper = false;
$r1 = $conn->query("SHOW COLUMNS FROM `quotations` LIKE 'shipper'");
if ($r1 && $r1->num_rows > 0) $hasShipper = true;

$hasPolPod = false;
$r2 = $conn->query("SHOW COLUMNS FROM `quotations` LIKE 'pol'");
if ($r2 && $r2->num_rows > 0) $hasPolPod = true;

$hasAmount = false;
$r3 = $conn->query("SHOW COLUMNS FROM `quotation_items` LIKE 'amount'");
if ($r3 && $r3->num_rows > 0) $hasAmount = true;

// Chuẩn bị dữ liệu quotation
$new_no_s      = (string) $new_no;
$customer_id   = (int)    $quot['customer_id'];
$issue_date    = (string) date('Y-m-d');
$valid_until   = !empty($quot['valid_until']) ? (string) $quot['valid_until'] : null;
$currency      = (string) ($quot['currency']      ?? 'USD');
$exchange_rate = (float)  ($quot['exchange_rate'] ?? 1);
$notes         = (string) ($quot['notes']         ?? '');
$created_by    = (int)    $_SESSION['user_id'];

$conn->begin_transaction();
try {

    // ── INSERT QUOTATION ─────────────────────────────────────────────
    if ($hasPolPod && $hasShipper) {
        $pol      = (string) ($quot['pol']      ?? '');
        $pod      = (string) ($quot['pod']      ?? '');
        $shipper  = !empty($quot['shipper'])  ? (string) $quot['shipper']  : null;
        $packages = !empty($quot['packages']) ? (float)  $quot['packages'] : null;
        $gw       = !empty($quot['gw'])       ? (float)  $quot['gw']      : null;
        $cw       = !empty($quot['cw'])       ? (float)  $quot['cw']      : null;

        // 14 params: s i s s s d s s s d d d s i
        $stmt = $conn->prepare(
            "INSERT INTO quotations
             (quotation_no, customer_id, issue_date, valid_until,
              currency, exchange_rate, pol, pod, shipper,
              packages, gw, cw, notes, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)"
        );
        $stmt->bind_param("sisssdsssdddsi",
            $new_no_s,
            $customer_id,
            $issue_date,
            $valid_until,
            $currency,
            $exchange_rate,
            $pol,
            $pod,
            $shipper,
            $packages,
            $gw,
            $cw,
            $notes,
            $created_by
        );

    } elseif ($hasPolPod) {
        $pol      = (string) ($quot['pol']      ?? '');
        $pod      = (string) ($quot['pod']      ?? '');
        $packages = !empty($quot['packages']) ? (float) $quot['packages'] : null;
        $gw       = !empty($quot['gw'])       ? (float) $quot['gw']      : null;
        $cw       = !empty($quot['cw'])       ? (float) $quot['cw']      : null;

        // 13 params: s i s s s d s s d d d s i
        $stmt = $conn->prepare(
            "INSERT INTO quotations
             (quotation_no, customer_id, issue_date, valid_until,
              currency, exchange_rate, pol, pod,
              packages, gw, cw, notes, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)"
        );
        $stmt->bind_param("sisssdssdddsi",
            $new_no_s,
            $customer_id,
            $issue_date,
            $valid_until,
            $currency,
            $exchange_rate,
            $pol,
            $pod,
            $packages,
            $gw,
            $cw,
            $notes,
            $created_by
        );

    } else {
        // 8 params: s i s s s d s i
        $stmt = $conn->prepare(
            "INSERT INTO quotations
             (quotation_no, customer_id, issue_date, valid_until,
              currency, exchange_rate, notes, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?)"
        );
        $stmt->bind_param("sisssdssi",
            $new_no_s,
            $customer_id,
            $issue_date,
            $valid_until,
            $currency,
            $exchange_rate,
            $notes,
            $created_by
        );
    }

    if (!$stmt->execute()) {
        throw new Exception("Insert quotation failed: " . $stmt->error);
    }
    $new_id = (int) $conn->insert_id;
    $stmt->close();

    // ── INSERT ITEMS ─────────────────────────────────────────────────
    foreach ($items as $item) {
        $qid             = (int)    $new_id;
        $arrival_code_id = (int)    ($item['arrival_code_id'] ?? 0);
        $cost_code       = (string) ($item['cost_code']       ?? '');
        $description     = (string) ($item['description']     ?? '');
        $item_currency   = (string) ($item['currency']        ?? 'USD');
        $unit_price      = (float)  ($item['unit_price']      ?? 0);
        $quantity        = (float)  ($item['quantity']        ?? 1);
        $item_notes      = (string) ($item['notes']           ?? '');
        $sort_order      = (int)    ($item['sort_order']      ?? 0);

        if ($hasAmount) {
            $amount = (float) ($item['amount'] ?? 0);
            // 10 params: i i s s s d d d s i
            $istmt = $conn->prepare(
                "INSERT INTO quotation_items
                 (quotation_id, arrival_code_id, cost_code, description,
                  currency, unit_price, quantity, amount, notes, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $istmt->bind_param("iisssdddsi",
                $qid,
                $arrival_code_id,
                $cost_code,
                $description,
                $item_currency,
                $unit_price,
                $quantity,
                $amount,
                $item_notes,
                $sort_order
            );
        } else {
            // 9 params: i i s s s d d s i
            $istmt = $conn->prepare(
                "INSERT INTO quotation_items
                 (quotation_id, arrival_code_id, cost_code, description,
                  currency, unit_price, quantity, notes, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $istmt->bind_param("iisssddsi",
                $qid,
                $arrival_code_id,
                $cost_code,
                $description,
                $item_currency,
                $unit_price,
                $quantity,
                $item_notes,
                $sort_order
            );
        }

        if (!$istmt->execute()) {
            throw new Exception("Insert item failed: " . $istmt->error);
        }
        $istmt->close();
    }

    $conn->commit();
    $conn->close();
    header("Location: edit.php?id=" . $new_id . "&success=copied");
    exit();

} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    error_log("copy.php error: " . $e->getMessage());
    header("Location: index.php?error=copy_failed");
    exit();
}