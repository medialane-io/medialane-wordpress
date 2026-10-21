<?php

namespace TokenizeContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Markdown {

	const SKIPPED = array( 'script', 'style', 'noscript', 'template', 'form', 'button', 'select', 'textarea' );

	const HEADINGS = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );

	public static function from_html( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}
		$previous = libxml_use_internal_errors( true );
		$doc      = new \DOMDocument();
		$doc->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$root = $doc->getElementsByTagName( 'div' )->item( 0 );
		if ( ! $root ) {
			return '';
		}
		$text = self::blocks( $root );
		$text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );
		return trim( (string) $text );
	}

	private static function blocks( \DOMNode $parent ): string {
		$out    = '';
		$inline = '';
		foreach ( $parent->childNodes as $node ) {
			if ( self::is_inline( $node ) ) {
				$inline .= self::inline( $node );
				continue;
			}
			$out   .= self::paragraph( $inline );
			$inline = '';
			$out   .= self::block( $node );
		}
		return $out . self::paragraph( $inline );
	}

	private static function is_inline( \DOMNode $node ): bool {
		if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) {
			return true;
		}
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return false;
		}
		return in_array( $node->nodeName, array( 'a', 'abbr', 'b', 'br', 'cite', 'code', 'em', 'i', 'img', 'mark', 'small', 'span', 'strong', 'sub', 'sup', 'u', 'time', 'del', 's', 'q', 'kbd' ), true );
	}

	private static function paragraph( string $inline ): string {
		$text = trim( preg_replace( array( '/\n[ \t]+/', '/ {2,}(?!\n)/', '/ [ ]+\n/' ), array( "\n", ' ', "  \n" ), $inline ) );
		return '' === $text ? '' : self::escape_line_starts( $text ) . "\n\n";
	}

	private static function block( \DOMNode $node ): string {
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}
		$tag = $node->nodeName;
		if ( in_array( $tag, self::SKIPPED, true ) ) {
			return '';
		}
		if ( in_array( $tag, self::HEADINGS, true ) ) {
			$text = trim( self::inline_children( $node ) );
			return '' === $text ? '' : str_repeat( '#', (int) substr( $tag, 1 ) ) . ' ' . $text . "\n\n";
		}
		switch ( $tag ) {
			case 'p':
				return self::paragraph( self::inline_children( $node ) );
			case 'hr':
				return "---\n\n";
			case 'pre':
				return self::fence( $node );
			case 'blockquote':
				return self::quote( $node );
			case 'ul':
			case 'ol':
				return self::list_block( $node, 0 ) . "\n";
			case 'table':
				return self::table( $node );
			case 'iframe':
				return self::embed( $node );
			case 'figcaption':
				return self::paragraph( self::inline_children( $node ) );
			default:
				return self::blocks( $node );
		}
	}

	private static function fence( \DOMNode $node ): string {
		$code = rtrim( $node->textContent, "\n" );
		return "```\n" . $code . "\n```\n\n";
	}

	private static function quote( \DOMNode $node ): string {
		$inner = trim( self::blocks( $node ) );
		if ( '' === $inner ) {
			return '';
		}
		$lines = array_map( function ( $line ) {
			return '' === $line ? '>' : '> ' . $line;
		}, explode( "\n", $inner ) );
		return implode( "\n", $lines ) . "\n\n";
	}

	private static function list_block( \DOMNode $list, int $depth ): string {
		$ordered = 'ol' === $list->nodeName;
		$n       = 0;
		$out     = '';
		foreach ( $list->childNodes as $item ) {
			if ( 'li' !== $item->nodeName ) {
				continue;
			}
			++$n;
			$marker = $ordered ? $n . '. ' : '- ';
			$text   = '';
			$nested = '';
			foreach ( $item->childNodes as $child ) {
				if ( in_array( $child->nodeName, array( 'ul', 'ol' ), true ) ) {
					$nested .= self::list_block( $child, $depth + 1 );
				} elseif ( self::is_inline( $child ) ) {
					$text .= self::inline( $child );
				} else {
					$text .= ' ' . trim( self::blocks( $child ) );
				}
			}
			$text = trim( preg_replace( '/\s*\n\s*/', ' ', $text ) );
			$out .= str_repeat( '   ', $depth ) . $marker . $text . "\n" . $nested;
		}
		return $out;
	}

	private static function table( \DOMNode $table ): string {
		$out = '';
		foreach ( $table->getElementsByTagName( 'tr' ) as $row ) {
			$cells = array();
			foreach ( $row->childNodes as $cell ) {
				if ( 'td' === $cell->nodeName || 'th' === $cell->nodeName ) {
					$cells[] = trim( preg_replace( '/\s+/', ' ', self::inline_children( $cell ) ) );
				}
			}
			if ( $cells ) {
				$out .= implode( ' | ', $cells ) . "\n\n";
			}
		}
		return $out;
	}

	private static function embed( \DOMElement $node ): string {
		$src = $node->getAttribute( 'src' );
		return self::is_web_url( $src ) ? '[' . self::escape_text( $node->getAttribute( 'title' ) ?: $src ) . '](' . self::url( $src ) . ")\n\n" : '';
	}

	private static function inline_children( \DOMNode $node ): string {
		$out = '';
		foreach ( $node->childNodes as $child ) {
			$out .= self::inline( $child );
		}
		return $out;
	}

	private static function inline( \DOMNode $node ): string {
		if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) {
			return self::escape_text( preg_replace( '/[ \t\r\n\f]+/', ' ', $node->nodeValue ) );
		}
		if ( XML_ELEMENT_NODE !== $node->nodeType || in_array( $node->nodeName, self::SKIPPED, true ) ) {
			return '';
		}
		switch ( $node->nodeName ) {
			case 'br':
				return "  \n";
			case 'strong':
			case 'b':
				return self::wrap( self::inline_children( $node ), '**' );
			case 'em':
			case 'i':
			case 'cite':
				return self::wrap( self::inline_children( $node ), '*' );
			case 'code':
			case 'kbd':
				return self::code_span( $node->textContent );
			case 'a':
				return self::anchor( $node );
			case 'img':
				return self::image( $node );
			default:
				if ( XML_ELEMENT_NODE === $node->nodeType && ! self::is_inline( $node ) ) {
					return ' ' . trim( self::blocks( $node ) ) . ' ';
				}
				return self::inline_children( $node );
		}
	}

	private static function wrap( string $text, string $mark ): string {
		$core = trim( $text );
		if ( '' === $core ) {
			return $text;
		}
		$lead  = substr( $text, 0, strlen( $text ) - strlen( ltrim( $text ) ) );
		$trail = substr( $text, strlen( rtrim( $text ) ) );
		return $lead . $mark . $core . $mark . $trail;
	}

	private static function code_span( string $text ): string {
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		if ( '' === $text ) {
			return '';
		}
		return false === strpos( $text, '`' ) ? '`' . $text . '`' : '`` ' . $text . ' ``';
	}

	private static function anchor( \DOMElement $node ): string {
		$text = self::inline_children( $node );
		$href = trim( $node->getAttribute( 'href' ) );
		if ( '' === trim( $text ) || ! ( self::is_web_url( $href ) || 0 === stripos( $href, 'mailto:' ) ) ) {
			return $text;
		}
		return '[' . trim( $text ) . '](' . self::url( $href ) . ')';
	}

	private static function image( \DOMElement $node ): string {
		$src = trim( $node->getAttribute( 'src' ) );
		if ( ! self::is_web_url( $src ) ) {
			return '';
		}
		$alt = str_replace( array( '[', ']' ), array( '\\[', '\\]' ), trim( preg_replace( '/\s+/', ' ', $node->getAttribute( 'alt' ) ) ) );
		return '![' . $alt . '](' . self::url( $src ) . ')';
	}

	private static function is_web_url( string $url ): bool {
		return 1 === preg_match( '#^https?://#i', trim( $url ) );
	}

	private static function url( string $url ): string {
		return str_replace( array( ' ', '(', ')' ), array( '%20', '%28', '%29' ), trim( $url ) );
	}

	private static function escape_text( string $text ): string {
		return preg_replace( '/([\\\\`*\[\]])/', '\\\\$1', $text );
	}

	private static function escape_line_starts( string $text ): string {
		$rules = array(
			'/^(\s*)(#{1,6})(?=\s)/m'      => '$1\\\\$2',
			'/^(\s*)>/m'                   => '$1\\\\>',
			'/^(\s*)([-+])(?=\s)/m'        => '$1\\\\$2',
			'/^(\s*)(\d+)([.)])(?=\s)/m'  => '$1$2\\\\$3',
			'/^(\s*)(-{3,}|_{3,})\s*$/m'  => '$1\\\\$2',
			'/^(\s*)```/m'                 => '$1\\\\```',
		);
		return (string) preg_replace( array_keys( $rules ), array_values( $rules ), $text );
	}
}
