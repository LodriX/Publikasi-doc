<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';
load_admin_session_if_present();
header('Cache-Control: private, no-store');

$document = find_accessible_document_by_slug((string) ($_GET['slug'] ?? ''));
if ($document === null) {
    http_response_code(404);
    page_header('Dokumen tidak ditemukan', 'fm-body', 'assets/public.css');
    ?>
<header class="fm-masthead">
  <div class="fm-masthead-inner">
    <div class="fm-brand-zone">
      <a href="<?= e(app_url()) ?>" class="brand-link">
        <?php if (is_file(APP_ROOT . '/' . app_logo())): ?><img class="brand-mark" src="<?= e(app_url(app_logo())) ?>" alt=""><?php endif; ?>
        <div class="brand-texts"><span class="brand-name"><?= e(app_brand()) ?></span></div>
      </a>
    </div>
    <a class="btn-cyber-outline" href="<?= e(app_url()) ?>">&larr; Kembali ke File Explorer</a>
  </div>
</header>
<main class="fm-workspace"><div class="fm-deck"><div class="fm-empty-state"><h3>Dokumen Tidak Ditemukan</h3><p>Dokumen tidak ditemukan atau belum dipublikasikan ke publik.</p><a class="btn-cyber" href="<?= e(app_url()) ?>">Kembali ke Beranda</a></div></div></main>
<?php page_footer(); exit;
}

$documentUrl = document_public_url($document);
$folderPath = str_replace('\\', '/', dirname($document['path']));
$lockedFolder = locked_folder_pin($folderPath === '.' ? '' : $folderPath);
if ($lockedFolder !== null) {
    require_pin_grant($lockedFolder['hash'], 'unlocked_folders', folder_pin_grant_key($lockedFolder['folder'], $lockedFolder['hash']), $documentUrl, 'folder');
}
$pinHash = document_pin_hash($document);
if ($pinHash !== null) {
    require_pin_grant($pinHash, 'unlocked_docs', document_pin_grant_key($document['path'], $pinHash), $documentUrl, 'dokumen');
}

$extension = (string) supported_extension($document['path']);
$category = file_category($extension);
$directory = str_replace('\\', '/', dirname($document['path']));
$directory = $directory === '.' ? '' : $directory;
$viewUrl = document_view_url($document['path']);
$downloadUrl = document_download_url($document['path']);
$officeAvailable = !is_local_request();
$statistics = document_stat($document['path']);
$visibility = document_access_level($document, $document['path']);
$ogTags = [
    'description' => 'Lihat dan unduh berkas resmi ' . $document['filename'] . ' dari ' . app_brand() . '.',
    'og:title' => $document['filename'] . ' - ' . app_brand(),
    'og:description' => 'Dipublikasikan pada ' . format_publication_date($document['published_at']) . '. Ukuran ' . format_file_size($document['size']) . '.',
    'og:type' => 'article',
    'og:url' => app_full_url('dokumen/' . document_slug($document)),
    'og:site_name' => app_brand(),
    'twitter:card' => 'summary',
    'twitter:title' => $document['filename'],
    'twitter:description' => 'Dokumen resmi publikasi ' . app_brand() . '.',
];
if ($visibility !== 'public') {
    $ogTags['robots'] = 'noindex, nofollow';
    header('X-Robots-Tag: noindex, nofollow');
}
if (is_file(APP_ROOT . '/' . app_logo())) {
    $ogTags['og:image'] = app_full_url(app_logo());
}
page_header($document['filename'], 'fm-body', 'assets/public.css', $ogTags);
?>
<header class="fm-masthead">
  <div class="fm-masthead-inner">
    <div class="fm-brand-zone">
      <a href="<?= e(app_url()) ?>" class="brand-link">
        <?php if (is_file(APP_ROOT . '/' . app_logo())): ?><img class="brand-mark" src="<?= e(app_url(app_logo())) ?>" alt=""><?php endif; ?>
        <div class="brand-texts">
          <span class="brand-name"><?= e(app_brand()) ?></span>
          <span class="system-status"><span class="status-dot"></span> DETAIL BERKAS</span>
        </div>
      </a>
    </div>
    <div class="fm-masthead-actions">
      <?php if (is_admin()): ?><a class="btn-cyber-outline" href="<?= e(app_url('admin.php')) ?>">Dashboard</a><?php endif; ?>
      <?php render_public_mode_switcher(); ?>
      <?php if (is_admin()): ?><span class="theme-admin-indicator">Tema: <?= e(match (app_theme_style()) { 'classic' => 'Classic Drive', 'standart' => 'Standart', default => 'Futuristic HUD' }) ?></span><?php endif; ?>
      <a class="btn-cyber-outline" href="<?= e(app_url()) ?>">&larr; Kembali ke File Explorer</a>
    </div>
  </div>
