<?php
/**
 * QR codes for check-in passes.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Attendance;

/**
 * A small QR Code encoder (ISO/IEC 18004, model 2): byte mode, error
 * correction level M (about 15% of the symbol can be damaged), versions 1
 * to 10, so up to 213 bytes, plenty for a check-in link. Output is inline
 * SVG, so passes need no images, external services, or JavaScript.
 *
 * Steps follow the standard: encode the bytes into data codewords, add
 * Reed–Solomon error correction per block and interleave the blocks, draw
 * the function patterns, place the codewords in the zigzag order, then
 * apply the mask with the lowest penalty and write the format and
 * version information.
 */
final class QrCode {

	private const MAX_VERSION = 10;

	/** Error correction codewords per block, level M, by version. */
	private const EC_PER_BLOCK = array( 1 => 10, 16, 26, 18, 24, 16, 18, 22, 22, 26 );

	/** Number of error correction blocks, level M, by version. */
	private const BLOCKS = array( 1 => 1, 1, 1, 2, 2, 4, 4, 4, 5, 5 );

	/** Alignment pattern centres, by version. */
	private const ALIGN = array(
		1  => array(),
		2  => array( 6, 18 ),
		3  => array( 6, 22 ),
		4  => array( 6, 26 ),
		5  => array( 6, 30 ),
		6  => array( 6, 34 ),
		7  => array( 6, 22, 38 ),
		8  => array( 6, 24, 42 ),
		9  => array( 6, 26, 46 ),
		10 => array( 6, 28, 50 ),
	);

	private int $size;

	/** @var array<int, array<int, bool>> Dark modules, [y][x]. */
	private array $modules = array();

	/** @var array<int, array<int, bool>> Function patterns, which masks skip. */
	private array $function = array();

	private function __construct( private int $version ) {
		$this->size = $version * 4 + 17;
		$row        = array_fill( 0, $this->size, false );
		$this->modules  = array_fill( 0, $this->size, $row );
		$this->function = array_fill( 0, $this->size, $row );
	}

	/**
	 * The symbol for $data as rows of booleans (true = dark), or null when
	 * it's longer than version 10 holds.
	 *
	 * @return array<int, array<int, bool>>|null
	 */
	public static function matrix( string $data ): ?array {
		$length = strlen( $data );
		for ( $version = 1; $version <= self::MAX_VERSION; $version++ ) {
			$capacity = self::data_codewords( $version ) * 8;
			if ( 4 + ( $version < 10 ? 8 : 16 ) + 8 * $length <= $capacity ) {
				return ( new self( $version ) )->build( $data );
			}
		}
		return null;
	}

	/**
	 * Inline SVG for $data with a four-module quiet zone, sized by CSS (it
	 * scales to its container), or empty when $data is too long.
	 *
	 * @param string $label Accessible name, such as "Check-in code ABCD".
	 */
	public static function svg( string $data, string $label ): string {
		$matrix = self::matrix( $data );
		if ( ! $matrix ) {
			return '';
		}
		$size = count( $matrix ) + 8;
		$path = '';
		foreach ( $matrix as $y => $row ) {
			// One rectangle per run of dark modules keeps the path short.
			for ( $x = 0, $n = count( $row ); $x < $n; $x++ ) {
				if ( ! $row[ $x ] ) {
					continue;
				}
				$start = $x;
				while ( $x + 1 < $n && $row[ $x + 1 ] ) {
					++$x;
				}
				$path .= sprintf( 'M%d %dh%dv1h-%dz', $start + 4, $y + 4, $x - $start + 1, $x - $start + 1 );
			}
		}
		return sprintf(
			'<svg class="oe-qr" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" role="img" aria-label="%2$s" shape-rendering="crispEdges"><rect width="%1$d" height="%1$d" fill="#fff"/><path fill="#000" d="%3$s"/></svg>',
			$size,
			esc_attr( $label ),
			$path
		);
	}

