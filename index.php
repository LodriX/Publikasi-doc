<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';
load_admin_session_if_present();
header('Cache-Control: private, no-store');

$sharedFolder = null;
$folderRules = load_folder_visibility();
if (array_key_exists('folder', $_GET)) {
    $requested = $_GET['folder'];
    $sharedFolder = is_string($requested) ? safe_relative_directory($requested) : null;
    if ($sharedFolder === null || $sharedFolder === '' || absolute_directory_path($sharedFolder) === null) {
        http_response_code(404);
        header('X-Robots-Tag: noindex, nofollow');
        page_header('Folder tidak ditemukan', 'fm-body', 'assets/public.css');
        ?><main class="fm-workspace pin-workspace"><div class="pin-card"><h1>Folder tidak ditemukan</h1><p>Folder tidak tersedia.</p><a class="btn-cyber-outline" href="<?= e(app_url()) ?>">Kembali ke beranda</a></div></main><?php
        page_footer();
        exit;
    }
    $lockedFolder = locked_folder_pin($sharedFolder, $folderRules);
    if ($lockedFolder !== null) {
        require_pin_grant($lockedFolder['hash'], 'unlocked_folders', folder_pin_grant_key($lockedFolder['folder'], $lockedFolder['hash']), folder_public_url($sharedFolder), 'folder');
    }
    $folderIsUnlisted = folder_is_unlisted($sharedFolder, $folderRules);
    if ($folderIsUnlisted) {
        header('X-Robots-Tag: noindex, nofollow');
    }
    $folderPrefix = $sharedFolder . '/';
    $allDocuments = array_values(array_filter(published_documents(), static function (array $document) use ($folderPrefix, $folderIsUnlisted, $folderRules): bool {
        $path = $document['path'];
        if (!str_starts_with($path, $folderPrefix) || document_visibility($document) === 'hidden') {
            return false;
        }
        if (!$folderIsUnlisted && document_access_level($document, $path, $folderRules) !== 'public') {
            return false;
        }
        $directory = str_replace('\\', '/', dirname($path));
        return locked_folder_pin($directory === '.' ? '' : $directory, $folderRules) === null
            && document_pin_is_unlocked($document, $path);
    }));
} else {
    $allDocuments = public_documents();
}
$documentTree = build_document_tree($allDocuments);
$officePreviewAvailable = !is_local_request();
$totalBytes = array_sum(array_column($allDocuments, 'size'));
$totalFolders = document_tree_folder_count($documentTree);

$years = [];
$types = [];
foreach ($allDocuments as $item) {
    $years[substr($item['published_at'], 0, 4)] = true;
    $types[strtoupper((string) supported_extension($item['path']))] = true;
}
krsort($years);
ksort($types);

function render_document_card(array $document, int $index, string $directory, bool $officePreviewAvailable): void
{
    $extension = (string) supported_extension($document['path']);
    $category = file_category($extension);
    $statistics = document_stat($document['path']);
    $number = document_sort_number($document);
    $extUpper = strtoupper($extension);
    ?>
    <article class="document fm-card"
      tabindex="0"
      data-search-text="<?= e($document['filename'] . ' ' . directory_label($directory)) ?>"
      data-year="<?= e(substr($document['published_at'], 0, 4)) ?>"
      data-extension="<?= e(strtolower($extension)) ?>"
      data-category="<?= e($category) ?>"
      data-visibility="<?= e(document_access_level($document, $document['path'])) ?>"
      data-published-at="<?= e($document['published_at']) ?>"
      data-name="<?= e($document['filename']) ?>"
      data-size-bytes="<?= $document['size'] ?>"
      data-size-formatted="<?= e(format_file_size($document['size'])) ?>"
      data-date-formatted="<?= e(format_publication_date($document['published_at'])) ?>"
      data-directory="<?= e(directory_label($directory)) ?>"
      data-default-order="<?= $index ?>"
      data-views="<?= $statistics['views'] ?>"
      data-downloads="<?= $statistics['downloads'] ?>"
      data-preview-kind="<?= e($extension) ?>"
      data-title="<?= e($document['filename']) ?>"
      data-view-url="<?= e(document_view_url($document['path'])) ?>"
      data-download-url="<?= e(document_download_url($document['path'])) ?>"
      data-document-url="<?= e(document_public_url($document)) ?>"
      data-office-url="<?= e(office_viewer_url($document['path'])) ?>"
      data-office-available="<?= $officePreviewAvailable ? '1' : '0' ?>"
      data-stat-path="<?= e($document['path']) ?>">
      <div class="card-glow" aria-hidden="true"></div>

      <div class="card-header">
        <?php render_format_icon($category, $extension); ?>
        <div class="card-telemetry">
          <span class="tele-item" title="Dilihat"><svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 3c-4 0-7 5-7 5s3 5 7 5 7-5 7-5-3-5-7-5zm0 8.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7zm0-5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3z"/></svg> <?= $statistics['views'] ?></span>
          <span class="tele-item" title="Diunduh"><svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 12l-4-4h2.5V2h3v6H12L8 12zm-6 2h12v1.5H2V14z"/></svg> <?= $statistics['downloads'] ?></span>
        </div>
      </div>

      <div class="card-body">
        <h3 class="document-title">
          <a href="<?= e(document_public_url($document)) ?>"><span class="document-link-text"><?= e($document['filename']) ?></span></a>
        </h3>
        <div class="card-meta">
          <span class="meta-size mono"><?= e(format_file_size($document['size'])) ?></span>
          <span class="meta-sep">·</span>
          <span class="meta-date"><?= e(format_publication_date($document['published_at'])) ?></span>
          <?php if ($directory !== ''): ?>
            <span class="meta-sep">·</span>
            <span class="meta-dir" title="Direktori"><?= e(directory_label($directory)) ?></span>
          <?php endif; ?>
        </div>
      </div>

      <div class="card-actions">
        <button class="btn-action btn-preview" type="button" data-preview-trigger aria-label="Pratinjau <?= e($document['filename']) ?>">
          <svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 3c-4 0-7 5-7 5s3 5 7 5 7-5 7-5-3-5-7-5zm0 8.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7z"/></svg>
          <span>Preview</span>
        </button>
        <a class="btn-action btn-download" href="<?= e(document_download_url($document['path'])) ?>" aria-label="Unduh <?= e($document['filename']) ?>">
          <svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 11l-3.5-3.5h2V2h3v5.5h2L8 11zm-5 2h10v1.5H3V13z"/></svg>
          <span>Unduh</span>
        </a>
        <button class="btn-action btn-copy" type="button" data-copy-link="<?= e(document_public_url($document)) ?>" title="Salin Tautan" aria-label="Salin tautan <?= e($document['filename']) ?>">
          <svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M4 2h7v2H4v8H2V4a2 2 0 0 1 2-2zm4 4h6a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2zm0 2v6h6V8H8z"/></svg>
          <span class="copy-label" aria-live="polite">Salin</span>
        </button>
      </div>
    </article>
    <?php
}