</header>

<main class="fm-workspace fm-detail-workspace">
  <div class="fm-deck">
    <div class="deck-toolbar">
      <div class="deck-breadcrumb">
        <a class="bc-root" href="<?= e(app_url()) ?>">Drive</a>
        <span class="bc-sep">/</span>
        <span class="bc-current"><?= e(directory_label($directory)) ?></span>
        <span class="bc-sep">/</span>
        <span class="bc-current mono"><?= e($document['filename']) ?></span>
      </div>
      <div class="detail-actions">
        <a class="btn-cyber" href="<?= e($downloadUrl) ?>">Unduh</a>
        <button class="btn-cyber-ghost" type="button" data-copy-link="<?= e(document_public_url($document)) ?>"><span class="copy-label" aria-live="polite">Salin Link</span></button>
      </div>
    </div>

    <div class="detail-hud-card">
      <div class="detail-header-zone">
        <?php render_format_icon($category, $extension); ?>
        <div>
          <h1 class="document-detail-title"><?= e($document['filename']) ?></h1>
          <span class="visibility-badge visibility-badge--<?= e($visibility) ?>"><?= e(visibility_label($visibility)) ?></span>
          <div class="detail-meta-pills">
            <span class="preview-meta-pill"><span class="meta-label">Direktori</span> <strong><?= e(directory_label($directory)) ?></strong></span>
            <span class="preview-meta-pill"><span class="meta-label">Tanggal Terbit</span> <strong><?= e(format_publication_date($document['published_at'])) ?></strong></span>
            <span class="preview-meta-pill"><span class="meta-label">Ukuran</span> <strong class="mono"><?= e(format_file_size($document['size'])) ?></strong></span>
            <span class="preview-meta-pill"><span class="meta-label">Dilihat</span> <strong class="mono"><?= $statistics['views'] ?>x</strong></span>
            <span class="preview-meta-pill"><span class="meta-label">Diunduh</span> <strong class="mono"><?= $statistics['downloads'] ?>x</strong></span>
          </div>
        </div>
      </div>

      <section class="viewer-card" id="detail-viewer-card">
        <?php if (in_array($extension, ['jpg', 'jpeg', 'png'], true)): ?>
          <img class="preview-image" src="<?= e($viewUrl) ?>" alt="<?= e($document['filename']) ?>">
        <?php elseif ($extension === 'pdf'): ?>
          <div class="pdf-viewer-deck">
            <div class="pdf-controls-bar">
              <button type="button" class="btn-pdf-nav" id="pdf-prev" disabled>&larr; Prev</button>
              <span class="pdf-page-indicator mono"><span id="pdf-page-num">1</span> / <span id="pdf-page-total">-</span></span>
              <button type="button" class="btn-pdf-nav" id="pdf-next" disabled>Next &rarr;</button>
            </div>
            <div class="pdf-canvas-wrap"><canvas id="pdf-canvas"></canvas></div>
          </div>
        <?php elseif ($extension === 'docx'): ?>
          <div class="docx-render-wrap" id="detail-docx-wrap"></div>
        <?php elseif (in_array($extension, ['xlsx', 'xls', 'csv'], true)): ?>
          <div id="detail-sheet-wrap"></div>
        <?php elseif ($officeAvailable): ?>
          <iframe class="preview-frame" src="<?= e(office_viewer_url($document['path'])) ?>" title="Preview <?= e($document['filename']) ?>" tabindex="-1"></iframe>
        <?php else: ?>
          <p class="preview-message">Pratinjau berkas ini memerlukan aplikasi terpasang atau domain publik HTTPS.</p>
        <?php endif; ?>
      </section>
      <?php if (in_array($extension, ['jpg', 'jpeg', 'png'], true)): ?>
        <p class="image-original-link"><a class="btn-cyber-outline" href="<?= e($viewUrl) ?>" target="_blank" rel="noopener">Buka Gambar Asli di Tab Baru &rarr;</a></p>
      <?php endif; ?>
    </div>
  </div>
