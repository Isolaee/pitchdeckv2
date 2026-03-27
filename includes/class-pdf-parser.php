<?php
defined( 'ABSPATH' ) || exit;

use Smalot\PdfParser\Parser;

class Pitchdeck_PDF_Parser {

    /**
     * Parse a .pdf file and return an array of slide data structs.
     *
     * @param string $file_path Absolute path to the .pdf file.
     * @return array
     * @throws RuntimeException If the file cannot be parsed or has no pages.
     */
    public static function parse( string $file_path ): array {
        if ( ! class_exists( Parser::class ) ) {
            throw new RuntimeException( 'PDF parsing library not available. Run composer install in the plugin directory.' );
        }

        if ( ! file_exists( $file_path ) ) {
            throw new RuntimeException( "Cannot read PDF file: {$file_path}" );
        }

        try {
            $parser = new Parser();
            $pdf    = $parser->parseFile( $file_path );
            $pages  = $pdf->getPages();
        } catch ( \Exception $e ) {
            throw new RuntimeException( 'Failed to parse PDF: ' . $e->getMessage() );
        }

        if ( empty( $pages ) ) {
            throw new RuntimeException( 'No pages found in the PDF.' );
        }

        $slides = [];
        foreach ( $pages as $i => $page ) {
            $slides[] = [
                'slide_number' => $i + 1,
                'slide_text'   => trim( $page->getText() ),
                'extra_info'   => '',
            ];
        }

        return $slides;
    }
}
