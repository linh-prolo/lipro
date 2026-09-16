<?php
// ⚠️ XÓA FILE NÀY SAU KHI TEST XONG!
require_once '../config/database.php';
require_once '../config/ehoadon.php';
checkLogin();

echo '<style>pre{background:#f4f4f4;padding:10px;border-radius:5px;overflow-x:auto;font-size:12px;}</style>';
echo '<h3>🔍 Debug SOAP Raw - v3</h3>';

// ============================================================
// Parse token
// ============================================================
$token    = EHOADON_PARTNER_TOKEN;
$colonPos = strrpos($token, ':');
$aesKey   = base64_decode(substr($token, 0, $colonPos));
$aesIv    = base64_decode(substr($token, $colonPos + 1));

// ============================================================
// Encrypt payload
// ============================================================
$payload    = ['CmdType' => 110, 'CommandObject' => [['PartnerInvoiceID' => 1]]];
$json       = json_encode($payload);
$compressed = gzencode($json, 9);
$encrypted  = openssl_encrypt($compressed, 'AES-256-CBC', $aesKey, OPENSSL_RAW_DATA, $aesIv);
$encoded    = base64_encode($encrypted);

$guid = EHOADON_PARTNER_GUID;

echo '<h4>Kiểm tra giá trị biến:</h4>';
echo '<p>GUID value: [' . $guid . '] length=' . strlen($guid) . '</p>';
echo '<p>Encoded length: ' . strlen($encoded) . '</p>';

// ============================================================
// Build SOAP XML - thử nhiều cách
// ============================================================

// Cách 1: string concatenation thông thường
$soap1 = '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><ExecCommand xmlns="http://tempuri.org/"><PartnerGUID>' . $guid . '</PartnerGUID><CommandData>' . $encoded . '</CommandData></ExecCommand></soap:Body></soap:Envelope>';

// Kiểm tra GUID có trong XML không
echo '<h4>GUID có trong SOAP XML không?</h4>';
echo '<p>' . (strpos($soap1, $guid) !== false ? '✅ CÓ' : '❌ KHÔNG - GUID bị mất!') . '</p>';

// In ra đoạn XML quanh PartnerGUID
$start = strpos($soap1, '<PartnerGUID>');
$end   = strpos($soap1, '</PartnerGUID>') + strlen('</PartnerGUID>');
echo '<p>PartnerGUID tag: <code>' . htmlspecialchars(substr($soap1, $start, $end - $start)) . '</code></p>';

// ============================================================
// Gọi SOAP và dump toàn bộ
// ============================================================
$wsUrl = EHOADON_PRODUCTION ? EHOADON_WS_URL : EHOADON_WS_URL_TEST;

echo '<h4>Gọi SOAP:</h4>';
echo '<p>URL: ' . $wsUrl . '</p>';

$ch = curl_init($wsUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $soap1,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: text/xml; charset=utf-8',
        'SOAPAction: "http://tempuri.org/ExecCommand"',
    ],
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response = curl_exec($ch);
$info     = curl_getinfo($ch);
$err      = curl_error($ch);
curl_close($ch);

echo '<p>HTTP: ' . $info['http_code'] . '</p>';
echo '<p>cURL error: ' . ($err ?: 'none') . '</p>';
echo '<h4>Raw Response:</h4>';
echo '<pre>' . htmlspecialchars($response) . '</pre>';

// ============================================================
// Thử SoapClient native PHP
// ============================================================
echo '<hr><h4>Thử PHP SoapClient (native):</h4>';
if (!extension_loaded('soap')) {
    echo '<p>❌ SOAP extension không có - thử cách khác</p>';

    // Thử gọi trực tiếp qua WSDL URL
    echo '<p>Kiểm tra WSDL accessible:</p>';
    $ch2 = curl_init($wsUrl . '?wsdl');
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $wsdl     = curl_exec($ch2);
    $wsdlCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    echo '<p>WSDL HTTP: ' . $wsdlCode . '</p>';
    echo '<pre>' . htmlspecialchars(substr($wsdl, 0, 500)) . '...</pre>';

} else {
    try {
        $soapClient = new SoapClient($wsUrl . '?wsdl', [
            'trace'              => true,
            'exceptions'         => true,
            'stream_context'     => stream_context_create([
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
            ]),
        ]);

        $result = $soapClient->ExecCommand([
            'PartnerGUID' => $guid,
            'CommandData' => $encoded,
        ]);

        echo '<p>✅ SoapClient result:</p>';
        echo '<pre>' . htmlspecialchars(print_r($result, true)) . '</pre>';

        // Giải mã kết quả
        $raw = $result->ExecCommandResult ?? '';
        echo '<p>Result text: [' . htmlspecialchars(substr($raw, 0, 100)) . ']</p>';

    } catch (Exception $e) {
        echo '<p>❌ SoapClient error: ' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '<p>Last request:</p>';
        echo '<pre>' . htmlspecialchars($soapClient->__getLastRequest() ?? '') . '</pre>';
    }
}

// ============================================================
// Thử đọc EHOADON_PARTNER_GUID trực tiếp
// ============================================================
echo '<hr><h4>Kiểm tra constant:</h4>';
echo '<p>defined EHOADON_PARTNER_GUID: ' . (defined('EHOADON_PARTNER_GUID') ? '✅' : '❌') . '</p>';
echo '<p>Value: [' . EHOADON_PARTNER_GUID . ']</p>';
echo '<p>strlen: ' . strlen(EHOADON_PARTNER_GUID) . '</p>';
// In từng ký tự để kiểm tra hidden chars
$guidChars = str_split(EHOADON_PARTNER_GUID);
$hexDump   = implode(' ', array_map('bin2hex', $guidChars));
echo '<p>Hex dump (first 40 chars): <code>' . substr($hexDump, 0, 120) . '</code></p>';