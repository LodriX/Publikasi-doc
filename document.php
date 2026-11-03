<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

$document = find_public_document_by_slug((string) ($_GET['slug'] ?? ''));
if ($document === null) {
    http_response_code(404);
    page_header('Dokumen tidak ditemukan');
    ?>
<main class="shell"><section class="card"><p class="empty">Dokumen tidak ditemukan atau belum dipublikasikan.</p></section></main>
<?php page_footer(); exit;
}

$extension = supported_extension($document['path']);
$directory = str_replace('\\', '/', dirname($document['path']));
$directory = $directory === '.' ? '' : $directory;
$viewUrl = document_view_url($document['path']);
$downloadUrl = document_download_url($document['path']);
$officeAvailable = !is_local_request();
$statistics = document_stat($document['path']);
page_header($document['filename']);
?>
<main class="shell">
  <header class="site-header detail-header">
    <div class="detail-title-block">
      <img class="detail-logo" src="<?= e(app_url(app_logo())) ?>" alt="<?= e(app_brand()) ?>">
      <div>
        <div class="brand-kicker">Detail Dokumen Publik</div>
        <h1 class="document-detail-title"><?= e($document['filename']) ?></h1>
        <p class="subtitle">Direktori: <?= e(directory_label($directory)) ?> | <strong>Tanggal publikasi: <?= e(format_publication_date($document['published_at'])) ?></strong> | Ukuran: <?= e(format_file_size($document['size'])) ?></p>
        <p class="detail-stats">Dilihat <?= $statistics['views'] ?> kali <span>|</span> Diunduh <?= $statistics['downloads'] ?> kali</p>
      </div>
    </div>
    <a class="button detail-back" href="<?= e(app_url()) ?>">Kembali ke Arsip</a>
  </header>

  <section class="card viewer-card">
    <?php if (in_array($extension, ['jpg', 'jpeg', 'png'], true)): ?>
      <img class="preview-image" src="<?= e($viewUrl) ?>" alt="<?= e($document['filename']) ?>">
    <?php elseif ($extension === 'pdf' || $extension === 'csv'): ?>
      <iframe class="preview-frame" src="<?= e($viewUrl) ?>" title="<?= e($document['filename']) ?>"></iframe>
    <?php elseif (in_array($extension, ['docx', 'xls', 'xlsx'], true) && $officeAvailable): ?>
      <iframe class="preview-frame" src="<?= e(office_viewer_url($document['path'])) ?>" title="Preview <?= e($document['filename']) ?>"></iframe>
    <?php else: ?>
      <p class="preview-message">Preview Office tersedia setelah aplikasi diakses dari domain publik HTTPS.</p>
    <?php endif; ?>
  </section>

  <div class="form-actions"><a class="button button-primary" href="<?= e($downloadUrl) ?>">Download</a><button class="button" type="button" id="copy-link">Copy link</button></div>
</main>
<script>
  const viewerCard = document.querySelector('.viewer-card');
  if (viewerCard) {
    viewerCard.classList.add('is-loading');
    const hideLoader = () => viewerCard.classList.remove('is-loading');
    const frame = viewerCard.querySelector('iframe');
    if (frame) frame.addEventListener('load', hideLoader);
    const image = viewerCard.querySelector('.preview-image');
    if (image) { image.addEventListener('load', hideLoader); image.addEventListener('error', hideLoader); }
    if (!frame && !image) hideLoader();
  }
  document.getElementById('copy-link').addEventListener('click', async (event) => {
    try {
      await navigator.clipboard.writeText(window.location.href);
      event.currentTarget.textContent = 'Tersalin';
      window.setTimeout(() => { event.currentTarget.textContent = 'Copy link'; }, 1600);
    } catch {
      window.prompt('Salin tautan berikut:', window.location.href);
    }
  });
</script>
<?php page_footer();
