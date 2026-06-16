/**
 * Pan/zoom enhancement for rendered mermaid diagrams (VSCode-preview style).
 *
 * After mermaid.run() replaces a `<pre class="mermaid">` with an `<svg>`, call
 * enhanceMermaidZoom(root): each processed diagram is wrapped in a viewport that
 * clips the SVG, and given a control cluster (zoom in / out / fit-reset),
 * drag-to-pan, and wheel-zoom-toward-the-cursor.
 *
 * Vanilla JS, no dependencies. Idempotent: a wrapped diagram carries
 * `data-zoom-ready` so repeated renderMermaid() passes don't double-wrap.
 */

const MIN_SCALE = 0.1;
const MAX_SCALE = 8;
const ZOOM_STEP = 1.2; // multiplicative step for the +/- buttons
const FIT_PADDING = 0.95; // leave a little breathing room when fitting

/**
 * The diagram's intrinsic (scale-1) size in CSS pixels. Mermaid always sets a
 * `viewBox="0 0 W H"`, and we pin the SVG to exactly that width/height (see
 * enhanceOne) so 1 user unit == 1 CSS pixel — which makes the viewBox the
 * authoritative natural size and keeps the fit/zoom math unit-consistent.
 * getBBox / the client rect are fallbacks if the viewBox is ever missing.
 */
function svgNaturalSize(svg) {
    const vb = svg.viewBox && svg.viewBox.baseVal;
    if (vb && vb.width && vb.height) {
        return { w: vb.width, h: vb.height };
    }
    let w = 0;
    let h = 0;
    try {
        const bb = svg.getBBox();
        w = bb.width;
        h = bb.height;
    } catch (e) {
        /* getBBox throws if not rendered; ignore */
    }
    if (!w || !h) {
        const rect = svg.getBoundingClientRect();
        w = w || rect.width;
        h = h || rect.height;
    }
    return { w: w || 1, h: h || 1 };
}

