<?php
/**
 * Etel-ID Premium — Pure PHP Code128 Barcode Generator
 * No external dependencies, no GD required, no Composer.
 * Compatible with PHP 5.6+ (all arrays returned via methods, not static properties).
 *
 * @package Etel-ID Premium
 * @version 1.0.1
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_Barcode {

    /* Return Code128-B character set (index = value) */
    private static function code_b() {
        return array(
            ' ','!','"','#','$','%','&',"'",'(',')',
            '*','+',',','-','.','/','0','1','2','3',
            '4','5','6','7','8','9',':',';','<','=',
            '>','?','@','A','B','C','D','E','F','G',
            'H','I','J','K','L','M','N','O','P','Q',
            'R','S','T','U','V','W','X','Y','Z','[',
            '\\',']','^','_','`','a','b','c','d','e',
            'f','g','h','i','j','k','l','m','n','o',
            'p','q','r','s','t','u','v','w','x','y',
            'z','{','|','}','~',chr(127),
        );
    }

    /* Return Code128 bar patterns (index = symbol value, 11 modules each) */
    private static function patterns() {
        return array(
            '11011001100','11001101100','11001100110','10010011000','10010001100',
            '10001001100','10011001000','10011000100','10001100100','11001001000',
            '11001000100','11000100100','10110011100','10011011100','10011001110',
            '10111001100','10011101100','10011100110','11001110010','11001011100',
            '11001001110','11011100100','11001110100','11101101110','11101001100',
            '11100101100','11100100110','11101100100','11100110100','11100110010',
            '11011011000','11011000110','11000110110','10100011000','10001011000',
            '10001000110','10110001000','10001101000','10001100010','11010001000',
            '11000101000','11000100010','10110111000','10110001110','10001101110',
            '10111011000','10111000110','10001110110','11101110110','11010001110',
            '11000101110','11011101000','11011100010','11011101110','11101011000',
            '11101000110','11100010110','11101101000','11101100010','11100011010',
            '11101111010','11001000010','10111101000','10100111100','10010111100',
            '10010011110','10111100100','10011110100','10011110010','11110100100',
            '11110010100','11110010010','11011110100','11011110010','11110110100',
            '11110110010','10011010000','10011010111','10111010000','10111010111',
            '11110010111','11110101110','11010111110','11110101000','10000101000',
            '10000101110','10011101110','10011110111','11100010111','11110001010',
            '11000010100','10001111010','10111101110','11110111010','11100111010',
            '10001010000','10001010111','11001110111',
            '11000111010', /* STOP = index 106 */
        );
    }

    /**
     * Generate inline SVG Code128 barcode — no external request, no GD.
     *
     * @param string $data   ASCII text to encode
     * @param int    $width  Total width in px
     * @param int    $height Bar height in px
     * @param string $color  Bar colour (CSS hex)
     * @return string Raw SVG markup
     */
    public static function svg( $data, $width = 200, $height = 40, $color = '#003366' ) {
        $modules = self::encode( $data );
        if ( empty( $modules ) ) return '';

        $total = count( $modules ) + 20; /* 10 quiet zone modules each side */
        $mw    = $width / $total;
        $bars  = '';
        $x     = 10; /* start after quiet zone */

        foreach ( $modules as $m ) {
            if ( $m ) {
                $bx = round( $x * $mw, 3 );
                $bw = max( 1, round( $mw, 3 ) );
                $bars .= '<rect x="' . $bx . '" y="0" width="' . $bw . '" height="' . $height . '"/>';
            }
            $x++;
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
             . '<svg xmlns="http://www.w3.org/2000/svg"'
             . ' width="' . $width . '" height="' . $height . '"'
             . ' viewBox="0 0 ' . $width . ' ' . $height . '">'
             . '<g fill="' . htmlspecialchars( $color, ENT_QUOTES ) . '">' . $bars . '</g>'
             . '</svg>';
    }

    /**
     * Return <img> with inline base64 SVG — safe for card templates.
     */
    public static function img_tag( $data, $width = 200, $height = 40, $color = '#003366', $extra_attrs = '' ) {
        $svg = self::svg( $data, $width, $height, $color );
        if ( ! $svg ) return '';
        return '<img src="data:image/svg+xml;base64,' . base64_encode( $svg ) . '"'
             . ' width="' . (int) $width . '" height="' . (int) $height . '"'
             . ' alt="' . htmlspecialchars( $data, ENT_QUOTES ) . '"'
             . ( $extra_attrs ? ' ' . $extra_attrs : '' ) . '>';
    }

    /**
     * Encode string to Code128-B module array (1=black, 0=white).
     */
    public static function encode( $data ) {
        $data = preg_replace( '/[^\x20-\x7E]/', '', (string) $data );
        if ( $data === '' ) return array();

        $code_b   = self::code_b();
        $patterns = self::patterns();
        $start_b  = 104;
        $stop     = 106;

        /* Build symbol stream */
        $symbols  = array( $start_b );
        $checksum = $start_b;
        $pos      = 1;

        for ( $i = 0; $i < strlen( $data ); $i++ ) {
            $idx = array_search( $data[ $i ], $code_b, true );
            if ( $idx === false ) continue;
            $symbols[]  = $idx;
            $checksum  += $idx * $pos;
            $pos++;
        }

        $symbols[] = $checksum % 103;
        $symbols[] = $stop;

        /* Expand to module array */
        $modules = array();
        foreach ( $symbols as $sym ) {
            if ( ! isset( $patterns[ $sym ] ) ) continue;
            $bits = str_split( $patterns[ $sym ] );
            foreach ( $bits as $bit ) {
                $modules[] = (int) $bit;
            }
        }
        /* Termination bar: 2 extra modules */
        $modules[] = 1;
        $modules[] = 1;

        return $modules;
    }
}
