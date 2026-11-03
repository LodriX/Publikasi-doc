<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

$documentTree = public_document_tree();
$officePreviewAvailable = !is_local_request();

function render_document_list(array $documents, string $directory, bool $officePreviewAvailable): void
{
    if (!$documents) {
        return;
    }
    ?>
    <div class="card">
      <?php foreach ($documents as $index => $document): ?>
        <?php $extension = supported_extension($document['path']); ?>
        <?php $statistics = document_stat($document['path']); ?>
        <?php $number = document_sort_number($document); ?>
        <article class="document<?= $number === null ? ' document-without-number' : '' ?>">
          <?php if ($number !== null): ?><div class="number"><?= $number ?></div><?php endif; ?>
          <div>
            <div class="title"><?= e($document['filename']) ?></div>
            <div class="date"><strong>Tanggal publikasi: <?= e(format_publication_date($document['published_at'])) ?></strong> | Ukuran: <?= e(format_file_size($document['size'])) ?></div>
            <div class="document-stats">Dilihat <?= $statistics['views'] ?> kali <span>|</span> Diunduh <?= $statistics['downloads'] ?> kali</div>
          </div>
          <div class="actions">
            <button class="button" type="button" data-preview-kind="<?= e((string) $extension) ?>" data-title="<?= e($document['filename']) ?>" data-directory="<?= e(directory_label($directory)) ?>" data-date="<?= e(format_publication_date($document['published_at'])) ?>" data-size="<?= e(format_file_size($document['size'])) ?>" data-view-url="<?= e(document_view_url($document['path'])) ?>" data-download-url="<?= e(document_download_url($document['path'])) ?>" data-document-url="<?= e(document_public_url($document)) ?>" data-office-url="<?= e(office_viewer_url($document['path'])) ?>" data-office-available="<?= $officePreviewAvailable ? '1' : '0' ?>" data-stat-path="<?= e($document['path']) ?>" data-views="<?= $statistics['views'] ?>" data-downloads="<?= $statistics['downloads'] ?>">Preview</button>
            <a class="button button-primary" href="<?= e(document_download_url($document['path'])) ?>">Download</a>
            <button class="button" type="button" data-copy-link="<?= e(document_public_url($document)) ?>">Copy link</button>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <?php
}

function render_folder_node(string|int $name, array $node, string $parentPath, bool $officePreviewAvailable): void
{
    $name = (string) $name;
    $path = $parentPath === '' ? $name : $parentPath . '/' . $name;
    ?>
    <details class="folder-node">
      <summary><span class="folder-name"><?= e((string) $name) ?></span><span class="folder-hint">Klik untuk melihat daftar</span><span class="folder-count"><?= document_tree_count($node) ?> dokumen</span></summary>
      <div class="folder-body">
        <?php render_document_list($node['documents'], $path, $officePreviewAvailable); ?>
        <?php foreach ($node['children'] as $childName => $childNode): ?>
          <?php render_folder_node($childName, $childNode, $path, $officePreviewAvailable); ?>
        <?php endforeach; ?>
      </div>
    </details>
    <?php
}

page_header('Transparansi dan Publikasi');
?>
<main class="shell">
  <header class="site-header site-header-public">
    <div>
      <div class="brand-kicker"><?= e(app_brand()) ?></div>
      <h1><?= e(app_headline()) ?></h1>
      <p class="subtitle"><?= e(app_subtitle()) ?></p>
    </div>
    <img class="site-logo" src="<?= e(app_url(app_logo())) ?>" alt="<?= e(app_brand()) ?>">
  </header>

  <div class="content-intro"><div><h2>Arsip Dokumen Publik</h2><p>Pilih folder untuk menelusuri dokumen yang tersedia.</p></div></div>

  <?php if (!$documentTree['documents'] && !$documentTree['children']): ?>
    <section class="card" aria-label="Daftar dokumen"><p class="empty">Belum ada dokumen yang dipublikasikan.</p></section>
  <?php else: ?>
    <?php if ($documentTree['documents']): ?>
      <section class="directory-group" aria-label="Dokumen Utama"><h2 class="directory-heading">Dokumen Utama</h2><?php render_document_list($documentTree['documents'], '', $officePreviewAvailable); ?></section>
    <?php endif; ?>
    <?php if ($documentTree['children']): ?>
      <section class="folder-tree" aria-label="Folder dokumen">
        <?php foreach ($documentTree['children'] as $folderName => $folderNode): ?>
          <?php render_folder_node($folderName, $folderNode, '', $officePreviewAvailable); ?>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
  <?php endif; ?>
