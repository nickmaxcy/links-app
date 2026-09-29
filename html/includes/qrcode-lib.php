<?php
declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

require_once __DIR__ . '/../vendor/autoload.php';

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;

// Generates a QR code as an inline SVG string using the endroid/qr-code
// library (backed by bacon/bacon-qr-code for the actual encoding), rather
// than a custom encoder. Returns the raw <svg>...</svg> markup.
function qr_encode_svg_lib(string $data): string
{
    $qrCode = new QrCode($data);
    $writer = new SvgWriter();
    $result = $writer->write($qrCode);
    return $result->getString();
}
