<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$isAdmin = Auth::isAdmin($user);
$rootArtworkCount = PromptSettings::rootArtworkCount();

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Step 1 · Create Base Image - The Artwork Curator</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
  <style>
      .form-container {
          max-width: 860px;
          margin: 12px auto;
      }
      .tabs-container {
          display: flex;
          gap: 8px;
          margin-bottom: 12px;
          border-bottom: 1.5px solid var(--line);
          padding-bottom: 2px;
      }
      .tab-button {
          background: transparent;
          border: none;
          border-bottom: 2px solid transparent;
          padding: 10px 20px;
          font-family: var(--font-sans);
          font-size: 13px;
          font-weight: 600;
          color: var(--muted);
          cursor: pointer;
          transition: all 0.2s ease;
          text-transform: uppercase;
          letter-spacing: 0.05em;
      }
      .tab-button:hover {
          color: var(--ink);
      }
      .tab-button.active {
          color: var(--accent);
          border-bottom-color: var(--accent);
      }
      .tab-content {
          display: none;
      }
      .tab-content.active {
          display: block;
      }
      .root-mode-label {
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 16px;
          margin-bottom: 10px;
          padding-bottom: 8px;
          border-bottom: 1px dashed var(--line);
      }
      .root-mode-label h2 {
          margin: 0;
          font-family: var(--font-serif);
          font-size: 26px;
          font-weight: 500;
          display: flex;
          align-items: center;
          gap: 8px;
      }
      .tooltip-icon {
          display: inline-flex;
          align-items: center;
          justify-content: center;
          width: 16px;
          height: 16px;
          border-radius: 50%;
          background: var(--surface-soft);
          color: var(--muted);
          font-size: 11px;
          font-weight: bold;
          cursor: help;
          margin-left: 6px;
          border: 1px solid var(--line);
          position: relative;
      }
      .tooltip-icon:hover::after {
          content: attr(title);
          position: absolute;
          bottom: 125%;
          left: 50%;
          transform: translateX(-50%);
          background: var(--ink);
          color: var(--surface);
          padding: 8px 12px;
          border-radius: var(--radius);
          font-size: 11px;
          line-height: 1.4;
          white-space: normal;
          width: 240px;
          z-index: 100;
          box-shadow: var(--shadow-hover);
          font-family: var(--font-sans);
          font-weight: normal;
          text-transform: none;
      }
      .dropzone-container {
          position: relative;
          border: 1.5px dashed var(--line);
          border-radius: var(--radius);
          background: var(--surface-soft);
          min-height: 200px;
          padding: 16px 24px;
          text-align: center;
          cursor: pointer;
          transition: all 0.3s ease;
          display: flex;
          flex-direction: column;
          align-items: center;
          justify-content: center;
          gap: 12px;
          margin-bottom: 14px;
      }
      .dropzone-container:hover,
      .dropzone-container.dragover {
          border-color: var(--accent);
          background: var(--surface);
          box-shadow: var(--shadow-hover);
      }
      .dropzone-container input[type="file"] {
          position: absolute;
          top: 0;
          left: 0;
          width: 100%;
          height: 100%;
          opacity: 0;
          cursor: pointer;
      }
      .dropzone-icon {
          width: 32px;
          height: 32px;
          color: var(--accent);
          opacity: 0.8;
      }
      .dropzone-text {
          font-family: var(--font-sans);
          font-size: 14px;
          color: var(--ink);
          font-weight: 500;
      }
      .dropzone-text span {
          color: var(--accent);
          text-decoration: underline;
      }
      .dropzone-info {
          font-size: 11px;
          color: var(--muted);
      }
      .dropzone-preview {
          display: none;
          width: auto;
          max-width: min(100%, 720px);
          height: auto;
          max-height: 280px;
          object-fit: contain;
          border: 1px solid var(--line);
          border-radius: 2px;
          margin-top: 14px;
          box-shadow: var(--shadow);
          background: var(--surface);
      }
      .dropzone-container.has-preview {
          min-height: 320px;
          background: #f8f6f1;
      }
      .dropzone-container.has-preview .dropzone-icon {
          width: 24px;
          height: 24px;
      }
      .dropzone-container.has-preview .dropzone-info {
          margin-bottom: 4px;
      }
      .dim-input-group {
          display: grid;
          grid-template-columns: repeat(4, 1fr);
          gap: 15px;
          margin-top: 10px;
      }
      .dim-input-field {
          display: flex;
          flex-direction: column;
          gap: 6px;
      }
      .dim-input-field label {
          margin: 0;
          font-size: 10px;
          text-transform: uppercase;
          font-weight: 600;
          color: var(--muted);
          letter-spacing: 0.08em;
      }
      .dim-input-field input,
      .dim-input-field select {
          padding: 10px 12px;
          font-size: 13px;
          width: 100%;
      }
      .step-actions {
          margin-top: 35px;
          border-top: 1px dashed var(--line);
          padding-top: 25px;
          display: flex;
          justify-content: space-between;
          align-items: center;
          gap: 16px;
      }
      .step-actions button,
      .step-actions .button-link {
          width: auto;
          margin: 0;
          padding: 12px 24px;
      }
      .actions-group {
          display: flex;
          gap: 12px;
      }
      @media (max-width: 720px) {
          .root-mode-label {
              align-items: flex-start;
              flex-direction: column;
          }
      }
  </style>
