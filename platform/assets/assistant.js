(() => {
  const root = document.querySelector('[data-assistant-root]:not([data-assistant-ready])');
  if (!root) return;
  root.dataset.assistantReady = '1';

  const workspace = root.querySelector('.faithful-assistant-workspace');
  const launcher = root.querySelector('[data-assistant-open]');
  const messages = root.querySelector('[data-assistant-messages]');
  const thread = root.querySelector('[data-assistant-thread]');
  const title = root.querySelector('[data-assistant-title]');
  const contextLabel = root.querySelector('[data-assistant-context-label]');
  const input = root.querySelector('[data-assistant-input]');
  const form = root.querySelector('[data-assistant-form]');
  const sendButton = root.querySelector('[data-assistant-send]');
  const focusButton = root.querySelector('[data-assistant-focus]');
  const focusLabel = root.querySelector('[data-assistant-focus-label]');
  const historyToggle = root.querySelector('[data-assistant-history-toggle]');
  const historyList = root.querySelector('[data-assistant-history-list]');
  const historyEmpty = root.querySelector('[data-assistant-history-empty]');
  const errorBox = root.querySelector('[data-assistant-error]');
  const visualContextBox = root.querySelector('[data-assistant-visual-context]');
  const attachImageButton = root.querySelector('[data-assistant-attach-image]');
  const imageInput = root.querySelector('[data-assistant-image-input]');
  const targetButton = root.querySelector('[data-assistant-select-target]');
  const captureButton = root.querySelector('[data-assistant-capture-screen]');
  const targetBanner = root.querySelector('[data-assistant-target-banner]');
  const cropper = root.querySelector('[data-assistant-cropper]');
  const cropViewport = root.querySelector('[data-assistant-crop-viewport]');
  const cropStage = root.querySelector('[data-assistant-crop-stage]');
  const cropImage = root.querySelector('[data-assistant-crop-image]');
  const cropSelection = root.querySelector('[data-assistant-crop-selection]');
  const cropConfirm = root.querySelector('[data-assistant-crop-confirm]');
  const cropFull = root.querySelector('[data-assistant-crop-full]');
  const cropCancel = root.querySelector('[data-assistant-crop-cancel]');
  const endpoint = root.dataset.endpoint || 'assistant_api.php';
  const csrf = root.dataset.csrf || '';
  const providerSelect = root.querySelector('[data-assistant-provider-select]');

  let context = {};
  let conversationKey = null;
  let conversations = [];
  let loaded = false;
  let loading = false;
  let sending = false;
  let selectedTarget = null;
  let screenCapture = null;
  let screenCaptureLabel = 'Pantalla incluida';
  let imageProcessing = false;
  let hoveredTarget = null;
  let pendingScreenCapture = null;
  let cropStart = null;
  let cropBounds = null;

  try {
    context = JSON.parse(atob(root.dataset.context || ''));
  } catch (_) {
    context = { current_route: 'dashboard.php', page_type: 'private_page' };
  }

  if (providerSelect) {
    const savedProvider = localStorage.getItem('assistant_provider');
    if (savedProvider) {
      providerSelect.value = savedProvider;
    } else {
      localStorage.setItem('assistant_provider', providerSelect.value);
    }
    providerSelect.addEventListener('change', () => {
      localStorage.setItem('assistant_provider', providerSelect.value);
    });
  }

  const icon = (name) => {
    const icons = {
      assistant: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.8 13.7 9l5.2 1.7-5.2 1.7-1.7 5.2-1.7-5.2-5.2-1.7L10.3 9 12 3.8Z"/></svg>',
      task: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="m8 12 2.5 2.5L16 9"/></svg>',
      check: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 12 4 4 8-9"/></svg>',
    };
    return icons[name] || '';
  };

  const element = (tag, className = '', text = '') => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text) node.textContent = text;
    return node;
  };

  const showError = (text) => {
    errorBox.textContent = text || '';
    errorBox.hidden = !text;
  };

  const refreshContext = () => {
    const selected = [];
    document.querySelectorAll('[data-mockup-id].is-selected,[data-mockup-id][aria-selected="true"],input[type="checkbox"][data-mockup-id]:checked').forEach((node) => {
      const id = Number(node.dataset.mockupId || node.value || 0);
      if (id > 0) selected.push(id);
    });
    context.selected_mockup_ids = [...new Set(selected)].slice(0, 12);
    const artwork = document.querySelector('[data-artwork-id].is-selected,[data-artwork-id][aria-selected="true"]');
    if (artwork && Number(artwork.dataset.artworkId) > 0) context.artwork_id = Number(artwork.dataset.artworkId);
    const mockup = document.querySelector('[data-mockup-id].is-selected,[data-mockup-id][aria-selected="true"]');
    if (mockup && Number(mockup.dataset.mockupId) > 0) context.mockup_id = Number(mockup.dataset.mockupId);
    if (selectedTarget) context.ui_target = selectedTarget;
    else delete context.ui_target;
  };

  const targetLabel = (target) => {
    if (!target) return '';
    return target.text || target.aria_label || target.title || target.element_id || target.role || target.tag || 'Elemento seleccionado';
  };

  const renderVisualContext = () => {
    visualContextBox.replaceChildren();
    if (selectedTarget) {
      const chip = element('span', 'faithful-assistant-context-chip');
      const copy = element('span');
      copy.append(element('small', '', 'Viendo'), element('strong', '', targetLabel(selectedTarget)));
      const remove = element('button', '', '×');
      remove.type = 'button';
      remove.setAttribute('aria-label', 'Quitar elemento seleccionado');
      remove.addEventListener('click', () => {
        selectedTarget = null;
        delete context.ui_target;
        renderVisualContext();
      });
      chip.append(copy, remove);
      visualContextBox.append(chip);
    }
    if (screenCapture) {
      const chip = element('span', 'faithful-assistant-context-chip is-screen');
      const image = document.createElement('img');
      image.src = screenCapture;
      image.alt = '';
      const copy = element('span');
      copy.append(element('small', '', 'Image'), element('strong', '', screenCaptureLabel));
      const remove = element('button', '', '×');
      remove.type = 'button';
      remove.setAttribute('aria-label', 'Quitar captura de pantalla');
      remove.addEventListener('click', () => {
        screenCapture = null;
        screenCaptureLabel = 'Pantalla incluida';
        renderVisualContext();
        resizeInput();
      });
      chip.append(image, copy, remove);
      visualContextBox.append(chip);
    }
    visualContextBox.hidden = visualContextBox.childElementCount === 0;
  };

  const request = async (payload) => {
    const response = await fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ ...payload, csrf }),
    });
    let data;
    try {
      data = await response.json();
    } catch (_) {
      throw new Error('The server did not return a valid response.');
    }
    if (!response.ok || !data.ok) throw new Error(data.message || 'The operation could not be completed.');
    return data.result;
  };

  const relativeDate = (isoDate) => {
    const value = new Date(isoDate);
    if (Number.isNaN(value.getTime())) return '';
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const target = new Date(value.getFullYear(), value.getMonth(), value.getDate());
    const days = Math.round((today - target) / 86400000);
    if (days === 0) return 'Hoy';
    if (days === 1) return 'Ayer';
    return new Intl.DateTimeFormat('es', { day: 'numeric', month: 'short' }).format(value);
  };

  const emptyState = () => {
    const section = element('section', 'faithful-assistant-empty');
    section.innerHTML = `${icon('assistant')}<h2>What are we working on?</h2><p>I am connected to the context of this screen. You can also point to a visible element or choose to include the screen when you need a visual assessment.</p>`;
    messages.append(section);
  };

  const appendMessage = (role, text, pending = false) => {
    const normalizedRole = role === 'user' ? 'user' : 'assistant';
    const article = element('article', `faithful-assistant-message is-${normalizedRole}${pending ? ' is-pending' : ''}`);
    if (normalizedRole === 'assistant') {
      const avatar = element('span', 'faithful-assistant-message-avatar');
      avatar.innerHTML = icon('assistant');
      article.append(avatar);
    }
    const body = element('div', 'faithful-assistant-message-body');
    body.append(element('div', 'faithful-assistant-message-text', String(text || '')));
    article.append(body);
    messages.append(article);
    return article;
  };

  const renderTask = (task) => {
    const card = element('section', 'faithful-assistant-connected-card');
    const header = element('header');
    const mark = element('span');
    mark.innerHTML = icon('task');
    const copy = element('div');
    copy.append(element('small', '', 'Task prepared for Codex'), element('strong', '', task.title || 'Technical task'));
    header.append(mark, copy);
    card.append(header);
    if (task.description) card.append(element('p', '', task.description));
    const footer = element('footer');
    footer.append(element('span', '', task.component || task.route || 'Artwork'), element('em', '', task.status === 'in_progress' ? 'En curso' : 'Pendiente'));
    card.append(footer);
    messages.append(card);
  };

  const renderAction = (action) => {
    if (!action || action.action_type !== 'remember_memory') return;
    const status = element('div', 'faithful-assistant-inline-status');
    status.innerHTML = `${icon('check')}<span>Decision saved to Artwork memory</span>`;
    messages.append(status);
  };

  const renderHistory = (history) => {
    messages.replaceChildren();
    const items = history?.messages || [];
    items.forEach((item) => appendMessage(item.role, item.content));
    (history?.technical_tasks || []).forEach(renderTask);
    if (!items.length && !(history?.technical_tasks || []).length) emptyState();
    conversationKey = history?.conversation_key || null;
    const conversationTitle = String(history?.title || '').trim();
    const firstUserMessage = items.find((item) => item.role === 'user')?.content || '';
    const displayTitle = conversationTitle || firstUserMessage || 'New conversation';
    title.textContent = displayTitle;
    title.title = displayTitle;
    thread.scrollTop = thread.scrollHeight;
  };

  const renderConversations = () => {
    historyList.replaceChildren();
    conversations.forEach((conversation) => {
      const button = element('button');
      button.type = 'button';
      button.dataset.conversationKey = conversation.conversation_key;
      button.classList.toggle('is-active', conversation.conversation_key === conversationKey);
      button.append(element('strong', '', conversation.title || 'Untitled conversation'), element('span', '', relativeDate(conversation.updated_at)));
      button.addEventListener('click', () => openConversation(conversation.conversation_key));
      historyList.append(button);
    });
    historyEmpty.hidden = conversations.length > 0;
  };

  const loadWorkspace = async ({ includeHistory = false } = {}) => {
    if (loading) return;
    loading = true;
    root.classList.add('is-loading-data');
    showError('');
    refreshContext();
    try {
      if (includeHistory) {
        const [workspaceData, history] = await Promise.all([
          request({ action: 'workspace' }),
          request({ action: 'history', conversation_key: null, page_context: context }),
        ]);
        conversations = workspaceData.conversations || [];
        renderHistory(history);
      } else {
        const workspaceData = await request({ action: 'workspace' });
        conversations = workspaceData.conversations || [];
      }
      renderConversations();
      loaded = true;
    } catch (error) {
      showError(error.message);
      if (!messages.childElementCount) emptyState();
    } finally {
      loading = false;
      root.classList.remove('is-loading-data');
    }
  };

  const openConversation = async (key) => {
    if (!key || sending) return;
    showError('');
    root.classList.remove('is-sidebar-open');
    try {
      const history = await request({ action: 'history', conversation_key: key, page_context: context });
      renderHistory(history);
      renderConversations();
      input.focus();
    } catch (error) {
      showError(error.message);
    }
  };

  const resizeInput = () => {
    input.style.height = 'auto';
    input.style.height = `${Math.min(Math.max(input.scrollHeight, 116), 260)}px`;
    sendButton.disabled = sending || imageProcessing || (input.value.trim() === '' && !screenCapture);
  };

  const setFocus = (enabled) => {
    root.classList.toggle('is-focus', enabled);
    document.documentElement.classList.toggle('faithful-assistant-focus-lock', enabled && root.classList.contains('is-open'));
    focusButton.setAttribute('aria-label', enabled ? 'Reducir al modo lateral' : 'Ampliar a pantalla completa');
    focusButton.title = enabled ? 'Reducir al modo lateral' : 'Ampliar a pantalla completa';
    focusLabel.textContent = enabled ? 'Reducir' : 'Ampliar';
  };

  const setHistoryHidden = (hidden) => {
    root.classList.toggle('is-history-hidden', hidden);
    historyToggle.setAttribute('aria-expanded', hidden ? 'false' : 'true');
    historyToggle.setAttribute('aria-label', hidden ? 'Mostrar historial' : 'Ocultar historial');
    historyToggle.title = hidden ? 'Mostrar historial' : 'Ocultar historial';
  };

  const setOpen = (open) => {
    if (!open && root.classList.contains('is-picking-target')) finishTargetPicking(false);
    root.classList.toggle('is-open', open);
    workspace.setAttribute('aria-hidden', open ? 'false' : 'true');
    launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.documentElement.classList.toggle('faithful-assistant-focus-lock', open && root.classList.contains('is-focus'));
    if (open) {
      if (!loaded) loadWorkspace({ includeHistory: true });
      setTimeout(() => input.focus(), 180);
    } else {
      root.classList.remove('is-sidebar-open');
      launcher.focus();
    }
  };

  const startNewConversation = async () => {
    if (sending) return;
    showError('');
    try {
      await request({ action: 'new_conversation', conversation_key: conversationKey, page_context: context });
      conversationKey = null;
      renderHistory({ conversation_key: null, messages: [], technical_tasks: [] });
      await loadWorkspace();
      input.focus();
    } catch (error) {
      showError(error.message);
    }
  };

  const send = async (value) => {
    if (!value || sending) return;
    sending = true;
    refreshContext();
    showError('');
    messages.querySelector('.faithful-assistant-empty')?.remove();
    appendMessage('user', value);
    if (!conversationKey) {
      const shortTitle = value.replace(/\s+/g, ' ').slice(0, 120);
      title.textContent = shortTitle;
      title.title = shortTitle;
    }
    const pending = appendMessage('assistant', screenCapture ? 'Analizando la pantalla y el contexto de Artwork…' : 'Analizando el contexto de Artwork…', true);
    input.disabled = true;
    resizeInput();
    thread.scrollTo({ top: thread.scrollHeight, behavior: 'smooth' });
    try {
      const result = await request({
        action: 'chat',
        message: value,
        conversation_key: conversationKey,
        page_context: context,
        screen_capture: screenCapture,
        provider: providerSelect ? providerSelect.value : null,
      });
      pending.remove();
      appendMessage('assistant', result.message || 'No response was received.');
      (result.technical_tasks || []).forEach(renderTask);
      (result.actions || []).forEach(renderAction);
      conversationKey = result.conversation_key || conversationKey;
      if (result.conversation_title) {
        title.textContent = result.conversation_title;
        title.title = result.conversation_title;
      }
      if (result.context_label) contextLabel.textContent = result.context_label;
      selectedTarget = null;
      screenCapture = null;
      screenCaptureLabel = 'Pantalla incluida';
      delete context.ui_target;
      renderVisualContext();
      await loadWorkspace();
    } catch (error) {
      pending.remove();
      showError(error.message);
      await loadWorkspace();
    } finally {
      sending = false;
      input.disabled = false;
      resizeInput();
      input.focus();
      thread.scrollTo({ top: thread.scrollHeight, behavior: 'smooth' });
    }
  };

  launcher.addEventListener('click', () => setOpen(true));
  root.querySelector('[data-assistant-close]').addEventListener('click', () => setOpen(false));
  focusButton.addEventListener('click', () => setFocus(!root.classList.contains('is-focus')));
  historyToggle.addEventListener('click', () => {
    if (window.matchMedia('(max-width: 959px)').matches) root.classList.add('is-sidebar-open');
    else setHistoryHidden(!root.classList.contains('is-history-hidden'));
  });
  root.querySelector('[data-assistant-new]').addEventListener('click', startNewConversation);
  root.querySelector('[data-assistant-sidebar-close]').addEventListener('click', () => root.classList.remove('is-sidebar-open'));

  const safeText = (value, limit) => String(value || '').replace(/\s+/g, ' ').trim().slice(0, limit);

  const describeTarget = (node) => {
    const rect = node.getBoundingClientRect();
    const styles = window.getComputedStyle(node);
    const tag = node.tagName.toLowerCase();
    let text = '';
    if (node.matches('input,textarea,select')) {
      const label = node.id ? document.querySelector(`label[for="${CSS.escape(node.id)}"]`) : node.closest('label');
      text = safeText(label?.innerText || node.getAttribute('placeholder') || '', 500);
    } else if (node.matches('img')) {
      text = safeText(node.getAttribute('alt') || '', 500);
    } else {
      text = safeText(node.innerText || node.textContent || '', 500);
    }
    const container = node.closest('form,section,article,main,[role="dialog"],[role="region"]');
    const heading = container?.querySelector('h1,h2,h3,legend') || null;
    return {
      tag,
      role: safeText(node.getAttribute('role') || '', 40),
      type: safeText(node.getAttribute('type') || '', 40),
      text,
      aria_label: safeText(node.getAttribute('aria-label') || '', 300),
      title: safeText(node.getAttribute('title') || '', 300),
      element_id: safeText(node.id || '', 120),
      nearby_text: safeText(heading?.innerText || '', 600),
      bounds: {
        x: Math.round(rect.x), y: Math.round(rect.y), width: Math.round(rect.width), height: Math.round(rect.height),
        viewport_width: window.innerWidth, viewport_height: window.innerHeight,
      },
      styles: {
        background_color: styles.backgroundColor,
        color: styles.color,
        font_size: styles.fontSize,
        font_weight: styles.fontWeight,
        padding: styles.padding,
        border_radius: styles.borderRadius,
        border: styles.border,
      },
    };
  };

  const onTargetHover = (event) => {
    const node = event.target instanceof Element ? event.target : null;
    if (!node || root.contains(node)) return;
    hoveredTarget?.classList.remove('faithful-assistant-target-hover');
    hoveredTarget = node;
    hoveredTarget.classList.add('faithful-assistant-target-hover');
  };

  const finishTargetPicking = (keepSelection, node = null) => {
    document.removeEventListener('pointerover', onTargetHover, true);
    document.removeEventListener('click', onTargetClick, true);
    hoveredTarget?.classList.remove('faithful-assistant-target-hover');
    hoveredTarget = null;
    root.classList.remove('is-picking-target');
    document.documentElement.classList.remove('faithful-assistant-picking');
    targetBanner.setAttribute('aria-hidden', 'true');
    if (keepSelection && node) {
      selectedTarget = describeTarget(node);
      context.ui_target = selectedTarget;
      renderVisualContext();
    }
    setTimeout(() => input.focus(), 60);
  };

  const onTargetClick = (event) => {
    const node = event.target instanceof Element ? event.target : null;
    if (!node || root.contains(node)) return;
    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation();
    finishTargetPicking(true, node);
  };

  const startTargetPicking = () => {
    if (sending) return;
    showError('');
    root.classList.add('is-picking-target');
    document.documentElement.classList.add('faithful-assistant-picking');
    targetBanner.setAttribute('aria-hidden', 'false');
    window.setTimeout(() => {
      document.addEventListener('pointerover', onTargetHover, true);
      document.addEventListener('click', onTargetClick, true);
    }, 0);
  };

  const resetCropSelection = () => {
    cropStart = null;
    cropBounds = null;
    cropSelection.hidden = true;
    cropConfirm.disabled = true;
  };

  const fitCropStage = () => {
    if (!cropImage.naturalWidth || !cropImage.naturalHeight || cropper.hidden) return;
    const availableWidth = Math.max(1, cropViewport.clientWidth - 36);
    const availableHeight = Math.max(1, cropViewport.clientHeight - 36);
    const scale = Math.min(1, availableWidth / cropImage.naturalWidth, availableHeight / cropImage.naturalHeight);
    cropStage.style.width = `${Math.max(1, Math.round(cropImage.naturalWidth * scale))}px`;
    cropStage.style.height = `${Math.max(1, Math.round(cropImage.naturalHeight * scale))}px`;
  };

  const openCropper = (dataUrl) => {
    pendingScreenCapture = dataUrl;
    resetCropSelection();
    cropper.hidden = false;
    cropper.setAttribute('aria-hidden', 'false');
    cropImage.src = dataUrl;
    if (cropImage.complete) fitCropStage();
    window.setTimeout(() => cropCancel.focus(), 0);
  };

  const closeCropper = () => {
    cropper.hidden = true;
    cropper.setAttribute('aria-hidden', 'true');
    pendingScreenCapture = null;
    cropImage.removeAttribute('src');
    cropStage.style.removeProperty('width');
    cropStage.style.removeProperty('height');
    resetCropSelection();
    window.setTimeout(() => input.focus(), 0);
  };

  const cropPoint = (event) => {
    const rect = cropStage.getBoundingClientRect();
    return {
      x: Math.max(0, Math.min(rect.width, event.clientX - rect.left)),
      y: Math.max(0, Math.min(rect.height, event.clientY - rect.top)),
    };
  };

  const renderCropSelection = () => {
    if (!cropBounds) {
      resetCropSelection();
      return;
    }
    cropSelection.hidden = false;
    cropSelection.style.left = `${cropBounds.x}px`;
    cropSelection.style.top = `${cropBounds.y}px`;
    cropSelection.style.width = `${cropBounds.width}px`;
    cropSelection.style.height = `${cropBounds.height}px`;
    cropConfirm.disabled = cropBounds.width < 12 || cropBounds.height < 12;
  };

  const beginCropSelection = (event) => {
    if (event.button !== 0 || !pendingScreenCapture) return;
    const point = cropPoint(event);
    cropStart = { ...point, pointerId: event.pointerId };
    cropBounds = { x: point.x, y: point.y, width: 0, height: 0 };
    cropStage.setPointerCapture(event.pointerId);
    renderCropSelection();
    event.preventDefault();
  };

  const moveCropSelection = (event) => {
    if (!cropStart || event.pointerId !== cropStart.pointerId) return;
    const point = cropPoint(event);
    cropBounds = {
      x: Math.min(cropStart.x, point.x),
      y: Math.min(cropStart.y, point.y),
      width: Math.abs(point.x - cropStart.x),
      height: Math.abs(point.y - cropStart.y),
    };
    renderCropSelection();
    event.preventDefault();
  };

  const endCropSelection = (event) => {
    if (!cropStart || event.pointerId !== cropStart.pointerId) return;
    moveCropSelection(event);
    if (cropStage.hasPointerCapture(event.pointerId)) cropStage.releasePointerCapture(event.pointerId);
    cropStart = null;
  };

  const useScreenCapture = (dataUrl, label = 'Pantalla incluida') => {
    screenCapture = dataUrl;
    screenCaptureLabel = label;
    closeCropper();
    renderVisualContext();
    resizeInput();
  };

  const readImageFile = (file) => new Promise((resolve, reject) => {
    if (!(file instanceof Blob) || !String(file.type || '').startsWith('image/')) {
      reject(new Error('The clipboard does not contain a compatible image.'));
      return;
    }
    if (file.size > 15 * 1024 * 1024) {
      reject(new Error('The image exceeds the 15 MB limit.'));
      return;
    }
    const objectUrl = URL.createObjectURL(file);
    const image = new Image();
    image.onload = () => {
      URL.revokeObjectURL(objectUrl);
      resolve(image);
    };
    image.onerror = () => {
      URL.revokeObjectURL(objectUrl);
      reject(new Error('The image could not be read. Use PNG, JPG, or WEBP.'));
    };
    image.src = objectUrl;
  });

  const normalizeImageFile = async (file) => {
    const source = await readImageFile(file);
    if (!source.naturalWidth || !source.naturalHeight) throw new Error('The image is empty or damaged.');

    let scale = Math.min(1, 1920 / source.naturalWidth, 1200 / source.naturalHeight);
    let quality = .86;
    for (let attempt = 0; attempt < 8; attempt += 1) {
      const canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(source.naturalWidth * scale));
      canvas.height = Math.max(1, Math.round(source.naturalHeight * scale));
      const canvasContext = canvas.getContext('2d', { alpha: false });
      if (!canvasContext) throw new Error('El navegador no pudo preparar la captura.');
      canvasContext.fillStyle = '#fff';
      canvasContext.fillRect(0, 0, canvas.width, canvas.height);
      canvasContext.drawImage(source, 0, 0, canvas.width, canvas.height);
      const dataUrl = canvas.toDataURL('image/jpeg', quality);
      if (dataUrl.length <= 1950000) return dataUrl;
      if (quality > .58) quality -= .1;
      else scale *= .82;
    }
    throw new Error('The screenshot could not be reduced to the allowed size.');
  };

  const acceptImageFile = async (file, label) => {
    if (!file || sending || imageProcessing) return;
    imageProcessing = true;
    attachImageButton.disabled = true;
    showError('');
    resizeInput();
    try {
      screenCapture = await normalizeImageFile(file);
      screenCaptureLabel = label;
      renderVisualContext();
    } catch (error) {
      showError(error.message || 'The screenshot could not be attached.');
    } finally {
      imageProcessing = false;
      attachImageButton.disabled = false;
      resizeInput();
      input.focus();
    }
  };

  const imageFromClipboard = (clipboardData) => {
    const files = Array.from(clipboardData?.files || []);
    const directFile = files.find((file) => String(file.type || '').startsWith('image/'));
    if (directFile) return directFile;
    const item = Array.from(clipboardData?.items || []).find((candidate) => candidate.kind === 'file' && String(candidate.type || '').startsWith('image/'));
    return item?.getAsFile() || null;
  };

  const pasteImage = (event) => {
    const file = imageFromClipboard(event.clipboardData);
    if (!file) return;
    event.preventDefault();
    acceptImageFile(file, 'Captura pegada');
  };

  const confirmCropSelection = () => {
    if (!pendingScreenCapture || !cropBounds || cropConfirm.disabled) return;
    const rect = cropStage.getBoundingClientRect();
    if (!rect.width || !rect.height) return;
    const scaleX = cropImage.naturalWidth / rect.width;
    const scaleY = cropImage.naturalHeight / rect.height;
    const sourceX = Math.max(0, Math.floor(cropBounds.x * scaleX));
    const sourceY = Math.max(0, Math.floor(cropBounds.y * scaleY));
    const sourceWidth = Math.min(cropImage.naturalWidth - sourceX, Math.max(1, Math.ceil(cropBounds.width * scaleX)));
    const sourceHeight = Math.min(cropImage.naturalHeight - sourceY, Math.max(1, Math.ceil(cropBounds.height * scaleY)));
    const canvas = document.createElement('canvas');
    canvas.width = sourceWidth;
    canvas.height = sourceHeight;
    const canvasContext = canvas.getContext('2d', { alpha: false });
    if (!canvasContext) {
      showError('The selected crop could not be prepared.');
      closeCropper();
      return;
    }
    canvasContext.drawImage(cropImage, sourceX, sourceY, sourceWidth, sourceHeight, 0, 0, sourceWidth, sourceHeight);
    useScreenCapture(canvas.toDataURL('image/jpeg', .78));
  };

  const captureCurrentScreen = async () => {
    if (sending) return;
    showError('');
    if (!navigator.mediaDevices?.getDisplayMedia) {
      showError('This browser cannot include the screen. You can point to an element or use a compatible browser.');
      return;
    }
    captureButton.disabled = true;
    let stream = null;
    let capturedScreen = null;
    try {
      // Hide the complete assistant before the browser builds its sharing preview.
      // Reading layout applies the class without delaying getDisplayMedia and losing
      // the user activation required by the permission prompt.
      root.classList.add('is-capturing-screen');
      void root.offsetHeight;
      stream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false, preferCurrentTab: true });
      const video = document.createElement('video');
      video.muted = true;
      video.playsInline = true;
      video.srcObject = stream;
      await video.play();
      await new Promise((resolve) => window.setTimeout(resolve, 400));
      const sourceWidth = video.videoWidth;
      const sourceHeight = video.videoHeight;
      if (!sourceWidth || !sourceHeight) throw new Error('The selected screen could not be read.');
      const scale = Math.min(1, 1440 / sourceWidth, 900 / sourceHeight);
      const canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(sourceWidth * scale));
      canvas.height = Math.max(1, Math.round(sourceHeight * scale));
      const canvasContext = canvas.getContext('2d', { alpha: false });
      if (!canvasContext) throw new Error('The screenshot could not be prepared.');
      canvasContext.drawImage(video, 0, 0, canvas.width, canvas.height);
      capturedScreen = canvas.toDataURL('image/jpeg', .72);
    } catch (error) {
      if (error?.name !== 'NotAllowedError') showError(error.message || 'The screen could not be included.');
    } finally {
      stream?.getTracks().forEach((track) => track.stop());
      root.classList.remove('is-capturing-screen');
      captureButton.disabled = false;
      if (!capturedScreen) input.focus();
    }
    if (capturedScreen) openCropper(capturedScreen);
  };

  targetButton.addEventListener('click', startTargetPicking);
  attachImageButton.addEventListener('click', () => {
    if (!sending && !imageProcessing) imageInput.click();
  });
  imageInput.addEventListener('change', () => {
    const file = Array.from(imageInput.files || []).find((candidate) => String(candidate.type || '').startsWith('image/'));
    imageInput.value = '';
    if (file) acceptImageFile(file, file.name || 'Attached image');
  });
  captureButton.addEventListener('click', captureCurrentScreen);
  root.querySelector('[data-assistant-target-cancel]').addEventListener('click', () => finishTargetPicking(false));
  cropImage.addEventListener('load', fitCropStage);
  cropStage.addEventListener('pointerdown', beginCropSelection);
  cropStage.addEventListener('pointermove', moveCropSelection);
  cropStage.addEventListener('pointerup', endCropSelection);
  cropStage.addEventListener('pointercancel', endCropSelection);
  cropConfirm.addEventListener('click', confirmCropSelection);
  cropFull.addEventListener('click', () => {
    if (pendingScreenCapture) useScreenCapture(pendingScreenCapture);
  });
  cropCancel.addEventListener('click', closeCropper);
  window.addEventListener('resize', () => {
    if (cropper.hidden) return;
    resetCropSelection();
    fitCropStage();
  });

  input.addEventListener('input', resizeInput);
  input.addEventListener('paste', pasteImage);
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
      event.preventDefault();
      form.requestSubmit();
    }
  });
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const value = input.value.trim() || (screenCapture ? 'Help me with this screenshot.' : '');
    if (!value || sending) return;
    input.value = '';
    resizeInput();
    send(value);
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (!cropper.hidden) {
      closeCropper();
      return;
    }
    if (!root.classList.contains('is-open')) return;
    if (root.classList.contains('is-picking-target')) finishTargetPicking(false);
    else if (root.classList.contains('is-sidebar-open')) root.classList.remove('is-sidebar-open');
    else setOpen(false);
  });

  resizeInput();
  emptyState();
})();
