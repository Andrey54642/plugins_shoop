<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSM_Frontend {
	private WSM_Plugin $plugin;

	public function __construct( WSM_Plugin $plugin ) {
		$this->plugin = $plugin;
		add_shortcode( 'stores_map', array( $this, 'render_shortcode' ) );
	}

	public function render_shortcode( $atts ): string {
		// WordPress can pass an empty string when the shortcode is used without attributes.
		if ( ! is_array( $atts ) ) {
			$atts = array();
		}

		$atts = shortcode_atts(
			array(
				'button_url' => '',
			),
			$atts,
			'stores_map'
		);

		global $post;
		$post_id = $post instanceof WP_Post ? (int) $post->ID : (int) get_queried_object_id();
		$override_url = $this->plugin->get_context_override_url( $post_id, (string) $atts['button_url'] );
		$payload      = $this->plugin->get_frontend_payload( $override_url, $post_id );

		wp_enqueue_style( 'wsm-frontend', WSM_URL . 'assets/css/frontend.css', array(), WSM_VERSION );
		wp_enqueue_script( 'wsm-frontend', WSM_URL . 'assets/js/frontend.js', array(), WSM_VERSION, true );

		$wrapper_id = wp_unique_id( 'wsm-map-' );
		$initial_brand = $this->plugin->get_brand_data_by_slug( (string) $payload['active_brand'] );
		$initial_cta_text  = (string) $payload['override_label'];
		$initial_cta_url   = $override_url;
		if ( '' === $initial_cta_url && ! empty( $initial_brand['default_url'] ) ) {
			$initial_cta_url = (string) $initial_brand['default_url'];
			$initial_cta_text = (string) $payload['branding']['site_button_text'];
		}

		ob_start();
		?>
		<div id="<?php echo esc_attr( $wrapper_id ); ?>" class="wsm-map" data-config="<?php echo esc_attr( wp_json_encode( $payload ) ); ?>">
			<div class="wsm-map__inner">
				<aside class="wsm-map__brands" aria-label="Бренды">
					<?php echo $this->render_brand_cards( $payload ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</aside>

				<section class="wsm-map__content">
					<div class="wsm-map__sidebar">
						<div class="wsm-map__filters">
							<label class="wsm-field">
								<span class="wsm-field__label">Город</span>
								<select class="wsm-city-select" data-role="city-select">
									<option value=""><?php echo esc_html( $payload['strings']['all_cities'] ); ?></option>
									<?php foreach ( (array) $payload['cities'] as $city ) : ?>
										<option value="<?php echo esc_attr( $city['slug'] ); ?>"><?php echo esc_html( $city['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						</div>

						<div class="wsm-map__store-list" data-role="store-list">
							<?php if ( empty( $payload['stores'] ) ) : ?>
								<div class="wsm-empty-state is-visible" data-role="empty-state">
									<h3><?php echo esc_html( $payload['strings']['no_valid_stores'] ); ?></h3>
									<p>Добавьте магазины, назначьте бренды и координаты в админке.</p>
								</div>
							<?php else : ?>
								<div class="wsm-empty-state" data-role="empty-state" hidden>
									<h3><?php echo esc_html( $payload['strings']['no_stores'] ); ?></h3>
									<p>Попробуйте выбрать другой бренд или город.</p>
								</div>
								<?php echo $this->render_store_cards( $payload, $initial_cta_url, $initial_cta_text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php endif; ?>
						</div>
					</div>

					<div class="wsm-map__map-wrap">
						<div class="wsm-map__map" data-role="map-canvas">
							<div class="wsm-map__placeholder" data-role="map-fallback">
								<strong><?php echo esc_html( $payload['strings']['map_key_missing'] ); ?></strong>
								<span><?php echo esc_html( $payload['strings']['map_unavailable'] ); ?></span>
							</div>
						</div>
					</div>
				</section>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	private function render_brand_cards( array $payload ): string {
		$active_brand = (string) $payload['active_brand'];
		$override_url = (string) $payload['override_url'];

		ob_start();
		foreach ( (array) $payload['brands'] as $brand ) {
			$brand_url   = $this->plugin->resolve_brand_button_url( (string) $brand['slug'], $override_url );
			$button_text = $this->plugin->resolve_button_text( $override_url );
			?>
			<div class="wsm-brand-card <?php echo ( $active_brand === $brand['slug'] ) ? 'is-active' : ''; ?>" data-brand-card data-brand-slug="<?php echo esc_attr( $brand['slug'] ); ?>" data-brand-url="<?php echo esc_attr( $brand_url ); ?>" data-brand-default-url="<?php echo esc_attr( $brand['default_url'] ); ?>">
				<button type="button" class="wsm-brand-card__tab" data-role="brand-tab" aria-pressed="<?php echo $active_brand === $brand['slug'] ? 'true' : 'false'; ?>">
					<span class="wsm-brand-card__logo">
						<?php if ( ! empty( $brand['logo_url'] ) ) : ?>
							<img src="<?php echo esc_url( $brand['logo_url'] ); ?>" alt="<?php echo esc_attr( $brand['name'] ); ?>">
						<?php else : ?>
							<span class="wsm-brand-card__fallback" aria-hidden="true">?</span>
						<?php endif; ?>
					</span>
					<span class="wsm-brand-card__content">
						<span class="wsm-brand-card__title"><?php echo esc_html( $brand['name'] ); ?></span>
						<span class="wsm-brand-card__hint">Показать магазины бренда</span>
					</span>
				</button>
				<?php if ( $brand_url ) : ?>
					<a class="wsm-brand-card__cta" data-role="brand-cta" href="<?php echo esc_url( $brand_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $button_text ); ?></a>
				<?php endif; ?>
			</div>
			<?php
		}

		return (string) ob_get_clean();
	}

	private function render_store_cards( array $payload, string $initial_cta_url, string $initial_cta_text ): string {
		$active_brand = (string) $payload['active_brand'];

		ob_start();
		foreach ( (array) $payload['stores'] as $store ) {
			$is_visible = in_array( $active_brand, (array) $store['brand_slugs'], true );
			?>
			<article class="wsm-store-card <?php echo $is_visible ? 'is-visible' : ''; ?>" data-store-card data-store-id="<?php echo esc_attr( $store['id'] ); ?>" data-city-slug="<?php echo esc_attr( $store['city']['slug'] ); ?>" data-brand-slugs="<?php echo esc_attr( implode( ',', (array) $store['brand_slugs'] ) ); ?>" data-lat="<?php echo esc_attr( $store['lat'] ); ?>" data-lng="<?php echo esc_attr( $store['lng'] ); ?>" <?php echo $is_visible ? '' : 'hidden'; ?>>
				<button type="button" class="wsm-store-card__focus" data-role="store-focus" aria-label="<?php echo esc_attr( $store['title'] ); ?>">
					<h3 class="wsm-store-card__title"><?php echo esc_html( $store['title'] ); ?></h3>
					<div class="wsm-store-card__meta">
						<span class="wsm-store-card__city"><?php echo esc_html( $store['city']['name'] ); ?></span>
						<span class="wsm-store-card__address"><?php echo esc_html( $store['address'] ); ?></span>
					</div>
				</button>

				<div class="wsm-store-card__brands">
					<?php foreach ( (array) $store['brand_data'] as $brand ) : ?>
						<span class="wsm-store-card__badge <?php echo $active_brand === $brand['slug'] ? 'is-active' : ''; ?>" data-role="store-brand-badge" data-brand-slug="<?php echo esc_attr( $brand['slug'] ); ?>"><?php echo esc_html( $brand['name'] ); ?></span>
					<?php endforeach; ?>
				</div>

				<a class="wsm-store-card__cta" data-role="store-cta" href="<?php echo esc_url( $initial_cta_url ); ?>" target="_blank" rel="noopener noreferrer" <?php echo $initial_cta_url ? '' : 'hidden'; ?>><?php echo esc_html( $initial_cta_text ); ?></a>
			</article>
			<?php
		}

		return (string) ob_get_clean();
	}
}