</head>
<body>

<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

  <main class="main-area">
    <header class="app-header">
      <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
    </header>

    <div class="alert-strip">
      Step 1 · Create Base Image: Upload the original artwork to isolate the canvas, remove background noise and shadow interference.
    </div>

    <div class="workspace">
      <div class="workspace-header">
        <div>
          <h1>Step 1 · Upload Artwork</h1>
          <p>Upload the original art piece and enter its actual physical dimensions.</p>
        </div>
        <div class="topbar-actions">
          <a class="button-link secondary" href="root_album.php">Root Artworks</a>
        </div>
      </div>

      <p class="page-kicker">
        It is critical to specify the exact canvas sizes to maintain realistic scaling in future mockups.
      </p>

      <div class="form-container">
        <!-- Tabs Selector -->
        <div class="tabs-container" role="tablist" aria-label="Upload modes">
          <button type="button" class="tab-button active" role="tab" aria-selected="true" aria-controls="ai-cleanup-tab" id="tab-ai" onclick="switchTab('ai-cleanup-tab', 'tab-ai')">
              AI Cleanup & Candidates
          </button>
          <button type="button" class="tab-button" role="tab" aria-selected="false" aria-controls="direct-upload-tab" id="tab-direct" onclick="switchTab('direct-upload-tab', 'tab-direct')">
              Direct Upload (Bypass)
          </button>
        </div>

        <!-- TAB 1: AI CLEANUP -->
        <div id="ai-cleanup-tab" class="tab-content active" role="tabpanel" aria-labelledby="tab-ai">
          <form action="start_generate.php" method="post" enctype="multipart/form-data" class="panel">
            <div class="root-mode-label">
              <h2>
                  <svg class="icon-inline" style="width:20px;height:20px;color:var(--accent);" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />
                  </svg>
                  Generate Root Artwork
                  <span class="tooltip-icon" title="Recommended when the photo has background shadows, frames, wall perspective, or needs canvas separation. Generates several isolated candidates.">ⓘ</span>
              </h2>
            </div>
            
            <div class="form-group" style="margin-bottom: 16px;">
              <label style="margin: 0 0 10px 0; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Primary Image of the Artwork</label>
              
              <div class="dropzone-container" id="dropzone">
                  <svg class="dropzone-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                  </svg>
                  <div class="dropzone-text" id="dropzoneText">Drag and drop your image here, or <span>browse files</span></div>
                  <div class="dropzone-info">Supports PNG, JPG, JPEG or WEBP (Max 15MB)</div>
                  <img id="previewImage" class="dropzone-preview" alt="Preview" />
                  <input type="file" name="main_artwork" id="fileInput" accept="image/*" required>
              </div>
            </div>

            <div class="form-group">
              <label style="margin: 0; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Physical Dimensions</label>
              <small style="margin: 4px 0 14px 0; color: var(--muted); font-size: 11.5px; line-height: 1.4;">
                Provide the artwork dimensions without accounting for frames, supports, shadows or photo borders.
              </small>
              
              <div class="dim-input-group">
                <div class="dim-input-field">
                  <label>Width</label>
                  <input type="number" name="width" step="0.1" placeholder="e.g. 80" required min="0.1">
                </div>
                <div class="dim-input-field">
                  <label>Height</label>
                  <input type="number" name="height" step="0.1" placeholder="e.g. 100" required min="0.1">
                </div>
                <div class="dim-input-field">
                  <label>Depth (optional)</label>
                  <input type="number" name="depth" step="0.1" placeholder="e.g. 4">
                </div>
                <div class="dim-input-field">
                  <label>Unit</label>
                  <select name="unit">
                    <option value="cm" selected>cm</option>
                    <option value="in">inches</option>
                  </select>
                </div>
              </div>
            </div>

            <div class="step-actions">
              <span style="font-size: 11px; color: var(--muted);">Beta Mode: Uses Imagen 3 generation pipeline.</span>
              <div class="actions-group">
                  <a href="root_album.php" class="button secondary">Cancel</a>
                  <button type="submit" class="button">Upload & Create Candidates</button>
              </div>
            </div>
          </form>
        </div>

        <!-- TAB 2: DIRECT UPLOAD (BYPASS) -->
        <div id="direct-upload-tab" class="tab-content" role="tabpanel" aria-labelledby="tab-direct">
          <form action="upload_existing_root.php" method="post" enctype="multipart/form-data" class="panel">
            <div class="root-mode-label">
              <h2>
                  <svg class="icon-inline" style="width:20px;height:20px;color:var(--accent);" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                  </svg>
                  Use Existing Root Artwork
                  <span class="tooltip-icon" title="Recommended when the photo is already clean, frontal, isolated, and cropped. Gemini root candidate select step is skipped.">ⓘ</span>
              </h2>
            </div>

            <div class="form-group" style="margin-bottom: 16px;">
              <label style="margin: 0 0 10px 0; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Ready Root Artwork Image</label>

              <div class="dropzone-container" id="existingRootDropzone">
                  <svg class="dropzone-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                  </svg>
                  <div class="dropzone-text" id="existingRootDropzoneText">Drag and drop your finished root image here, or <span>browse files</span></div>
                  <div class="dropzone-info">Supports PNG, JPG, JPEG or WEBP. Direct catalog upload.</div>
                  <img id="existingRootPreviewImage" class="dropzone-preview" alt="Existing root preview" />
                  <input type="file" name="existing_root_artwork" id="existingRootFileInput" accept="image/*" required>
              </div>
            </div>

            <div class="form-group">
              <label style="margin: 0; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Physical Dimensions</label>
              <small style="margin: 4px 0 14px 0; color: var(--muted); font-size: 11.5px; line-height: 1.4;">
                Provide the artwork dimensions for realistic scale in future mockups.
              </small>

              <div class="dim-input-group">
                <div class="dim-input-field">
                  <label>Width</label>
                  <input type="number" name="width" step="0.1" placeholder="e.g. 80" required min="0.1">
                </div>
                <div class="dim-input-field">
                  <label>Height</label>
                  <input type="number" name="height" step="0.1" placeholder="e.g. 100" required min="0.1">
                </div>
                <div class="dim-input-field">
                  <label>Depth (optional)</label>
                  <input type="number" name="depth" step="0.1" placeholder="e.g. 4">
                </div>
                <div class="dim-input-field">
                  <label>Unit</label>
                  <select name="unit">
                    <option value="cm" selected>cm</option>
                    <option value="in">inches</option>
                  </select>
                </div>
              </div>
            </div>

            <div class="step-actions">
              <span style="font-size: 11px; color: var(--muted);">Bypass Mode: stores this file as the final root artwork.</span>
              <div class="actions-group">
                  <a href="root_album.php" class="button secondary">Cancel</a>
                  <button type="submit" class="button secondary">Use This Root Artwork</button>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
  </main>