function render_document_list(array $documents, string $directory, bool $officePreviewAvailable): void
{
    if (!$documents) {
        return;
    }
    ?>
    <div class="fm-grid-register">
      <?php foreach ($documents as $index => $document): ?>
        <?php render_document_card($document, $index, $directory, $officePreviewAvailable); ?>
      <?php endforeach; ?>
    </div>
    <?php
}

function render_folder_node(string|int $name, array $node, string $parentPath, bool $officePreviewAvailable, ?array $folderRules = null): void
{
    $folderRules ??= load_folder_visibility();
    $name = (string) $name;
    $path = $parentPath === '' ? $name : $parentPath . '/' . $name;
    ?>
    <details class="folder-node" open>
      <summary class="folder-summary">
        <span class="file-icon-box folder-icon-box" aria-hidden="true">
          <svg viewBox="0 0 20 20" fill="currentColor"><path d="M2 4.5A2.5 2.5 0 0 1 4.5 2h3.172a2 2 0 0 1 1.414.586l1.828 1.828A2 2 0 0 0 12.328 5H15.5A2.5 2.5 0 0 1 18 7.5v8a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 2 15.5v-11z"/></svg>
        </span>
        <span class="folder-name"><?= e((string) $name) ?></span>
        <span class="folder-badge"><?= document_tree_count($node) ?></span>
        <button type="button" class="btn-folder-zip" data-copy-link="<?= e(folder_public_url($path)) ?>" aria-label="Salin tautan folder <?= e($path) ?>" title="Salin tautan folder"><span class="copy-label" aria-live="polite">Salin Link</span></button>
        <?php if (!folder_is_unlisted($path, $folderRules)): ?>
        <a class="btn-folder-zip" href="<?= e(app_url('download-folder.php?folder=' . rawurlencode($path))) ?>" title="Unduh folder <?= e((string) $name) ?> sebagai ZIP" download onclick="event.stopPropagation()">
          <svg viewBox="0 0 16 16" fill="currentColor"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/><path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/></svg>
          <span>ZIP</span>
        </a>
        <?php endif; ?>
        <span class="folder-toggle-caret" aria-hidden="true"></span>
      </summary>
      <div class="folder-body">
        <?php render_document_list($node['documents'], $path, $officePreviewAvailable); ?>
        <?php foreach ($node['children'] as $childName => $childNode): ?>
          <?php render_folder_node($childName, $childNode, $path, $officePreviewAvailable, $folderRules); ?>
        <?php endforeach; ?>
      </div>
    </details>
    <?php
}

page_header($sharedFolder !== null ? directory_label($sharedFolder) : app_headline(), 'fm-body', 'assets/public.css');
?>
<!-- Modern Futuristic File Manager Command Masthead -->
<header class="fm-masthead" id="site-header">
  <div class="fm-masthead-inner">
    <div class="fm-brand-zone">
      <a href="<?= e(app_url()) ?>" class="brand-link">
        <?php if (is_file(APP_ROOT . '/' . app_logo())): ?>
          <img class="brand-mark" src="<?= e(app_url(app_logo())) ?>" alt="">
        <?php endif; ?>
        <div class="brand-texts">
          <span class="brand-name"><?= e(app_brand()) ?></span>
          <span class="system-status"><span class="status-dot"></span> <?= e(app_subtitle()) ?></span>
        </div>
      </a>
    </div>

    <div class="fm-omnibar" role="search">
      <div class="search-input-wrap">
        <svg class="search-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor">
          <circle cx="8.5" cy="8.5" r="5.5" stroke-width="1.8"/>
          <path d="M12.5 12.5L17 17" stroke-width="1.8" stroke-linecap="round"/>
        </svg>
        <input id="document-search" type="search" placeholder="Cari dokumen atau direktori..." autocomplete="off">
        <span class="search-kbd-hint">Ctrl K</span>
      </div>
    </div>

    <div class="fm-masthead-actions">
      <?php if (is_admin()): ?><a class="btn-cyber-outline" href="<?= e(app_url('admin.php')) ?>">Dashboard</a><?php endif; ?>
      <div class="view-switch-deck" role="group" aria-label="Mode Tampilan">
        <button type="button" class="view-switch-btn is-active" id="view-grid-btn" data-view="grid" title="Tampilan Kartu / Grid">
          <svg viewBox="0 0 20 20" fill="currentColor">
            <path d="M3 4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4zm8 0a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1V4zM3 12a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-4zm8 0a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1v-4z"/>
          </svg>
          <span>Grid</span>
        </button>
        <button type="button" class="view-switch-btn" id="view-list-btn" data-view="list" title="Tampilan Baris / Tabel">
          <svg viewBox="0 0 20 20" fill="currentColor">
            <path d="M3 5a1 1 0 0 1 1-1h12a1 1 0 1 1 0 2H4a1 1 0 0 1-1-1zm0 5a1 1 0 0 1 1-1h12a1 1 0 1 1 0 2H4a1 1 0 0 1-1-1zm0 5a1 1 0 0 1 1-1h12a1 1 0 1 1 0 2H4a1 1 0 0 1-1-1z"/>
          </svg>
          <span>List</span>
        </button>
      </div>
      <?php render_public_mode_switcher(); ?>
      <?php if (is_admin()): ?><span class="theme-admin-indicator" title="Tema global: <?= e(app_theme_style()) ?>">Tema: <?= e(match (app_theme_style()) { 'classic' => 'Classic Drive', 'standart' => 'Standart', default => 'Futuristic HUD' }) ?></span><?php endif; ?>

    </div>
  </div>
</header>

