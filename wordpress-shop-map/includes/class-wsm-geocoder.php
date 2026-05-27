<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSM_Geocoder {
	private WSM_Plugin $plugin;

	public function __construct( WSM_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function geocode( string $city, string $address ) {
		$api_key = $this->plugin->get_yandex_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'wsm_missing_api_key', 'Не задан API-ключ Яндекс.Карт для автогеокодирования.' );
		}

		$city    = trim( sanitize_text_field( $city ) );
		$address = trim( sanitize_text_field( $address ) );
		$query   = trim( implode( ', ', array_filter( array( $city, $address ) ) ) );

		if ( '' === $query ) {
			return new WP_Error( 'wsm_empty_query', 'Недостаточно данных для геокодирования.' );
		}

		$url = add_query_arg(
			array(
				'geocode' => $query,
				'format'  => 'json',
				'results' => 1,
				'lang'    => 'ru_RU',
				'apikey'  => $api_key,
			),
			'https://geocode-maps.yandex.ru/1.x/'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'wsm_geocode_request_failed', $response->get_error_message() );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $status_code ) {
			return new WP_Error( 'wsm_geocode_bad_status', 'Геокодирование не удалось: HTTP ' . $status_code . '.' );
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'wsm_geocode_bad_json', 'Не удалось разобрать ответ Яндекс.Геокодера.' );
		}

		$pos = $data['response']['GeoObjectCollection']['featureMember'][0]['GeoObject']['Point']['pos'] ?? '';
		if ( '' === $pos ) {
			return new WP_Error( 'wsm_geocode_empty_result', 'Яндекс.Геокодер не вернул координаты для этого адреса.' );
		}

		$parts = preg_split( '/\s+/', trim( $pos ) );
		if ( empty( $parts[0] ) || empty( $parts[1] ) ) {
			return new WP_Error( 'wsm_geocode_invalid_result', 'Получен некорректный ответ координат.' );
		}

		return array(
			'lng' => (float) $parts[0],
			'lat' => (float) $parts[1],
		);
	}
}

