<?php
namespace AAA;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ZipImport {
	const MAX_BYTES = 52428800;
	const MAX_MEDIA_BYTES = 20971520;
	const META = '_aaa_zip_media_v1';
	const MIMES = array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf' );

	public static function supported(): bool {
		return class_exists( \ZipArchive::class ) && class_exists( \finfo::class );
	}

	public static function post_limit(): int { return wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) ); }

	public static function upload_limit(): int {
		// Reserve space for the multipart fields and boundary.
		$post = self::post_limit();
		return min( self::MAX_BYTES, wp_max_upload_size(), $post > 0 ? max( 0, $post - 16384 ) : self::MAX_BYTES );
	}

	public static function available(): bool {
		return self::supported() && current_user_can( 'upload_files' ) && self::upload_limit() > 0;
	}

	private static function entries( \ZipArchive $zip ): array {
		if ( $zip->numFiles > Media::MAX_ITEMS + 3 ) {
			throw new \InvalidArgumentException( 'ZIPには記事CSV1点と画像・PDFを合計20点まで入れられます。' );
		}
		$entries = array();
		$paths = array();
		$total = 0;
		$media_limit = min( self::MAX_MEDIA_BYTES, wp_max_upload_size() );
		for ( $index = 0; $index < $zip->numFiles; ++$index ) {
			$stat = $zip->statIndex( $index );
			$path = $stat['name'] ?? '';
			if ( ! $stat || strlen( $path ) > 240 || ! preg_match( '//u', $path ) || preg_match( '~[\x00-\x1f\x7f:\\\\]~', $path )
				|| ! $zip->getExternalAttributesIndex( $index, $os, $attributes ) ) {
				throw new \InvalidArgumentException( 'ZIP内に不正なファイル名やパスがあります。' );
			}
			$directory = in_array( $path, array( 'images/', 'files/' ), true );
			$mode = ( $attributes >> 16 ) & 0170000;
			if ( ( \ZipArchive::OPSYS_UNIX === $os && ! in_array( $mode, array( 0, $directory ? 0040000 : 0100000 ), true ) )
				|| 0 !== ( $stat['encryption_method'] ?? -1 ) || isset( $paths[ strtolower( $path ) ] )
				|| ! in_array( $stat['comp_method'], array( \ZipArchive::CM_STORE, \ZipArchive::CM_DEFLATE ), true ) ) {
				throw new \InvalidArgumentException( 'ZIP内に重複したパス、リンク、暗号化、または未対応の圧縮形式があります。' );
			}
			$paths[ strtolower( $path ) ] = true;
			if ( $directory && 0 === $stat['size'] ) { continue; }
			$name = basename( $path );
			$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			$is_csv = 'article.csv' === $path;
			$mime = $is_csv ? '' : wp_check_filetype( $name, self::MIMES )['type'];
			$folder = 'application/pdf' === $mime ? 'files' : 'images';
			if ( ! $is_csv && ( ! $mime || '.' === $name[0] || $path !== $folder . '/' . $name || strpbrk( $name, '<>"|?*' ) !== false ) ) {
				throw new \InvalidArgumentException( 'ZIPの直下にはarticle.csv、images/にはJPG・PNG・GIF・WebP、files/にはPDFを入れてください。' );
			}
			$size = $stat['size'];
			$total += $size;
			if ( $size < 1 || $size > ( $is_csv ? Csv::MAX_BYTES : $media_limit ) || $total > self::MAX_BYTES || $size > 200 * max( 1, $stat['comp_size'] ) ) {
				throw new \InvalidArgumentException( 'ZIP内のファイルが空か、個別容量・展開後の合計50MB・圧縮率の上限を超えています。' );
			}
			if ( isset( $entries[ strtolower( $name ) ] ) ) {
				throw new \InvalidArgumentException( 'ZIP内に同じファイル名があります。別の名前に変更してください。' );
			}
			$entries[ strtolower( $name ) ] = array( 'path' => $path, 'name' => $name, 'size' => $size, 'crc' => sprintf( '%08x', $stat['crc'] & 0xffffffff ), 'mime' => $mime, 'extension' => $extension );
		}
		if ( ! isset( $entries['article.csv'] ) || count( $entries ) > Media::MAX_ITEMS + 1 ) {
			throw new \InvalidArgumentException( 'ZIPにはarticle.csvを1点と画像・PDFを合計20点まで入れてください。' );
		}
		return $entries;
	}

	private static function copy_entry( \ZipArchive $zip, array $entry, string $path ): void {
		$input = @$zip->getStream( $entry['path'] );
		if ( ! is_resource( $input ) ) { throw new \InvalidArgumentException( 'ZIP内のファイルを読み出せません。' ); }
		$output = null;
		try {
			$output = @fopen( $path, 'wb' );
			if ( ! is_resource( $output ) || @stream_copy_to_stream( $input, $output, $entry['size'] + 1 ) !== $entry['size'] ) {
				throw new \InvalidArgumentException( 'ZIPの展開サイズが一致しないか、一時ファイルへ保存できません。' );
			}
		} finally {
			if ( is_resource( $output ) ) { fclose( $output ); }
			fclose( $input );
		}
		if ( hash_file( 'crc32b', $path ) !== $entry['crc'] ) {
			throw new \InvalidArgumentException( 'ZIP内のファイルが破損しています。' );
		}
	}

	private static function validate_media( array $entry, \finfo $info ): void {
		$file = $entry['temp'];
		$check = wp_check_filetype_and_ext( $file, $entry['name'] );
		if ( $info->file( $file ) !== $entry['mime'] || $check['type'] !== $entry['mime'] || strtolower( (string) $check['ext'] ) !== $entry['extension'] ) {
			throw new \InvalidArgumentException( '拡張子・実ファイル形式・WordPressの許可形式を確認してください。 ' . $entry['name'] );
		}
		if ( 'application/pdf' === $entry['mime'] ) { return; }
		$image = @getimagesize( $file );
		if ( ! $image || $image['mime'] !== $entry['mime'] || max( $image[0], $image[1] ) > 10000 || $image[0] * $image[1] > 40000000 ) {
			throw new \InvalidArgumentException( '画像の形式または寸法を確認してください（各辺1万px・4,000万画素まで）。 ' . $entry['name'] );
		}
	}

	/** The consumer runs only after every entry has passed validation. */
	public static function read( string $path, callable $consume ) {
		if ( ! self::available() ) { return new \WP_Error( 'zip_unavailable', 'ZIP取り込みにはアップロード権限とサーバーのZIP・Fileinfo拡張が必要です。CSV単独で取り込めます。' ); }
		$size = @filesize( $path );
		if ( ! $size || $size > self::upload_limit() ) {
			return new \WP_Error( 'zip_size', 'ZIPが空か、このサイトのアップロード上限を超えています。' );
		}
		$zip = new \ZipArchive();
		if ( true !== @$zip->open( $path, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS ) ) {
			return new \WP_Error( 'zip_format', 'ZIPを開けません。破損や形式を確認してください。' );
		}
		$temps = array();
		$cleanup = static function () use ( &$temps ): void {
			foreach ( $temps as $temp ) { if ( is_file( $temp ) ) { wp_delete_file( $temp ); } }
			$temps = array();
		};
		register_shutdown_function( $cleanup );
		try {
			$entries = self::entries( $zip );
			if ( ! function_exists( 'wp_handle_sideload' ) ) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
			$info = new \finfo( FILEINFO_MIME_TYPE );
			foreach ( $entries as &$entry ) {
				$temp = @tempnam( sys_get_temp_dir(), 'aaa-zip-' );
				if ( false === $temp ) { throw new \InvalidArgumentException( 'ZIPの展開用一時ファイルを作成できません。' ); }
				$temps[] = $temp;
				self::copy_entry( $zip, $entry, $temp );
				$entry['temp'] = $temp;
				if ( $entry['mime'] ) { self::validate_media( $entry, $info ); }
			}
			unset( $entry );
			$source = file_get_contents( $entries['article.csv']['temp'] );
			unset( $entries['article.csv'] );
			if ( false === $source ) { throw new \InvalidArgumentException( 'ZIP内の記事CSVを読み込めません。' ); }
			return $consume( $source, $entries, hash_file( 'sha256', $path ) );
		} catch ( \InvalidArgumentException $error ) {
			return new \WP_Error( 'zip_entry', $error->getMessage() );
		} finally {
			$zip->close();
			$cleanup();
		}
	}

	public static function register( string $key, string $ref, string $kind, array $entry ) {
		if ( ! current_user_can( 'upload_files' ) ) { return new \WP_Error( 'permission', 'メディアを登録する権限がありません。' ); }
		$hash = hash_file( 'sha256', $entry['temp'] );
		if ( false === $hash ) { return new \WP_Error( 'zip_media', '資料の内容を確認できません。' ); }
		$user = get_current_user_id();
		$identity = hash( 'sha256', $key . ':' . $ref . ':' . $entry['mime'] . ':' . $hash );
		$ids = get_posts( array(
			'post_type' => 'attachment', 'post_status' => 'inherit', 'author' => $user,
			'meta_key' => self::META, 'meta_value' => $identity, 'fields' => 'ids', 'posts_per_page' => 10,
		) );
		foreach ( $ids as $id ) {
			$id = (int) $id;
			$media = Media::attachment( $id, $kind );
			if ( $media && $entry['mime'] === $media['mime'] && get_post_meta( $id, self::META, true ) === $identity ) { return $id; }
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) { require_once ABSPATH . 'wp-admin/includes/image.php'; }
		$upload = array( 'name' => $entry['name'], 'type' => $entry['mime'], 'tmp_name' => $entry['temp'], 'size' => $entry['size'], 'error' => UPLOAD_ERR_OK );
		$file = wp_handle_sideload( $upload, array( 'test_form' => false, 'mimes' => get_allowed_mime_types() ) );
		if ( isset( $file['error'] ) ) { return new \WP_Error( 'zip_media', $entry['name'] . ' の登録に失敗しました。 ' . $file['error'] ); }
		if ( $file['type'] !== $entry['mime'] ) {
			wp_delete_file( $file['file'] );
			return new \WP_Error( 'zip_media', $entry['name'] . ' の形式がメディア登録時に変わりました。' );
		}
		$id = wp_insert_attachment( wp_slash( array(
			'post_title' => sanitize_text_field( pathinfo( $entry['name'], PATHINFO_FILENAME ) ),
			'post_mime_type' => $file['type'], 'guid' => $file['url'], 'post_status' => 'inherit',
			'post_author' => $user, 'meta_input' => array( self::META => $identity ),
		) ), $file['file'], 0, true );
		if ( is_wp_error( $id ) || ! $id ) {
			// This request just wrote this file and no attachment was created.
			wp_delete_file( $file['file'] );
			return new \WP_Error( 'zip_media', $entry['name'] . ' のメディア情報を保存できません。' );
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file['file'] ) );
		if ( get_post_meta( $id, self::META, true ) !== $identity || ! Media::attachment( (int) $id, $kind ) ) {
			return new \WP_Error( 'zip_media_record', $entry['name'] . ' の登録結果を確認できません。メディアライブラリを確認してください（ID ' . $id . '）。' );
		}
		return (int) $id;
	}
}