function enhanceOne(pre) {
    if (pre.dataset.zoomReady === 'true') return;
    const svg = pre.querySelector('svg');
    if (!svg) return; // not actually rendered yet
    pre.dataset.zoomReady = 'true';

    // Mermaid constrains the svg to width:100% (useMaxWidth) and centers it; we
    // instead pin it to its viewBox size in CSS pixels (1 user unit = 1px) so our
    // own transform fully controls placement and the fit math stays unit-true.
    const vb = svg.viewBox && svg.viewBox.baseVal;
    svg.style.maxWidth = 'none';
    svg.style.transformOrigin = '0 0';
    svg.style.transform = 'translate(0px, 0px) scale(1)';
    svg.style.display = 'block';
    if (vb && vb.width && vb.height) {
        svg.style.width = vb.width + 'px';
        svg.style.height = vb.height + 'px';
        svg.removeAttribute('width');
        svg.removeAttribute('height');
    }

    // Build the viewport wrapper around the <pre> in the DOM.
    const viewport = document.createElement('div');
    viewport.className = 'mermaid-viewport';
    pre.parentNode.insertBefore(viewport, pre);
    viewport.appendChild(pre);
    // Give the viewport a definite height so it's a real box to pan within (the
    // absolutely-sized SVG can't size it). Use the diagram's natural height,
    // clamped — the CSS max-height (70vh) still caps a tall one. Without a
    // definite height the wheel-zoom/fit math has nothing to center against.
    if (vb && vb.height) {
        viewport.style.height = vb.height + 'px';
    }
    // Neutralise the prose `pre` styling (bg/padding/overflow) so it's just a
    // transparent transform host filling the viewport.
    pre.classList.add('mermaid-zoom-host');

    // --- transform state ---
    const state = { scale: 1, tx: 0, ty: 0 };

    const apply = () => {
        svg.style.transform = `translate(${state.tx}px, ${state.ty}px) scale(${state.scale})`;
    };

    const clampScale = (s) => Math.min(MAX_SCALE, Math.max(MIN_SCALE, s));

    /**
     * Zoom by `factor` while keeping the viewport point (cx, cy) — given in
     * viewport-local CSS pixels — pinned under itself. The content point under
     * the cursor is (cx - tx) / scale; after changing scale we re-solve tx/ty so
     * that same content point still maps to (cx, cy).
     */
    const zoomAt = (factor, cx, cy) => {
        const newScale = clampScale(state.scale * factor);
        if (newScale === state.scale) return;
        const k = newScale / state.scale;
        state.tx = cx - (cx - state.tx) * k;
        state.ty = cy - (cy - state.ty) * k;
        state.scale = newScale;
        apply();
    };

    /**
     * Fit the whole diagram into the viewport and center it. Scale is the lesser
     * of the width/height ratios (so nothing is clipped), capped at 1 so a small
     * diagram isn't blown up past its natural size.
     */
    const fit = () => {
        const vw = viewport.clientWidth;
        const vh = viewport.clientHeight;
        const { w, h } = svgNaturalSize(svg);
        if (!vw || !vh) return;
        const s = clampScale(Math.min(vw / w, vh / h, 1) * FIT_PADDING);
        state.scale = s;
        state.tx = (vw - w * s) / 2;
        state.ty = (vh - h * s) / 2;
        apply();
    };

    // --- controls ---
    const controls = document.createElement('div');
    controls.className = 'mermaid-zoom-controls';

    const mkBtn = (label, title, onClick) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'mermaid-zoom-btn';
        b.setAttribute('aria-label', title);
        b.title = title;
        b.innerHTML = label;
        // Stop pointerdown so a click on a button never starts a pan.
        b.addEventListener('pointerdown', (e) => e.stopPropagation());
        b.addEventListener('click', (e) => {
            e.stopPropagation();
            e.preventDefault();
            onClick();
        });
        return b;
    };

    const centerZoom = (factor) =>
        zoomAt(factor, viewport.clientWidth / 2, viewport.clientHeight / 2);

    controls.appendChild(mkBtn('+', 'Zoom in', () => centerZoom(ZOOM_STEP)));
    controls.appendChild(mkBtn('&minus;', 'Zoom out', () => centerZoom(1 / ZOOM_STEP)));
    // Fit/reset glyph (corners-out arrows).
    controls.appendChild(mkBtn('&#x2922;', 'Fit to view', fit));
    viewport.appendChild(controls);

    // --- drag to pan + two-finger pinch zoom ---
    // Track every active pointer (pointerId -> last viewport-local position) so
    // we can distinguish one-finger pan from a two-finger pinch. Positions are
    // kept in viewport-local CSS pixels (matching zoomAt's coordinate space).
    const pointers = new Map();
    let dragging = false; // single-pointer pan in progress
    let lastX = 0; // last single-pointer position (viewport-local)
    let lastY = 0;
    // Pinch state: previous pinch distance + midpoint, refreshed every move so
    // the zoom is applied incrementally (newDist/prevDist) for smoothness.
    let pinchDist = 0;
    let pinchMidX = 0;
    let pinchMidY = 0;

    const localPoint = (e) => {
        const rect = viewport.getBoundingClientRect();
        return { x: e.clientX - rect.left, y: e.clientY - rect.top };
    };

    // (Re)seed single-pointer pan from the one remaining pointer. Called both on
    // pointerdown and when a pinch drops back to a single pointer, so resuming a
    // drag never jumps.
    const startPan = (id) => {
        const p = pointers.get(id);
        if (!p) return;
        dragging = true;
        lastX = p.x;
        lastY = p.y;
        viewport.classList.add('is-grabbing');
    };

    // Seed pinch state from the two currently-active pointers.
    const startPinch = () => {
        dragging = false; // suspend single-finger pan while pinching
        viewport.classList.remove('is-grabbing');
        const [a, b] = [...pointers.values()];
        pinchDist = Math.hypot(b.x - a.x, b.y - a.y) || 1;
        pinchMidX = (a.x + b.x) / 2;
        pinchMidY = (a.y + b.y) / 2;
    };

    viewport.addEventListener('pointerdown', (e) => {
        if (e.button !== 0 && e.pointerType === 'mouse') return;
        const p = localPoint(e);
        pointers.set(e.pointerId, p);
        viewport.setPointerCapture(e.pointerId);
        if (pointers.size === 1) {
            startPan(e.pointerId);
        } else if (pointers.size === 2) {
            startPinch();
        }
        // 3+ pointers: ignore the extra; pinch keeps using the first two.
    });

    viewport.addEventListener('pointermove', (e) => {
        if (!pointers.has(e.pointerId)) return;
        const p = localPoint(e);
        pointers.set(e.pointerId, p);

        if (pointers.size >= 2) {
            // Pinch: scale by the change in distance between the first two
            // pointers and zoom toward their current midpoint, then pan by the
            // midpoint's movement so a two-finger drag also translates.
            const [a, b] = [...pointers.values()];
            const dist = Math.hypot(b.x - a.x, b.y - a.y) || 1;
            const midX = (a.x + b.x) / 2;
            const midY = (a.y + b.y) / 2;
            zoomAt(dist / pinchDist, midX, midY);
            state.tx += midX - pinchMidX;
            state.ty += midY - pinchMidY;
            apply();
            pinchDist = dist;
            pinchMidX = midX;
            pinchMidY = midY;
            return;
        }

        if (!dragging) return;
        state.tx += p.x - lastX;
        state.ty += p.y - lastY;
        lastX = p.x;
        lastY = p.y;
        apply();
    });

    const endPointer = (e) => {
        if (!pointers.has(e.pointerId)) return;
        pointers.delete(e.pointerId);
        try {
            viewport.releasePointerCapture(e.pointerId);
        } catch (err) {
            /* pointer may already be released */
        }
        if (pointers.size === 1) {
            // Dropped from pinch back to one finger: resume pan from the
            // survivor without jumping.
            startPan([...pointers.keys()][0]);
        } else if (pointers.size === 0) {
            dragging = false;
            viewport.classList.remove('is-grabbing');
        } else if (pointers.size >= 2) {
            // Still multi-touch (a 3rd finger lifted): re-seed pinch baseline.
            startPinch();
        }
    };
    viewport.addEventListener('pointerup', endPointer);
    viewport.addEventListener('pointercancel', endPointer);

    // --- wheel zoom toward cursor (also trackpad pinch) ---
    // A trackpad pinch is delivered as wheel events with ctrlKey === true and a
    // small deltaY; a regular scroll wheel has ctrlKey === false. Both map to the
    // same toward-cursor zoom, so no special-casing is needed — we just zoom on
    // every wheel and always preventDefault so the page/modal never scrolls and
    // the browser's native ctrl+wheel page-zoom is suppressed over the diagram.
    viewport.addEventListener(
        'wheel',
        (e) => {
            e.preventDefault(); // don't scroll the modal/page (or page-zoom on ctrl) while zooming
            const rect = viewport.getBoundingClientRect();
            const cx = e.clientX - rect.left;
            const cy = e.clientY - rect.top;
            // Smooth, direction-aware factor; deltaY<0 = scroll up / pinch out = zoom in.
            const factor = Math.exp(-e.deltaY * 0.0015);
            zoomAt(factor, cx, cy);
        },
        { passive: false }
    );

    // Fit once on first render so a big diagram starts fully visible. Defer a
    // frame so layout (viewport size) has settled.
    requestAnimationFrame(fit);
}

/**
 * Enhance every processed mermaid diagram under `root` (defaults to document)
 * that hasn't been wrapped yet.
 */
export function enhanceMermaidZoom(root) {
    const scope = root || document;
    scope
        .querySelectorAll('pre.mermaid[data-processed="true"]:not([data-zoom-ready="true"])')
        .forEach(enhanceOne);
}
