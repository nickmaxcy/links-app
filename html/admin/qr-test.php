<?php
declare(strict_types=1);

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/theme.php';
require __DIR__ . '/../includes/layout.php';
require __DIR__ . '/../includes/qrcode.php';
require_role('admin');

$pdo = get_pdo();

$tests = [
    'Tiny (5 bytes, should be Version 1)' => 'HELLO',
    'Short URL (about 25 bytes)' => 'https://links.example.org',
    'Medium (about 60 bytes)' => 'otpauth://totp/Test:test?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP',
    'Full otpauth length (about 95 bytes)' => 'otpauth://totp/LinksDirectory:testuser123?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=LinksDirectory',
];

render_layout_head($pdo, 'QR Diagnostic - Links Directory Admin');
?>
<?php render_layout_body_open($pdo, 'QR Diagnostic', ''); ?>

  <div class="container">
    <h2>QR Code Diagnostic</h2>
    <p style="color: var(--text-muted); font-size: 0.9rem;">
      Try scanning each of these with your phone's camera, in order. Tell me the first one (if any) that fails —
      that pinpoints where the bug is.
    </p>

    <?php foreach ($tests as $label => $data): ?>
      <?php $svg = qr_encode_svg($data); ?>
      <div style="margin-bottom:24px; padding-bottom:20px; border-bottom:1px solid var(--border-color);">
        <h3><?= h($label) ?></h3>
        <p style="font-family:monospace; font-size:0.8rem; word-break:break-all; color:var(--text-muted);"><?= h($data) ?> (<?= strlen($data) ?> bytes)</p>
        <?php if ($svg): ?>
          <div style="background:#fff; display:inline-block; padding:12px; border-radius:8px;"><?= $svg ?></div>
        <?php else: ?>
          <p style="color:var(--danger);">Could not generate — data too long for supported versions.</p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

<?php render_layout_close(); ?>