	/**
	 * @return array<int, array<int, bool>>
	 */
	private function build( string $data ): array {
		$this->draw_function_patterns();
		$this->draw_codewords( $this->codewords( $data ) );

		$best_mask    = 0;
		$best_penalty = PHP_INT_MAX;
		for ( $mask = 0; $mask < 8; $mask++ ) {
			$this->apply_mask( $mask );
			$this->draw_format( $mask );
			$penalty = $this->penalty();
			if ( $penalty < $best_penalty ) {
				$best_mask    = $mask;
				$best_penalty = $penalty;
			}
			$this->apply_mask( $mask ); // XOR again to undo.
		}
		$this->apply_mask( $best_mask );
		$this->draw_format( $best_mask );
		return $this->modules;
	}

	/** Codewords for data, by version (level M). */
	private static function data_codewords( int $version ): int {
		return intdiv( self::raw_modules( $version ), 8 ) - self::BLOCKS[ $version ] * self::EC_PER_BLOCK[ $version ];
	}

	/** Modules left for data and error correction once function patterns are drawn. */
	private static function raw_modules( int $version ): int {
		$result = ( 16 * $version + 128 ) * $version + 64;
		if ( $version >= 2 ) {
			$align   = intdiv( $version, 7 ) + 2;
			$result -= ( 25 * $align - 10 ) * $align - 55;
			if ( $version >= 7 ) {
				$result -= 36;
			}
		}
		return $result;
	}

	/**
	 * Data codewords (mode, length, bytes, terminator, padding), split into
	 * blocks with their error correction, interleaved.
	 *
	 * @return int[]
	 */
	private function codewords( string $data ): array {
		$bits = array();
		$push = static function ( int $value, int $count ) use ( &$bits ): void {
			for ( $i = $count - 1; $i >= 0; $i-- ) {
				$bits[] = ( $value >> $i ) & 1;
			}
		};
		$push( 0b0100, 4 ); // Byte mode.
		$push( strlen( $data ), $this->version < 10 ? 8 : 16 );
		foreach ( unpack( 'C*', $data ) ?: array() as $byte ) {
			$push( $byte, 8 );
		}
		$capacity = self::data_codewords( $this->version ) * 8;
		$push( 0, min( 4, $capacity - count( $bits ) ) ); // Terminator.
		$push( 0, ( 8 - count( $bits ) % 8 ) % 8 );
		for ( $pad = 0xEC; count( $bits ) < $capacity; $pad ^= 0xEC ^ 0x11 ) {
			$push( $pad, 8 );
		}
		$bytes = array_map( static fn( array $byte ) => bindec( implode( '', $byte ) ), array_chunk( $bits, 8 ) );

		$blocks    = self::BLOCKS[ $this->version ];
		$ec_length = self::EC_PER_BLOCK[ $this->version ];
		$raw       = intdiv( self::raw_modules( $this->version ), 8 );
		$short     = $blocks - $raw % $blocks;
		$short_len = intdiv( $raw, $blocks );
		$divisor   = self::rs_divisor( $ec_length );

		$split = array();
		for ( $i = 0, $k = 0; $i < $blocks; $i++ ) {
			$length = $short_len - $ec_length + ( $i < $short ? 0 : 1 );
			$block  = array_slice( $bytes, $k, $length );
			$k     += $length;
			$ec     = self::rs_remainder( $block, $divisor );
			if ( $i < $short ) {
				$block[] = null; // Short blocks skip a column when interleaving.
			}
			$split[] = array_merge( $block, $ec );
		}

		$out = array();
		for ( $i = 0, $n = count( $split[0] ); $i < $n; $i++ ) {
			foreach ( $split as $block ) {
				if ( null !== $block[ $i ] ) {
					$out[] = $block[ $i ];
				}
			}
		}
		return $out;
	}

	/**
	 * Reed–Solomon generator polynomial of degree $degree over GF(2^8)
	 * (field polynomial 0x11D), leading 1 omitted.
	 *
	 * @return int[]
	 */
	private static function rs_divisor( int $degree ): array {
		$result = array_fill( 0, $degree, 0 );
		$result[ $degree - 1 ] = 1;
		$root   = 1;
		for ( $i = 0; $i < $degree; $i++ ) {
			for ( $j = 0; $j < $degree; $j++ ) {
				$result[ $j ] = self::gf_multiply( $result[ $j ], $root );
				if ( $j + 1 < $degree ) {
					$result[ $j ] ^= $result[ $j + 1 ];
				}
			}
			$root = self::gf_multiply( $root, 0x02 );
		}
		return $result;
	}

