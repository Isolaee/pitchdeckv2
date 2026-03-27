<?php
defined( 'ABSPATH' ) || exit;

/**
 * Parses a .pptx file (ZIP archive of XML) and extracts slide text.
 *
 * A .pptx file is a ZIP containing:
 *   ppt/slides/slide1.xml, slide2.xml, ... — one file per slide
 *
 * Each slide XML uses the DrawingML namespace for text elements:
 *   http://schemas.openxmlformats.org/drawingml/2006/main
 * Text runs are <a:t>, grouped inside paragraphs <a:p>.
 */
class Pitchdeck_PPTX_Parser {

    /**
     * Parse a .pptx file and return an array of slide data structs.
     *
     * Each struct:
     *   [
     *     'slide_number' => int,
     *     'slide_text'   => string,  // all text from slide, paragraphs separated by \n
     *     'extra_info'   => string,  // empty; filled by user in the UI
     *   ]
     *
     * @param string $file_path Absolute path to the .pptx file.
     * @return array
     * @throws RuntimeException If the file cannot be opened as a ZIP archive.
     */
    public static function parse( string $file_path ): array {
        $zip    = new ZipArchive();
        $result = $zip->open( $file_path );

        if ( true !== $result ) {
            throw new RuntimeException(
                "Cannot open PPTX file as ZIP archive (ZipArchive error code: {$result}): {$file_path}"
            );
        }

        $slide_files = self::collect_slide_filenames( $zip );

        // natsort so slide10 comes after slide9, not after slide1 (lexicographic order).
        natsort( $slide_files );
        $slide_files = array_values( $slide_files );

        $slides = [];
        foreach ( $slide_files as $index => $entry_name ) {
            $xml = $zip->getFromName( $entry_name );
            if ( false === $xml ) {
                continue;
            }
            $slides[] = [
                'slide_number' => $index + 1,
                'slide_text'   => self::extract_text_from_slide_xml( $xml ),
                'extra_info'   => '',
            ];
        }

        $zip->close();
        return $slides;
    }

    /**
     * Collect all ppt/slides/slideN.xml entry names from the ZIP.
     *
     * @param ZipArchive $zip
     * @return string[]
     */
    private static function collect_slide_filenames( ZipArchive $zip ): array {
        $names = [];
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $name = $zip->getNameIndex( $i );
            // Match only slide content files, not slide layouts or masters.
            if ( preg_match( '#^ppt/slides/slide(\d+)\.xml$#', $name ) ) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * Extract all human-readable text from a single slide's XML string.
     *
     * DrawingML stores text in <a:t> elements inside paragraphs <a:p>.
     * We collect runs per paragraph and join paragraphs with newlines.
     *
     * @param string $xml_content Raw XML from the ZIP entry.
     * @return string             Paragraphs joined by "\n".
     */
    private static function extract_text_from_slide_xml( string $xml_content ): string {
        // Suppress parse warnings — some PPTX files have minor XML issues.
        libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        $dom->loadXML( $xml_content );
        libxml_clear_errors();

        $ns         = 'http://schemas.openxmlformats.org/drawingml/2006/main';
        $paragraphs = $dom->getElementsByTagNameNS( $ns, 'p' );

        $paragraph_texts = [];
        foreach ( $paragraphs as $para ) {
            $runs      = $para->getElementsByTagNameNS( $ns, 't' );
            $run_texts = [];
            foreach ( $runs as $run ) {
                $text = trim( $run->nodeValue );
                if ( '' !== $text ) {
                    $run_texts[] = $text;
                }
            }
            if ( ! empty( $run_texts ) ) {
                $paragraph_texts[] = implode( ' ', $run_texts );
            }
        }

        return implode( "\n", $paragraph_texts );
    }
}