</main>
<div class="preview-modal" id="preview-modal" hidden role="dialog" aria-modal="true" aria-labelledby="preview-title">
  <section class="preview-panel">
    <header class="preview-header"><div><h2 class="preview-title" id="preview-title"></h2><p class="preview-meta" id="preview-meta"></p></div><button class="button" type="button" data-close-preview>Tutup Preview</button></header>
    <div class="preview-content" id="preview-content"></div>
    <footer class="preview-footer"><span class="preview-help">Preview belum tampil sempurna? Buka dokumen di tab baru.</span><button class="button" type="button" data-copy-preview>Copy link</button><a class="button" id="preview-open" href="#" target="_blank" rel="noopener">Buka di tab baru</a><a class="button button-primary" id="preview-download" href="#">Download</a></footer>
  </section>
</div>
<script>
  const modal = document.getElementById('preview-modal');
  const previewContent = document.getElementById('preview-content');
  const previewTitle = document.getElementById('preview-title');
  const previewMeta = document.getElementById('preview-meta');
  const previewDownload = document.getElementById('preview-download');
  const previewOpen = document.getElementById('preview-open');
  let previewCopyLink = '';

  function copyLink(link, button) {
    navigator.clipboard.writeText(new URL(link, window.location.href).href).then(() => {
      const label = button.textContent;
      button.textContent = 'Tersalin';
      window.setTimeout(() => { button.textContent = label; }, 1600);
    }).catch(() => window.prompt('Salin tautan berikut:', new URL(link, window.location.href).href));
  }

  function trackDocumentView(path) {
    const body = new URLSearchParams({ file: path });
    fetch('<?= e(app_url('track.php')) ?>', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body, keepalive: true }).catch(() => {});
  }

  document.querySelectorAll('[data-copy-link]').forEach((button) => {
    button.addEventListener('click', () => copyLink(button.dataset.copyLink, button));
  });

  document.querySelectorAll('[data-preview-kind]').forEach((button) => {
    button.addEventListener('click', () => {
      const { previewKind, title, directory, date, size, viewUrl, downloadUrl, documentUrl, officeUrl, officeAvailable, statPath, views, downloads } = button.dataset;
      previewTitle.textContent = title;
      previewMeta.replaceChildren();
      previewMeta.append('Direktori: ', directory, ' | ');
      const publicationDate = document.createElement('strong');
      publicationDate.textContent = `Tanggal publikasi: ${date}`;
      previewMeta.append(publicationDate, ` | Ukuran: ${size} | Dilihat ${Number(views) + 1} kali | Diunduh ${downloads} kali`);
      trackDocumentView(statPath);
      previewDownload.href = downloadUrl;
      previewOpen.href = ['docx', 'xls', 'xlsx'].includes(previewKind) && officeAvailable === '1' ? officeUrl : viewUrl;
      previewCopyLink = documentUrl;
      previewContent.replaceChildren();
      const showLoader = () => {
        previewContent.classList.add('is-loading');
        const hideLoader = () => previewContent.classList.remove('is-loading');
        return hideLoader;
      };
      if (['jpg', 'jpeg', 'png'].includes(previewKind)) {
        const image = document.createElement('img');
        const hideLoader = showLoader();
        image.className = 'preview-image'; image.src = viewUrl; image.alt = title;
        image.addEventListener('load', hideLoader);
        image.addEventListener('error', hideLoader);
        previewContent.append(image);
      } else if (previewKind === 'pdf' || previewKind === 'csv') {
        const frame = document.createElement('iframe');
        const hideLoader = showLoader();
        frame.className = 'preview-frame'; frame.src = viewUrl; frame.title = title;
        frame.addEventListener('load', hideLoader);
        previewContent.append(frame);
      } else if (['docx', 'xls', 'xlsx'].includes(previewKind) && officeAvailable === '1') {
        const frame = document.createElement('iframe');
        const hideLoader = showLoader();
        frame.className = 'preview-frame'; frame.src = officeUrl; frame.title = `Preview ${title}`;
        frame.addEventListener('load', hideLoader);
        previewContent.append(frame);
      } else {
        const message = document.createElement('p');
        message.className = 'preview-message';
        message.textContent = 'Preview Office tersedia setelah aplikasi diakses dari domain publik HTTPS.';
        previewContent.append(message);
      }
      modal.hidden = false;
    });
  });

  document.querySelector('[data-close-preview]').addEventListener('click', () => { modal.hidden = true; previewContent.replaceChildren(); previewContent.classList.remove('is-loading'); });
  document.querySelector('[data-copy-preview]').addEventListener('click', (event) => copyLink(previewCopyLink, event.currentTarget));
  modal.addEventListener('click', (event) => { if (event.target === modal) event.target.querySelector('[data-close-preview]').click(); });
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !modal.hidden) modal.querySelector('[data-close-preview]').click(); });
</script>
<?php page_footer();