	/**
	 * @param int[] $data    Data codewords.
	 * @param int[] $divisor Generator polynomial.
	 * @return int[] Error correction codewords.
	 */
	private static function rs_remainder( array $data, array $divisor ): array {
		$result = array_fill( 0, count( $divisor ), 0 );
		foreach ( $data as $byte ) {
			$factor = $byte ^ array_shift( $result );
			$result[] = 0;
			foreach ( $divisor as $i => $coefficient ) {
				$result[ $i ] ^= self::gf_multiply( $coefficient, $factor );
			}
		}
		return $result;
	}

	private static function gf_multiply( int $x, int $y ): int {
		$z = 0;
		for ( $i = 7; $i >= 0; $i-- ) {
			$z  = ( $z << 1 ) ^ ( ( $z >> 7 ) * 0x11D );
			$z ^= ( ( $y >> $i ) & 1 ) * $x;
		}
		return $z;
	}

	private function set_function( int $x, int $y, bool $dark ): void {
		$this->modules[ $y ][ $x ]  = $dark;
		$this->function[ $y ][ $x ] = true;
	}

	private function draw_function_patterns(): void {
		for ( $i = 0; $i < $this->size; $i++ ) {
			$this->set_function( 6, $i, 0 === $i % 2 );
			$this->set_function( $i, 6, 0 === $i % 2 );
		}
		foreach ( array( array( 3, 3 ), array( $this->size - 4, 3 ), array( 3, $this->size - 4 ) ) as list( $cx, $cy ) ) {
			// Finder (7×7) with its light separator.
			for ( $dy = -4; $dy <= 4; $dy++ ) {
				for ( $dx = -4; $dx <= 4; $dx++ ) {
					$x = $cx + $dx;
					$y = $cy + $dy;
					if ( $x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size ) {
						$distance = max( abs( $dx ), abs( $dy ) );
						$this->set_function( $x, $y, 2 !== $distance && 4 !== $distance );
					}
				}
			}
		}
		$centres = self::ALIGN[ $this->version ];
		$last    = count( $centres ) - 1;
		foreach ( $centres as $i => $cx ) {
			foreach ( $centres as $j => $cy ) {
				// Not on the finders.
				if ( ( 0 === $i && 0 === $j ) || ( 0 === $i && $last === $j ) || ( $last === $i && 0 === $j ) ) {
					continue;
				}
				for ( $dy = -2; $dy <= 2; $dy++ ) {
					for ( $dx = -2; $dx <= 2; $dx++ ) {
						$this->set_function( $cx + $dx, $cy + $dy, 1 !== max( abs( $dx ), abs( $dy ) ) );
					}
				}
			}
		}
		$this->draw_format( 0 ); // Reserves the format areas; redrawn with the real mask.
		if ( $this->version >= 7 ) {
			$remainder = $this->version;
			for ( $i = 0; $i < 12; $i++ ) {
				$remainder = ( $remainder << 1 ) ^ ( ( $remainder >> 11 ) * 0x1F25 );
			}
			$bits = $this->version << 12 | $remainder;
			for ( $i = 0; $i < 18; $i++ ) {
				$dark = 1 === ( ( $bits >> $i ) & 1 );
				$a    = $this->size - 11 + $i % 3;
				$b    = intdiv( $i, 3 );
				$this->set_function( $a, $b, $dark );
				$this->set_function( $b, $a, $dark );
			}
		}
	}

	/**
	 * Format information: level M (00) and the mask, BCH-protected, in both
	 * copies, plus the dark module.
	 */
	private function draw_format( int $mask ): void {
		$data      = $mask; // Level M's bits are 00.
		$remainder = $data;
		for ( $i = 0; $i < 10; $i++ ) {
			$remainder = ( $remainder << 1 ) ^ ( ( $remainder >> 9 ) * 0x537 );
		}
		$bits = ( $data << 10 | $remainder ) ^ 0x5412;
		$bit  = static fn( int $i ): bool => 1 === ( ( $bits >> $i ) & 1 );

		for ( $i = 0; $i <= 5; $i++ ) {
			$this->set_function( 8, $i, $bit( $i ) );
		}
		$this->set_function( 8, 7, $bit( 6 ) );
		$this->set_function( 8, 8, $bit( 7 ) );
		$this->set_function( 7, 8, $bit( 8 ) );
		for ( $i = 9; $i < 15; $i++ ) {
			$this->set_function( 14 - $i, 8, $bit( $i ) );
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$this->set_function( $this->size - 1 - $i, 8, $bit( $i ) );
		}
		for ( $i = 8; $i < 15; $i++ ) {
			$this->set_function( 8, $this->size - 15 + $i, $bit( $i ) );
		}
		$this->set_function( 8, $this->size - 8, true );
	}

