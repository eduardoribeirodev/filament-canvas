// resources/js/snapping.js
function elementBounds(data) {
  const width = Number(data.largura_pt), height = Number(data.altura_pt), angle = Number(data.rotacao_graus || 0) * Math.PI / 180;
  const cx = Number(data.x_pt) + width / 2, cy = Number(data.y_pt) + height / 2;
  const halfWidth = (Math.abs(width * Math.cos(angle)) + Math.abs(height * Math.sin(angle))) / 2;
  const halfHeight = (Math.abs(width * Math.sin(angle)) + Math.abs(height * Math.cos(angle))) / 2;
  return { x: [cx - halfWidth, cx, cx + halfWidth], y: [cy - halfHeight, cy, cy + halfHeight] };
}
function targets(blocks, key, width, height) {
  const result = { x: [0, width / 2, width], y: [0, height / 2, height] };
  for (const block of blocks) {
    if (block._key === key || block.data.visivel === false) continue;
    const bounds = elementBounds(block.data);
    result.x.push(...bounds.x);
    result.y.push(...bounds.y);
  }
  return result;
}
function nearest(anchors, lines, tolerance) {
  let best = null;
  for (const position of lines) for (const anchor of anchors) {
    const delta = position - anchor;
    if (Math.abs(delta) <= tolerance && (!best || Math.abs(delta) < Math.abs(best.delta))) best = { position, delta };
  }
  return best;
}
function alignMove(data, blocks, key, width, height, zoom, tolerance = 6) {
  const bounds = elementBounds(data), lines = targets(blocks, key, width, height), radius = tolerance / zoom;
  const x = nearest(bounds.x, lines.x, radius), y = nearest(bounds.y, lines.y, radius);
  return { geometry: { x_pt: Number(data.x_pt) + (x?.delta || 0), y_pt: Number(data.y_pt) + (y?.delta || 0) }, guides: [...x ? [{ axis: "x", position: x.position }] : [], ...y ? [{ axis: "y", position: y.position }] : []] };
}
function alignResize(data, original, corner, modifiers, blocks, key, width, height, zoom, tolerance = 6) {
  if (Math.abs(Number(original.rotacao_graus || 0) % 360) > 1e-5) return { geometry: data, guides: [] };
  const west = corner.includes("w"), north = corner.includes("n"), lines = targets(blocks, key, width, height), radius = tolerance / zoom;
  const x = nearest([Number(data.x_pt) + (west ? 0 : Number(data.largura_pt))], lines.x, radius);
  const y = nearest([Number(data.y_pt) + (north ? 0 : Number(data.altura_pt))], lines.y, radius);
  const factor = modifiers.altKey ? 2 : 1;
  let w = Number(data.largura_pt), h = Number(data.altura_pt), matchX = x, matchY = y;
  if (modifiers.shiftKey && x && y) {
    if (Math.abs(x.delta) <= Math.abs(y.delta)) matchY = null;
    else matchX = null;
  }
  if (matchX) {
    w += (west ? -1 : 1) * matchX.delta * factor;
    if (modifiers.shiftKey) h = w * Number(original.altura_pt) / Number(original.largura_pt);
  }
  if (matchY) {
    h += (north ? -1 : 1) * matchY.delta * factor;
    if (modifiers.shiftKey) w = h * Number(original.largura_pt) / Number(original.altura_pt);
  }
  const geometry = {
    ...data,
    largura_pt: w,
    altura_pt: h,
    x_pt: modifiers.altKey ? Number(original.x_pt) + (Number(original.largura_pt) - w) / 2 : west ? Number(original.x_pt) + Number(original.largura_pt) - w : Number(original.x_pt),
    y_pt: modifiers.altKey ? Number(original.y_pt) + (Number(original.altura_pt) - h) / 2 : north ? Number(original.y_pt) + Number(original.altura_pt) - h : Number(original.y_pt)
  };
  if (w < 1 || h < 1 || geometry.x_pt < 0 || geometry.y_pt < 0 || geometry.x_pt + w > width || geometry.y_pt + h > height) return { geometry: data, guides: [] };
  const guides = [];
  if (x && Math.abs(geometry.x_pt + (west ? 0 : w) - x.position) < 1e-5) guides.push({ axis: "x", position: x.position });
  if (y && Math.abs(geometry.y_pt + (north ? 0 : h) - y.position) < 1e-5) guides.push({ axis: "y", position: y.position });
  return { geometry, guides };
}