</main>
<script src="<?= e(app_url('assets/js/pdf.min.js')) ?>"></script>
<script src="<?= e(app_url('assets/js/jszip.min.js')) ?>"></script>
<script src="<?= e(app_url('assets/js/docx-preview.min.js')) ?>"></script>
<script src="<?= e(app_url('assets/js/xlsx.mini.min.js')) ?>"></script>
<script src="<?= e(app_url('assets/js/share-link.js')) ?>"></script>
<script>
window.addEventListener('DOMContentLoaded', () => {
  const viewerCard = document.querySelector('.viewer-card');
  if (viewerCard) {
    viewerCard.classList.add('is-loading');
    const hideLoader = () => viewerCard.classList.remove('is-loading');

    const extension = <?= json_encode($extension) ?>;
    const viewUrl = <?= json_encode($viewUrl) ?>;

    if (extension === 'pdf' && window.pdfjsLib) {
      pdfjsLib.GlobalWorkerOptions.workerSrc = '<?= e(app_url('assets/js/pdf.worker.min.js')) ?>';
      pdfjsLib.getDocument({ url: viewUrl, rangeChunkSize: 65536, disableAutoFetch: true, disableStream: false }).promise.then(async (pdfDoc) => {
        const totalPages = pdfDoc.numPages;
        const totalEl = document.getElementById('pdf-page-total');
        if (totalEl) totalEl.textContent = totalPages;
        let currentPage = 1;
        const canvas = document.getElementById('pdf-canvas');
        const ctx = canvas?.getContext('2d');

        async function renderPage(num) {
          if (!canvas || !ctx) return;
          const numEl = document.getElementById('pdf-page-num');
          const prevBtn = document.getElementById('pdf-prev');
          const nextBtn = document.getElementById('pdf-next');
          if (numEl) numEl.textContent = num;
          if (prevBtn) prevBtn.disabled = (num <= 1);
          if (nextBtn) nextBtn.disabled = (num >= totalPages);

          const page = await pdfDoc.getPage(num);
          const viewport = page.getViewport({ scale: 1.5 });
          canvas.height = viewport.height;
          canvas.width = viewport.width;
          await page.render({ canvasContext: ctx, viewport }).promise;
          hideLoader();
        }

        const prevBtn = document.getElementById('pdf-prev');
        const nextBtn = document.getElementById('pdf-next');
        if (prevBtn) prevBtn.onclick = () => { if (currentPage > 1) renderPage(--currentPage); };
        if (nextBtn) nextBtn.onclick = () => { if (currentPage < totalPages) renderPage(++currentPage); };

        await renderPage(1);
      }).catch(err => {
        console.warn('PDF error:', err);
        hideLoader();
      });
    } else if (extension === 'docx' && window.docx) {
      fetch(viewUrl).then(r => r.blob()).then(blob => {
        const wrap = document.getElementById('detail-docx-wrap');
        return docx.renderAsync(blob, wrap, null, {
          inWrapper: true,
          ignoreWidth: false,
          breakPages: true,
          useBase64URL: true,
          renderHeaders: true,
          renderFooters: true
        });
      }).then(hideLoader).catch(err => {
        console.warn('DOCX preview error:', err);
        hideLoader();
      });
    } else if (['xlsx', 'xls', 'csv'].includes(extension) && window.XLSX) {
      fetch(viewUrl).then(r => r.arrayBuffer()).then(buf => {
        const wb = XLSX.read(buf, { type: 'array' });
        if (!wb.SheetNames || wb.SheetNames.length === 0) {
          hideLoader();
          return;
        }
        const firstSheet = wb.SheetNames[0];

        function getSheetInfo(sheetName) {
          const ws = wb.Sheets[sheetName];
          if (!ws || !ws['!ref']) return '0 baris';
          try {
            const range = XLSX.utils.decode_range(ws['!ref']);
            const rows = range.e.r - range.s.r + 1;
            const cols = range.e.c - range.s.c + 1;
            return `${rows} baris · ${cols} kolom`;
          } catch {
            return '';
          }
        }

        function getTableHtml(sheetName) {
          const ws = wb.Sheets[sheetName];
          if (!ws || !ws['!ref']) {
            return '<div class="sheet-empty"><p>Lembar kerja ini kosong.</p></div>';
          }
          return XLSX.utils.sheet_to_html(ws, { header: '', footer: '' });
        }

        const wrap = document.getElementById('detail-sheet-wrap');
        if (wrap) {
          wrap.innerHTML = `
            <div class="sheet-render-deck">
              <div class="sheet-toolbar">
                <div class="sheet-toolbar-meta">
                  <span class="sheet-badge-tag"><svg viewBox="0 0 16 16" fill="currentColor"><path d="M2 3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3zm1 0v3h3V3H3zm4 0v3h3V3H7zm4 0v3h2V3h-2zm2 4h-2v3h2V7zm-3 0H7v3h3V7zM6 7H3v3h3V7zm-3 4v2h3v-2H3zm4 0v2h3v-2H7zm4 0v2h2v-2h-2z"/></svg> SPREADSHEET</span>
                  <strong class="sheet-active-name" id="sheet-active-name">${firstSheet}</strong>
                  <span class="sheet-dim-pill mono" id="sheet-dim-pill">${getSheetInfo(firstSheet)}</span>
                </div>
                ${wb.SheetNames.length > 1 ? `
                  <div class="sheet-tabs-bar" role="tablist" aria-label="Lembar kerja">
                    ${wb.SheetNames.map((s, i) => `
                      <button type="button" class="sheet-tab-btn ${i === 0 ? 'is-active' : ''}" data-sheet-name="${s}" role="tab" aria-selected="${i === 0 ? 'true' : 'false'}">
                        <svg viewBox="0 0 16 16" fill="currentColor"><path d="M4 2a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H4zm0 1h8a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zm1 2v2h2V5H5zm3 0v2h3V5H8zm-3 3v2h2V8H5zm3 0v2h3V8H8z"/></svg>
                        <span>${s}</span>
                      </button>
                    `).join('')}
                  </div>
                ` : ''}
              </div>
              <div class="sheet-table-wrap" id="detail-sheet-table-wrap">${getTableHtml(firstSheet)}</div>
            </div>
          `;
          if (wb.SheetNames.length > 1) {
            wrap.querySelectorAll('.sheet-tab-btn').forEach(btn => {
              btn.onclick = () => {
                wrap.querySelectorAll('.sheet-tab-btn').forEach(b => {
                  b.classList.remove('is-active');
                  b.setAttribute('aria-selected', 'false');
                });
                btn.classList.add('is-active');
                btn.setAttribute('aria-selected', 'true');
                const sheetName = btn.dataset.sheetName;
                const activeNameEl = wrap.querySelector('#sheet-active-name');
                const dimPillEl = wrap.querySelector('#sheet-dim-pill');
                const tableWrapEl = wrap.querySelector('#detail-sheet-table-wrap');
                if (activeNameEl) activeNameEl.textContent = sheetName;
                if (dimPillEl) dimPillEl.textContent = getSheetInfo(sheetName);
                if (tableWrapEl) tableWrapEl.innerHTML = getTableHtml(sheetName);
              };
            });
          }
        }
        hideLoader();
      }).catch(hideLoader);
    } else {
      const frame = viewerCard.querySelector('iframe');
      if (frame) {
        frame.addEventListener('load', hideLoader);
        try { if (frame.contentDocument && frame.contentDocument.readyState === 'complete') hideLoader(); } catch {}
      }
      const image = viewerCard.querySelector('.preview-image');
      if (image) {
        image.addEventListener('load', hideLoader);
        image.addEventListener('error', hideLoader);
        if (image.complete) hideLoader();
      }
      if (!frame && !image) hideLoader();
    }
  }

});
</script>
<?php page_footer();
