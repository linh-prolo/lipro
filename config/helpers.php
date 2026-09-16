<?php
// ============================================================
// HELPER FUNCTIONS DÙNG CHUNG TOÀN HỆ THỐNG
// ============================================================

if (!function_exists('splitEmails')) {
    function splitEmails(string $str): array {
        $parts = preg_split('/[;,]/', $str);
        $result = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) $result[] = $p;
        }
        return $result;
    }
}

if (!function_exists('fmtSize')) {
    function fmtSize(int $bytes): string {
        if ($bytes < 1024)    return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }
}

if (!function_exists('fileIcon')) {
    function fileIcon(string $type): string {
        if (str_contains($type, 'pdf'))   return 'bi-file-earmark-pdf text-danger';
        if (str_contains($type, 'excel') || str_contains($type, 'spreadsheet')) return 'bi-file-earmark-excel text-success';
        if (str_contains($type, 'word')  || str_contains($type, 'document'))    return 'bi-file-earmark-word text-primary';
        if (str_contains($type, 'image')) return 'bi-file-earmark-image text-info';
        if (str_contains($type, 'zip')   || str_contains($type, 'rar')) return 'bi-file-earmark-zip text-warning';
        return 'bi-file-earmark text-secondary';
    }
}
