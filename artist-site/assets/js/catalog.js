(function () {
    const toggle = document.querySelector('[data-mobile-nav-toggle]');
    const header = toggle && toggle.closest('.site-header');
    if (!toggle || !header) return;

    function setOpen(open) {
        header.classList.toggle('is-menu-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    toggle.addEventListener('click', () => {
        setOpen(!header.classList.contains('is-menu-open'));
    });
    header.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            setOpen(false);
            toggle.focus();
        }
    });
    window.matchMedia('(min-width: 1181px)').addEventListener('change', (event) => {
        if (event.matches) setOpen(false);
    });
})();

(function () {
    const slider = document.querySelector('[data-hero-slider]');

    if (!slider) {
        return;
    }

    const slides = Array.from(slider.querySelectorAll('[data-hero-slide]'));
    const prev = slider.querySelector('[data-hero-prev]');
    const next = slider.querySelector('[data-hero-next]');

    if (slides.length <= 1) {
        return;
    }

    let index = 0;
    const desktop = window.matchMedia('(min-width: 941px)');

    function hydrate() {
        slides.forEach((slide) => {
            if (!slide.getAttribute('src') && slide.dataset.src) {
                slide.setAttribute('src', slide.dataset.src);
            }
            if (!slide.getAttribute('srcset') && slide.dataset.srcset) {
                slide.setAttribute('srcset', slide.dataset.srcset);
            }
        });
    }

    function show(nextIndex) {
        index = (nextIndex + slides.length) % slides.length;
        slides.forEach((slide, slideIndex) => {
            if (slideIndex === index) {
                slide.setAttribute('data-active', 'true');
            } else {
                slide.removeAttribute('data-active');
            }
        });
    }

    if (prev) {
        prev.addEventListener('click', () => {
            show(index - 1);
        });
    }

    if (next) {
        next.addEventListener('click', () => {
            show(index + 1);
        });
    }

    function configure() {
        show(0);
        if (desktop.matches) hydrate();
    }

    desktop.addEventListener('change', configure);
    configure();
})();

(function () {
    const cards = Array.from(document.querySelectorAll('[data-artwork-card]'));
    const input = document.querySelector('[data-catalog-search]');
    const count = document.querySelector('[data-catalog-count]');
    const statusButtons = Array.from(document.querySelectorAll('[data-filter-status]'));
    const seriesButtons = Array.from(document.querySelectorAll('[data-filter-series]'));

    if (!cards.length) {
        return;
    }

    const params = new URLSearchParams(window.location.search);
    let activeStatus = params.get('status') || 'all';
    let activeSeries = params.get('series') || 'all';
    let query = params.get('q') || '';

    if (input) {
        input.value = query;
    }

    function setPressed() {
        statusButtons.forEach((button) => {
            button.setAttribute('aria-pressed', button.dataset.filterStatus === activeStatus ? 'true' : 'false');
        });
        seriesButtons.forEach((button) => {
            button.setAttribute('aria-pressed', button.dataset.filterSeries === activeSeries ? 'true' : 'false');
        });
    }

    function applyFilters() {
        const q = query.trim().toLowerCase();
        let visible = 0;

        cards.forEach((card) => {
            const matchesStatus = activeStatus === 'all' || card.dataset.status === activeStatus;
            const matchesSeries = activeSeries === 'all' || card.dataset.series === activeSeries;
            const matchesQuery = !q || card.dataset.search.includes(q);
            const show = matchesStatus && matchesSeries && matchesQuery;

            card.hidden = !show;
            if (show) {
                visible += 1;
            }
        });

        if (count) {
            count.textContent = visible === 1 ? '1 work found' : visible + ' works found';
        }
        setPressed();
    }

    statusButtons.forEach((button) => {
        button.addEventListener('click', () => {
            activeStatus = button.dataset.filterStatus;
            applyFilters();
        });
    });

    seriesButtons.forEach((button) => {
        button.addEventListener('click', () => {
            activeSeries = activeSeries === button.dataset.filterSeries ? 'all' : button.dataset.filterSeries;
            applyFilters();
        });
    });

    if (input) {
        input.addEventListener('input', () => {
            query = input.value;
            applyFilters();
        });
    }

    applyFilters();
})();

(function () {
    const widthInput = document.querySelector('[data-dimension-width-cm]');
    const heightInput = document.querySelector('[data-dimension-height-cm]');
    const depthInput = document.querySelector('[data-dimension-depth-cm]');
    const cmInput = document.querySelector('[data-dimensions-cm]');
    const inchesInput = document.querySelector('[data-dimensions-in]');

    if (!widthInput || !heightInput || !cmInput || !inchesInput) {
        return;
    }

    function formatInches(value) {
        const rounded = Math.round(value);
        return Math.abs(value - rounded) < 0.15 ? String(rounded) : value.toFixed(1);
    }

    function convertDimensions() {
        const values = [widthInput.value, heightInput.value, depthInput ? depthInput.value : '']
            .map((value) => value.trim().replace(',', '.'))
            .filter((value) => value !== '' && Number.isFinite(parseFloat(value)));
        if (values.length < 2) {
            return;
        }

        cmInput.value = values.map((value) => formatInches(parseFloat(value))).join(' x ') + ' cm';
        inchesInput.value = values.map((value) => formatInches(parseFloat(value) / 2.54)).join(' x ') + ' in';
    }

    [widthInput, heightInput, depthInput].filter(Boolean).forEach((input) => {
        input.addEventListener('input', convertDimensions);
    });
    convertDimensions();
})();

