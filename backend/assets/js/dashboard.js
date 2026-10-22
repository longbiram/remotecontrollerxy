/**
 * Dashboard UI interactions, video previewer, and media handlers
 */

document.addEventListener('DOMContentLoaded', function () {
  // 1. Video Player Modal with HLS.js support
  const videoModal = document.getElementById('videoPlayerModal');
  const modalVideo = document.getElementById('modalVideoPlayer');
  const modalTitle = document.getElementById('modalVideoTitle');
  const closeModalBtn = document.getElementById('closeVideoModal');
  let hlsInstance = null;

  window.openStreamPreview = function (streamUrl, title) {
    if (!videoModal || !modalVideo) return;
    
    if (modalTitle) modalTitle.innerText = title || 'Stream Preview';
    videoModal.style.display = 'flex';

    // Clean up previous Hls instance
    if (hlsInstance) {
      hlsInstance.destroy();
      hlsInstance = null;
    }

    if (streamUrl.includes('.m3u8') && window.Hls && Hls.isSupported()) {
      hlsInstance = new Hls();
      hlsInstance.loadSource(streamUrl);
      hlsInstance.attachMedia(modalVideo);
      hlsInstance.on(Hls.Events.MANIFEST_PARSED, function () {
        modalVideo.play().catch(e => console.log('Autoplay blocked:', e));
      });
    } else {
      modalVideo.src = streamUrl;
      modalVideo.load();
      modalVideo.play().catch(e => console.log('Autoplay blocked:', e));
    }
  };

  function closeVideoModal() {
    if (!videoModal || !modalVideo) return;
    modalVideo.pause();
    modalVideo.removeAttribute('src');
    if (hlsInstance) {
      hlsInstance.destroy();
      hlsInstance = null;
    }
    videoModal.style.display = 'none';
  }

  if (closeModalBtn) {
    closeModalBtn.addEventListener('click', closeVideoModal);
  }

  if (videoModal) {
    videoModal.addEventListener('click', function (e) {
      if (e.target === videoModal) closeVideoModal();
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeVideoModal();
  });

  // 2. Media Tabs (Upload vs URL)
  document.querySelectorAll('.media-tab-group').forEach(group => {
    const buttons = group.querySelectorAll('.media-tab-btn');
    const sections = group.querySelectorAll('.media-tab-content');

    buttons.forEach(btn => {
      btn.addEventListener('click', () => {
        const target = btn.getAttribute('data-target');
        buttons.forEach(b => b.classList.remove('active'));
        sections.forEach(s => s.style.display = 'none');

        btn.classList.add('active');
        const activeSec = group.querySelector('.' + target);
        if (activeSec) activeSec.style.display = 'block';
      });
    });
  });

  // 3. Image URL live preview
  function setupUrlPreview(inputId, previewImgId) {
    const input = document.getElementById(inputId);
    const img = document.getElementById(previewImgId);
    if (!input || !img) return;

    input.addEventListener('input', function () {
      const val = input.value.trim();
      if (val && (val.startsWith('http://') || val.startsWith('https://') || val.startsWith('/'))) {
        img.src = val;
        img.parentElement.style.display = 'block';
      } else {
        img.parentElement.style.display = 'none';
      }
    });
  }

  setupUrlPreview('poster_url', 'posterPreviewImg');
  setupUrlPreview('banner_url', 'bannerPreviewImg');

  // 4. File input preview
  function setupFilePreview(inputId, previewImgId) {
    const input = document.getElementById(inputId);
    const img = document.getElementById(previewImgId);
    if (!input || !img) return;

    input.addEventListener('change', function () {
      if (input.files && input.files[0]) {
        const file = input.files[0];
        img.src = URL.createObjectURL(file);
        img.parentElement.style.display = 'block';
      }
    });
  }

  setupFilePreview('poster_file', 'posterPreviewImg');
  setupFilePreview('banner_file', 'bannerPreviewImg');

  // 5. Delete confirmation
  document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', function (e) {
      const msg = el.getAttribute('data-confirm') || 'Are you sure you want to delete this item?';
      if (!confirm(msg)) {
        e.preventDefault();
      }
    });
  });

  // 6. Content type switcher (supports radio cards or select)
  const typeRadios = document.querySelectorAll('input[name="type"]');
  const typeSelect = document.getElementById('content_type');
  const channelCatGroup = document.getElementById('channelCategoryGroup');
  const seriesNotice = document.getElementById('seriesNoticeBanner');

  function handleTypeChange(val) {
    if (channelCatGroup) {
      channelCatGroup.style.display = val === 'liveTv' ? 'grid' : 'none';
    }
    if (seriesNotice) {
      seriesNotice.style.display = val === 'series' ? 'block' : 'none';
    }
    const yearInput = document.getElementById('year');
    const durationInput = document.getElementById('duration');
    if (val === 'liveTv') {
      if (yearInput && !yearInput.value) yearInput.value = 'LIVE';
      if (durationInput && !durationInput.value) durationInput.value = '24/7 Live Stream';
    }
  }

  if (typeRadios.length > 0) {
    typeRadios.forEach(radio => {
      radio.addEventListener('change', () => {
        if (radio.checked) handleTypeChange(radio.value);
      });
      if (radio.checked) handleTypeChange(radio.value);
    });
  } else if (typeSelect) {
    typeSelect.addEventListener('change', () => handleTypeChange(typeSelect.value));
    handleTypeChange(typeSelect.value);
  }

  // 7. Synchronized Rating Slider
  const ratingSlider = document.getElementById('rating_slider');
  const ratingInput = document.getElementById('rating');
  const ratingDisplay = document.getElementById('ratingDisplayValue');

  if (ratingSlider && ratingInput) {
    ratingSlider.addEventListener('input', () => {
      const val = parseFloat(ratingSlider.value).toFixed(1);
      ratingInput.value = val;
      if (ratingDisplay) ratingDisplay.innerText = val + ' ★';
    });

    ratingInput.addEventListener('input', () => {
      const val = parseFloat(ratingInput.value) || 0.0;
      ratingSlider.value = val;
      if (ratingDisplay) ratingDisplay.innerText = val.toFixed(1) + ' ★';
    });
  }

  // 8. Synopsis Character Counter
  const synopsisArea = document.getElementById('synopsis');
  const synopsisCounter = document.getElementById('synopsisCharCount');
  if (synopsisArea && synopsisCounter) {
    function updateCounter() {
      const len = synopsisArea.value.length;
      synopsisCounter.innerText = len + ' characters';
    }
    synopsisArea.addEventListener('input', updateCounter);
    updateCounter();
  }

  // 9. Quick Preset Chips (Duration, Year, or any targeted input)
  document.querySelectorAll('.preset-chip').forEach(chip => {
    chip.addEventListener('click', () => {
      const targetId = chip.getAttribute('data-target') || 'duration';
      const targetInput = document.getElementById(targetId);
      const val = chip.getAttribute('data-val');
      if (targetInput && val) {
        targetInput.value = val;
        targetInput.focus();
        targetInput.dispatchEvent(new Event('input'));
      }
    });
  });

  // 10. Drag & Drop Upload Handlers
  document.querySelectorAll('.dropzone-card').forEach(dropzone => {
    const fileInput = dropzone.querySelector('input[type="file"]');
    if (!fileInput) return;

    ['dragenter', 'dragover'].forEach(eventName => {
      dropzone.addEventListener(eventName, e => {
        e.preventDefault();
        dropzone.classList.add('dragover');
      });
    });

    ['dragleave', 'drop'].forEach(eventName => {
      dropzone.addEventListener(eventName, e => {
        e.preventDefault();
        dropzone.classList.remove('dragover');
      });
    });

    dropzone.addEventListener('drop', e => {
      if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
        fileInput.files = e.dataTransfer.files;
        fileInput.dispatchEvent(new Event('change'));
      }
    });

    dropzone.addEventListener('click', e => {
      if (e.target !== fileInput) {
        fileInput.click();
      }
    });
  });

  // 11. Inline Test Stream Button
  const btnTestStreamInline = document.getElementById('btnTestStreamInline');
  if (btnTestStreamInline) {
    btnTestStreamInline.addEventListener('click', () => {
      const streamInput = document.getElementById('stream_url');
      const titleInput = document.getElementById('title');
      const streamVal = streamInput ? streamInput.value.trim() : '';
      const titleVal = titleInput ? titleInput.value.trim() : 'Stream Preview';

      if (!streamVal) {
        alert('Please enter a stream URL first to preview.');
        if (streamInput) streamInput.focus();
        return;
      }
      openStreamPreview(streamVal, titleVal);
    });
  }

  // 12. Add Custom Genre / Language inline
  window.addCustomTag = function(type) {
    const promptText = type === 'genre' ? 'Enter new genre name:' : 'Enter new language:';
    const tag = prompt(promptText);
    if (!tag || !tag.trim()) return;

    const cleanTag = tag.trim();
    const containerId = type === 'genre' ? 'genresChipGroup' : 'languagesChipGroup';
    const container = document.getElementById(containerId);
    const fieldName = type === 'genre' ? 'genres[]' : 'languages[]';

    if (container) {
      const label = document.createElement('label');
      label.className = 'chip-item';
      label.innerHTML = `
        <input type="checkbox" name="${fieldName}" value="${cleanTag}" checked>
        <span class="chip-badge">
          <span class="check-icon">&#10003;</span>
          ${cleanTag}
        </span>
      `;
      container.appendChild(label);
    }
  };

  // 13. Form submission validation
  const contentForm = document.getElementById('contentItemForm');
  if (contentForm) {
    contentForm.addEventListener('submit', (e) => {
      const title = document.getElementById('title')?.value.trim();
      const posterUrl = document.getElementById('poster_url')?.value.trim();
      const posterFile = document.getElementById('poster_file')?.files.length;

      if (!title) {
        e.preventDefault();
        alert('Please enter a Title for this content item.');
        document.getElementById('title')?.focus();
        return;
      }

      if (!posterUrl && !posterFile) {
        e.preventDefault();
        alert('Please provide a Poster image (paste an image URL or upload a file).');
        return;
      }

      const submitBtn = document.getElementById('submitContentBtn');
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = `
          <svg style="width: 16px; height: 16px; animation: spin 1s linear infinite; margin-right: 6px; fill: currentColor;" viewBox="0 0 24 24">
            <path d="M12 4V2A10 10 0 0 0 2 12h2a8 8 0 0 1 8-8z"/>
          </svg> Saving Content...
        `;
      }
    });
  }
});

