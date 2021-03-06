<?php
/**
 * @author  PressBooks <code@pressbooks.com>
 * @license GPLv2 (or any later version)
 */
namespace PressBooks\Import\WordPress;


use PressBooks\Import\Import;
use PressBooks\Book;

class Wxr extends Import {

	/**
	 * If PressBooks generated the WXR file
	 *
	 * @var boolean
	 */
	protected $isPbWxr = false;


	/**
	 * @param array $upload
	 *
	 * @return bool
	 */
	function setCurrentImportOption( array $upload ) {

		try {
			$parser = new Parser();
			$xml = $parser->parse( $upload['file'] );
		} catch ( \Exception $e ) {
			return false;
		}

		$this->pbCheck( $xml );

		$option = array(
			'file' => $upload['file'],
			'file_type' => $upload['type'],
			'type_of' => 'wxr',
			'chapters' => array(),
			'post_types' => array(),
			'allow_parts' => true
		);

		$supported_post_types = array( 'post', 'page', 'front-matter', 'chapter', 'part', 'back-matter' );

		if ( $this->isPbWxr ) {
			//put the posts in correct part / menu_order order
			$xml['posts'] = $this->customNestedSort( $xml['posts'] );
		}

		foreach ( $xml['posts'] as $p ) {

			// Skip
			if ( ! in_array( $p['post_type'], $supported_post_types ) ) continue;
			if ( empty( $p['post_content'] ) && 'part' != $p['post_type'] ) continue;
			if ( '<!-- Here be dragons.-->' == $p['post_content'] ) continue;

			// Set
			$option['chapters'][$p['post_id']] = $p['post_title'];
			$option['post_types'][$p['post_id']] = $p['post_type'];
		}

		return update_option( 'pressbooks_current_import', $option );
	}


	/**
	 * @param array $current_import
	 *
	 * @return bool
	 */
	function import( array $current_import ) {

		try {
			$parser = new Parser();
			$xml = $parser->parse( $current_import['file'] );
		} catch ( \Exception $e ) {
			return false;
		}

		$this->pbCheck( $xml );
		
		if ( $this->isPbWxr ) {
			$xml['posts'] = $this->customNestedSort( $xml['posts'] );
		}
		
		$match_ids = array_flip( array_keys( $current_import['chapters'] ) );
		$chapter_parent = $this->getChapterParent();
		$total = 0;

		libxml_use_internal_errors( true );
		
		foreach ( $xml['posts'] as $p ) {

			// Skip
			if ( ! $this->flaggedForImport( $p['post_id'] ) ) continue;
			if ( ! isset( $match_ids[$p['post_id']] ) ) continue;

			// Insert
			$post_type = $this->determinePostType( $p['post_id'] );

			// Load HTMl snippet into DOMDocument using UTF-8 hack
			$utf8_hack = '<?xml version="1.0" encoding="UTF-8"?>';
			$doc = new \DOMDocument();
			$doc->loadHTML( $utf8_hack . $this->tidy( $p['post_content'] ) );

			// Download images, change image paths
			$doc = $this->scrapeAndKneadImages( $doc );

			$html = $doc->saveXML( $doc->documentElement );

			// Remove auto-created <html> <body> and <!DOCTYPE> tags.
			$html = preg_replace( '/^<!DOCTYPE.+?>/', '', str_replace( array( '<html>', '</html>', '<body>', '</body>' ), array( '', '', '', '' ), $html ) );

			$new_post = array(
				'post_title' => wp_strip_all_tags( $p['post_title'] ),
				'post_type' => $post_type,
				'post_status' => ( 'part' == $post_type )?'publish':'draft',
			);
			if ( 'part' != $post_type ) {
				$new_post['post_content'] = $html;
			}
			
			if ( 'chapter' == $post_type ) {
				$new_post['post_parent'] = $chapter_parent;
			}

			$pid = wp_insert_post( $new_post );
			
			if ( 'part' == $post_type ) {
				$chapter_parent = $pid;
			}

			$meta_to_update = apply_filters( 'pb_import_metakeys', array( 'pb_section_author', 'pb_section_license', 'pb_short_title', 'pb_subtitle' ) );
			
			if ( isset( $p['postmeta'] ) && is_array( $p['postmeta'] ) ) {
				foreach ($meta_to_update as $meta_key) {
					$meta_val = $this->searchForMetaValue( $meta_key, $p['postmeta'] );
					if (is_serialized($meta_val)) {
						$meta_val = unserialize($meta_val);
					}
					if ($meta_val) {
						update_post_meta( $pid, $meta_key, $meta_val);
					}
				}
				if ( 'part' == $post_type ) {
					$part_content = $this->searchForPartContent( $p['postmeta'] );
					if ( $part_content ) {
						update_post_meta( $pid, 'pb_part_content', $part_content );
					}
				}
			}

			update_post_meta( $pid, 'pb_show_title', 'on' );
			update_post_meta( $pid, 'pb_export', 'on' );

			Book::consolidatePost( $pid, get_post( $pid ) ); // Reorder
			++$total;
		}

		// Done
		$_SESSION['pb_notices'][] = sprintf( __( 'Imported %s chapters.', 'pressbooks' ), $total );
		return $this->revokeCurrentImport();
	}