<main class="fm-workspace fm-explorer-workspace" id="public-main">
  <!-- Left Navigator Sidebar -->
  <aside class="fm-sidebar" id="fm-sidebar" aria-label="Navigasi Direktori & Filter">
    <div class="theme-sidebar-heading"><span class="sidebar-brand-dot" aria-hidden="true"></span><strong><?= e(app_brand()) ?></strong><span>Dokumen</span></div>
    <!-- Drive System Stats -->
    <div class="sidebar-block telemetry-card">
      <div class="block-title">Status Drive</div>
      <div class="telemetry-stats">
        <div class="stat-cell">
          <span class="stat-value mono"><?= count($allDocuments) ?></span>
          <span class="stat-label">Dokumen</span>
        </div>
        <div class="stat-cell">
          <span class="stat-value mono"><?= e(format_file_size($totalBytes)) ?></span>
          <span class="stat-label">Kapasitas</span>
        </div>
        <div class="stat-cell">
          <span class="stat-value mono"><?= $totalFolders ?></span>
          <span class="stat-label">Folder</span>
        </div>
      </div>
    </div>

    <!-- Category Filters -->
    <div class="sidebar-block">
      <div class="block-title">Kategori Berkas</div>
      <div class="category-chips" role="tablist">
        <button type="button" class="cat-chip is-active" data-cat="">
          <span class="cat-dot"></span>
          <span>Semua Berkas</span>
          <span class="cat-count mono"><?= count($allDocuments) ?></span>
        </button>
        <button type="button" class="cat-chip" data-cat="pdf">
          <span class="cat-dot type-pdf"></span>
          <span>Dokumen PDF</span>
        </button>
        <button type="button" class="cat-chip" data-cat="docx">
          <span class="cat-dot type-docx"></span>
          <span>Berkas Word</span>
        </button>
        <button type="button" class="cat-chip" data-cat="xlsx">
          <span class="cat-dot type-xlsx"></span>
          <span>Spreadsheet</span>
        </button>
        <button type="button" class="cat-chip" data-cat="image">
          <span class="cat-dot type-image"></span>
          <span>Gambar / Foto</span>
        </button>
        <button type="button" class="cat-chip" data-cat="csv">
          <span class="cat-dot type-csv"></span>
          <span>Data CSV</span>
        </button>
      </div>
    </div>

    <!-- Year Filter -->
    <?php if ($years): ?>
    <div class="sidebar-block">
      <div class="block-title">Tahun Rilis</div>
      <div class="year-select-wrap">
        <select id="document-year" class="fm-select" aria-label="Pilih tahun">
          <option value="">Semua Tahun Rilis</option>
          <?php foreach ($years as $year => $_): ?>
            <option value="<?= e((string) $year) ?>"><?= e((string) $year) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <?php endif; ?>

    <!-- Hidden native inputs for JS filter sync -->
    <select id="document-type" hidden><option value="">Semua jenis</option><?php foreach ($types as $type => $_): ?><option value="<?= e(strtolower((string) $type)) ?>"><?= e((string) $type) ?></option><?php endforeach; ?></select>
  </aside>

  <!-- Center Explorer Deck -->
  <section class="fm-deck" id="fm-deck">
    <?php if ($sharedFolder !== null): ?>
    <div class="folder-share-heading">
      <div><a href="<?= e(app_url()) ?>">&larr; Semua direktori</a><h1><?= e(directory_label($sharedFolder)) ?></h1><span class="visibility-badge visibility-badge--<?= $folderIsUnlisted ? 'unlisted' : 'public' ?>"><?= e(visibility_label($folderIsUnlisted ? 'unlisted' : 'public')) ?></span></div>
      <button type="button" class="btn-cyber-outline" data-copy-link="<?= e(folder_public_url($sharedFolder)) ?>" aria-label="Salin tautan folder <?= e($sharedFolder) ?>"><span class="copy-label" aria-live="polite">Salin Link Folder</span></button>
    </div>
    <?php endif; ?>
    <!-- Breadcrumb & Control Deck -->
    <div class="deck-toolbar">
      <div class="deck-breadcrumb">
        <span class="bc-root">Drive</span>
        <span class="bc-sep">/</span>
        <span class="bc-current" id="breadcrumb-current"><?= e($sharedFolder === null ? 'Semua Direktori' : directory_label($sharedFolder)) ?></span>
        <span class="deck-counter mono" id="filter-status"><?= count($allDocuments) ?> berkas</span>
      </div>

      <div class="deck-sort-zone">
        <label for="document-sort" class="sort-label">Urutan:</label>
        <select id="document-sort" class="fm-select-sort">
          <option value="default">Default Arsip</option>
          <option value="newest">Terbaru per folder</option>
          <option value="oldest">Terlama per folder</option>
          <option value="name">Nama A–Z</option>
          <option value="size-desc">Ukuran Terbesar</option>
          <option value="size-asc">Ukuran Terkecil</option>
        </select>
        <button type="button" class="btn-reset-filter" id="filter-reset" hidden>Reset Filter</button>
      </div>
    </div>

    <!-- Empty State Notification -->
    <div class="fm-empty-state" id="filter-empty" hidden>
      <div class="empty-icon-box">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <circle cx="11" cy="11" r="8"/>
          <line x1="21" y1="21" x2="16.65" y2="16.65"/>
          <line x1="8" y1="11" x2="14" y2="11"/>
        </svg>
      </div>
      <h3>Berkas tidak ditemukan</h3>
      <p>Tidak ada dokumen yang cocok dengan kriteria pencarian atau filter yang dipilih.</p>
      <button type="button" class="btn-cyber" id="btn-empty-reset">Kosongkan Pencarian</button>
    </div>

    <!-- Document File Explorer Zone (default: grid mode) -->
    <div class="fm-explorer-content view-grid" id="fm-explorer">
      <?php if (!$documentTree['documents'] && !$documentTree['children']): ?>
        <div class="fm-empty-state">
          <div class="empty-icon-box">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
              <polyline points="14 2 14 8 20 8"/>
            </svg>
          </div>
          <h3>Arsip Masih Kosong</h3>
          <p>Belum ada dokumen yang dipublikasikan ke dalam sistem portal ini.</p>
        </div>
      <?php else: ?>
        <?php if ($documentTree['documents']): ?>
          <section class="directory-group" aria-label="Dokumen Utama">
            <div class="directory-header-bar">
              <span class="dir-icon"><svg viewBox="0 0 16 16" fill="currentColor"><path d="M1 3.5A1.5 1.5 0 0 1 2.5 2h3.172a1 1 0 0 1 .707.293L7.793 3.7A1 1 0 0 0 8.5 4H13.5A1.5 1.5 0 0 1 15 5.5v8A1.5 1.5 0 0 1 13.5 15h-11A1.5 1.5 0 0 1 1 13.5v-10z"/></svg></span>
              <h2 class="directory-heading">Dokumen Utama</h2>
            </div>
            <?php render_document_list($documentTree['documents'], '', $officePreviewAvailable); ?>
          </section>
        <?php endif; ?>

        <?php if ($documentTree['children']): ?>
          <section class="folder-tree" aria-label="Folder Dokumen">
            <?php foreach ($documentTree['children'] as $folderName => $folderNode): ?>
              <?php render_folder_node($folderName, $folderNode, '', $officePreviewAvailable, $folderRules); ?>
            <?php endforeach; ?>
          </section>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>

  <button type="button" class="inspector-backdrop" id="inspector-backdrop" aria-label="Tutup Inspektor Berkas" tabindex="-1" hidden></button>
  <!-- Right Inspector HUD (Detail Berkas Cepat) -->
  <aside class="fm-inspector" id="fm-inspector" aria-label="Detail Dokumen Cepat">
    <div class="inspector-header">
      <span class="inspector-badge">INSPEKTOR BERKAS</span>
      <button type="button" class="inspector-close" id="inspector-close" aria-label="Tutup Panel">&times;</button>
    </div>

    <div class="inspector-empty" id="inspector-empty">
      <div class="inspector-idle-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
          <rect x="4" y="4" width="16" height="16" rx="2"/>
          <line x1="9" y1="9" x2="15" y2="9"/>
          <line x1="9" y1="13" x2="15" y2="13"/>
          <line x1="9" y1="17" x2="12" y2="17"/>
        </svg>
      </div>
      <p>Pilih salah satu berkas untuk melihat detail informasi dan pratinjau cepat.</p>
    </div>

    <div class="inspector-content" id="inspector-content" hidden>
      <div class="inspector-preview-box">
        <span class="inspector-ext-pill" id="insp-ext">FILE</span>
        <svg class="inspector-preview-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
          <polyline points="14 2 14 8 20 8"/>
        </svg>
      </div>

      <h4 class="inspector-filename" id="insp-title">-</h4>
      <span class="visibility-badge visibility-badge--public" id="insp-visibility">Publik</span>
      <div class="inspector-dir mono" id="insp-dir">-</div>

      <dl class="inspector-meta-list">
        <div>
          <dt>Ukuran Berkas</dt>
          <dd class="mono" id="insp-size">-</dd>
        </div>
        <div>
          <dt>Tanggal Terbit</dt>
          <dd id="insp-date">-</dd>
        </div>
        <div>
          <dt>Total Dilihat</dt>
          <dd class="mono" id="insp-views">-</dd>
        </div>
        <div>
          <dt>Total Diunduh</dt>
          <dd class="mono" id="insp-downloads">-</dd>
        </div>
      </dl>

      <div class="inspector-actions">
        <button type="button" class="btn-cyber btn-block" id="insp-btn-preview">Pratinjau Layar Penuh</button>
        <a href="#" class="btn-cyber-outline btn-block" id="insp-btn-open" target="_blank" rel="noopener">Buka Dokumen di Tab Baru</a>
        <a href="#" class="btn-cyber-outline btn-block" id="insp-btn-download">Unduh Langsung</a>
        <button type="button" class="btn-cyber-ghost btn-block" id="insp-btn-copy" data-copy-link=""><span class="copy-label" aria-live="polite">Salin Tautan Bersih</span></button>
      </div>
    </div>
  </aside>
