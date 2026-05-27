(function () {
	'use strict';

	var WSM_YANDEX_PROMISE = null;

	function onReady(callback) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', callback);
			return;
		}

		callback();
	}

	function parseConfig(root) {
		var raw = root.getAttribute('data-config') || '{}';
		try {
			return JSON.parse(raw);
		} catch (error) {
			return {};
		}
	}

	function escapeHtml(value) {
		var str = value === null || value === undefined ? '' : String(value);
		return str.replace(/[&<>'"]/g, function (character) {
			switch (character) {
				case '&':
					return '&amp;';
				case '<':
					return '&lt;';
				case '>':
					return '&gt;';
				case '"':
					return '&quot;';
				case "'":
					return '&#39;';
				default:
					return character;
			}
		});
	}

	function isFiniteNumber(value) {
		return typeof value === 'number' && isFinite(value);
	}

	function toNumber(value) {
		var number = parseFloat(value);
		return isFinite(number) ? number : null;
	}

	function loadYandexMaps(apiKey) {
		if (!apiKey) {
			return Promise.resolve(null);
		}

		if (window.ymaps && typeof window.ymaps.ready === 'function') {
			return new Promise(function (resolve) {
				window.ymaps.ready(function () {
					resolve(window.ymaps);
				});
			});
		}

		if (WSM_YANDEX_PROMISE) {
			return WSM_YANDEX_PROMISE;
		}

		WSM_YANDEX_PROMISE = new Promise(function (resolve, reject) {
			var existingScript = document.querySelector('script[data-wsm-yandex="1"]');
			var handleReady = function () {
				if (window.ymaps && typeof window.ymaps.ready === 'function') {
					window.ymaps.ready(function () {
						resolve(window.ymaps);
					});
					return;
				}

				reject(new Error('Yandex Maps API is unavailable'));
			};

			if (existingScript) {
				if (window.ymaps) {
					handleReady();
					return;
				}

				existingScript.addEventListener('load', handleReady, { once: true });
				existingScript.addEventListener('error', function () {
					reject(new Error('Yandex Maps API failed to load'));
				}, { once: true });
				return;
			}

			var script = document.createElement('script');
			script.async = true;
			script.defer = true;
			script.setAttribute('data-wsm-yandex', '1');
			script.src = 'https://api-maps.yandex.ru/2.1/?lang=ru_RU&apikey=' + encodeURIComponent(apiKey);
			script.onload = handleReady;
			script.onerror = function () {
				reject(new Error('Yandex Maps API failed to load'));
			};
			document.head.appendChild(script);
		});

		return WSM_YANDEX_PROMISE;
	}

	function StoreMapWidget(root) {
		this.root = root;
		this.config = parseConfig(root);
		this.brandLookup = {};
		this.storeLookup = {};
		this.markers = {};
		this.map = null;
		this.ymaps = null;
		this.mapCanvas = null;
		this.mapFallback = null;
		this.pendingFocus = false;
		this.state = {
			activeBrand: '',
			activeCity: '',
			activeStoreId: ''
		};
	}

	StoreMapWidget.prototype.init = function () {
		this.buildLookupTables();
		this.state.activeBrand = this.config.active_brand || this.getFirstBrandSlug();
		this.mapCanvas = this.root.querySelector('[data-role="map-canvas"]');
		this.mapFallback = this.root.querySelector('[data-role="map-fallback"]');
		this.bindEvents();
		this.refresh(false);

		if (!this.config.yandex_api_key) {
			this.showMapFallback();
			return;
		}

		loadYandexMaps(this.config.yandex_api_key)
			.then(this.onYandexReady.bind(this))
			.catch(this.onMapError.bind(this));
	};

	StoreMapWidget.prototype.buildLookupTables = function () {
		var i;
		var brands = this.config.brands || [];
		var stores = this.config.stores || [];

		for (i = 0; i < brands.length; i += 1) {
			this.brandLookup[brands[i].slug] = brands[i];
		}

		for (i = 0; i < stores.length; i += 1) {
			this.storeLookup[String(stores[i].id)] = stores[i];
		}
	};

	StoreMapWidget.prototype.getFirstBrandSlug = function () {
		var brands = this.config.brands || [];
		return brands.length ? String(brands[0].slug || '') : '';
	};

	StoreMapWidget.prototype.bindEvents = function () {
		var self = this;
		var brandCards = this.root.querySelectorAll('[data-brand-card]');
		var citySelect = this.root.querySelector('[data-role="city-select"]');
		var storeCards = this.root.querySelectorAll('[data-store-card]');

		Array.prototype.forEach.call(brandCards, function (card) {
			var tab = card.querySelector('[data-role="brand-tab"]');
			if (!tab) {
				return;
			}

			tab.addEventListener('click', function () {
				self.setActiveBrand(card.getAttribute('data-brand-slug'));
			});
		});

		if (citySelect) {
			citySelect.addEventListener('change', function () {
				self.setActiveCity(citySelect.value);
			});
		}

		Array.prototype.forEach.call(storeCards, function (card) {
			var focusButton = card.querySelector('[data-role="store-focus"]');
			if (!focusButton) {
				return;
			}

			focusButton.addEventListener('click', function () {
				self.setActiveStore(card.getAttribute('data-store-id'), true);
			});
		});
	};

	StoreMapWidget.prototype.setActiveBrand = function (brandSlug) {
		if (!brandSlug || brandSlug === this.state.activeBrand) {
			return;
		}

		this.state.activeBrand = brandSlug;
		this.refresh(false);
	};

	StoreMapWidget.prototype.setActiveCity = function (citySlug) {
		this.state.activeCity = citySlug || '';
		this.refresh(false);
	};

	StoreMapWidget.prototype.setActiveStore = function (storeId, shouldFocusMap) {
		if (!storeId) {
			return;
		}

		this.state.activeStoreId = String(storeId);
		this.refresh(Boolean(shouldFocusMap));
	};

	StoreMapWidget.prototype.getVisibleStores = function () {
		var stores = this.config.stores || [];
		var visibleStores = [];
		var i;

		for (i = 0; i < stores.length; i += 1) {
			if (this.matchesFilters(stores[i])) {
				visibleStores.push(stores[i]);
			}
		}

		return visibleStores;
	};

	StoreMapWidget.prototype.matchesFilters = function (store) {
		var brandMatch = !this.state.activeBrand || store.brand_slugs.indexOf(this.state.activeBrand) !== -1;
		var cityMatch = !this.state.activeCity || String(store.city.slug) === this.state.activeCity;
		return brandMatch && cityMatch;
	};

	StoreMapWidget.prototype.getStoreButton = function () {
		var brandSlug = this.state.activeBrand;
		var brand = brandSlug ? this.brandLookup[brandSlug] : null;
		var overrideUrl = this.config.override_url || '';
		var siteText = this.config.branding && this.config.branding.site_button_text ? this.config.branding.site_button_text : 'Перейти на сайт';
		var buyText = this.config.branding && this.config.branding.buy_button_text ? this.config.branding.buy_button_text : 'Купить';

		if (overrideUrl) {
			return {
				url: overrideUrl,
				text: buyText
			};
		}

		if (brand && brand.default_url) {
			return {
				url: brand.default_url,
				text: siteText
			};
		}

		return null;
	};

	StoreMapWidget.prototype.refresh = function (focusActiveStore) {
		var visibleStores = this.getVisibleStores();
		var activeStoreId = this.state.activeStoreId;
		var activeStoreVisible = false;
		var i;

		for (i = 0; i < visibleStores.length; i += 1) {
			if (String(visibleStores[i].id) === activeStoreId) {
				activeStoreVisible = true;
				break;
			}
		}

		if (!activeStoreVisible) {
			activeStoreId = visibleStores.length ? String(visibleStores[0].id) : '';
			this.state.activeStoreId = activeStoreId;
		}

		this.updateBrandState();
		this.updateStoreCards(visibleStores);
		this.updateEmptyState(visibleStores.length === 0);

		if (!this.ymaps || !this.map) {
			this.pendingFocus = this.pendingFocus || Boolean(focusActiveStore);
			return;
		}

		this.renderMap(visibleStores, Boolean(focusActiveStore));
	};

	StoreMapWidget.prototype.updateBrandState = function () {
		var cards = this.root.querySelectorAll('[data-brand-card]');
		var self = this;

		Array.prototype.forEach.call(cards, function (card) {
			var slug = card.getAttribute('data-brand-slug');
			var button = card.querySelector('[data-role="brand-tab"]');
			var active = slug === self.state.activeBrand;

			card.classList.toggle('is-active', active);
			if (button) {
				button.setAttribute('aria-pressed', active ? 'true' : 'false');
			}
		});
	};

	StoreMapWidget.prototype.updateStoreCards = function (visibleStores) {
		var cards = this.root.querySelectorAll('[data-store-card]');
		var visibleLookup = {};
		var i;
		var self = this;

		for (i = 0; i < visibleStores.length; i += 1) {
			visibleLookup[String(visibleStores[i].id)] = visibleStores[i];
		}

		Array.prototype.forEach.call(cards, function (card) {
			var storeId = String(card.getAttribute('data-store-id'));
			var isVisible = Boolean(visibleLookup[storeId]);
			var cta = card.querySelector('[data-role="store-cta"]');
			var focusButton = card.querySelector('[data-role="store-focus"]');
			var button = self.getStoreButton();
			var brandBadges = card.querySelectorAll('[data-role="store-brand-badge"]');

			card.hidden = !isVisible;
			card.classList.toggle('is-hidden', !isVisible);
			card.classList.toggle('is-selected', isVisible && storeId === self.state.activeStoreId);

			if (focusButton) {
				focusButton.setAttribute('aria-pressed', isVisible && storeId === self.state.activeStoreId ? 'true' : 'false');
			}

			if (cta) {
				if (button && button.url) {
					cta.hidden = false;
					cta.setAttribute('href', button.url);
					cta.textContent = button.text;
				} else {
					cta.hidden = true;
				}
			}

			Array.prototype.forEach.call(brandBadges, function (badge) {
				var badgeSlug = badge.getAttribute('data-brand-slug');
				badge.classList.toggle('is-active', badgeSlug === self.state.activeBrand);
			});
		});
	};

	StoreMapWidget.prototype.updateEmptyState = function (shouldShow) {
		var emptyState = this.root.querySelector('[data-role="empty-state"]');
		if (!emptyState) {
			return;
		}

		emptyState.hidden = !shouldShow;
		emptyState.classList.toggle('is-visible', shouldShow);
	};

	StoreMapWidget.prototype.onYandexReady = function (ymaps) {
		this.ymaps = ymaps;
		this.createMap();
		this.refresh(false);
		this.root.classList.add('is-map-ready');
		this.hideMapFallback();

		if (this.pendingFocus && this.state.activeStoreId) {
			this.openStoreBalloon(this.state.activeStoreId);
		}

		this.pendingFocus = false;
	};

	StoreMapWidget.prototype.onMapError = function () {
		this.map = null;
		this.showMapFallback();
	};

	StoreMapWidget.prototype.showMapFallback = function () {
		if (this.mapFallback) {
			this.mapFallback.hidden = false;
		}
	};

	StoreMapWidget.prototype.hideMapFallback = function () {
		if (this.mapFallback) {
			this.mapFallback.hidden = true;
		}
	};

	StoreMapWidget.prototype.getInitialCenter = function () {
		var center = this.config.map_center;
		var stores = this.config.stores || [];
		var firstStore = stores.length ? stores[0] : null;

		if (center && isFiniteNumber(center.lat) && isFiniteNumber(center.lng)) {
			return [center.lat, center.lng];
		}

		if (firstStore && isFiniteNumber(toNumber(firstStore.lat)) && isFiniteNumber(toNumber(firstStore.lng))) {
			return [parseFloat(firstStore.lat), parseFloat(firstStore.lng)];
		}

		return [55.751244, 37.618423];
	};

	StoreMapWidget.prototype.createMap = function () {
		var center = this.getInitialCenter();

		if (!this.mapCanvas) {
			return;
		}

		if (!this.mapCanvas.id) {
			this.mapCanvas.id = this.root.id ? this.root.id + '-canvas' : 'wsm-map-canvas-' + Math.random().toString(36).slice(2, 8);
		}

		this.map = new this.ymaps.Map(this.mapCanvas.id, {
			center: center,
			zoom: 11,
			controls: ['zoomControl', 'fullscreenControl']
		}, {
			searchControlProvider: 'yandex#search'
		});
	};

	StoreMapWidget.prototype.renderMap = function (visibleStores, focusSelected) {
		var i;
		var points = [];
		var selectedStore = null;
		var currentStoreId = String(this.state.activeStoreId || '');

		if (!this.map || !this.ymaps) {
			return;
		}

		this.map.geoObjects.removeAll();
		this.markers = {};

		for (i = 0; i < visibleStores.length; i += 1) {
			if (String(visibleStores[i].id) === currentStoreId) {
				selectedStore = visibleStores[i];
			}
		}

		if (!selectedStore && visibleStores.length) {
			selectedStore = visibleStores[0];
			currentStoreId = String(selectedStore.id);
			this.state.activeStoreId = currentStoreId;
		}

		for (i = 0; i < visibleStores.length; i += 1) {
			points.push([parseFloat(visibleStores[i].lat), parseFloat(visibleStores[i].lng)]);
			this.addMarker(visibleStores[i]);
		}

		if (!visibleStores.length) {
			this.pendingFocus = false;
			return;
		}

		this.fitMapToPoints(points);

		if (focusSelected || this.pendingFocus) {
			this.openStoreBalloon(currentStoreId);
		}

		this.pendingFocus = false;
	};

	StoreMapWidget.prototype.fitMapToPoints = function (points) {
		var bounds;
		var minLat = null;
		var minLng = null;
		var maxLat = null;
		var maxLng = null;
		var i;

		if (!points.length) {
			return;
		}

		for (i = 0; i < points.length; i += 1) {
			if (!isFiniteNumber(points[i][0]) || !isFiniteNumber(points[i][1])) {
				continue;
			}

			if (minLat === null || points[i][0] < minLat) {
				minLat = points[i][0];
			}
			if (minLng === null || points[i][1] < minLng) {
				minLng = points[i][1];
			}
			if (maxLat === null || points[i][0] > maxLat) {
				maxLat = points[i][0];
			}
			if (maxLng === null || points[i][1] > maxLng) {
				maxLng = points[i][1];
			}
		}

		if (minLat === null || minLng === null || maxLat === null || maxLng === null) {
			return;
		}

		if (minLat === maxLat && minLng === maxLng) {
			this.map.setCenter([minLat, minLng], 14, { duration: 250 });
			return;
		}

		bounds = [
			[minLat, minLng],
			[maxLat, maxLng]
		];
		this.map.setBounds(bounds, {
			checkZoomRange: true,
			zoomMargin: 40
		});
	};

	StoreMapWidget.prototype.addMarker = function (store) {
		var self = this;
		var isActive = String(store.id) === String(this.state.activeStoreId);
		var brandBadges = store.brand_data || [];
		var body = [];
		var i;

		body.push('<div class="wsm-map-balloon">');
		body.push('<div class="wsm-map-balloon__title">' + escapeHtml(store.title) + '</div>');
		body.push('<div class="wsm-map-balloon__address">' + escapeHtml(store.address) + '</div>');
		body.push('<div class="wsm-map-balloon__city">' + escapeHtml(store.city && store.city.name ? store.city.name : '') + '</div>');

		if (brandBadges.length) {
			body.push('<div class="wsm-map-balloon__brands">');
			for (i = 0; i < brandBadges.length; i += 1) {
				body.push('<span class="wsm-map-balloon__badge">' + escapeHtml(brandBadges[i].name) + '</span>');
			}
			body.push('</div>');
		}

		body.push('</div>');

		this.markers[String(store.id)] = new this.ymaps.Placemark([
			parseFloat(store.lat),
			parseFloat(store.lng)
		], {
			balloonContentHeader: '<strong>' + escapeHtml(store.title) + '</strong>',
			balloonContentBody: body.join(''),
			hintContent: escapeHtml(store.title)
		}, {
			preset: isActive ? 'islands#redIcon' : 'islands#blueIcon'
		});

		this.markers[String(store.id)].events.add('click', function () {
			self.setActiveStore(String(store.id), true);
		});

		this.map.geoObjects.add(this.markers[String(store.id)]);
	};

	StoreMapWidget.prototype.openStoreBalloon = function (storeId) {
		var store = this.storeLookup[String(storeId)];
		var marker = this.markers[String(storeId)];
		var center;

		if (!store || !marker || !this.map) {
			return;
		}

		center = [parseFloat(store.lat), parseFloat(store.lng)];
		if (isFiniteNumber(center[0]) && isFiniteNumber(center[1])) {
			this.map.setCenter(center, Math.max(this.map.getZoom(), 14), { duration: 250 });
		}

		marker.balloon.open();
	};

	onReady(function () {
		var widgets = document.querySelectorAll('.wsm-map[data-config]');
		var i;

		for (i = 0; i < widgets.length; i += 1) {
			(new StoreMapWidget(widgets[i])).init();
		}
	});
}());
