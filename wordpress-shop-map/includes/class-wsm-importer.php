<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSM_Importer {
	private const OLE_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

	private WSM_Plugin $plugin;

	public function __construct( WSM_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function import_uploaded_file( array $file ): array|WP_Error {
		if ( empty( $file['tmp_name'] ) || ! is_string( $file['tmp_name'] ) ) {
			return new WP_Error( 'wsm_missing_upload', 'Файл не был загружен.' );
		}

		if ( isset( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'wsm_upload_error', $this->get_upload_error_message( (int) $file['error'] ) );
		}

		$tmp_name = (string) $file['tmp_name'];
		$original = isset( $file['name'] ) ? (string) $file['name'] : '';

		if ( ! file_exists( $tmp_name ) || ! is_readable( $tmp_name ) ) {
			return new WP_Error( 'wsm_upload_unreadable', 'Не удалось прочитать загруженный файл.' );
		}

		$type = $this->detect_workbook_type( $tmp_name, $original );
		if ( is_wp_error( $type ) ) {
			return $type;
		}

		$source = 'xlsx' === $type ? $this->load_xlsx_rows( $tmp_name ) : $this->load_xls_rows( $tmp_name );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		return $this->import_rows( $source['rows'], (string) $source['sheet_name'], $type, $original );
	}

	private function detect_workbook_type( string $path, string $original_name = '' ): string|WP_Error {
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return new WP_Error( 'wsm_file_open_failed', 'Не удалось открыть файл для чтения.' );
		}

		$signature = (string) fread( $handle, 8 );
		fclose( $handle );

		if ( 0 === strncmp( $signature, 'PK', 2 ) ) {
			return 'xlsx';
		}

		if ( self::OLE_SIGNATURE === $signature ) {
			return 'xls';
		}

		$extension = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
		if ( 'xlsx' === $extension || 'xls' === $extension ) {
			return 'xlsx' === $extension ? 'xlsx' : 'xls';
		}

		return new WP_Error( 'wsm_invalid_format', 'Файл не похож на XLS/XLSX и не может быть импортирован.' );
	}

	private function load_xlsx_rows( string $path ): array|WP_Error {
		if ( ! class_exists( '\\Shuchkin\\SimpleXLSX' ) ) {
			require_once WSM_PATH . 'includes/lib/SimpleXLSX.php';
		}

		$xlsx = \Shuchkin\SimpleXLSX::parseFile( $path );
		if ( ! $xlsx ) {
			$error = (string) \Shuchkin\SimpleXLSX::parseError();
			return new WP_Error( 'wsm_xlsx_parse_failed', '' !== $error ? $error : 'Не удалось прочитать XLSX-файл.' );
		}

		$sheet_index = $this->find_first_non_empty_xlsx_sheet( $xlsx );
		if ( null === $sheet_index ) {
			return new WP_Error( 'wsm_empty_file', 'В XLSX-файле нет данных для импорта.' );
		}

		return array(
			'sheet_name' => (string) $xlsx->sheetName( $sheet_index ),
			'rows'       => $xlsx->readRows( $sheet_index ),
		);
	}

	private function find_first_non_empty_xlsx_sheet( \Shuchkin\SimpleXLSX $xlsx ): ?int {
		$sheet_count = (int) $xlsx->sheetsCount();

		for ( $sheet_index = 0; $sheet_index < $sheet_count; $sheet_index++ ) {
			foreach ( $xlsx->readRows( $sheet_index ) as $row ) {
				if ( ! $this->is_empty_row( $this->normalize_row( (array) $row ) ) ) {
					return $sheet_index;
				}
			}
		}

		return null;
	}

	private function load_xls_rows( string $path ): array|WP_Error {
		if ( ! class_exists( 'Spreadsheet_Excel_Reader' ) ) {
			require_once WSM_PATH . 'includes/lib/excel_reader2.php';
		}

		$reader = new Spreadsheet_Excel_Reader( $path );
		if ( ! is_array( $reader->sheets ) || empty( $reader->sheets ) ) {
			return new WP_Error( 'wsm_xls_parse_failed', 'Не удалось прочитать XLS-файл.' );
		}

		$sheet_index = $this->find_first_non_empty_xls_sheet( $reader );
		if ( null === $sheet_index ) {
			return new WP_Error( 'wsm_empty_file', 'В XLS-файле нет данных для импорта.' );
		}

		return array(
			'sheet_name' => $this->get_xls_sheet_name( $reader, $sheet_index ),
			'rows'       => $this->iterate_xls_rows( $reader, $sheet_index ),
		);
	}

	private function find_first_non_empty_xls_sheet( Spreadsheet_Excel_Reader $reader ): ?int {
		foreach ( $reader->sheets as $sheet_index => $sheet ) {
			if ( ! empty( $sheet['numRows'] ) ) {
				return (int) $sheet_index;
			}
		}

		return null;
	}

	private function get_xls_sheet_name( Spreadsheet_Excel_Reader $reader, int $sheet_index ): string {
		if ( isset( $reader->boundsheets[ $sheet_index ]['name'] ) ) {
			return (string) $reader->boundsheets[ $sheet_index ]['name'];
		}

		return 'Лист ' . ( $sheet_index + 1 );
	}

	private function iterate_xls_rows( Spreadsheet_Excel_Reader $reader, int $sheet_index ): iterable {
		$row_count = (int) $reader->rowcount( $sheet_index );
		$col_count = (int) $reader->colcount( $sheet_index );

		for ( $row = 1; $row <= $row_count; $row++ ) {
			$current = array();
			for ( $col = 1; $col <= $col_count; $col++ ) {
				$current[] = $this->normalize_cell_value( $reader->val( $row, $col, $sheet_index ) );
			}

			yield $current;
		}
	}

	private function import_rows( iterable $rows, string $sheet_name, string $file_type, string $source_name ): array|WP_Error {
		$index        = $this->build_existing_store_index();
		$brand_lookup = $this->build_brand_lookup();
		$result       = array(
			'file_name'      => $source_name,
			'file_type'      => $file_type,
			'sheet_name'     => $sheet_name,
			'rows_seen'      => 0,
			'rows_imported'  => 0,
			'created'        => 0,
			'updated'        => 0,
			'skipped'        => 0,
			'warnings'       => array(),
			'errors'         => array(),
			'header_map'     => array(),
			'required_keys'  => array( 'title', 'city', 'address', 'brands' ),
			'import_status'  => 'success',
		);
		$header_map   = array();
		$header_found = false;

		foreach ( $rows as $row ) {
			$row = $this->normalize_row( (array) $row );

			if ( ! $header_found ) {
				if ( $this->is_empty_row( $row ) ) {
					continue;
				}

				$header_map = $this->build_header_map( $row );
				$missing    = array();
				foreach ( $result['required_keys'] as $required_key ) {
					if ( ! isset( $header_map[ $required_key ] ) ) {
						$missing[] = $required_key;
					}
				}

				if ( ! empty( $missing ) ) {
					return new WP_Error( 'wsm_missing_columns', $this->format_missing_columns_message( $missing ) );
				}

				$result['header_map'] = $this->describe_header_map( $header_map );
				$header_found         = true;
				continue;
			}

			if ( $this->is_empty_row( $row ) ) {
				continue;
			}

			$result['rows_seen']++;
			$row_data   = $this->extract_row_data( $row, $header_map );
			$row_result = $this->import_single_row( $row_data, $index, $brand_lookup, $result['rows_seen'] );

			if ( is_wp_error( $row_result ) ) {
				$result['skipped']++;
				$this->append_message( $result['errors'], $row_result->get_error_message() );
				continue;
			}

			$result['rows_imported']++;
			if ( 'created' === $row_result['action'] ) {
				$result['created']++;
			} else {
				$result['updated']++;
			}

			foreach ( (array) $row_result['warnings'] as $warning ) {
				$this->append_message( $result['warnings'], (string) $warning );
			}
		}

		if ( ! $header_found ) {
			return new WP_Error( 'wsm_empty_file', 'В файле не найдена строка заголовков.' );
		}

		if ( 0 === $result['rows_seen'] ) {
			return new WP_Error( 'wsm_empty_file', 'В файле нет строк с данными для импорта.' );
		}

		if ( ! empty( $result['warnings'] ) || ! empty( $result['errors'] ) ) {
			$result['import_status'] = 'warning';
		}

		if ( 0 === $result['rows_imported'] && ! empty( $result['errors'] ) ) {
			$result['import_status'] = 'error';
		}

		return $result;
	}

	private function import_single_row( array $row, array &$index, array $brand_lookup, int $row_number ): array|WP_Error {
		$title       = trim( (string) ( $row['title'] ?? '' ) );
		$city        = trim( (string) ( $row['city'] ?? '' ) );
		$address     = trim( (string) ( $row['address'] ?? '' ) );
		$brands_cell = trim( (string) ( $row['brands'] ?? '' ) );
		$external_id = trim( (string) ( $row['external_id'] ?? '' ) );
		$external_id_key = $this->normalize_lookup_key( $external_id );
		$warnings    = array();

		if ( '' === $title ) {
			return new WP_Error( 'wsm_row_missing_title', 'Строка ' . $row_number . ': не указано название магазина.' );
		}

		if ( '' === $city ) {
			return new WP_Error( 'wsm_row_missing_city', 'Строка ' . $row_number . ': не указан город.' );
		}

		if ( '' === $address ) {
			return new WP_Error( 'wsm_row_missing_address', 'Строка ' . $row_number . ': не указан адрес.' );
		}

		$brand_slugs = $this->parse_brand_slugs( $brands_cell, $brand_lookup, $warnings );
		if ( empty( $brand_slugs ) ) {
			return new WP_Error( 'wsm_row_missing_brands', 'Строка ' . $row_number . ': не распознаны бренды.' );
		}

		$existing = $this->resolve_existing_store( $index, $external_id_key, $title );
		if ( 'ambiguous' === $existing['status'] ) {
			$label = 'external_id' === $existing['mode'] ? 'external_id' : 'название';
			return new WP_Error(
				'wsm_row_ambiguous_match',
				'Строка ' . $row_number . ': найдено несколько магазинов для ' . $label . '. Добавьте external_id.'
			);
		}

		$coordinates = $this->resolve_coordinates(
			$city,
			$address,
			(string) ( $row['lat'] ?? '' ),
			(string) ( $row['lng'] ?? '' )
		);

		if ( ! empty( $coordinates['warnings'] ) ) {
			foreach ( (array) $coordinates['warnings'] as $warning ) {
				$warnings[] = (string) $warning;
			}
		}

		$post_data = array(
			'post_type'   => WSM_Plugin::STORE_POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $title,
		);

		if ( 'found' === $existing['status'] ) {
			$post_data['ID'] = (int) $existing['post_id'];
			$post_id         = wp_update_post( $post_data, true );
			$action          = 'updated';
		} else {
			$post_id = wp_insert_post( $post_data, true );
			$action  = 'created';
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post_id = (int) $post_id;

		update_post_meta( $post_id, WSM_Plugin::STORE_META_CITY, $city );
		update_post_meta( $post_id, WSM_Plugin::STORE_META_ADDRESS, $address );
		update_post_meta( $post_id, WSM_Plugin::STORE_META_BRAND_SLUGS, $brand_slugs );

		if ( '' !== $external_id ) {
			update_post_meta( $post_id, WSM_Plugin::STORE_META_EXTERNAL_ID, $external_id );
		}

		if ( '' !== $coordinates['lat'] && '' !== $coordinates['lng'] ) {
			update_post_meta( $post_id, WSM_Plugin::STORE_META_LAT, (string) $coordinates['lat'] );
			update_post_meta( $post_id, WSM_Plugin::STORE_META_LNG, (string) $coordinates['lng'] );
			delete_post_meta( $post_id, WSM_Plugin::STORE_META_GEOCODE_ERROR );
		} else {
			delete_post_meta( $post_id, WSM_Plugin::STORE_META_LAT );
			delete_post_meta( $post_id, WSM_Plugin::STORE_META_LNG );
			if ( '' !== (string) $coordinates['geocode_error'] ) {
				update_post_meta( $post_id, WSM_Plugin::STORE_META_GEOCODE_ERROR, (string) $coordinates['geocode_error'] );
			} else {
				delete_post_meta( $post_id, WSM_Plugin::STORE_META_GEOCODE_ERROR );
			}
		}

		$validation_messages = array_merge( $warnings, (array) $coordinates['warnings'] );
		$validation_messages = array_values( array_filter( array_map( 'trim', $validation_messages ) ) );
		if ( ! empty( $validation_messages ) ) {
			update_post_meta( $post_id, WSM_Plugin::STORE_META_VALIDATION_ERRORS, implode( "\n", array_unique( $validation_messages ) ) );
		} else {
			delete_post_meta( $post_id, WSM_Plugin::STORE_META_VALIDATION_ERRORS );
		}

		$this->register_index_value( $index, 'title', $this->normalize_lookup_key( $title ), $post_id );
		if ( '' !== $external_id_key ) {
			$this->register_index_value( $index, 'external_id', $external_id_key, $post_id );
		}

		return array(
			'post_id'  => $post_id,
			'action'   => $action,
			'warnings' => $validation_messages,
		);
	}

	private function resolve_coordinates( string $city, string $address, string $lat_raw, string $lng_raw ): array {
		$lat = WSM_Plugin::sanitize_float( $lat_raw );
		$lng = WSM_Plugin::sanitize_float( $lng_raw );

		if ( '' !== $lat && '' !== $lng ) {
			return array(
				'lat'      => $lat,
				'lng'      => $lng,
				'warnings' => array(),
				'geocode_error' => '',
			);
		}

		if ( '' === $city || '' === $address ) {
			return array(
				'lat'           => '',
				'lng'           => '',
				'warnings'      => array( 'Укажите координаты вручную или настройте автогеокодирование.' ),
				'geocode_error' => '',
			);
		}

		$result = $this->plugin->geocode_address( $city, $address );
		if ( is_wp_error( $result ) ) {
			$message = $result->get_error_message();

			return array(
				'lat'           => '',
				'lng'           => '',
				'warnings'      => array( $message ),
				'geocode_error' => $message,
			);
		}

		return array(
			'lat'           => (string) $result['lat'],
			'lng'           => (string) $result['lng'],
			'warnings'      => array(),
			'geocode_error' => '',
		);
	}

	private function build_existing_store_index(): array {
		$index = array(
			'external_id' => array(),
			'title'       => array(),
		);

		$posts = get_posts(
			array(
				'post_type'              => WSM_Plugin::STORE_POST_TYPE,
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $posts as $post_id ) {
			$post_id = (int) $post_id;
			$this->register_index_value( $index, 'title', $this->normalize_lookup_key( (string) get_the_title( $post_id ) ), $post_id );
			$this->register_index_value( $index, 'external_id', $this->normalize_lookup_key( (string) get_post_meta( $post_id, WSM_Plugin::STORE_META_EXTERNAL_ID, true ) ), $post_id );
		}

		return $index;
	}

	private function resolve_existing_store( array $index, string $external_id, string $title ): array {
		if ( '' !== $external_id ) {
			$ids = array_values( array_unique( array_map( 'intval', (array) ( $index['external_id'][ $external_id ] ?? array() ) ) ) );
			if ( count( $ids ) > 1 ) {
				return array(
					'status' => 'ambiguous',
					'mode'   => 'external_id',
					'ids'    => $ids,
				);
			}

			if ( 1 === count( $ids ) ) {
				return array(
					'status'  => 'found',
					'mode'    => 'external_id',
					'post_id' => $ids[0],
				);
			}

			return array(
				'status' => 'new',
				'mode'   => 'external_id',
			);
		}

		$title_key = $this->normalize_lookup_key( $title );
		$ids       = array_values( array_unique( array_map( 'intval', (array) ( $index['title'][ $title_key ] ?? array() ) ) ) );
		if ( count( $ids ) > 1 ) {
			return array(
				'status' => 'ambiguous',
				'mode'   => 'title',
				'ids'    => $ids,
			);
		}

		if ( 1 === count( $ids ) ) {
			return array(
				'status'  => 'found',
				'mode'    => 'title',
				'post_id' => $ids[0],
			);
		}

		return array(
			'status' => 'new',
			'mode'   => 'title',
		);
	}

	private function register_index_value( array &$index, string $field, string $key, int $post_id ): void {
		if ( '' === $key ) {
			return;
		}

		if ( ! isset( $index[ $field ] ) || ! is_array( $index[ $field ] ) ) {
			$index[ $field ] = array();
		}

		if ( ! isset( $index[ $field ][ $key ] ) || ! is_array( $index[ $field ][ $key ] ) ) {
			$index[ $field ][ $key ] = array();
		}

		if ( ! in_array( $post_id, $index[ $field ][ $key ], true ) ) {
			$index[ $field ][ $key ][] = $post_id;
		}
	}

	private function build_brand_lookup(): array {
		$lookup = array();

		foreach ( $this->plugin->get_brand_data() as $brand ) {
			$slug = (string) $brand['slug'];
			$lookup[ $this->normalize_lookup_key( $slug ) ] = $slug;
			$lookup[ $this->normalize_lookup_key( (string) $brand['name'] ) ] = $slug;
		}

		return $lookup;
	}

	private function parse_brand_slugs( string $value, array $brand_lookup, array &$warnings ): array {
		$tokens  = preg_split( '/[\,;\|\r\n]+/u', $value );
		$matched = array();
		$unknown = array();

		if ( false === $tokens ) {
			$tokens = array( $value );
		}

		foreach ( $tokens as $token ) {
			$token = trim( (string) $token );
			if ( '' === $token ) {
				continue;
			}

			$key = $this->normalize_lookup_key( $token );
			if ( '' !== $key && isset( $brand_lookup[ $key ] ) ) {
				$matched[ $brand_lookup[ $key ] ] = true;
			} else {
				$unknown[] = $token;
			}
		}

		if ( ! empty( $unknown ) ) {
			$warnings[] = 'Не распознаны бренды: ' . implode( ', ', array_unique( $unknown ) );
		}

		$ordered = array();
		foreach ( $this->plugin->get_brand_data() as $brand ) {
			$slug = (string) $brand['slug'];
			if ( isset( $matched[ $slug ] ) ) {
				$ordered[] = $slug;
			}
		}

		return $ordered;
	}

	private function build_header_map( array $header_row ): array {
		$map     = array();
		$aliases = self::column_aliases();

		foreach ( $header_row as $column_index => $header_value ) {
			$normalized = $this->normalize_lookup_key( (string) $header_value );
			if ( '' === $normalized ) {
				continue;
			}

			foreach ( $aliases as $field => $field_aliases ) {
				if ( isset( $map[ $field ] ) ) {
					continue;
				}

				foreach ( $field_aliases as $alias ) {
					if ( $normalized === $this->normalize_lookup_key( $alias ) ) {
						$map[ $field ] = array(
							'index'  => (int) $column_index,
							'header' => trim( (string) $header_value ),
						);
						break 2;
					}
				}
			}
		}

		return $map;
	}

	public static function column_aliases(): array {
		return array(
			'title'       => array( 'title', 'name', 'название', 'наименование', 'магазин' ),
			'city'        => array( 'city', 'город' ),
			'address'     => array( 'address', 'адрес' ),
			'brands'      => array( 'brands', 'brand', 'brand_slugs', 'brand_slug', 'бренды', 'бренд' ),
			'lat'         => array( 'lat', 'latitude', 'широта' ),
			'lng'         => array( 'lng', 'lon', 'long', 'longitude', 'долгота' ),
			'external_id' => array( 'external_id', 'id', 'externalid', 'код', 'артикул' ),
		);
	}

	private function describe_header_map( array $header_map ): array {
		$described = array();

		foreach ( $header_map as $field => $meta ) {
			$described[ $field ] = array(
				'header' => isset( $meta['header'] ) ? (string) $meta['header'] : $field,
				'index'  => isset( $meta['index'] ) ? (int) $meta['index'] : 0,
			);
		}

		return $described;
	}

	private function extract_row_data( array $row, array $header_map ): array {
		$data = array();

		foreach ( array( 'title', 'city', 'address', 'brands', 'lat', 'lng', 'external_id' ) as $field ) {
			$index = isset( $header_map[ $field ]['index'] ) ? (int) $header_map[ $field ]['index'] : null;
			$data[ $field ] = null !== $index && array_key_exists( $index, $row ) ? $row[ $index ] : '';
		}

		return $data;
	}

	private function normalize_row( array $row ): array {
		$normalized = array();

		foreach ( $row as $index => $value ) {
			$normalized[ (int) $index ] = $this->normalize_cell_value( $value );
		}

		return $normalized;
	}

	private function normalize_cell_value( $value ): string {
		if ( null === $value ) {
			return '';
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		if ( is_array( $value ) ) {
			return '';
		}

		$value = trim( str_replace( "\xC2\xA0", ' ', (string) $value ) );

		return $value;
	}

	private function is_empty_row( array $row ): bool {
		foreach ( $row as $value ) {
			if ( '' !== trim( (string) $value ) ) {
				return false;
			}
		}

		return true;
	}

	private function normalize_lookup_key( string $value ): string {
		$value = trim( wp_strip_all_tags( $value ) );
		if ( '' === $value ) {
			return '';
		}

		if ( function_exists( 'remove_accents' ) ) {
			$value = remove_accents( $value );
		}

		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$value = preg_replace( '/[^\pL\pN]+/u', '_', $value );
		$value = preg_replace( '/_+/', '_', (string) $value );

		return trim( (string) $value, '_' );
	}

	private function format_missing_columns_message( array $missing ): string {
		$labels = array(
			'title'   => 'title / название',
			'city'    => 'city / город',
			'address' => 'address / адрес',
			'brands'  => 'brands / бренды',
		);
		$parts  = array();

		foreach ( $missing as $field ) {
			$parts[] = isset( $labels[ $field ] ) ? $labels[ $field ] : $field;
		}

		return 'Не найдены обязательные колонки: ' . implode( ', ', $parts ) . '.';
	}

	private function append_message( array &$messages, string $message ): void {
		$message = trim( $message );
		if ( '' === $message ) {
			return;
		}

		if ( ! in_array( $message, $messages, true ) ) {
			$messages[] = $message;
		}
	}

	private function get_upload_error_message( int $error_code ): string {
		switch ( $error_code ) {
			case UPLOAD_ERR_INI_SIZE:
				return 'Файл превышает лимит, заданный на сервере.';
			case UPLOAD_ERR_FORM_SIZE:
				return 'Файл превышает лимит формы.';
			case UPLOAD_ERR_PARTIAL:
				return 'Файл загружен не полностью.';

			case UPLOAD_ERR_NO_FILE:
				return 'Файл не выбран.';

			case UPLOAD_ERR_NO_TMP_DIR:
				return 'На сервере не найдена временная папка для загрузки.';

			case UPLOAD_ERR_CANT_WRITE:
				return 'Не удалось сохранить загруженный файл.';

			case UPLOAD_ERR_EXTENSION:
				return 'Загрузка файла остановлена расширением PHP.';

			default:
				return 'Не удалось загрузить файл.';
		}
	}
}