	/**
	 * Codewords in the standard zigzag: two-module columns from the right,
	 * alternately upwards and downwards, skipping the vertical timing line.
	 *
	 * @param int[] $codewords Interleaved codewords.
	 */
	private function draw_codewords( array $codewords ): void {
		$total = count( $codewords ) * 8;
		$i     = 0;
		for ( $right = $this->size - 1; $right >= 1; $right -= 2 ) {
			if ( 6 === $right ) {
				$right = 5;
			}
			$upward = 0 === ( ( $right + 1 ) & 2 );
			for ( $vert = 0; $vert < $this->size; $vert++ ) {
				$y = $upward ? $this->size - 1 - $vert : $vert;
				for ( $j = 0; $j < 2; $j++ ) {
					$x = $right - $j;
					if ( ! $this->function[ $y ][ $x ] && $i < $total ) {
						$this->modules[ $y ][ $x ] = 1 === ( ( $codewords[ $i >> 3 ] >> ( 7 - ( $i & 7 ) ) ) & 1 );
						++$i;
					}
				}
			}
		}
	}

	private function apply_mask( int $mask ): void {
		for ( $y = 0; $y < $this->size; $y++ ) {
			for ( $x = 0; $x < $this->size; $x++ ) {
				if ( $this->function[ $y ][ $x ] ) {
					continue;
				}
				$flip = match ( $mask ) {
					0 => 0 === ( $x + $y ) % 2,
					1 => 0 === $y % 2,
					2 => 0 === $x % 3,
					3 => 0 === ( $x + $y ) % 3,
					4 => 0 === ( intdiv( $x, 3 ) + intdiv( $y, 2 ) ) % 2,
					5 => 0 === $x * $y % 2 + $x * $y % 3,
					6 => 0 === ( $x * $y % 2 + $x * $y % 3 ) % 2,
					default => 0 === ( ( $x + $y ) % 2 + $x * $y % 3 ) % 2,
				};
				if ( $flip ) {
					$this->modules[ $y ][ $x ] = ! $this->modules[ $y ][ $x ];
				}
			}
		}
	}

	/**
	 * The standard's four penalty rules: long runs, 2×2 blocks, finder-like
	 * patterns, and dark/light balance. Lower scans more reliably.
	 */
	private function penalty(): int {
		$size    = $this->size;
		$penalty = 0;
		$lines   = array();
		for ( $i = 0; $i < $size; $i++ ) {
			$lines[] = $this->modules[ $i ];
			$lines[] = array_column( $this->modules, $i );
		}
		foreach ( $lines as $line ) {
			$run = 1;
			for ( $i = 1; $i <= $size; $i++ ) {
				if ( $i < $size && $line[ $i ] === $line[ $i - 1 ] ) {
					++$run;
					continue;
				}
				if ( $run >= 5 ) {
					$penalty += 3 + $run - 5;
				}
				$run = 1;
			}
			$text = implode( '', array_map( 'intval', $line ) );
			$penalty += 40 * ( substr_count( $text, '10111010000' ) + substr_count( $text, '00001011101' ) );
		}
		$dark = 0;
		for ( $y = 0; $y < $size; $y++ ) {
			for ( $x = 0; $x < $size; $x++ ) {
				$dark += (int) $this->modules[ $y ][ $x ];
				if ( $x + 1 < $size && $y + 1 < $size ) {
					$colour = $this->modules[ $y ][ $x ];
					if ( $colour === $this->modules[ $y ][ $x + 1 ] && $colour === $this->modules[ $y + 1 ][ $x ] && $colour === $this->modules[ $y + 1 ][ $x + 1 ] ) {
						$penalty += 3;
					}
				}
			}
		}
		$total    = $size * $size;
		$penalty += 10 * intdiv( abs( $dark * 100 - $total * 50 ), $total * 5 );
		return $penalty;
	}
}