</main>

<!-- Futuristic Preview Modal -->
<div class="preview-modal" id="preview-modal" hidden role="dialog" aria-modal="true" aria-labelledby="preview-title">
  <div class="preview-backdrop" data-close-preview></div>
  <section class="preview-panel" id="preview-panel">
    <header class="preview-header">
      <div class="preview-heading">
        <div class="preview-hud-status"><span class="status-dot"></span> PRATINJAU DOKUMEN</div>
        <h2 class="preview-title" id="preview-title">Pratinjau Dokumen</h2>
        <div class="preview-meta" id="preview-meta"></div>
      </div>
      <div class="preview-header-controls">
        <div class="viewer-tools" role="toolbar" aria-label="Kontrol Pratinjau">
          <button type="button" class="btn-viewer-tool" id="btn-zoom-out" title="Perkecil (-)">
            <svg viewBox="0 0 16 16" fill="currentColor"><path d="M4 8a.5.5 0 0 1 .5-.5h7a.5.5 0 0 1 0 1h-7A.5.5 0 0 1 4 8z"/><path d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1zM0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8z"/></svg>
          </button>
          <button type="button" class="btn-viewer-tool mono" id="btn-zoom-reset" title="Reset Zoom (100%)">100%</button>
          <button type="button" class="btn-viewer-tool" id="btn-zoom-in" title="Perbesar (+)">
            <svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4z"/><path d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1zM0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8z"/></svg>
          </button>
          <button type="button" class="btn-viewer-tool" id="btn-print-doc" title="Cetak Berkas (Print)">
            <svg viewBox="0 0 16 16" fill="currentColor"><path d="M2.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1z"/><path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2H5zm1 2a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2H6V3zm7 8v2a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-2h10z"/></svg>
          </button>
          <button type="button" class="btn-viewer-tool" id="btn-fullscreen-modal" title="Layar Penuh (Fullscreen)">
            <svg viewBox="0 0 16 16" fill="currentColor"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 0 1 0V2h3.5a.5.5 0 0 0 0-1h-4zm13 0h-4a.5.5 0 0 0 0 1H14v3.5a.5.5 0 0 0 1 0v-4a.5.5 0 0 0-.5-.5zm0 14a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 0-1 0V14h-3.5a.5.5 0 0 0 0 1h4zm-13 0h4a.5.5 0 0 0 0-1H2v-3.5a.5.5 0 0 0-1 0v4a.5.5 0 0 0 .5.5z"/></svg>
          </button>
          <button type="button" class="btn-viewer-tool" id="btn-qr-preview" title="QR Code Berkas">
            <svg viewBox="0 0 16 16" fill="currentColor"><path d="M2 2h5v5H2V2zm1 1v3h3V3H3zm6-1h5v5H9V2zm1 1v3h3V3h-3zM2 9h5v5H2V9zm1 1v3h3v-3H3zm6 0h2v1H9v-1zm3 0h2v2h-2v-2zm-3 2h1v2H9v-2zm2 1h1v2h-1v-2zm2 1h1v1h-1v-1zm-4 1h1v1H9v-1zm2 0h2v1h-2v-1z"/></svg>
          </button>
        </div>
        <button class="btn-close-modal" type="button" data-close-preview aria-label="Tutup Preview">&times;</button>
      </div>
    </header>
    <div class="qr-overlay" id="qr-overlay" hidden>
      <div class="qr-box">
        <div class="qr-header">
          <strong>Pindai QR Code</strong>
          <button type="button" class="qr-close" id="qr-close" aria-label="Tutup QR Code">&times;</button>
        </div>
        <div class="qr-graphic" id="qr-graphic"></div>
        <p class="qr-tip">Pindai dengan kamera ponsel untuk membuka atau mengunduh langsung.</p>
      </div>
    </div>
    <div class="preview-content-viewport" id="preview-content-viewport">
      <div class="preview-content" id="preview-content"></div>
    </div>
    <footer class="preview-footer">
      <span class="preview-help">Ukuran belum optimal? Buka berkas langsung di tab baru.</span>
      <div class="preview-footer-btns">
        <button class="btn-cyber-ghost" type="button" id="preview-copy" data-copy-link=""><span class="copy-label" aria-live="polite">Salin Link</span></button>
        <a class="btn-cyber-outline" id="preview-open" href="#" target="_blank" rel="noopener">Buka di Tab Baru</a>
        <a class="btn-cyber" id="preview-download" href="#">Unduh Berkas</a>
      </div>
    </footer>
  </section>