	/**
	 * Is it a WXR generated by PB?
	 *
	 * @param array $xml
	 */
	protected function pbCheck( array $xml ) {

		$pt = $ch = $fm = $bm = 0;

		foreach ( $xml['posts'] as $p ) {

			if ( 'part' == $p['post_type'] ) $pt = 1;
			elseif ( 'chapter' == $p['post_type'] ) $ch = 1;
			elseif ( 'front-matter' == $p['post_type'] ) $fm = 1;
			elseif ( 'back-matter' == $p['post_type'] ) $bm = 1;

			if ( $pt + $ch + $fm + $bm >= 2 ) {
				$this->isPbWxr = true;
				break;
			}
		}

	}
	
	/**
	 * Custom sort for the xml posts to put them in correct nested order
	 *
	 * @param array $xml
	 *
	 * @return array sorted $xml
	 */
	 protected function customNestedSort( $xml ) {
	 	 $array = array();
	 	 
	 	 //first, put them in ascending menu_order
	 	 usort( $xml, function ( $a, $b ) {
	 	 	return ( $a['menu_order'] - $b['menu_order'] );
	 	 });
	 	 
	 	 //now, list all front matter
	 	 foreach ( $xml as $p ) {
	 	 	if ( 'front-matter' == $p['post_type'] ) {
	 	 		$array[] = $p;	
	 	 	}
	 	 }
	 	 
	 	 //now, list all parts, then their associated chapters
	 	 foreach ( $xml as $p ) {
	 	 	if ( 'part' == $p['post_type'] ) {
	 	 		$array[] = $p;
	 	 		foreach ( $xml as $psub ) {
	 	 			if ( 'chapter' == $psub['post_type'] && $psub['post_parent'] == $p['post_id'] ) {
	 	 				$array[] = $psub;
	 	 			}
	 	 		}
	 	 	}
	 	 }
	 	 
	 	 //now, list all back matter
	 	 foreach ( $xml as $p ) {
	 	 	if ( 'back-matter' == $p['post_type'] ) {
	 	 		$array[] = $p;	
	 	 	}
	 	 }
	 	 return $array;
	 }

	/**
	 * Check for PB specific metadata, returns empty string if not found.
	 *
	 * @param $meta_key, array $postmeta
	 *
	 * @return string meta field value
	 */
	protected function searchForMetaValue( $meta_key, array $postmeta ) {

		if ( empty( $postmeta ) ) {
			return '';
		}

		foreach ( $postmeta as $meta ) {
			// prefer this value, if it's set
			if ( $meta_key == $meta['key'] ) {
				return $meta['value'];
			}
		}

		return '';
	}

	/**
	 * Check for PB specific metadata, returns empty string if not found.
	 *
	 * @param array $postmeta
	 *
	 * @return string Part Content
	 */
	protected function searchForPartContent( array $postmeta ) {

		if ( empty( $postmeta ) ) {
			return '';
		}

		foreach ( $postmeta as $meta ) {
			// prefer this value, if it's set
			if ( 'pb_part_content' == $meta['key'] ) {
				return $meta['value'];
			}
		}

		return '';
	}
	
	/**
	 * Parse HTML snippet, save all found <img> tags using media_handle_sideload(), return the HTML with changed <img> paths.
	 *
	 * @param \DOMDocument $doc
	 *
	 * @return \DOMDocument
	 */
	protected function scrapeAndKneadImages( \DOMDocument $doc ) {

		$images = $doc->getElementsByTagName( 'img' );

		foreach ( $images as $image ) {
			// Fetch image, change src
			$old_src = $image->getAttribute( 'src' );

			$new_src = $this->fetchAndSaveUniqueImage( $old_src );

			if ( $new_src ) {
				// Replace with new image
				$image->setAttribute( 'src', $new_src );
			} else {
				// Tag broken image
				$image->setAttribute( 'src', "{$old_src}#fixme" );
			}
		}

		return $doc;
	}


	/**
	 * Load remote url of image into WP using media_handle_sideload()
	 * Will return an empty string if something went wrong.
	 *
	 * @param string $url 
	 *
	 * @see media_handle_sideload
	 *
	 * @return string filename
	 */
	protected function fetchAndSaveUniqueImage( $url ) {

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return '';
		}

		$remote_img_location = $url;

		// Cheap cache
		static $already_done = array ( );
		if ( isset( $already_done[$remote_img_location] ) ) {
			return $already_done[$remote_img_location];
		}

		/* Process */

		// Basename without query string
		$filename = explode( '?', basename( $url ) );
		$filename = array_shift( $filename );

		$filename = sanitize_file_name( urldecode( $filename ) );

		if ( ! preg_match( '/\.(jpe?g|gif|png)$/i', $filename ) ) {
			// Unsupported image type
			$already_done[$remote_img_location] = '';
			return '';
		}

		$tmp_name = download_url( $remote_img_location );
		if ( is_wp_error( $tmp_name ) ) {
			// Download failed
			$already_done[$remote_img_location] = '';
			return '';
		}

		if ( ! \PressBooks\Image\is_valid_image( $tmp_name, $filename ) ) {

			try { // changing the file name so that extension matches the mime type
				$filename = $this->properImageExtension( $tmp_name, $filename );

				if ( ! \PressBooks\Image\is_valid_image( $tmp_name, $filename ) ) {
					throw new \Exception( 'Image is corrupt, and file extension matches the mime type' );
				}
			} catch ( \Exception $exc ) {
				// Garbage, don't import
				$already_done[$remote_img_location] = '';
				unlink( $tmp_name );
				return '';
			}
		}

		$pid = media_handle_sideload( array ( 'name' => $filename, 'tmp_name' => $tmp_name ), 0 );
		$src = wp_get_attachment_url( $pid );
		if ( ! $src ) $src = ''; // Change false to empty string
		$already_done[$remote_img_location] = $src;
		@unlink( $tmp_name );

		return $src;
	}

}