(function () {
    const metricSizes = Array.from(document.querySelectorAll('[data-size-metric]'));
    const imperialSizes = Array.from(document.querySelectorAll('[data-size-imperial]'));

    if (!metricSizes.length || !imperialSizes.length) {
        return;
    }

    const locale = navigator.language || '';
    const country = locale.split('-')[1] || '';
    const imperialCountries = new Set(['US', 'LR', 'MM']);
    const useImperial = imperialCountries.has(country.toUpperCase());

    metricSizes.forEach((size) => {
        size.hidden = useImperial;
    });
    imperialSizes.forEach((size) => {
        size.hidden = !useImperial;
    });
})();

(function () {
    const maps = Array.from(document.querySelectorAll('[data-constellation-map]'));

    maps.forEach((map) => {
        const buttons = Array.from(map.querySelectorAll('[data-map-view-button]'));
        if (!buttons.length) {
            return;
        }

        buttons.forEach((button) => {
            button.addEventListener('click', () => {
                const view = button.dataset.mapViewButton || 'world';
                map.dataset.mapView = view;
                buttons.forEach((item) => {
                    item.setAttribute('aria-pressed', item === button ? 'true' : 'false');
                });
            });
        });
    });
})();

(function () {
    const atlas = document.querySelector('[data-constellation-leaflet]');

    if (!atlas || typeof L === 'undefined') {
        return;
    }

    const canvas = atlas.querySelector('[data-map-canvas]');
    if (!canvas) {
        return;
    }

    let items = [];
    try {
        items = JSON.parse(atlas.dataset.mapItems || '[]');
    } catch (error) {
        items = [];
    }

    if (!items.length) {
        return;
    }

    const card = atlas.querySelector('[data-constellation-card]');
    const title = card ? card.querySelector('[data-constellation-title]') : null;
    const place = card ? card.querySelector('[data-constellation-place]') : null;
    const image = card ? card.querySelector('[data-constellation-image]') : null;
    const link = card ? card.querySelector('[data-constellation-link]') : null;

    function hideCard() {
        if (card) {
            card.hidden = true;
        }
    }

    function updateCard(item) {
        if (!item || !card || !title || !place || !image || !link) {
            return;
        }
        const location = [item.postal_code, item.country].filter(Boolean).join(' / ');
        title.textContent = item.title || 'Placed work';
        place.textContent = location || 'Placement recorded';
        link.href = item.url || '#';
        if (item.image) {
            image.hidden = false;
            image.src = item.image;
        } else {
            image.hidden = true;
        }
        card.hidden = false;
    }

    const map = L.map(canvas, {
        attributionControl: true,
        scrollWheelZoom: false,
        worldCopyJump: true,
        zoomControl: true,
    }).setView([26, 8], 2);

    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
        attribution: '&copy; OpenStreetMap contributors &copy; CARTO',
        maxZoom: 8,
        minZoom: 2,
    }).addTo(map);

    const grouped = new Map();
    items.forEach((item) => {
        const lat = Number(item.lat);
        const lng = Number(item.lng);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
            return;
        }
        const key = `${lat.toFixed(3)}:${lng.toFixed(3)}:${String(item.country || '').toLowerCase()}`;
        const group = grouped.get(key) || [];
        group.push(item);
        grouped.set(key, group);
    });

    function readableLatLng(item, total, index) {
        const lat = Number(item.lat);
        const lng = Number(item.lng);
        if (total <= 1) {
            return [lat, lng];
        }

        const markerDistance = 3 + Math.floor(index / 8) * 1.5;
        const angle = (-90 + index * 137.508) * Math.PI / 180;
        const offset = L.point(
            Math.cos(angle) * markerDistance,
            Math.sin(angle) * markerDistance
        );
        const basePoint = map.latLngToLayerPoint([lat, lng]);
        const movedPoint = basePoint.add(offset);
        return [map.layerPointToLatLng(movedPoint).lat, map.layerPointToLatLng(movedPoint).lng];
    }

    const bounds = [];

    Array.from(grouped.values()).forEach((group) => {
        group.forEach((item, index) => {
            const latLng = readableLatLng(item, group.length, index);
            bounds.push(latLng);
            const marker = L.circleMarker(latLng, {
                radius: 4,
                color: '#8cffbd',
                weight: 1,
                fillColor: '#34ff8c',
                fillOpacity: 0.95,
                opacity: 0.95,
                className: 'constellation-green-point',
            }).addTo(map);
            marker.on('mouseover focus click', () => updateCard(item));
            marker.on('mouseout blur', hideCard);
            marker.bindTooltip(item.title || 'Placed work', {
                direction: 'top',
                opacity: 0.92,
                className: 'constellation-map-tooltip',
            });
        });
    });

    map.on('click', hideCard);
    map.on('movestart', hideCard);
    hideCard();

    if (bounds.length > 1) {
        map.fitBounds(bounds, { padding: [54, 54], maxZoom: 4 });
    } else if (bounds.length === 1) {
        map.setView(bounds[0], 4);
    }
})();

(function () {
    const inputs = Array.from(document.querySelectorAll('[data-root-image-input]'));

    if (!inputs.length) {
        return;
    }

    inputs.forEach((input) => {
        const scope = input.closest('.admin-root-image') || input.closest('form');
        const preview = scope ? scope.querySelector('[data-root-image-preview]') : null;
        if (!preview) {
            return;
        }

        let previewUrl = '';

        input.addEventListener('change', () => {
            const file = input.files && input.files[0];
            if (!file) {
                return;
            }

            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }

            previewUrl = URL.createObjectURL(file);
            preview.innerHTML = '';

            const image = document.createElement('img');
            image.src = previewUrl;
            image.alt = 'Selected root artwork preview';
            preview.appendChild(image);
        });
    });
})();