</div>

<script src="<?= e(app_url('assets/js/qrcode.min.js')) ?>"></script>
<script src="<?= e(app_url('assets/js/pdf.min.js')) ?>"></script>
<script src="<?= e(app_url('assets/js/jszip.min.js')) ?>"></script>
<script src="<?= e(app_url('assets/js/docx-preview.min.js')) ?>"></script>
<script src="<?= e(app_url('assets/js/xlsx.mini.min.js')) ?>"></script>
<script src="<?= e(app_url('assets/js/share-link.js')) ?>"></script>
<script>
  // DOM References
  const modal = document.getElementById('preview-modal');
  const previewContent = document.getElementById('preview-content');
  const previewTitle = document.getElementById('preview-title');
  const previewMeta = document.getElementById('preview-meta');
  const previewDownload = document.getElementById('preview-download');
  const previewOpen = document.getElementById('preview-open');
  let previewCopyLink = '';
  let previewTrigger = null;
  let previousOverflow = '';

  const explorer = document.getElementById('fm-explorer');
  const viewGridBtn = document.getElementById('view-grid-btn');
  const viewListBtn = document.getElementById('view-list-btn');

  // View Switcher (Grid vs List)
  const setViewMode = (mode) => {
    if (mode === 'list') {
      explorer.classList.remove('view-grid');
      explorer.classList.add('view-list');
      viewListBtn.classList.add('is-active');
      viewGridBtn.classList.remove('is-active');
      localStorage.setItem('fm_view_mode', 'list');
    } else {
      explorer.classList.remove('view-list');
      explorer.classList.add('view-grid');
      viewGridBtn.classList.add('is-active');
      viewListBtn.classList.remove('is-active');
      localStorage.setItem('fm_view_mode', 'grid');
    }
  };

  const savedMode = localStorage.getItem('fm_view_mode');
  setViewMode(savedMode === 'list' ? 'list' : 'grid');
  viewGridBtn.addEventListener('click', () => setViewMode('grid'));
  viewListBtn.addEventListener('click', () => setViewMode('list'));

  // Inspector Panel Elements
  const inspectorEmpty = document.getElementById('inspector-empty');
  const inspectorContent = document.getElementById('inspector-content');
  const inspExt = document.getElementById('insp-ext');
  const inspTitle = document.getElementById('insp-title');
  const inspVisibility = document.getElementById('insp-visibility');
  const inspDir = document.getElementById('insp-dir');
  const inspSize = document.getElementById('insp-size');
  const inspDate = document.getElementById('insp-date');
  const inspViews = document.getElementById('insp-views');
  const inspDownloads = document.getElementById('insp-downloads');
  const inspBtnPreview = document.getElementById('insp-btn-preview');
  const inspBtnOpen = document.getElementById('insp-btn-open');
  const inspBtnDownload = document.getElementById('insp-btn-download');
  const inspBtnCopy = document.getElementById('insp-btn-copy');
  const previewCopy = document.getElementById('preview-copy');
  const inspectorClose = document.getElementById('inspector-close');
  const inspector = document.getElementById('fm-inspector');
  const inspectorBackdrop = document.getElementById('inspector-backdrop');
  let selectedDocCard = null;
  let previousInspectorOverflow = '';

  const closeInspector = () => {
    const returnFocus = selectedDocCard;
    document.querySelectorAll('.document.is-selected').forEach(el => el.classList.remove('is-selected'));
    updateInspector(null);
    returnFocus?.focus({ preventScroll: true });
  };

  const updateInspector = (card) => {
    if (!card) {
      inspectorEmpty.hidden = false;
      inspectorContent.hidden = true;
      selectedDocCard = null;
      inspector.classList.remove('is-open');
      inspectorBackdrop.hidden = true;
      document.body.style.overflow = previousInspectorOverflow;
      document.body.classList.remove('inspector-drawer-open');
      return;
    }
    const d = card.dataset;
    inspExt.textContent = (d.extension || 'FILE').toUpperCase();
    inspExt.className = `inspector-ext-pill type-${d.category || 'file'}`;
    inspTitle.textContent = d.title || '';
    inspVisibility.textContent = d.visibility === 'unlisted' ? 'Akses via Tautan' : 'Publik';
    inspVisibility.className = 'visibility-badge visibility-badge--' + (d.visibility === 'unlisted' ? 'unlisted' : 'public');
    inspDir.textContent = d.directory ? `Direktori: ${d.directory}` : 'Direktori: Dokumen Utama';
    inspSize.textContent = d.sizeFormatted || '-';
    inspDate.textContent = d.dateFormatted || '-';
    inspViews.textContent = `${d.views || 0} kali`;
    inspDownloads.textContent = `${d.downloads || 0} kali`;
    inspBtnDownload.href = d.downloadUrl || '#';
    inspBtnOpen.href = d.documentUrl || '#';
    inspBtnCopy.dataset.copyLink = d.documentUrl || '';
    inspBtnPreview.onclick = () => card.querySelector('[data-preview-trigger]')?.click();

    inspectorEmpty.hidden = true;
    inspectorContent.hidden = false;
    if (window.matchMedia('(max-width: 1200px)').matches) {
      if (!inspector.classList.contains('is-open')) {
        previousInspectorOverflow = document.body.style.overflow;
      }
      inspector.classList.add('is-open');
      inspectorBackdrop.hidden = false;
      document.body.classList.add('inspector-drawer-open');
      inspectorClose.focus({ preventScroll: true });
    }
  };

  inspectorClose.addEventListener('click', () => {
    closeInspector();
  });
  inspectorBackdrop.addEventListener('click', () => {
    closeInspector();
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && inspector.classList.contains('is-open')) {
      closeInspector();
    }
  });

  // Filter & Search Logic
  const search = document.getElementById('document-search');
  const yearSelect = document.getElementById('document-year');
  const sortSelect = document.getElementById('document-sort');
  const resetBtn = document.getElementById('filter-reset');
  const emptyResetBtn = document.getElementById('btn-empty-reset');
  const catChips = document.querySelectorAll('.cat-chip');
  let activeCategory = '';

  const folders = [...document.querySelectorAll('.folder-node')];
  const documents = [...document.querySelectorAll('.document')];
  let openBeforeFilter = null;

  function highlightMatch(text, query) {
    if (!query) return text;
    const escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return text.replace(new RegExp(`(${escaped})`, 'gi'), '<mark class="search-match">$1</mark>');
  }

  const applyFilters = () => {
    const query = search ? search.value.trim().toLocaleLowerCase('id') : '';
    const year = yearSelect ? yearSelect.value : '';
    const filtering = !!(query || year || activeCategory);

    if (filtering && openBeforeFilter === null) {
      openBeforeFilter = new Set(folders.filter(f => f.open));
    }

    let visible = 0;
    for (const doc of documents) {
      const matchQuery = !query || doc.dataset.searchText.toLocaleLowerCase('id').includes(query);
      const matchYear = !year || doc.dataset.year === year;
      const matchCat = !activeCategory || doc.dataset.category === activeCategory;
      const show = matchQuery && matchYear && matchCat;
      doc.hidden = !show;
      if (show) {
        visible++;
        const titleSpan = doc.querySelector('.document-link-text');
        if (titleSpan) {
          titleSpan.innerHTML = query ? highlightMatch(doc.dataset.name, query) : doc.dataset.name;
        }
      }
    }

    // Sort documents within registers
    for (const register of document.querySelectorAll('.fm-grid-register')) {
      const rows = [...register.querySelectorAll(':scope > .document')];
      rows.sort((a, b) => {
        if (sortSelect.value === 'default') return Number(a.dataset.defaultOrder) - Number(b.dataset.defaultOrder);
        if (sortSelect.value === 'name') return a.dataset.name.localeCompare(b.dataset.name, 'id', { numeric: true });
        if (sortSelect.value === 'size-desc') return Number(b.dataset.sizeBytes) - Number(a.dataset.sizeBytes);
        if (sortSelect.value === 'size-asc') return Number(a.dataset.sizeBytes) - Number(b.dataset.sizeBytes);
        const diff = a.dataset.publishedAt.localeCompare(b.dataset.publishedAt);
        return (sortSelect.value === 'newest' ? -diff : diff) || Number(a.dataset.defaultOrder) - Number(b.dataset.defaultOrder);
      });
      register.append(...rows);
    }

    // Folder counts & auto-open
    for (const folder of folders) {
      const count = folder.querySelectorAll('.document:not([hidden])').length;
      folder.hidden = count === 0;
      const badge = folder.querySelector(':scope > summary .folder-badge');
      if (badge) badge.textContent = count;
      if (filtering) folder.open = count > 0;
    }

    if (!filtering && openBeforeFilter !== null) {
      for (const folder of folders) folder.open = openBeforeFilter.has(folder);
      openBeforeFilter = null;
    }

    const rootGroup = document.querySelector('.directory-group');
    if (rootGroup) rootGroup.hidden = !rootGroup.querySelector('.document:not([hidden])');
    const folderTree = document.querySelector('.folder-tree');
    if (folderTree) folderTree.hidden = !folderTree.querySelector('.folder-node:not([hidden])');

    document.getElementById('filter-status').textContent = `${visible} berkas`;
    document.getElementById('filter-empty').hidden = visible !== 0;
    resetBtn.hidden = !filtering && sortSelect.value === 'default';

    // If currently selected doc became hidden, clear inspector
    if (selectedDocCard && selectedDocCard.hidden) {
      selectedDocCard.classList.remove('is-selected');
      updateInspector(null);
    }
  };

  if (search) search.addEventListener('input', applyFilters);
  if (yearSelect) yearSelect.addEventListener('change', applyFilters);
  if (sortSelect) sortSelect.addEventListener('change', applyFilters);

  catChips.forEach(chip => {
    chip.addEventListener('click', () => {
      catChips.forEach(c => c.classList.remove('is-active'));
      chip.classList.add('is-active');
      activeCategory = chip.dataset.cat || '';
      applyFilters();
    });
  });

  const resetAll = () => {
    if (search) search.value = '';
    if (yearSelect) yearSelect.value = '';
    if (sortSelect) sortSelect.value = 'default';
    activeCategory = '';
    catChips.forEach((c, idx) => c.classList.toggle('is-active', idx === 0));
    applyFilters();
    search?.focus();
  };

  resetBtn?.addEventListener('click', resetAll);
  emptyResetBtn?.addEventListener('click', resetAll);

  // Keyboard shortcut Ctrl+K to focus search
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
      e.preventDefault();
      search?.focus();
      search?.select();
    }
  });

  // Card Selection & Inspector
  const selectDocumentCard = card => {
    documents.forEach(c => c.classList.toggle('is-selected', c === card));
    selectedDocCard = card;
    updateInspector(card);
  };

  documents.forEach(card => {
    card.addEventListener('click', (e) => {
      const action = e.target.closest('button, a');
      if (action) {
        const titleLink = action.matches('.document-title a');
        const modifiedClick = e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0;
        if (!titleLink || modifiedClick) return;
        e.preventDefault();
      }
      selectDocumentCard(card);
    });
    card.addEventListener('keydown', e => {
      if (e.target !== card || e.key !== 'Enter') return;
      e.preventDefault();
      selectDocumentCard(card);
    });
  });

  function trackDocumentView(path) {
    const body = new URLSearchParams({ file: path });
    fetch('<?= e(app_url('track.php')) ?>', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
      keepalive: true
    }).catch(() => {});
  }

  // Preview Modal Trigger
  document.querySelectorAll('[data-preview-trigger]').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const card = btn.closest('.document');
      if (!card) return;
      const { previewKind, title, directory, dateFormatted, sizeFormatted, viewUrl, downloadUrl, documentUrl, officeUrl, officeAvailable, statPath, views, downloads } = card.dataset;

      previewTitle.textContent = title;
      previewMeta.replaceChildren();

      const items = [
        ['Direktori', directory || 'Dokumen Utama'],
        ['Tanggal Rilis', dateFormatted],
        ['Ukuran', sizeFormatted],
        ['Dilihat', `${Number(views) + 1}x`],
        ['Diunduh', `${downloads}x`],
      ];

      for (const [lbl, val] of items) {
        const span = document.createElement('span');
        span.className = 'preview-meta-pill';
        span.innerHTML = `<span class="meta-label">${lbl}</span> <strong class="mono">${val}</strong>`;
        previewMeta.append(span);
      }

      trackDocumentView(statPath);
      previewDownload.href = downloadUrl;
      previewOpen.href = documentUrl;
      previewCopyLink = new URL(documentUrl, window.location.href).href;
      previewCopy.dataset.copyLink = documentUrl;
      previewContent.replaceChildren();

      const showLoader = () => {
        previewContent.classList.add('is-loading');
        return () => previewContent.classList.remove('is-loading');
      };

      if (['jpg', 'jpeg', 'png'].includes(previewKind)) {
        const img = document.createElement('img');
        const hide = showLoader();
        img.className = 'preview-image';
        img.src = viewUrl;
        img.alt = title;
        img.onload = hide;
        img.onerror = hide;
        previewContent.append(img);
        if (img.complete) hide();
      } else if (previewKind === 'pdf') {
        const hide = showLoader();
        if (window.pdfjsLib) {
          renderFastPdf(viewUrl, previewContent, hide);
        } else {
          const frame = document.createElement('iframe');
          frame.className = 'preview-frame';
          frame.src = viewUrl;
          frame.title = title;
          frame.onload = hide;
          previewContent.append(frame);
        }
      } else if (previewKind === 'docx') {
        const hide = showLoader();
        if (window.docx) {
          renderDocx(viewUrl, previewContent, hide);
        } else if (officeAvailable === '1') {
          const frame = document.createElement('iframe');
          frame.className = 'preview-frame';
          frame.src = officeUrl;
          frame.title = `Preview ${title}`;
          frame.onload = hide;
          previewContent.append(frame);
        } else {
          hide();
          previewContent.innerHTML = '<p class="preview-message">Pratinjau Office memerlukan domain publik HTTPS atau unduh berkas langsung.</p>';
        }
      } else if (['xlsx', 'xls', 'csv'].includes(previewKind)) {
        const hide = showLoader();
        if (window.XLSX) {
          renderSpreadsheet(viewUrl, previewContent, hide);
        } else if (officeAvailable === '1') {
          const frame = document.createElement('iframe');
          frame.className = 'preview-frame';
          frame.src = officeUrl;
          frame.title = `Preview ${title}`;
          frame.onload = hide;
          previewContent.append(frame);
        } else {
          hide();
          previewContent.innerHTML = '<p class="preview-message">Pratinjau spreadsheet memerlukan domain publik HTTPS atau unduh berkas langsung.</p>';
        }
      } else {
        const p = document.createElement('p');
        p.className = 'preview-message';
        p.textContent = 'Pratinjau berkas ini belum didukung secara langsung. Silakan unduh dokumen untuk membukanya.';
        previewContent.append(p);
      }

      previewTrigger = btn;
      previousOverflow = document.body.style.overflow;
      applyZoom(1.0);
      if (qrOverlay) qrOverlay.hidden = true;
      modal.hidden = false;
      document.getElementById('site-header').inert = true;
      document.getElementById('public-main').inert = true;
      document.body.style.overflow = 'hidden';
      modal.querySelector('[data-close-preview]').focus();
    });
  });

  // Close modal
  document.querySelectorAll('[data-close-preview]').forEach(btn => {
    btn.addEventListener('click', () => {
      modal.hidden = true;
      applyZoom(1.0);
      if (qrOverlay) qrOverlay.hidden = true;
      previewContent.replaceChildren();
      previewContent.classList.remove('is-loading');
      document.getElementById('site-header').inert = false;
      document.getElementById('public-main').inert = false;
      document.body.style.overflow = previousOverflow;
      previewTrigger?.focus();
    });
  });

  // Spacebar QuickLook
  document.addEventListener('keydown', (e) => {
    if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT')) {
      return;
    }
    if (e.code === 'Space') {
      e.preventDefault();
      if (!modal.hidden) {
        modal.querySelector('[data-close-preview]').click();
      } else {
        const focusedCard = document.activeElement && document.activeElement.closest('.fm-card')
          ? document.activeElement.closest('.fm-card')
          : document.querySelector('.fm-card.is-selected');
        if (focusedCard) {
          const trigger = focusedCard.querySelector('[data-preview-trigger]') || focusedCard;
          trigger.click();
        }
      }
    }
  });

  // Modal Viewer Toolbar Controls
  let currentZoom = 1.0;
  const zoomOutBtn = document.getElementById('btn-zoom-out');
  const zoomInBtn = document.getElementById('btn-zoom-in');
  const zoomResetBtn = document.getElementById('btn-zoom-reset');
  const printBtn = document.getElementById('btn-print-doc');
  const fullscreenBtn = document.getElementById('btn-fullscreen-modal');
  const qrBtn = document.getElementById('btn-qr-preview');
  const qrOverlay = document.getElementById('qr-overlay');
  const qrCloseBtn = document.getElementById('qr-close');
  const qrGraphic = document.getElementById('qr-graphic');

  function applyZoom(zoom) {
    currentZoom = zoom;
    if (zoomResetBtn) zoomResetBtn.textContent = Math.round(currentZoom * 100) + '%';
    previewContent.style.transform = `scale(${currentZoom})`;
    previewContent.style.transformOrigin = 'top center';
  }

  if (zoomInBtn) zoomInBtn.onclick = () => applyZoom(Math.min(2.0, currentZoom + 0.15));
  if (zoomOutBtn) zoomOutBtn.onclick = () => applyZoom(Math.max(0.5, currentZoom - 0.15));
  if (zoomResetBtn) zoomResetBtn.onclick = () => applyZoom(1.0);

  if (printBtn) {
    printBtn.onclick = () => window.print();
  }

  if (fullscreenBtn) {
    fullscreenBtn.onclick = () => {
      const panel = document.getElementById('preview-panel');
      if (!document.fullscreenElement) {
        if (panel && panel.requestFullscreen) panel.requestFullscreen();
        else if (panel && panel.webkitRequestFullscreen) panel.webkitRequestFullscreen();
      } else {
        if (document.exitFullscreen) document.exitFullscreen();
      }
    };
  }

  if (qrBtn && qrOverlay && qrGraphic) {
    qrBtn.onclick = () => {
      if (window.QRCode && previewCopyLink) {
        qrGraphic.innerHTML = window.QRCode.generateSVG(previewCopyLink, 180);
        qrOverlay.hidden = false;
      }
    };
  }

  if (qrCloseBtn && qrOverlay) {
    qrCloseBtn.onclick = () => {
      qrOverlay.hidden = true;
    };
  }

  document.addEventListener('keydown', (e) => {
    if (modal.hidden) return;
    if (e.key === 'Escape') modal.querySelector('[data-close-preview]').click();
    if (e.key !== 'Tab') return;
    const controls = [...modal.querySelectorAll('button, a[href]')];
    const first = controls[0];
    const last = controls.at(-1);
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });

  if (window.pdfjsLib) {
    pdfjsLib.GlobalWorkerOptions.workerSrc = '<?= e(app_url('assets/js/pdf.worker.min.js')) ?>';
  }

  async function renderFastPdf(url, container, hideLoader) {
    container.innerHTML = `
      <div class="pdf-viewer-deck">
        <div class="pdf-controls-bar">
          <button type="button" class="btn-pdf-nav" id="pdf-prev" disabled>&larr; Prev</button>
          <span class="pdf-page-indicator mono"><span id="pdf-page-num">1</span> / <span id="pdf-page-total">-</span></span>
          <button type="button" class="btn-pdf-nav" id="pdf-next" disabled>Next &rarr;</button>
        </div>
        <div class="pdf-canvas-wrap">
          <canvas id="pdf-canvas"></canvas>
        </div>
      </div>
    `;

    try {
      const loadingTask = pdfjsLib.getDocument({
        url,
        rangeChunkSize: 65536,
        disableAutoFetch: true,
        disableStream: false
      });
      const pdfDoc = await loadingTask.promise;
      const totalPages = pdfDoc.numPages;
      const totalEl = document.getElementById('pdf-page-total');
      if (totalEl) totalEl.textContent = totalPages;

      let currentPage = 1;
      const canvas = document.getElementById('pdf-canvas');
      const ctx = canvas.getContext('2d');

      async function renderPage(num) {
        const numEl = document.getElementById('pdf-page-num');
        const prevBtn = document.getElementById('pdf-prev');
        const nextBtn = document.getElementById('pdf-next');
        if (numEl) numEl.textContent = num;
        if (prevBtn) prevBtn.disabled = (num <= 1);
        if (nextBtn) nextBtn.disabled = (num >= totalPages);

        const page = await pdfDoc.getPage(num);
        const viewport = page.getViewport({ scale: 1.4 });
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
    } catch (err) {
      console.warn('PDF.js error, fallback to frame:', err);
      container.innerHTML = `<iframe class="preview-frame" src="${url}" title="Preview PDF" tabindex="-1"></iframe>`;
      hideLoader();
    }
  }

  async function renderDocx(url, container, hideLoader) {
    container.innerHTML = '<div class="docx-render-wrap"></div>';
    try {
      const res = await fetch(url);
      const blob = await res.blob();
      const wrap = container.querySelector('.docx-render-wrap');
      await docx.renderAsync(blob, wrap, null, {
        inWrapper: true,
        ignoreWidth: false,
        breakPages: true,
        useBase64URL: true,
        renderHeaders: true,
        renderFooters: true
      });
      hideLoader();
    } catch (err) {
      console.warn('docx-preview error, fallback:', err);
      container.innerHTML = '<p class="preview-message">Pratinjau DOCX tidak dapat dirender. Silakan unduh berkas untuk membukanya.</p>';
      hideLoader();
    }
  }

  async function renderSpreadsheet(url, container, hideLoader) {
    try {
      const res = await fetch(url);
      const buf = await res.arrayBuffer();
      const wb = XLSX.read(buf, { type: 'array' });
      if (!wb.SheetNames || wb.SheetNames.length === 0) {
        container.innerHTML = '<p class="preview-message">Berkas spreadsheet tidak memiliki lembar kerja yang valid.</p>';
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

      container.innerHTML = `
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
          <div class="sheet-table-wrap" id="sheet-table-wrap">${getTableHtml(firstSheet)}</div>
        </div>
      `;

      if (wb.SheetNames.length > 1) {
        container.querySelectorAll('.sheet-tab-btn').forEach(btn => {
          btn.onclick = () => {
            container.querySelectorAll('.sheet-tab-btn').forEach(b => {
              b.classList.remove('is-active');
              b.setAttribute('aria-selected', 'false');
            });
            btn.classList.add('is-active');
            btn.setAttribute('aria-selected', 'true');
            const sheetName = btn.dataset.sheetName;
            const activeNameEl = container.querySelector('#sheet-active-name');
            const dimPillEl = container.querySelector('#sheet-dim-pill');
            const wrapEl = container.querySelector('#sheet-table-wrap');
            if (activeNameEl) activeNameEl.textContent = sheetName;
            if (dimPillEl) dimPillEl.textContent = getSheetInfo(sheetName);
            if (wrapEl) wrapEl.innerHTML = getTableHtml(sheetName);
          };
        });
      }
      hideLoader();
    } catch (err) {
      console.warn('xlsx error, fallback:', err);
      container.innerHTML = '<p class="preview-message">Pratinjau spreadsheet tidak dapat dirender. Silakan unduh berkas untuk membukanya.</p>';
      hideLoader();
    }
  }
</script>
<?php page_footer();
