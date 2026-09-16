<?php
/**
 * Cấu hình Bkav eHoaDon - PRODUCTION
 */

// ✅ PRODUCTION
define('EHOADON_PRODUCTION', true);

// URLs WebService
define('EHOADON_WS_URL',      'https://ws.ehoadon.vn/WSPublicEHoaDon.asmx');
define('EHOADON_WS_URL_TEST', 'https://wsdemo.ehoadon.vn/WSPublicEHoaDon.asmx');

// Thông tin xác thực production - thay bằng credentials thật
define('EHOADON_PARTNER_GUID',  'eac35ab3-8be9-4da5-95ef-beb1701b2178');
define('EHOADON_PARTNER_TOKEN', '0A5/3C4XRVl+kpt/6lGTXBvtWMhO82RFAU1tP1HWqig=:Az0S3rH+VLXvmxHmyAMoLQ==');

// Thông tin công ty
define('EHOADON_TAX_CODE',     '0110453612');
define('EHOADON_COMPANY_NAME', 'CONG TY TNHH LIPRO LOGISTICS');

// Mẫu số, ký hiệu HĐ production
define('EHOADON_TEMPLATE_CODE', '1-1C23TYY');
define('EHOADON_BILL_CODE',     '1C26TYY');