</div>

<script>
    function switchTab(tabId, buttonId) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-button').forEach(el => el.classList.remove('active'));
        
        document.getElementById(tabId).classList.add('active');
        document.getElementById(buttonId).classList.add('active');
    }

    bindDropzone('fileInput', 'dropzone', 'dropzoneText', 'previewImage');
    bindDropzone('existingRootFileInput', 'existingRootDropzone', 'existingRootDropzoneText', 'existingRootPreviewImage');

    function bindDropzone(fileInputId, dropzoneId, dropzoneTextId, previewImageId) {
        const fileInput = document.getElementById(fileInputId);
        const dropzone = document.getElementById(dropzoneId);
        const dropzoneText = document.getElementById(dropzoneTextId);
        const previewImage = document.getElementById(previewImageId);

        fileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                updateDropzoneWithFile(dropzone, dropzoneText, previewImage, file);
            }
        });

        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                dropzone.classList.add('dragover');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                dropzone.classList.remove('dragover');
            }, false);
        });

        dropzone.addEventListener('drop', (e) => {
            const dt = e.dataTransfer;
            const file = dt.files[0];
            if (file) {
                fileInput.files = dt.files;
                updateDropzoneWithFile(dropzone, dropzoneText, previewImage, file);
            }
        });
    }

    function updateDropzoneWithFile(dropzone, dropzoneText, previewImage, file) {
        dropzone.classList.add('has-preview');
        dropzoneText.innerHTML = `Selected: <strong>${file.name}</strong> (Click to replace)`;
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImage.src = e.target.result;
            previewImage.style.display = 'block';
        }
        reader.readAsDataURL(file);
    }
</script>

</body>
</html>
