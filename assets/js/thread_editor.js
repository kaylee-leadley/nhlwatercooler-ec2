// assets/js/thread_editor.js
document.addEventListener('DOMContentLoaded', function () {
  const editorEl       = document.getElementById('editor');
  const emojiToggleBtn = document.getElementById('emoji-toggle');
  const emojiPanel     = document.getElementById('emoji-panel');

  if (!editorEl) return; // no editor on this page

  const EMOJIS = [
    '😀','😂','🤣','😅','😎','😍','😭','🤬',
    '👍','👎','👏','🙏','🔥','💀','🦈','🏒','🥅'
  ];

  /* ---------- Caret insertion helper ---------- */

  function insertHtmlAtCaret(html) {
    editorEl.focus();
    const sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) {
      editorEl.insertAdjacentHTML('beforeend', html);
      return;
    }

    const range = sel.getRangeAt(0);
    range.deleteContents();

    const temp = document.createElement('div');
    temp.innerHTML = html;

    const frag = document.createDocumentFragment();
    let node, lastNode;
    while ((node = temp.firstChild)) {
      lastNode = frag.appendChild(node);
    }

    range.insertNode(frag);

    if (lastNode) {
      range.setStartAfter(lastNode);
      range.collapse(true);
      sel.removeAllRanges();
      sel.addRange(range);
    }
  }

  /* ---------- Emoji panel ---------- */

  function buildEmojiPanel() {
    if (!emojiPanel) return;

    emojiPanel.innerHTML = '';
    emojiPanel.hidden = true; // start closed

    EMOJIS.forEach(ch => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'emoji-panel__item';
      btn.textContent = ch;

      btn.addEventListener('click', () => {
        insertHtmlAtCaret(ch);
        emojiPanel.hidden = true;
        editorEl.focus();
      });

      emojiPanel.appendChild(btn);
    });
  }

  buildEmojiPanel();

  if (emojiToggleBtn && emojiPanel) {
    emojiToggleBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const willShow = !emojiPanel.hidden;
      emojiPanel.hidden = !emojiPanel.hidden;
      if (!willShow) editorEl.focus();
    });

    // Click outside closes the emoji panel
    document.addEventListener('click', (e) => {
      if (emojiPanel.hidden) return;
      if (!emojiPanel.contains(e.target) && e.target !== emojiToggleBtn) {
        emojiPanel.hidden = true;
      }
    });
  }

  /* ---------- Shared image upload helper ---------- */

  async function uploadImage(file) {
    const formData = new FormData();
    formData.append('file', file);

    const res = await fetch('post_api_upload_post_image.php', {
      method: 'POST',
      body: formData
    });

    if (!res.ok) {
      console.error('Image upload failed', res.status);
      return null;
    }

    const data = await res.json();
    if (!data || !data.ok || !data.url) {
      console.error('Upload error response', data);
      return null;
    }

    // API returns "assets/img/post_uploads/filename.ext"
    return '../' + data.url;
  }

  /* ---------- Paste handling (HTML first, then file) ---------- */

  editorEl.addEventListener('paste', async (e) => {
    const cd = e.clipboardData || window.clipboardData;
    if (!cd) return;

    // 1) Try HTML first: if there is an <img src="...">, use that directly.
    const htmlData = cd.getData('text/html');
    if (htmlData) {
      try {
        const parser = new DOMParser();
        const doc = parser.parseFromString(htmlData, 'text/html');
        const img = doc.querySelector('img');

        if (img && img.src) {
          const src = img.src;

          // If we got here, prevent default so browser doesn’t double-insert
          e.preventDefault();
          insertHtmlAtCaret(
            '<img src="' + src + '" alt="" ' +
            'style="max-height:200px;max-width:100%;height:auto;width:auto;">'
          );
          return;
        }
      } catch (err) {
        console.error('Error parsing pasted HTML', err);
        // fall through to file-based handling
      }
    }

    // 2) Fallback: file item from clipboard
    const items = cd.items;
    if (!items || !items.length) return;

    let imageItem = null;
    for (let i = 0; i < items.length; i++) {
      const it = items[i];
      if (it.kind === 'file' && it.type && it.type.indexOf('image/') === 0) {
        imageItem = it;
        break;
      }
    }

    if (!imageItem) {
      // Let normal text paste happen
      return;
    }

    e.preventDefault();
    const file = imageItem.getAsFile();
    if (!file) return;

    console.log('Uploading pasted file:', file.type, file.name);

    try {
      const url = await uploadImage(file);
      if (!url) return;
      insertHtmlAtCaret(
        '<img src="' + url + '" alt="" ' +
        'style="max-height:200px;max-width:100%;height:auto;width:auto;">'
      );
    } catch (err) {
      console.error('paste upload error', err);
    }
  });

  /* ---------- Drag + drop (always uses real file) ---------- */

  editorEl.addEventListener('dragover', (e) => {
    e.preventDefault();
    editorEl.classList.add('drag-hover');
  });

  editorEl.addEventListener('dragleave', (e) => {
    e.preventDefault();
    editorEl.classList.remove('drag-hover');
  });

  editorEl.addEventListener('drop', async (e) => {
    e.preventDefault();
    editorEl.classList.remove('drag-hover');

    if (!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) return;

    const file = e.dataTransfer.files[0];
    if (!file.type || file.type.indexOf('image/') !== 0) return;

    console.log('Uploading dropped file:', file.type, file.name);

    try {
      const url = await uploadImage(file);
      if (!url) return;
      insertHtmlAtCaret(
        '<img src="' + url + '" alt="" ' +
        'style="max-height:200px;max-width:100%;height:auto;width:auto;">'
      );
    } catch (err) {
      console.error('drop upload error', err);
    }
  });

  /* ---------- Manual file picker (optional) ---------- */

  const uploadInput = document.getElementById('upload-image-input');
  const uploadBtn   = document.getElementById('upload-image-button');

  if (uploadBtn && uploadInput) {
    uploadBtn.addEventListener('click', () => uploadInput.click());

    uploadInput.addEventListener('change', async () => {
      if (!uploadInput.files || !uploadInput.files.length) return;

      const file = uploadInput.files[0];
      if (!file.type || file.type.indexOf('image/') !== 0) return;

      console.log('Uploading selected file:', file.type, file.name);

      try {
        const url = await uploadImage(file);
        if (!url) return;
        insertHtmlAtCaret(
          '<img src="' + url + '" alt="" ' +
          'style="max-height:200px;max-width:100%;height:auto;width:auto;">'
        );
      } catch (err) {
        console.error('manual upload error', err);
      } finally {
        uploadInput.value = '';
      }
    });
  }
    /* ---------- Image enlarge modal for avatars / header ---------- */

  const imageModal     = document.getElementById('image-modal');
  const imageModalImg  = imageModal ? imageModal.querySelector('.image-modal__img') : null;
  const imageModalClose = imageModal ? imageModal.querySelector('.image-modal__close') : null;

  function openImageModal(src, altText) {
    if (!imageModal || !imageModalImg) return;
    imageModalImg.src = src;
    imageModalImg.alt = altText || '';
    imageModal.hidden = false;
    document.body.classList.add('image-modal-open');
  }

  function closeImageModal() {
    if (!imageModal || !imageModalImg) return;
    imageModal.hidden = true;
    imageModalImg.src = '';
    imageModalImg.alt = '';
    document.body.classList.remove('image-modal-open');
  }

  if (imageModal) {
    // Click on avatar / header image → open modal
    document.addEventListener('click', (e) => {
      // Adjust selectors to match your markup:
      // - .post-avatar img    → post avatars
      // - .thread-header__image-wrap img → big header image (optional)
      const img = e.target.closest('.post-avatar, .thread-header__image-wrap img');
      if (!img) return;

      // If you ever want a bigger original, you can set data-full-src on the <img>
      const fullSrc = img.dataset.fullSrc || img.src;
      const altText = img.alt || 'Avatar';
      openImageModal(fullSrc, altText);
    });

    // Click outside the image (on the backdrop) closes modal
    imageModal.addEventListener('click', (e) => {
      if (e.target === imageModal) {
        closeImageModal();
      }
    });

    // Close button
    if (imageModalClose) {
      imageModalClose.addEventListener('click', (e) => {
        e.stopPropagation();
        closeImageModal();
      });
    }

    // Escape key closes modal
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !imageModal.hidden) {
        closeImageModal();
      }
    });
  }
});