// resources/js/canvas.js
var escapeHtml = (value) => String(value ?? "").replace(/[&<>"']/g, (character) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[character]);
var richColor = (value) => {
  const color = String(value ?? "");
  if (/^#(?:[a-f\d]{3}|[a-f\d]{6}|[a-f\d]{8})$/i.test(color)) return color;
  const rgb = color.match(/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/i);
  return rgb && rgb.slice(1).every((channel) => Number(channel) <= 255) ? "#" + rgb.slice(1).map((channel) => Number(channel).toString(16).padStart(2, "0")).join("") : "";
};
var richLink = (value) => /^(?:https?:|mailto:|tel:|#)/i.test(String(value ?? "").trim()) ? String(value).trim() : "";
var cssRichColor = (value) => richColor(value) || (/^oklch\(\s*[\d.]+%?\s+[\d.]+\s+[\d.]+(?:\s*\/\s*[\d.]+%?)?\s*\)$/i.test(String(value ?? "")) ? String(value).trim() : "");
function renderRichDocument(document, textColors = {}) {
  let visited = 0;
  const render = (node, depth = 0) => {
    if (!node || typeof node !== "object" || depth > 20 || ++visited > 2e3) return "";
    if (node.type === "text") {
      let html = escapeHtml(String(node.text ?? "").slice(0, 2e4));
      for (const mark of Array.isArray(node.marks) ? node.marks : []) {
        const tag2 = { bold: "strong", italic: "em", underline: "u", strike: "s", code: "code", subscript: "sub", superscript: "sup" }[mark.type];
        if (tag2) html = `<${tag2}>${html}</${tag2}>`;
        if (mark.type === "link") {
          const href = richLink(mark.attrs?.href);
          if (href) html = `<a href="${escapeHtml(href)}" rel="noopener noreferrer">${html}</a>`;
        }
        if (["textColor", "textStyle"].includes(mark.type)) {
          const name = mark.attrs?.["data-color"] ?? mark.attrs?.color;
          const color = cssRichColor(textColors[name]?.color ?? name);
          if (color) html = `<span style="color:${color}" data-color="${escapeHtml(name)}">${html}</span>`;
        }
        if (mark.type === "highlight") {
          const color = richColor(mark.attrs?.color) || "#ffff00";
          html = `<mark style="background-color:${color}" data-color="${color}">${html}</mark>`;
        }
      }
      return html;
    }
    if (node.type === "hardBreak") return "<br>";
    if (node.type === "horizontalRule") return "<hr>";
    const children = (Array.isArray(node.content) ? node.content : []).map((child) => render(child, depth + 1)).join("");
    if (node.type === "doc") return children;
    const tag = { paragraph: "p", heading: `h${Math.max(1, Math.min(6, Number(node.attrs?.level) || 2))}`, blockquote: "blockquote", bulletList: "ul", orderedList: "ol", listItem: "li", codeBlock: "pre" }[node.type];
    if (!tag) return "";
    const align = { start: "left", end: "right", left: "left", right: "right", center: "center", justify: "justify" }[node.attrs?.textAlign];
    const attributes = (["paragraph", "heading"].includes(node.type) && align ? ` style="text-align:${align}"` : "") + (node.type === "orderedList" ? ` start="${Math.max(1, Math.trunc(Number(node.attrs?.start) || 1))}"` : "");
    return `<${tag}${attributes}>${node.type === "codeBlock" ? `<code>${children}</code>` : children || (node.type === "paragraph" ? "<br>" : "")}</${tag}>`;
  };
  return render(document);
}
function plainRichDocument(document) {
  let visited = 0;
  const render = (node, depth = 0) => {
    if (!node || depth > 20 || ++visited > 2e3) return "";
    if (node.type === "text") return String(node.text ?? "");
    if (node.type === "hardBreak") return "\n";
    const children = (node.content || []).map((child) => render(child, depth + 1)).join("");
    return children + (["paragraph", "heading", "listItem", "blockquote", "codeBlock"].includes(node.type) ? "\n" : "");
  };
  return render(document).replace(/\n+$/, "");
}
function sanitizeRichHtml(html, ownerDocument = globalThis.document) {
  if (!ownerDocument?.createElement) return escapeHtml(String(html ?? ""));
  const template = ownerDocument.createElement("template");
  template.innerHTML = String(html ?? "").slice(0, 2e5);
  let visited = 0;
  const clean = (node, depth = 0) => {
    if (depth > 20 || ++visited > 2e3) return "";
    if (node.nodeType === 3) return escapeHtml(node.textContent);
    if (node.nodeType !== 1) return "";
    const tag = node.tagName.toLowerCase();
    if (["script", "style", "iframe", "object", "embed", "svg", "math", "img", "video", "audio", "input", "button", "textarea", "select"].includes(tag)) return "";
    const children = [...node.childNodes].map((child) => clean(child, depth + 1)).join("");
    const normalizedTag = { b: "strong", i: "em", del: "s", strike: "s", div: "p" }[tag] || tag;
    if (!["p", "br", "strong", "em", "u", "s", "code", "pre", "sub", "sup", "a", "span", "mark", "h1", "h2", "h3", "h4", "h5", "h6", "ul", "ol", "li", "blockquote", "hr"].includes(normalizedTag)) return children;
    if (["br", "hr"].includes(normalizedTag)) return `<${normalizedTag}>`;
    let attributes = "";
    if (normalizedTag === "a") {
      const href = richLink(node.getAttribute("href"));
      if (!href) return children;
      attributes = ` href="${escapeHtml(href)}" rel="noopener noreferrer"`;
    }
    const color = cssRichColor(node.style?.color || node.style?.getPropertyValue("--color") || node.dataset?.color);
    const background = richColor(node.style?.backgroundColor || node.dataset?.color);
    const align = { left: "left", center: "center", right: "right", justify: "justify", start: "left", end: "right" }[node.style?.textAlign];
    const styles = [];
    if (normalizedTag === "span" && color) styles.push(`color:${color}`);
    if (normalizedTag === "mark") styles.push(`background-color:${background || "#ffff00"}`);
    if ((normalizedTag === "p" || /^h[1-6]$/.test(normalizedTag)) && align) styles.push(`text-align:${align}`);
    if (styles.length) attributes += ` style="${styles.join(";")}"`;
    if (normalizedTag === "span" && /^[a-z\-]+$|^#[a-f\d]{3,8}$/i.test(node.dataset?.color || "")) attributes += ` data-color="${escapeHtml(node.dataset.color)}"`;
    if (normalizedTag === "ol") attributes += ` start="${Math.max(1, Math.trunc(Number(node.getAttribute("start")) || 1))}"`;
    return `<${normalizedTag}${attributes}>${children}</${normalizedTag}>`;
  };
  return [...template.content.childNodes].map((node) => clean(node)).join("");
}
function richDocumentSignature(document) {
  return JSON.stringify(document, (key, value) => {
    if (value === null || typeof value !== "object" || Array.isArray(value)) return value;
    const entries = Object.entries(value).filter(([property, item]) => key !== "attrs" || item !== null && !(property === "textAlign" && ["start", "left"].includes(item)));
    if (key === "attrs" && entries.length === 0) return void 0;
    return Object.fromEntries(entries.sort(([left], [right]) => left.localeCompare(right)));
  });
}
function plainDocument(text) {
  return { type: "doc", content: String(text ?? "").split("\n").map((line) => ({ type: "paragraph", content: line ? [{ type: "text", text: line }] : [] })) };
}
function canonicalElementData(data = {}) {
  const dados = data.dados && typeof data.dados === "object" && !Array.isArray(data.dados) ? { ...data.dados } : { ...data.configuracoes || {}, ...data.conteudo || {}, ...Object.fromEntries(Object.entries(data.estilo || {}).filter(([, value]) => value !== null && value !== "")) };
  const envelope = { ...data, dados };
  delete envelope.configuracoes;
  delete envelope.conteudo;
  delete envelope.estilo;
  return envelope;
}
function createCanvas(initial = [], assets = [], pageWidth = 595.28, pageHeight = 841.89, previewHtml = "", options = {}) {
  const state = {
    blocks: JSON.parse(JSON.stringify(initial)),
    editorUi: {},
    assets,
    pageWidth: Number(pageWidth),
    pageHeight: Number(pageHeight),
    previewHtml,
    selected: null,
    cropIndex: null,
    panel: "properties",
    zoom: 1,
    grid: true,
    snapPixels: 5,
    alignmentSnap: true,
    snapGuides: [],
    viewport: { x: 32, y: 32, width: 0, height: 0 },
    editorFitZoom: null,
    editorViewportSize: null,
    disposeEditorViewport: null,
    disposeZoomSlider: null,
    dirty: false,
    saving: false,
    imageUploading: false,
    error: "",
    undoStack: [],
    redoStack: [],
    gesture: null,
    labels: { title: "T\xEDtulo", text: "Texto", asset: "Imagem", image: "Imagem", photo: "Foto", drawing: "Desenho", table: "Tabela", "data-table": "Tabela", ...options.labels || {} },
    imageTypes: options.imageTypes || [.../* @__PURE__ */ new Set(["asset", "image", "photo", ...Object.entries(options.elementTypes || {}).filter(([, element]) => element.kind === "image").map(([type]) => type)])],
    elementTypes: options.elementTypes || {},
    previewReady: false,
    previewLoaded: false,
    previewNodes: /* @__PURE__ */ new Map(),
    previewSignatures: /* @__PURE__ */ new Map(),
    previewFrame: null,
    nextKey: 1,
    nativePinch: null,
    destroyed: false,
    disposeWatch: null,
    disposeSelectionWatch: null,
    textEditing: null,
    inlineMountTimer: null,
    inlineFocusTimer: null,
    richEditorSignature: null,
    richTextColors: options.richTextColors || {},
    previewFontStyle: null,
    inspectorGeneration: 0,
    inspectorSelection: null,
    inspectorSignature: null,
    inspectorValueSignature: null,
    inspectorRequestRevision: 0,
    inspectorHydrating: false,
    inspectorInvalid: false,
    inspectorApplying: false,
    inspectorTimer: null,
    inspectorPending: null,
    inspectorPromise: null,
    inspectorFields: [],
    inspectorEditRevisions: /* @__PURE__ */ new Map(),
    disposeInspectorWatch: null,
    serverRenders: /* @__PURE__ */ new Map(),
    serverRenderTimers: /* @__PURE__ */ new Map(),
    serverRenderRequests: /* @__PURE__ */ new Map(),
    adding: false,
    init() {
      this.prepare();
      this.$nextTick(() => {
        if (this.destroyed) return;
        if (options.fitViewport) this.observeEditorViewport();
        else this.fit();
        this.$refs.preview.srcdoc = this.previewHtml;
      });
      this.disposeWatch = this.$watch("blocks", () => {
        this.schedulePreview();
        this.syncInspector();
      });
      this.disposeSelectionWatch = this.$watch("selected", () => {
        if (this.textEditing && this.current?._key !== this.textEditing.key) this.commitTextEditing();
        this.syncInspector();
      });
      if (this.$wire && this.$watch) this.disposeInspectorWatch = this.$watch("$wire.inspectorData", (values) => this.queueInspectorChanges(values));
      this.syncInspector();
    },
    schedulePreview() {
      if (this.destroyed || this.previewFrame !== null) return;
      if (typeof requestAnimationFrame !== "function") {
        this.syncPreview();
        return;
      }
      this.previewFrame = requestAnimationFrame(() => {
        this.previewFrame = null;
        if (!this.destroyed) this.syncPreview();
      });
    },
    fit() {
      const stage = this.$refs.stage;
      if (!stage) return;
      const zoom = Math.max(0.25, Math.min(1, (stage.clientWidth - 64) / this.pageWidth, (stage.clientHeight - 64) / this.pageHeight));
      this.placeViewport(zoom, Math.max(32, (stage.clientWidth - this.pageWidth * zoom) / 2), 32);
      this.editorFitZoom = this.zoom;
    },
    observeEditorViewport() {
      if (!this.$el || typeof window === "undefined") return;
      this.disposeEditorViewport?.();
      let frame = null;
      const resize = () => {
        if (frame !== null) return;
        frame = requestAnimationFrame(() => {
          frame = null;
          if (!this.destroyed) this.resizeEditorViewport();
        });
      };
      const observer = typeof ResizeObserver === "function" ? new ResizeObserver(resize) : null;
      observer?.observe(this.$el);
      if (this.$refs.stage) observer?.observe(this.$refs.stage);
      window.addEventListener("resize", resize);
      window.visualViewport?.addEventListener("resize", resize);
      this.disposeEditorViewport = () => {
        observer?.disconnect();
        window.removeEventListener("resize", resize);
        window.visualViewport?.removeEventListener("resize", resize);
        if (frame !== null) cancelAnimationFrame(frame);
      };
      this.resizeEditorViewport();
    },
    resizeEditorViewport() {
      const workspace = this.$refs.workspace, stage = this.$refs.stage;
      if (!workspace || !stage || this.destroyed || typeof window === "undefined") return;
      if (this.$el.closest(".canvas-editor-page") && window.matchMedia("(min-width:1024px)").matches) {
        const top = workspace.getBoundingClientRect().top + window.scrollY;
        const height = Math.max(280, Math.floor((window.visualViewport?.height || window.innerHeight) - top - (this.$refs.help?.offsetHeight || 0) - 24));
        workspace.style.setProperty("--fc-workspace-height", height + "px");
      } else workspace.style.removeProperty("--fc-workspace-height");
      const size = stage.clientWidth + ":" + stage.clientHeight;
      if (size === this.editorViewportSize) return;
      this.editorViewportSize = size;
      if (this.editorFitZoom === null || Math.abs(this.zoom - this.editorFitZoom) < 1e-4) this.fit();
    },
    mountZoomSlider(element) {
      const component = globalThis.Alpine?.$data?.(element), slider = component?.slider;
      if (!slider || this.destroyed) return;
      this.disposeZoomSlider?.();
      slider.updateOptions({ animate: false }, false);
      let dragging = false;
      const sync = () => {
        if (dragging || !element.isConnected || this.destroyed) return;
        const percentage = Math.round(this.zoom * 100);
        if (Math.round(Number(slider.get(true))) === percentage) return;
        if (Number(component.state) !== percentage) component.state = percentage;
        else slider.set(percentage);
      };
      const syncDisabled = () => {
        if (this.saving) slider.disable();
        else slider.enable();
      };
      slider.on("start.canvasZoom", () => {
        dragging = true;
      });
      slider.on("slide.canvasZoom", (values, handle, unencoded, isTap) => {
        if (isTap) element.classList.remove("noUi-state-tap");
        if (!this.saving) this.setZoom(Number(values[0]) / 100);
      });
      slider.on("end.canvasZoom", () => {
        dragging = false;
        sync();
      });
      const unwatchZoom = this.$watch("zoom", sync), unwatchSaving = this.$watch("saving", syncDisabled);
      this.disposeZoomSlider = () => {
        slider.off(".canvasZoom");
        unwatchZoom?.();
        unwatchSaving?.();
      };
      sync();
      syncDisabled();
    },
    viewportStyle() {
      return { position: "relative", overflow: "clip", width: Math.max(this.viewport.width, this.viewport.x + this.pageWidth * this.zoom + 32) + "px", height: Math.max(this.viewport.height, this.viewport.y + this.pageHeight * this.zoom + 32) + "px" };
    },
    paperStyle() {
      return { position: "absolute", left: this.viewport.x + "px", top: this.viewport.y + "px", width: this.pageWidth + "px", height: this.pageHeight + "px", transform: `scale(${this.zoom})` };
    },
    placeViewport(zoom, left, top) {
      const stage = this.$refs.stage;
      if (!stage) return;
      const scrollLeft = Math.max(0, 32 - left), scrollTop = Math.max(0, 32 - top);
      this.zoom = zoom;
      this.viewport = {
        x: left + scrollLeft,
        y: top + scrollTop,
        width: Math.max(stage.clientWidth + scrollLeft, left + scrollLeft + this.pageWidth * zoom + 32),
        height: Math.max(stage.clientHeight + scrollTop, top + scrollTop + this.pageHeight * zoom + 32)
      };
      const space = this.$refs.viewport, paper = this.$refs.paper;
      if (space?.style) Object.assign(space.style, this.viewportStyle());
      if (paper?.style) Object.assign(paper.style, this.paperStyle());
      stage.scrollLeft = scrollLeft;
      stage.scrollTop = scrollTop;
      this.positionInlineEditor();
      this.viewport.x = left + stage.scrollLeft;
      this.viewport.y = top + stage.scrollTop;
      if (paper?.style) Object.assign(paper.style, this.paperStyle());
    },
    snap(value) {
      const step = Number(this.snapPixels);
      return this.grid && Number.isFinite(step) && step > 0 ? Math.round(value / step) * step : value;
    },
    endGesture() {
      this.gesture = null;
      this.snapGuides = [];
    },
    selectBlock(index) {
      if (this.textEditing) this.commitTextEditing();
      this.selected = index;
      this.syncInspector();
    },
    hasCapability(block, capability) {
      const capabilities = this.elementTypes[block?.type]?.capabilities;
      if (Array.isArray(capabilities)) return capabilities.includes(capability);
      if (capabilities && typeof capabilities === "object") return Boolean(capabilities[capability]);
      return capability === "text" ? ["title", "text"].includes(block?.type) : capability === "image" ? this.imageTypes.includes(block?.type) : false;
    },
    syncInspector() {
      if (this.destroyed) return;
      this.syncRichEditor();
      const reference = this.$el?.querySelector?.("[data-canvas-reference-select]");
      if (reference) reference.dataset.canvasElementKey = this.current ? String(this.current._key) : "";
      const imageFields = this.$el?.querySelectorAll?.("[data-canvas-image-select]") || [this.$el?.querySelector?.("[data-canvas-image-select]")].filter(Boolean);
      for (const image of imageFields) {
        const component = globalThis.Alpine?.$data?.(image), policy = this.mediaCollection(this.current, image.dataset.canvasMediaCollection);
        const imageBlock = policy ? this.current : null;
        if (component) {
          image.dataset.canvasElementKey = imageBlock ? String(imageBlock._key) : "";
          const id = imageBlock?.data[policy.reference_field] ?? (policy?.max_files > 1 ? [] : null);
          if (JSON.stringify(component.state ?? null) !== JSON.stringify(id ?? null)) {
            image.dataset.canvasHydrating = "true";
            component.state = id;
            this.$nextTick?.(() => delete image.dataset.canvasHydrating);
          }
        }
      }
      const key = this.current ? String(this.current._key) : null;
      const signature = this.current ? JSON.stringify(this.current.data.dados) : null;
      if (this.$wire?.selectInspector && (key !== this.inspectorSelection || !this.inspectorPending && !this.inspectorApplying && !this.inspectorHydrating && signature !== this.inspectorSignature)) {
        this.hydrateInspector();
      }
      options.inspectorSync?.call(this);
    },
    async hydrateInspector() {
      const block = this.current, key = block ? String(block._key) : null;
      const generation = ++this.inspectorGeneration;
      this.inspectorSelection = key;
      this.inspectorRequestRevision = 0;
      this.inspectorSignature = block ? JSON.stringify(block.data.dados) : null;
      this.inspectorPending = null;
      this.inspectorValueSignature = null;
      if (this.inspectorTimer !== null) clearTimeout(this.inspectorTimer);
      this.inspectorTimer = null;
      this.inspectorHydrating = true;
      try {
        const colorFields = this.$el?.querySelectorAll?.("[data-canvas-color-picker]") || [];
        const settleUntil = Math.max(0, ...[...colorFields].map((field) => Number(field.dataset.canvasColorUpdatedAt || 0) + 250));
        const settleFor = settleUntil - Date.now();
        if (settleFor > 0) await new Promise((resolve) => setTimeout(resolve, settleFor));
        if (this.destroyed || generation !== this.inspectorGeneration) return;
        const result = await this.$wire.selectInspector(block?.type ?? null, key, block ? this.serializeBlock(block) : {}, generation);
        if (this.destroyed || generation !== this.inspectorGeneration || key !== (this.current ? String(this.current._key) : null)) return;
        if (result?.stale) return;
        if (result?.normalized) {
          block.data.dados = result.normalized;
          this.inspectorSignature = JSON.stringify(block.data.dados);
        }
        this.inspectorFields = result?.fields || [];
        this.inspectorValueSignature = this.inspectorStateSignature(result?.state ?? this.$wire.inspectorData ?? {});
        this.inspectorInvalid = false;
        this.syncRichEditor();
      } catch {
        if (generation === this.inspectorGeneration) this.error = "N\xE3o foi poss\xEDvel carregar as propriedades do elemento.";
      } finally {
        if (generation === this.inspectorGeneration) {
          this.inspectorHydrating = false;
          this.$nextTick?.(() => {
            this.syncRichEditor();
            if (this.textEditing) this.mountTextEditor();
          });
        }
      }
    },
    inspectorStateSignature(values) {
      const comparison = JSON.parse(JSON.stringify(values ?? {}));
      for (const field of this.inspectorFields) {
        if (!field.rich && field.path !== "rich_text") continue;
        const parts = field.path.split(".");
        let target = comparison;
        for (const part of parts.slice(0, -1)) {
          target = target?.[part];
          if (!target) break;
        }
        const property = parts.at(-1);
        if (target && Object.hasOwn(target, property)) target[property] = richDocumentSignature(target[property]);
      }
      return JSON.stringify(comparison);
    },
    queueInspectorChanges(values) {
      if (this.destroyed || this.saving || this.inspectorHydrating || this.inspectorApplying || !this.current || !this.$wire?.updateInspector) return;
      const key = String(this.current._key), form = this.$el?.querySelector?.("[data-canvas-properties]");
      if (!form || form.dataset.canvasInspectorKey !== key || Number(form.dataset.canvasInspectorGeneration) !== this.inspectorGeneration) return;
      const signature = this.inspectorStateSignature(values);
      if (signature === this.inspectorValueSignature) return;
      this.inspectorValueSignature = signature;
      this.inspectorPending = { key, generation: this.inspectorGeneration, revision: ++this.inspectorRequestRevision, values: JSON.parse(JSON.stringify(values ?? {})) };
      this.inspectorEditRevisions.set(key, { generation: this.inspectorGeneration, revision: this.inspectorRequestRevision });
      this.projectInspectorValues(this.inspectorPending.values);
      if (this.inspectorTimer !== null) clearTimeout(this.inspectorTimer);
      this.inspectorTimer = setTimeout(() => {
        this.inspectorTimer = null;
        this.flushInspector();
      }, 220);
    },
    projectInspectorValues(values) {
      const block = this.current;
      if (!block) return;
      const dados = JSON.parse(JSON.stringify(block.data.dados));
      for (const field of this.inspectorFields) {
        const parts = field.path.split(".");
        let source = values;
        for (const part of parts) {
          if (source === null || source === void 0 || !Object.hasOwn(source, part)) {
            source = void 0;
            break;
          }
          source = source[part];
        }
        if (source === void 0) continue;
        if (field.numeric && (source === null || source === "" || Number.isFinite(Number(source)))) source = source === null || source === "" ? null : field.integer ? Math.trunc(Number(source)) : Number(source);
        if (field.list && source && !Array.isArray(source)) source = Object.values(source);
        if (field.list && field.simple && Array.isArray(source)) source = source.map((row) => row && typeof row === "object" && Object.hasOwn(row, field.simple) ? row[field.simple] : row);
        let target = dados;
        for (const part of parts.slice(0, -1)) {
          if (!target[part] || typeof target[part] !== "object") target[part] = {};
          target = target[part];
        }
        if (source === null && !Object.hasOwn(target, parts.at(-1))) continue;
        target[parts.at(-1)] = JSON.parse(JSON.stringify(source));
      }
      if (JSON.stringify(dados) !== JSON.stringify(block.data.dados)) {
        this.checkpoint();
        block.data.dados = dados;
      }
      this.inspectorSignature = JSON.stringify(block.data.dados);
      this.schedulePreview();
    },
    async flushInspector() {
      if (this.inspectorTimer !== null) clearTimeout(this.inspectorTimer);
      this.inspectorTimer = null;
      const pending = this.inspectorPending;
      if (!pending) {
        if (this.inspectorPromise) await this.inspectorPromise;
        return;
      }
      this.inspectorPending = null;
      const block = this.blocks.find((item) => String(item._key) === pending.key);
      if (!block || String(this.current?._key) !== pending.key || pending.generation !== this.inspectorGeneration) return;
      const payload = this.serializeBlock(block);
      const promise = (async () => {
        try {
          const result = await this.$wire.updateInspector(pending.values, pending.key, pending.generation, pending.revision, payload);
          if (this.destroyed || result?.stale) return;
          this.inspectorInvalid = false;
          this.error = "";
          this.applyInspectorPatch(result, payload);
        } catch {
          if (pending.generation === this.inspectorGeneration) {
            this.inspectorInvalid = true;
            this.error = "Verifique os valores e as mensagens de valida\xE7\xE3o das propriedades.";
          }
        }
      })();
      this.inspectorPromise = promise;
      await promise;
      if (this.inspectorPromise === promise) this.inspectorPromise = null;
    },
    applyInspectorPatch(result, payload) {
      const block = this.blocks.find((item) => String(item._key) === String(result?.key));
      const token = this.inspectorEditRevisions.get(String(result?.key));
      if (!block || !token || result.generation !== token.generation || result.revision !== token.revision || JSON.stringify(block.data.dados) !== JSON.stringify(payload.data.dados)) return;
      const dados = { ...block.data.dados, ...result.patch };
      for (const property of result.removed || []) delete dados[property];
      if (JSON.stringify(dados) !== JSON.stringify(block.data.dados)) block.data.dados = dados;
      this.inspectorApplying = true;
      if (String(this.current?._key) === String(result.key)) this.inspectorSignature = JSON.stringify(block.data.dados);
      const expected = { type: payload.type, data: { ...payload.data, dados: { ...payload.data.dados, ...result.patch } } };
      for (const property of result.removed || []) delete expected.data.dados[property];
      if (this.contentSignature(block) === this.contentSignature(expected)) this.serverRenders.set(block._key, { signature: this.contentSignature(block), html: result.html ?? "" });
      this.schedulePreview();
      const release = () => {
        this.inspectorApplying = false;
      };
      if (this.$nextTick) this.$nextTick(release);
      else release();
    },
    serializeBlock(block) {
      const payload = JSON.parse(JSON.stringify(block));
      delete payload._key;
      return payload;
    },
    scheduleElementRender(block) {
      if (!this.$wire?.renderElement || this.destroyed) return;
      const key = block._key, signature = this.contentSignature(block);
      if (this.serverRenders.get(key)?.signature === signature || this.serverRenderRequests.get(key) === signature) return;
      if (this.serverRenderTimers.has(key)) clearTimeout(this.serverRenderTimers.get(key));
      this.serverRenderTimers.set(key, setTimeout(async () => {
        this.serverRenderTimers.delete(key);
        this.serverRenderRequests.set(key, signature);
        try {
          const html = await this.$wire.renderElement(this.serializeBlock(block));
          const current = this.blocks.find((item) => item._key === key);
          if (!this.destroyed && current && this.contentSignature(current) === signature) {
            this.serverRenders.set(key, { signature, html });
            this.previewSignatures.delete(key);
            this.schedulePreview();
          }
        } catch {
          if (!this.destroyed) this.error = "N\xE3o foi poss\xEDvel atualizar a pr\xE9via do elemento.";
        } finally {
          if (this.serverRenderRequests.get(key) === signature) this.serverRenderRequests.delete(key);
        }
      }, 300));
    },
    applyTextStyle(detail) {
      if (this.saving || !this.hasCapability(this.current, "text") || String(this.current._key) !== String(detail?.key)) return;
      const property = detail?.property;
      let value;
      if (property === "color") {
        value = richColor(detail.value);
        if (!value) return;
      } else if (property === "font_size") {
        value = Number(detail.value);
        if (!Number.isFinite(value) || value < 1 || value > 100) return;
      } else return;
      if (String(this.current.data.dados[property]) === String(value)) return;
      this.checkpoint();
      this.current.data.dados[property] = value;
      this.syncPreview();
    },
    mediaCollections(block) {
      const definitions = this.elementTypes[block?.type]?.media_collections;
      if (definitions) return Object.entries(definitions).map(([name, policy]) => ({ name, reference_field: "imagem_origem", max_files: 1, ...policy }));
      if (this.elementTypes[block?.type]) return [];
      return this.imageTypes.includes(block?.type) ? [{ name: "image", reference_field: "imagem_origem", max_files: 1 }] : [];
    },
    mediaCollection(block, name = null) {
      const policies = this.mediaCollections(block);
      return name ? policies.find((policy) => policy.name === name) : policies[0];
    },
    selectAsset(id, key = this.current?._key, collection = null) {
      if (this.saving || String(this.current?._key) !== String(key)) return;
      const policy = this.mediaCollection(this.current, collection);
      if (!policy) return;
      const ids = Array.isArray(id) ? id : id === null || id === "" ? [] : [id];
      if (ids.length > policy.max_files || new Set(ids.map(String)).size !== ids.length) return;
      const assets2 = ids.map((value2) => this.assets.find((asset) => String(asset.id) === String(value2)));
      if (assets2.some((asset) => !asset)) return;
      const block = this.current, value = policy.max_files > 1 ? assets2.map((asset) => asset.id) : assets2[0]?.id ?? null;
      if (JSON.stringify(block.data[policy.reference_field] ?? null) === JSON.stringify(value)) return;
      this.checkpoint();
      block.data[policy.reference_field] = value;
      if (policy.reference_field === "imagem_origem") block.data.imagem_url = assets2[0] ? this.safeImageUrl(assets2[0].image) : "";
      if (Object.hasOwn(block.data.dados, "image_transform")) block.data.dados.image_transform = null;
      this.inspectorSignature = JSON.stringify(block.data.dados);
      this.cropIndex = null;
      this.syncInspector();
      this.syncPreview();
    },
    mediaImages(block, collection) {
      const policy = this.mediaCollection(block, collection);
      if (!policy) return [];
      const refs = block.data[policy.reference_field], ids = Array.isArray(refs) ? refs : refs === null || refs === void 0 ? [] : [refs];
      return ids.map((id) => this.assets.find((asset) => String(asset.id) === String(id))).filter(Boolean);
    },
    async uploadImage(wire, collection = null) {
      if (this.imageUploading || this.saving) return;
      const key = this.current?._key, policy = this.mediaCollection(this.current, collection);
      if (!policy) return;
      this.imageUploading = true;
      this.error = "";
      try {
        const result = await wire.uploadAsset(policy.name);
        if (this.destroyed) return;
        if (!result?.asset) throw new Error("Media upload did not return an asset.");
        if (Array.isArray(result?.assets)) this.assets = result.assets;
        else if (result?.asset) {
          this.assets = this.assets.filter((asset) => String(asset.id) !== String(result.asset.id));
          this.assets.push(result.asset);
        }
        if (String(this.current?._key) === String(key)) {
          const ids = (result.inserted || [result.asset]).map((asset) => asset.id);
          this.selectAsset(policy.max_files > 1 ? ids : ids[0], key, policy.name);
        }
      } catch {
        this.error = "N\xE3o foi poss\xEDvel enviar a m\xEDdia. Verifique o formato, o tamanho e as mensagens de valida\xE7\xE3o.";
      } finally {
        this.imageUploading = false;
      }
    },
    syncRichEditor() {
      const element = this.$el?.querySelector?.("[data-canvas-rich-editor]");
      const component = element && globalThis.Alpine?.$data?.(element);
      const editor = component?.getEditor?.();
      if (!element || !editor || editor.isDestroyed || this.textEditing) return;
      this.richTextColors = editor.extensionManager?.extensions?.find((extension) => extension.name === "textColor")?.options?.textColors || this.richTextColors;
      const block = this.current, isText = this.hasCapability(block, "text");
      element.dataset.canvasElementKey = isText ? String(block._key) : "";
      if (!isText) {
        this.richEditorSignature = null;
        return;
      }
      const contents = block.data.dados || {};
      const value = contents.rich_text || (contents.html ? sanitizeRichHtml(contents.html) : plainDocument(this.text(block)));
      const signature = JSON.stringify([block._key, value]);
      if (signature === this.richEditorSignature && element.dataset.canvasSyncedKey === String(block._key)) return;
      const previousKey = element.dataset.canvasSyncedKey;
      const editorContent = typeof value === "string" ? editor.getHTML?.() : editor.getJSON?.();
      element.dataset.canvasHydrating = "true";
      if (previousKey !== String(block._key) || JSON.stringify(editorContent) !== JSON.stringify(value)) {
        editor.commands.setContent(value, { emitUpdate: false });
        const state2 = editor.state;
        if (state2?.constructor?.create && editor.view?.updateState) {
          editor.view.updateState(state2.constructor.create({ doc: state2.doc, plugins: state2.plugins }));
        }
      }
      component.shouldUpdateState = false;
      component.state = editor.getJSON?.() || value;
      element.dataset.canvasSyncedKey = String(block._key);
      this.richEditorSignature = signature;
      this.$nextTick?.(() => delete element.dataset.canvasHydrating);
    },
    applyRichText(detail, key = this.current?._key) {
      if (this.saving || this.textEditing || key === null || key === void 0) return;
      if (this.$el?.querySelector?.("[data-canvas-rich-editor]")?.dataset?.canvasHydrating) return;
      const block = this.blocks.find((block2) => String(block2._key) === String(key));
      if (!block || !this.hasCapability(block, "text") || String(this.current?._key) !== String(key)) return;
      const document = detail?.document || detail;
      if (document?.type !== "doc") return;
      const richText = JSON.parse(JSON.stringify(document));
      const html = renderRichDocument(richText, this.richTextColors), text = plainRichDocument(richText);
      const contents = block.data.dados || {};
      if (richDocumentSignature(contents.rich_text) === richDocumentSignature(richText)) return;
      this.checkpoint();
      block.data.dados = { ...contents, rich_text: richText, html, text };
      this.richEditorSignature = JSON.stringify([block._key, richText]);
      this.inspectorSignature = JSON.stringify(block.data.dados);
      this.$wire?.$set?.("inspectorData.rich_text", richText, false);
      this.schedulePreview();
    },
    textHtml(block) {
      if (this.isTextEditing(block)) return this.textEditing.html;
      const contents = block.data.dados || {};
      if (contents.html && globalThis.document?.createElement) return sanitizeRichHtml(contents.html);
      if (contents.rich_text?.type === "doc") return renderRichDocument(contents.rich_text, this.richTextColors);
      if (contents.html) return sanitizeRichHtml(contents.html);
      return escapeHtml(this.text(block)).replace(/\n/g, "<br>");
    },
    isTextEditing(block) {
      return Boolean(block && this.textEditing && String(block._key) === String(this.textEditing.key));
    },
    startTextEditing(event, index) {
      const block = this.blocks[index];
      if (this.saving || !this.hasCapability(block, "text") || this.elementTypes[block?.type]?.capabilities?.inline_text === false) return;
      event?.preventDefault?.();
      event?.stopPropagation?.();
      if (this.isTextEditing(block)) return;
      if (this.textEditing) this.commitTextEditing();
      this.selected = index;
      this.cropIndex = null;
      this.endGesture();
      this.textEditing = { key: block._key, html: this.textHtml(block), document: null, original: JSON.stringify(block.data.dados || {}), element: null };
      this.syncPreview();
      this.$nextTick?.(() => this.mountTextEditor());
    },
    nativeInlineEditor() {
      const owner = this.$el?.closest?.(".fc-editor,.canvas-editor") || this.$el;
      const node = owner?.querySelector?.("[data-canvas-inline-rich-editor]");
      const component = node && globalThis.Alpine?.$data?.(node);
      return { node, component, editor: component?.getEditor?.() };
    },
    inlineEditorStyle() {
      const block = this.blocks.find((item) => String(item._key) === String(this.textEditing?.key));
      const paper = this.$refs?.paper;
      if (!block || !paper) return {};
      const rect = paper.getBoundingClientRect(), d = block.data, c = d.dados || {};
      return { position: "fixed", left: rect.left + Number(d.x_pt) * this.zoom + "px", top: rect.top + Number(d.y_pt) * this.zoom + "px", width: Number(d.largura_pt) * this.zoom + "px", height: Number(d.altura_pt) * this.zoom + "px", fontSize: Number(c.font_size ?? (block.type === "title" ? 24 : 12)) * this.zoom + "px", color: richColor(c.color) || "#111111", zIndex: "40" };
    },
    textEditorHtml(block) {
      return this.isTextEditing(block) ? this.textEditing.html : this.textHtml(block);
    },
    textEditorStyle(block) {
      const c = block.data.dados || {}, previewNode = this.previewNodes.get(block._key);
      const fontFamily = previewNode?.ownerDocument?.defaultView?.getComputedStyle?.(previewNode)?.fontFamily || "Arial,sans-serif";
      return { fontWeight: c.rich_text?.type === "doc" ? "400" : c.bold ?? block.type === "title" ? "700" : "400", fontStyle: c.rich_text?.type === "doc" ? "normal" : c.italic ? "italic" : "normal", fontFamily, lineHeight: String(Math.max(0.5, Math.min(10, Number(c.leading ?? 1.25)))), textAlign: c.rich_text?.type === "doc" ? "left" : c.align ?? "center", columnCount: Math.max(1, Math.min(12, Math.trunc(Number(c.columns ?? 1)))), backgroundColor: richColor(c.background_color) || "transparent", padding: Math.max(0, Math.min(100, Number(c.padding) || 0)) * this.zoom + "px", borderRadius: Math.max(0, Math.min(100, Number(c.border_radius) || 0)) * this.zoom + "px", whiteSpace: "pre-wrap", transform: `rotate(${-Number(block.data.rotacao_graus || 0)}deg)`, transformOrigin: `${Number(block.data.largura_pt) * this.zoom / 2}px ${Number(block.data.altura_pt) * this.zoom / 2}px` };
    },
    positionInlineEditor() {
      if (!this.textEditing) return;
      const block = this.blocks.find((item) => String(item._key) === String(this.textEditing.key));
      if (this.$refs?.inlineTextHost) Object.assign(this.$refs.inlineTextHost.style, this.inlineEditorStyle());
      if (block && this.textEditing.element) Object.assign(this.textEditing.element.style, this.textEditorStyle(block));
    },
    mountTextEditor() {
      if (this.inlineMountTimer !== null) clearTimeout(this.inlineMountTimer);
      this.inlineMountTimer = null;
      const editing = this.textEditing;
      if (!editing || this.destroyed) return;
      const block = this.blocks.find((item) => String(item._key) === String(editing.key));
      const { node, component, editor } = this.nativeInlineEditor();
      if (!block || !editor || editor.isDestroyed || this.inspectorHydrating) {
        this.inlineMountTimer = setTimeout(() => this.mountTextEditor(), 40);
        return;
      }
      if (editing.element === editor.view.dom) {
        this.positionInlineEditor();
        return;
      }
      const contents = block.data.dados || {};
      const productSource = contents.text_source === "product" || !Object.hasOwn(contents, "text_source") && contents.field && contents.text == null;
      const linkedHtml = productSource ? this.previewNodes.get(block._key)?.querySelector(".text-content")?.innerHTML : null;
      const value = contents.rich_text || (contents.html ? sanitizeRichHtml(contents.html) : linkedHtml ? sanitizeRichHtml(linkedHtml) : plainDocument(this.text(block)));
      editing.original = JSON.stringify(contents);
      node.dataset.canvasHydrating = "true";
      node.dataset.canvasElementKey = String(editing.key);
      editor.commands.setContent(value, { emitUpdate: false });
      const state2 = editor.state;
      if (state2?.constructor?.create && editor.view?.updateState) editor.view.updateState(state2.constructor.create({ doc: state2.doc, plugins: state2.plugins }));
      component.shouldUpdateState = false;
      component.state = editor.getJSON();
      editing.document = JSON.parse(JSON.stringify(editor.getJSON()));
      editing.html = editor.getHTML();
      editing.element = editor.view.dom;
      this.positionInlineEditor();
      this.$nextTick?.(() => {
        delete node.dataset.canvasHydrating;
        if (this.textEditing?.key === editing.key && this.textEditing?.element === editor.view.dom) {
          editor.commands.focus("end", { scrollIntoView: false });
        }
      });
    },
    inputText(detail) {
      const editing = this.textEditing;
      if (!editing || String(detail?.key) !== String(editing.key) || detail?.document?.type !== "doc") return;
      editing.document = JSON.parse(JSON.stringify(detail.document));
      editing.html = renderRichDocument(editing.document, this.richTextColors);
    },
    textEditorKey(event) {
      if (!this.textEditing) return;
      event.stopPropagation();
      if (event.key === "Escape") {
        event.preventDefault();
        this.cancelTextEditing();
      }
      if ((event.metaKey || event.ctrlKey) && event.key === "Enter") {
        event.preventDefault();
        this.commitTextEditing();
      }
    },
    commitTextEditing() {
      const editing = this.textEditing;
      if (!editing) return;
      const block = this.blocks.find((item) => String(item._key) === String(editing.key));
      const { node, editor } = this.nativeInlineEditor();
      const document = editor && editing.element === editor.view.dom ? JSON.parse(JSON.stringify(editor.getJSON())) : editing.document;
      this.textEditing = null;
      if (this.inlineMountTimer !== null) clearTimeout(this.inlineMountTimer);
      this.inlineMountTimer = null;
      if (node) node.dataset.canvasElementKey = "";
      if (block && document?.type === "doc") {
        const original = JSON.parse(editing.original), html = renderRichDocument(document, this.richTextColors);
        const previous = original.rich_text?.type === "doc" ? renderRichDocument(original.rich_text, this.richTextColors) : sanitizeRichHtml(original.html || escapeHtml(original.text ?? this.text(block)).replace(/\n/g, "<br>"));
        if (html !== previous) {
          this.checkpoint();
          block.data.dados = this.textCommitData(block, original, { rich_text: document, html, text: plainRichDocument(document) });
        }
      }
      editor?.commands.blur();
      this.syncPreview();
      this.syncInspector();
    },
    textCommitData(block, original, content) {
      const dados = { ...original, ...content };
      if (this.hasCapability(block, "product_text") && (original.text_source === "product" || !Object.hasOwn(original, "text_source") && original.field && original.text == null)) dados.text_source = "manual";
      return dados;
    },
    resumeInlineEditing(detail) {
      if (!this.textEditing || !String(detail?.key ?? "").startsWith("inlineTextForm.")) return;
      const key = this.textEditing.key;
      if (this.inlineFocusTimer !== null) clearTimeout(this.inlineFocusTimer);
      this.inlineFocusTimer = setTimeout(() => {
        this.inlineFocusTimer = null;
        if (!this.destroyed && this.textEditing?.key === key) this.nativeInlineEditor().editor?.commands.focus(null, { scrollIntoView: false });
      }, 350);
    },
    finishInlineEditing(event) {
      if (!this.textEditing || event.target?.closest?.('[data-canvas-inline-editor-host],.fi-modal,[role="dialog"]')) return;
      this.commitTextEditing();
    },
    cancelTextEditing() {
      if (!this.textEditing) return;
      this.textEditing = null;
      if (this.inlineMountTimer !== null) clearTimeout(this.inlineMountTimer);
      this.inlineMountTimer = null;
      const { node, editor } = this.nativeInlineEditor();
      if (node) node.dataset.canvasElementKey = "";
      editor?.commands.blur();
      this.syncPreview();
      this.syncInspector();
    },
    reorderLayers(keys) {
      if (this.saving || !Array.isArray(keys) || keys.length !== this.blocks.length) return;
      const ordered = keys.map((key) => this.blocks.find((block) => String(block._key) === String(key)));
      if (ordered.some((block) => !block) || new Set(ordered).size !== this.blocks.length) return;
      ordered.reverse();
      if (ordered.every((block, index) => block === this.blocks[index])) return;
      this.commitTextEditing();
      const selectedKey = this.current?._key;
      this.checkpoint();
      this.blocks = ordered;
      this.selected = selectedKey === void 0 ? null : this.blocks.findIndex((block) => block._key === selectedKey);
      this.cropIndex = null;
      this.syncPreview();
    },
    restoreSortable(container, keys) {
      if (!container?.querySelectorAll) return;
      const nodes = [...container.querySelectorAll(":scope > [x-sortable-item]")];
      for (const key of keys) {
        const node = nodes.find((node2) => String(node2.getAttribute("x-sortable-item")) === String(key));
        if (node) container.appendChild(node);
      }
    },
    sortLayers(event) {
      const container = event.to || event.target, keys = container?.sortable?.toArray?.();
      if (!keys) return;
      const original = [...this.blocks].reverse().map((block) => block._key);
      queueMicrotask(() => {
        if (this.destroyed) return;
        this.restoreSortable(container, original);
        this.reorderLayers(keys);
      });
    },
    editCrop(e, i) {
      if (this.saving || !this.imageTypes.includes(this.blocks[i].type)) return;
      e.preventDefault();
      e.stopPropagation();
      this.selected = i;
      this.snapGuides = [];
      this.checkpoint();
      const d = this.blocks[i].data;
      d.dados = { ...d.dados || {}, fit: d.dados?.fit || "contain", crop_x: d.dados?.crop_x ?? 0.5, crop_y: d.dados?.crop_y ?? 0.5 };
      if (!d.dados.image_transform) {
        const img = this.previewNodes.get(this.blocks[i]._key)?.querySelector("img") || e.currentTarget?.querySelector("img");
        const iw = img?.naturalWidth || Number(d.largura_pt), ih = img?.naturalHeight || Number(d.altura_pt);
        const scale = (d.dados.fit === "cover" ? Math.max : Math.min)(d.largura_pt / iw, d.altura_pt / ih);
        d.dados.image_transform = { x: (d.largura_pt - iw * scale) * d.dados.crop_x, y: (d.altura_pt - ih * scale) * d.dados.crop_y, width: iw * scale, height: ih * scale, rotation: 0 };
      }
      this.cropIndex = i;
      this.syncPreview();
    },
    imageStyle(b, includeFlip = true) {
      const c = b.data.dados || {}, t = c.image_transform;
      const flip = includeFlip ? ` scale(${c.flip_x ? -1 : 1},${c.flip_y ? -1 : 1})` : "";
      if (t) return { position: "absolute", left: t.x + "px", top: t.y + "px", width: t.width + "px", height: t.height + "px", maxWidth: "none", objectFit: "fill", transform: `rotate(${-t.rotation}deg)${flip}`, transformOrigin: "center" };
      return { objectFit: c.fit === "cover" ? "cover" : "contain", objectPosition: `${(c.crop_x ?? 0.5) * 100}% ${(c.crop_y ?? 0.5) * 100}%`, transform: includeFlip ? `scale(${c.flip_x ? -1 : 1},${c.flip_y ? -1 : 1})` : "" };
    },
    flipImage(axis) {
      if (!this.imageTypes.includes(this.current?.type) || !["x", "y"].includes(axis)) return;
      this.checkpoint();
      const c = this.current.data.dados;
      c["flip_" + axis] = !c["flip_" + axis];
    },
    setFontSize(value) {
      const size = Number(value);
      if (!this.hasCapability(this.current, "text") || !Number.isFinite(size) || size < 1 || size > 100) return;
      this.checkpoint();
      this.current.data.dados.font_size = size;
    },
    resizeGeometry(d, dx, dy, corner = "se", modifiers = {}) {
      const a = -Number(d.rotacao_graus) * Math.PI / 180, sx = corner.includes("w") ? -1 : 1, sy = corner.includes("n") ? -1 : 1;
      const lx = dx * Math.cos(a) + dy * Math.sin(a), ly = -dx * Math.sin(a) + dy * Math.cos(a);
      const snap = (v) => this.snap(v);
      const width = Number(d.largura_pt), height = Number(d.altura_pt), factor = modifiers.altKey ? 2 : 1;
      let w = Math.max(1, snap(width + sx * lx * factor)), h = Math.max(1, snap(height + sy * ly * factor));
      if (modifiers.shiftKey) {
        const scale = Math.max(1 / width, 1 / height, Math.abs(w / width - 1) >= Math.abs(h / height - 1) ? w / width : h / height);
        w = width * scale;
        h = height * scale;
      }
      const dw = w - Number(d.largura_pt), dh = h - Number(d.altura_pt);
      if (modifiers.altKey) return { x_pt: Number(d.x_pt) - dw / 2, y_pt: Number(d.y_pt) - dh / 2, largura_pt: w, altura_pt: h };
      return { x_pt: Number(d.x_pt) + (sx * dw * Math.cos(a) - sy * dh * Math.sin(a) - dw) / 2, y_pt: Number(d.y_pt) + (sx * dw * Math.sin(a) + sy * dh * Math.cos(a) - dh) / 2, largura_pt: w, altura_pt: h };
    },
    key(e) {
      if (this.textEditing) {
        if (e.key === "Escape") {
          e.preventDefault?.();
          e.stopPropagation?.();
          this.cancelTextEditing();
        }
        return;
      }
      if (e.target?.closest?.('input,textarea,select,button,[role="slider"],[contenteditable="true"]') || this.saving) return;
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "z") {
        e.preventDefault();
        e.shiftKey ? this.redo() : this.undo();
        return;
      }
      if (e.key === "Escape") {
        this.cropIndex = null;
        this.endGesture();
        return;
      }
      if ((e.key === "Delete" || e.key === "Backspace") && this.current) {
        e.preventDefault();
        e.stopPropagation();
        this.remove();
        this.cropIndex = null;
      }
    },
    setZoom(value) {
      const stage = this.$refs.stage;
      if (!stage) return;
      const rect = stage.getBoundingClientRect();
      this.zoomAt(Number(value), rect.left + (stage.clientLeft || 0) + stage.clientWidth / 2, rect.top + (stage.clientTop || 0) + stage.clientHeight / 2);
    },
    zoomAt(value, clientX, clientY) {
      const stage = this.$refs.stage, paper = this.$refs.paper;
      if (!stage || !paper || this.gesture || this.saving || this.destroyed) return;
      const next = Math.max(0.25, Math.min(3, Number(value)));
      if (!Number.isFinite(next) || next === this.zoom || !Number.isFinite(clientX) || !Number.isFinite(clientY)) return;
      const rect = stage.getBoundingClientRect();
      const localX = clientX - rect.left - (stage.clientLeft || 0), localY = clientY - rect.top - (stage.clientTop || 0);
      const x = (localX - this.viewport.x + stage.scrollLeft) / this.zoom, y = (localY - this.viewport.y + stage.scrollTop) / this.zoom;
      this.placeViewport(next, localX - x * next, localY - y * next);
    },
    wheel(e) {
      e.preventDefault();
      e.stopPropagation();
      if (this.saving || this.gesture || this.nativePinch) return;
      const stage = this.$refs.stage;
      const unit = e.deltaMode === 1 ? 16 : e.deltaMode === 2 ? stage.clientHeight : 1;
      if (e.ctrlKey) {
        this.zoomAt(this.zoom * Math.exp(-e.deltaY * unit * 0.01), e.clientX, e.clientY);
      } else {
        stage.scrollLeft += (e.shiftKey && !e.deltaX ? e.deltaY : e.deltaX) * unit;
        stage.scrollTop += (e.shiftKey && !e.deltaX ? 0 : e.deltaY) * unit;
      }
    },
    pinchStart(e) {
      e.preventDefault();
      e.stopPropagation();
      if (this.gesture || this.saving) return;
      const r = this.$refs.stage.getBoundingClientRect();
      this.nativePinch = { zoom: this.zoom, x: Number.isFinite(e.clientX) ? e.clientX : r.left + r.width / 2, y: Number.isFinite(e.clientY) ? e.clientY : r.top + r.height / 2 };
    },
    pinchChange(e) {
      e.preventDefault();
      e.stopPropagation();
      if (!this.nativePinch) return;
      this.zoomAt(this.nativePinch.zoom * e.scale, this.nativePinch.x, this.nativePinch.y);
    },
    pinchEnd(e) {
      e.preventDefault();
      e.stopPropagation();
      this.nativePinch = null;
    },
    drawingDefaults() {
      return { x1: 0, y1: 50, x2: 100, y2: 50, stroke: "#E60014", stroke_width: 0.7, pattern: "solid", dash_length: 4.25, gap_length: 4.25 };
    },
    drawingOrientation() {
      const d = this.current?.data.dados?.drawing || {};
      if (Number(d.y1) === Number(d.y2)) return "horizontal";
      if (Number(d.x1) === Number(d.x2)) return "vertical";
      if (Number(d.x1) === 0 && Number(d.x2) === 100 && Number(d.y1) === 0 && Number(d.y2) === 100) return "descending";
      if (Number(d.x1) === 0 && Number(d.x2) === 100 && Number(d.y1) === 100 && Number(d.y2) === 0) return "ascending";
      return "custom";
    },
    setDrawingOrientation(value) {
      const points = { horizontal: { x1: 0, y1: 50, x2: 100, y2: 50 }, vertical: { x1: 50, y1: 0, x2: 50, y2: 100 }, descending: { x1: 0, y1: 0, x2: 100, y2: 100 }, ascending: { x1: 0, y1: 100, x2: 100, y2: 0 } }[value];
      if (this.current?.type !== "drawing" || !points) return;
      this.checkpoint();
      Object.assign(this.current.data.dados.drawing, points);
    },
    get current() {
      return this.selected === null ? null : this.blocks[this.selected];
    },
    snapshot() {
      return JSON.stringify(this.blocks);
    },
    checkpoint(snapshot = this.snapshot()) {
      this.undoStack.push(snapshot);
      if (this.undoStack.length > 60) this.undoStack.shift();
      this.redoStack = [];
      this.dirty = true;
    },
    undo() {
      this.endGesture();
      if (this.textEditing) this.commitTextEditing();
      if (!this.undoStack.length) return;
      this.redoStack.push(this.snapshot());
      this.blocks = JSON.parse(this.undoStack.pop());
      this.prepare();
      this.selected = null;
      this.cropIndex = null;
      this.inspectorInvalid = false;
      this.error = "";
      this.dirty = true;
      this.syncInspector();
    },
    redo() {
      this.endGesture();
      if (this.textEditing) this.commitTextEditing();
      if (!this.redoStack.length) return;
      this.undoStack.push(this.snapshot());
      this.blocks = JSON.parse(this.redoStack.pop());
      this.prepare();
      this.selected = null;
      this.cropIndex = null;
      this.inspectorInvalid = false;
      this.error = "";
      this.dirty = true;
      this.syncInspector();
    },
    normalize(b) {
      const d = b.data;
      d.largura_pt = Math.max(1, Math.min(this.pageWidth, Number(d.largura_pt) || 1));
      d.altura_pt = Math.max(1, Math.min(this.pageHeight, Number(d.altura_pt) || 1));
      d.x_pt = Math.max(0, Math.min(this.pageWidth - d.largura_pt, Number(d.x_pt) || 0));
      d.y_pt = Math.max(0, Math.min(this.pageHeight - d.altura_pt, Number(d.y_pt) || 0));
      d.rotacao_graus = Math.max(-360, Math.min(360, Number(d.rotacao_graus) || 0));
    },
    duplicate() {
      if (this.textEditing) this.commitTextEditing();
      if (!this.current) return;
      this.checkpoint();
      const b = JSON.parse(JSON.stringify(this.current));
      b._key = this.nextKey++;
      delete b.data.id;
      b.data.nome += " (c\xF3pia)";
      b.data.x_pt = Number(b.data.x_pt) + 12;
      b.data.y_pt = Number(b.data.y_pt) + 12;
      this.normalize(b);
      this.blocks.push(b);
      this.selected = this.blocks.length - 1;
    },
    remove() {
      if (this.textEditing) this.commitTextEditing();
      if (!this.current) return;
      this.checkpoint();
      this.blocks.splice(this.selected, 1);
      this.selected = null;
      this.cropIndex = null;
    },
    layer(delta) {
      const i = this.selected, j = i + delta;
      if (i === null || j < 0 || j >= this.blocks.length) return;
      this.checkpoint();
      [this.blocks[i], this.blocks[j]] = [this.blocks[j], this.blocks[i]];
      this.selected = j;
      this.cropIndex = null;
    },
    start(e, i, mode, corner = "se") {
      if (e.button !== 0 || this.nativePinch || this.saving || this.isTextEditing(this.blocks[i]) || e.target?.closest?.("[data-canvas-text-editor]")) return;
      if (this.textEditing) this.commitTextEditing();
      e.preventDefault();
      this.snapGuides = [];
      this.selected = i;
      e.currentTarget.focus?.({ preventScroll: true });
      if (this.cropIndex !== i) this.cropIndex = null;
      if (this.cropIndex === i && mode === "move") mode = "crop";
      const snapshot = this.snapshot();
      const d = this.current.data;
      const r = this.$refs.paper.getBoundingClientRect();
      this.gesture = { mode, corner, snapshot, changed: false, x: e.clientX, y: e.clientY, d: JSON.parse(JSON.stringify(d)), cx: r.left + (Number(d.x_pt) + Number(d.largura_pt) / 2) * this.zoom, cy: r.top + (Number(d.y_pt) + Number(d.altura_pt) / 2) * this.zoom };
      if (this.cropIndex === i) {
        const t = d.dados.image_transform;
        this.gesture.mode = "image-" + (mode === "crop" ? "move" : mode);
        const a = -Number(d.rotacao_graus) * Math.PI / 180;
        const ox = Number(t.x) + Number(t.width) / 2 - Number(d.largura_pt) / 2, oy = Number(t.y) + Number(t.height) / 2 - Number(d.altura_pt) / 2;
        this.gesture.cx += (ox * Math.cos(a) - oy * Math.sin(a)) * this.zoom;
        this.gesture.cy += (ox * Math.sin(a) + oy * Math.cos(a)) * this.zoom;
      }
      this.gesture.angle = Math.atan2(e.clientY - this.gesture.cy, e.clientX - this.gesture.cx);
      e.currentTarget.setPointerCapture(e.pointerId);
    },
    checkpointGesture() {
      const gesture = this.gesture;
      if (!gesture || gesture.changed || JSON.stringify(this.current?.data) === JSON.stringify(gesture.d)) return;
      this.checkpoint(gesture.snapshot ?? this.snapshot());
      gesture.changed = true;
    },
    move(e) {
      if (!this.gesture || !this.current) return;
      const g = this.gesture, d = this.current.data, dx = (e.clientX - g.x) / this.zoom, dy = (e.clientY - g.y) / this.zoom;
      const snap = (v) => this.snap(v);
      this.snapGuides = [];
      if (g.mode.startsWith("image-")) {
        const old = g.d.dados.image_transform, t = d.dados.image_transform;
        const a = -Number(g.d.rotacao_graus) * Math.PI / 180, lx = dx * Math.cos(a) + dy * Math.sin(a), ly = -dx * Math.sin(a) + dy * Math.cos(a);
        if (g.mode === "image-move") {
          t.x = Number(old.x) + lx;
          t.y = Number(old.y) + ly;
        }
        if (g.mode === "image-resize") {
          const next = this.resizeGeometry({ x_pt: old.x, y_pt: old.y, largura_pt: old.width, altura_pt: old.height, rotacao_graus: old.rotation }, lx, ly, g.corner, e);
          Object.assign(t, { x: next.x_pt, y: next.y_pt, width: next.largura_pt, height: next.altura_pt });
        }
        if (g.mode === "image-rotate") {
          t.rotation = Number(old.rotation) - (Math.atan2(e.clientY - g.cy, e.clientX - g.cx) - g.angle) * 180 / Math.PI;
          if (e.shiftKey) t.rotation = Math.round(t.rotation / 15) * 15;
          t.rotation = (t.rotation + 540) % 360 - 180;
        }
        this.checkpointGesture();
        this.syncPreview();
        return;
      }
      if (g.mode === "move") {
        const raw = { ...d, x_pt: Number(g.d.x_pt) + dx, y_pt: Number(g.d.y_pt) + dy };
        d.x_pt = snap(raw.x_pt);
        d.y_pt = snap(raw.y_pt);
        if (this.alignmentSnap && !e.ctrlKey && !e.metaKey) {
          const aligned = alignMove(raw, this.blocks, this.current._key, this.pageWidth, this.pageHeight, this.zoom);
          for (const guide of aligned.guides) {
            d[guide.axis === "x" ? "x_pt" : "y_pt"] = aligned.geometry[guide.axis === "x" ? "x_pt" : "y_pt"];
          }
          this.snapGuides = aligned.guides;
        }
      }
      if (g.mode === "resize") {
        let next = this.resizeGeometry(g.d, dx, dy, g.corner, e);
        if (this.alignmentSnap && !e.ctrlKey && !e.metaKey) {
          const aligned = alignResize(next, g.d, g.corner, e, this.blocks, this.current._key, this.pageWidth, this.pageHeight, this.zoom);
          next = aligned.geometry;
          this.snapGuides = aligned.guides;
        }
        if (next.x_pt >= 0 && next.y_pt >= 0 && next.x_pt + next.largura_pt <= this.pageWidth && next.y_pt + next.altura_pt <= this.pageHeight) Object.assign(d, next);
      }
      if (g.mode === "rotate") {
        d.rotacao_graus = Number(g.d.rotacao_graus) - (Math.atan2(e.clientY - g.cy, e.clientX - g.cx) - g.angle) * 180 / Math.PI;
        if (e.shiftKey) d.rotacao_graus = Math.round(d.rotacao_graus / 15) * 15;
      }
      this.normalize(this.current);
      this.checkpointGesture();
      const bounds = elementBounds(d);
      this.snapGuides = this.snapGuides.filter((guide) => bounds[guide.axis].some((value) => Math.abs(value - guide.position) < 1e-5));
    },
    style(b, i) {
      const d = b.data;
      return { left: d.x_pt + "px", top: d.y_pt + "px", width: d.largura_pt + "px", height: d.altura_pt + "px", transform: `rotate(${-Number(d.rotacao_graus)}deg)`, zIndex: i + 1, opacity: d.visivel === false ? 0.25 : 1, color: d.dados?.color || "#222", fontSize: (d.dados?.font_size || (b.type === "table" ? 8 : 12)) + "px", textAlign: d.dados?.align || "center" };
    },
    onPreviewLoad() {
      if (this.destroyed || !this.$refs.preview?.srcdoc) return;
      const doc = this.$refs.preview.contentDocument, page = doc?.querySelector(".page");
      if (!page) return;
      this.syncPreviewFonts(doc);
      this.previewNodes = /* @__PURE__ */ new Map();
      this.previewSignatures = /* @__PURE__ */ new Map();
      const first = page.querySelector(".block[data-editor-index]");
      this.previewAnchor = doc.createComment("canvas-editor-blocks");
      page.insertBefore(this.previewAnchor, first || page.querySelector(".chrome") || null);
      for (const node of page.querySelectorAll(".block[data-editor-index]")) {
        const block = this.blocks[Number(node.dataset.editorIndex)];
        if (!block) continue;
        this.previewNodes.set(block._key, node);
        this.previewSignatures.set(block._key, this.contentSignature(block));
        this.serverRenders.set(block._key, { signature: this.contentSignature(block), html: node.innerHTML });
      }
      this.previewReady = true;
      this.schedulePreview();
      doc.fonts.ready.then(() => {
        if (!this.destroyed && this.$refs.preview?.contentDocument === doc) this.previewLoaded = true;
      });
    },
    syncPreviewFonts(document) {
      if (!this.$el?.ownerDocument?.createElement) return;
      const fontRules = [];
      const collect = (rules) => {
        for (const rule of rules) {
          if (fontRules.length >= 32) return;
          if (rule.type === 5) fontRules.push(rule.cssText);
          else if (rule.cssRules) collect(rule.cssRules);
        }
      };
      for (const sheet of document.styleSheets || []) {
        try {
          collect(sheet.cssRules);
        } catch {
        }
      }
      if (!fontRules.length) {
        this.previewFontStyle?.remove();
        this.previewFontStyle = null;
        return;
      }
      if (!this.previewFontStyle) {
        this.previewFontStyle = this.$el.ownerDocument.createElement("style");
        this.previewFontStyle.dataset.canvasPreviewFonts = "";
        this.$el.prepend(this.previewFontStyle);
      }
      const css = fontRules.join("\n");
      if (this.previewFontStyle.textContent !== css) this.previewFontStyle.textContent = css;
    },
    escape(value) {
      return String(value ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
    },
    scheduleImage(b, node) {
      if (!this.imageTypes.includes(b.type)) return;
      const img = node.querySelector(".image");
      if (!img) return;
      const c = b.data.dados || {}, t = c.image_transform;
      img.style.cssText = t ? `position:absolute;left:${Number(t.x)}pt;top:${Number(t.y)}pt;width:${Number(t.width)}pt;height:${Number(t.height)}pt;max-width:none;object-fit:fill;transform:rotate(${-Number(t.rotation)}deg) scale(${c.flip_x ? -1 : 1},${c.flip_y ? -1 : 1})` : `object-fit:${c.fit === "cover" ? "cover" : "contain"};object-position:${Number(c.crop_x ?? 0.5) * 100}% ${Number(c.crop_y ?? 0.5) * 100}%;transform:scale(${c.flip_x ? -1 : 1},${c.flip_y ? -1 : 1})`;
    },
    syncPreview() {
      if (this.destroyed || !this.previewReady || !this.previewAnchor?.isConnected) return;
      const doc = this.$refs.preview.contentDocument, visible = [], active = /* @__PURE__ */ new Set();
      this.blocks.forEach((b, index) => {
        const key = b._key;
        active.add(key);
        let node = this.previewNodes.get(key);
        if (!node) {
          node = doc.createElement("div");
          this.previewNodes.set(key, node);
        }
        node.className = "block" + (this.hasCapability(b, "text") ? " text" : "") + (b.data.dados?.index_icon ? " index-icon" : "");
        node.dataset.kind = b.type;
        node.dataset.editorIndex = index;
        const d = b.data, c = d.dados || {}, s = d.dados || {};
        Object.assign(node.style, {
          left: Number(d.x_pt) + "pt",
          top: Number(d.y_pt) + "pt",
          width: Number(d.largura_pt) + "pt",
          height: Number(d.altura_pt) + "pt",
          transform: `rotate(${-Number(d.rotacao_graus || 0)}deg)`,
          fontSize: Number(s.font_size ?? c.font_size ?? (b.type === "title" ? 14 : b.type === "text" ? 9 : 8)) + "pt",
          color: /^#[a-f\d]{3,8}$/i.test(s.color ?? c.color ?? "") ? s.color ?? c.color : "#111111",
          textAlign: c.rich_text?.type === "doc" ? "left" : ["left", "center", "right", "justify"].includes(s.align ?? c.align) ? s.align ?? c.align : "center",
          opacity: String(c.opacity ?? 1),
          mixBlendMode: ["normal", "multiply", "screen"].includes(c.blend_mode) ? c.blend_mode : "normal",
          fontFamily: String(s.font ?? c.font ?? "").startsWith("Helvetica") ? "Arial,sans-serif" : "",
          visibility: this.isTextEditing(b) ? "hidden" : ""
        });
        const signature = this.contentSignature(b);
        if (this.previewSignatures.get(key) !== signature) {
          node.innerHTML = this.renderContent(b) + this.renderLink(b);
          this.previewSignatures.set(key, signature);
        }
        this.scheduleImage(b, node);
        if (d.visivel !== false) visible.push(node);
        else node.remove();
      });
      for (const [key, node] of this.previewNodes) if (!active.has(key)) {
        node.remove();
        this.previewNodes.delete(key);
        this.previewSignatures.delete(key);
      }
      this.previewAnchor.after(...visible);
    },
    renderDrawing(b) {
      const d = b.data.dados?.drawing || {}, w = Math.max(0.1, Number(b.data.largura_pt) || 0.1), h = Math.max(0.1, Number(b.data.altura_pt) || 0.1);
      const number = (key, fallback, min, max) => d[key] !== null && d[key] !== "" && Number.isFinite(Number(d[key])) ? Math.max(min, Math.min(max, Number(d[key]))) : fallback;
      const stroke = /^#(?:[a-f\d]{3}|[a-f\d]{6})$/i.test(d.stroke || "") ? d.stroke : "#E60014";
      const weight = number("stroke_width", 0.7, 0.1, 20), pattern = d.pattern || "solid";
      const dashes = pattern === "dotted" ? `0 ${number("gap_length", 4.25, 0.1, 100)}` : pattern === "dashed" ? `${number("dash_length", 4.25, 0.1, 100)} ${number("gap_length", 4.25, 0.1, 100)}` : "none";
      return `<svg class="drawing" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" aria-hidden="true"><line x1="${number("x1", 0, 0, 100) * w / 100}" y1="${number("y1", 50, 0, 100) * h / 100}" x2="${number("x2", 100, 0, 100) * w / 100}" y2="${number("y2", 50, 0, 100) * h / 100}" stroke="${stroke}" stroke-width="${weight}" stroke-dasharray="${dashes}" stroke-linecap="${pattern === "dotted" ? "round" : "butt"}" fill="none" /></svg>`;
    },
    async save(wire) {
      if (this.saving || this.imageUploading) return;
      if (this.textEditing) this.commitTextEditing();
      await this.flushInspector();
      if (this.inspectorInvalid) return;
      this.saving = true;
      this.error = "";
      this.endGesture();
      try {
        const keys = this.blocks.map((b) => b._key), payload = JSON.parse(this.snapshot());
        for (const b of payload) {
          delete b._key;
          if (b.data.imagem_origem === "") b.data.imagem_origem = null;
        }
        const saved = await wire.salvar(payload);
        if (this.destroyed) return;
        this.blocks = saved.map((b, index) => ({ ...b, _key: keys[index] }));
        this.prepare();
        this.syncPreview();
        this.dirty = false;
        this.undoStack = [];
        this.redoStack = [];
      } catch (e) {
        this.error = "N\xE3o foi poss\xEDvel salvar. Verifique as mensagens de valida\xE7\xE3o; se outra janela alterou a p\xE1gina, recarregue.";
      } finally {
        this.saving = false;
      }
    },
    prepare() {
      for (const b of this.blocks) {
        b._key ??= this.nextKey++;
        this.nextKey = Math.max(this.nextKey, Number(b._key) + 1);
        b.data = canonicalElementData(b.data);
        if (b.type === "drawing") b.data.dados.drawing = { ...this.drawingDefaults(), ...b.data.dados.drawing || {} };
        if (b.type === "data-table") {
          b.data.dados.rows ??= [];
        }
        this.elementTypes[b.type]?.prepare?.call(this, b);
      }
    },
    add(type) {
      if (this.saving || this.adding || !Object.hasOwn(this.labels, type)) return null;
      const insert = (initialized) => {
        if (this.destroyed) return null;
        this.checkpoint();
        const data = {
          nome: this.labels[type],
          x_pt: Math.min(40, this.pageWidth / 10),
          y_pt: Math.min(40, this.pageHeight / 10),
          largura_pt: Math.min(200, this.pageWidth),
          altura_pt: Math.min(this.elementTypes[type]?.kind === "drawing" || type === "drawing" ? 12 : 100, this.pageHeight),
          rotacao_graus: 0,
          visivel: true,
          imagem_origem: null,
          vinculos: [],
          dados: {},
          ...initialized?.data
        };
        const block = { _key: this.nextKey++, type, data };
        this.blocks.push(block);
        this.selected = this.blocks.length - 1;
        this.normalize(block);
        this.prepare();
        this.syncInspector();
        this.schedulePreview();
        return block;
      };
      if (!this.$wire?.createElement) return insert({ data: { dados: {} } });
      this.adding = true;
      return this.$wire.createElement(type).then(insert).catch(() => {
        this.error = "N\xE3o foi poss\xEDvel criar o elemento.";
        return null;
      }).finally(() => {
        this.adding = false;
      });
    },
    image(b) {
      const first = this.mediaCollection(b);
      return this.safeImageUrl(this.mediaImages(b, first?.name)[0]?.image ?? b.data.imagem_url ?? "");
    },
    safeImageUrl(value) {
      const url = String(value || "");
      return /^https?:/i.test(url) || url.startsWith("blob:") || url.startsWith("/") && !url.startsWith("//") || new RegExp("^data:image/(?:png|jpeg|webp|gif);base64,", "i").test(url) ? url : "";
    },
    renderLink(b) {
      const url = String(b.data.dados?.link_url || "");
      try {
        if (!["http:", "https:"].includes(new URL(url).protocol)) return "";
      } catch {
        return "";
      }
      return `<a class="block-link" href="${this.escape(url)}" aria-label="Abrir link"></a>`;
    },
    text(b) {
      return b.data.dados?.text ?? "";
    },
    contentSignature(b) {
      const { id, ...data } = b.data;
      return JSON.stringify([b.type, data, this.isTextEditing(b) ? this.textEditing.html : null]);
    },
    renderContent(b) {
      const metadata = this.elementTypes[b.type], custom = metadata?.render;
      if (custom) return custom.call(this, b);
      if (metadata && Object.hasOwn(metadata, "local_renderer") && !["text", "image", "drawing", "data-table"].includes(metadata.local_renderer)) {
        const cached2 = this.serverRenders.get(b._key);
        this.scheduleElementRender(b);
        return cached2?.html ?? "";
      }
      const c = b.data.dados || {};
      if (["title", "text"].includes(b.type)) {
        const background = /^#(?:[a-f\d]{3}|[a-f\d]{6})$/i.test(c.background_color || "") ? c.background_color : "transparent";
        return `<div class="text-content" style="font-weight:${c.rich_text?.type === "doc" ? 400 : c.bold ?? b.type === "title" ? 700 : 400};font-style:${c.rich_text?.type === "doc" ? "normal" : c.italic ? "italic" : "normal"};line-height:${Math.max(0.5, Math.min(10, Number(c.leading ?? 1.25)))};column-count:${Math.max(1, Math.min(12, Math.trunc(Number(c.columns ?? 1))))};background:${background};padding:${Math.max(0, Math.min(100, Number(c.padding) || 0))}pt;border-radius:${Math.max(0, Math.min(100, Number(c.border_radius) || 0))}pt"><div class="canvas-rich-text">${this.textHtml(b)}</div></div>`;
      }
      if (this.imageTypes.includes(b.type)) {
        const url = this.image(b);
        return `<div class="image-frame">${url ? `<img class="image" src="${this.escape(url)}" alt="">` : '<span class="missing">Selecione uma imagem</span>'}</div>`;
      }
      if (b.type === "drawing") return this.renderDrawing(b);
      if (["data-table", "table"].includes(b.type)) return this.renderDataTable(b);
      const cached = this.serverRenders.get(b._key);
      this.scheduleElementRender(b);
      return cached?.html ?? "";
    },
    renderDataTable(b) {
      const columns = b.data.dados?.columns || [], rows = b.data.dados?.rows || [];
      return '<table class="data-table" style="width:100%;border-collapse:collapse;table-layout:fixed;font-size:inherit"><thead><tr>' + columns.map((c) => '<th scope="col" style="border:1px solid #d1d5db;padding:4pt 6pt;text-align:left;background:#f3f4f6;font-weight:600;overflow-wrap:anywhere">' + this.escape(c.label) + "</th>").join("") + "</tr></thead><tbody>" + rows.map((row) => "<tr>" + columns.map((c) => '<td style="border:1px solid #d1d5db;padding:4pt 6pt;text-align:left;vertical-align:top;overflow-wrap:anywhere">' + this.escape(row[c.field]) + "</td>").join("") + "</tr>").join("") + "</tbody></table>";
    },
    addDataRow() {
      if (!["data-table", "table"].includes(this.current?.type)) return;
      this.checkpoint();
      this.current.data.dados.rows.push({});
    },
    destroy() {
      this.endGesture();
      if (this.inlineFocusTimer !== null) clearTimeout(this.inlineFocusTimer);
      this.inlineFocusTimer = null;
      if (this.inlineMountTimer !== null) clearTimeout(this.inlineMountTimer);
      this.inlineMountTimer = null;
      this.destroyed = true;
      this.disposeZoomSlider?.();
      this.disposeZoomSlider = null;
      this.disposeEditorViewport?.();
      this.disposeEditorViewport = null;
      this.previewReady = false;
      this.gesture = null;
      this.textEditing = null;
      this.previewFontStyle?.remove();
      this.previewFontStyle = null;
      if (this.previewFrame !== null) cancelAnimationFrame(this.previewFrame);
      this.previewFrame = null;
      if (typeof this.disposeWatch === "function") this.disposeWatch();
      if (typeof this.disposeSelectionWatch === "function") this.disposeSelectionWatch();
      this.disposeWatch = null;
      this.disposeSelectionWatch = null;
      if (typeof this.disposeInspectorWatch === "function") this.disposeInspectorWatch();
      if (this.inspectorTimer !== null) clearTimeout(this.inspectorTimer);
      for (const timer of this.serverRenderTimers.values()) clearTimeout(timer);
      this.serverRenderTimers.clear();
      this.serverRenders.clear();
      this.serverRenderRequests.clear();
      this.previewNodes.clear();
      this.previewSignatures.clear();
    }
  };
  if (options.extension) Object.defineProperties(state, Object.getOwnPropertyDescriptors(options.extension(state)));
  return state;
}
export {
  canonicalElementData,
  createCanvas as default,
  plainRichDocument,
  renderRichDocument,
  sanitizeRichHtml
};
