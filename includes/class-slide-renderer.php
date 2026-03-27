<?php
defined( 'ABSPATH' ) || exit;

/**
 * Renders each slide of a PPTX or PDF as a PNG image.
 *
 * Dependencies (must be installed on the server):
 *   - pdftoppm  (poppler-utils) — converts PDF pages to PNG
 *   - libreoffice --headless   — converts PPTX to PDF (PPTX only)
 */
class Pitchdeck_Slide_Renderer {

    /**
     * Render all slides as PNG images.
     *
     * @param  string   $file_path  Absolute path to the source file (.pptx or .pdf).
     * @param  string   $output_dir Directory to write slide images into.
     * @param  string   $ext        'pptx' or 'pdf'.
     * @return string[]             Ordered array of absolute paths to generated PNG files.
     * @throws RuntimeException     On missing dependencies or conversion failure.
     */
    public static function render( string $file_path, string $output_dir, string $ext ): array {
        wp_mkdir_p( $output_dir );

        $pdf_path = ( 'pptx' === $ext )
            ? self::pptx_to_pdf( $file_path, $output_dir )
            : $file_path;

        return self::pdf_to_images( $pdf_path, $output_dir );
    }

    /**
     * Convert a PPTX to PDF using LibreOffice in headless mode.
     *
     * @throws RuntimeException If libreoffice is not found or conversion fails.
     */
    private static function pptx_to_pdf( string $pptx_path, string $output_dir ): string {
        self::require_binary( 'libreoffice', 'LibreOffice' );

        $cmd = sprintf(
            'libreoffice --headless --convert-to pdf %s --outdir %s 2>&1',
            escapeshellarg( $pptx_path ),
            escapeshellarg( rtrim( $output_dir, '/' ) )
        );

        exec( $cmd, $output, $code );

        if ( 0 !== $code ) {
            throw new RuntimeException(
                'LibreOffice PPTX→PDF conversion failed: ' . implode( ' ', $output )
            );
        }

        // LibreOffice names the output after the input filename.
        $pdf_path = rtrim( $output_dir, '/' ) . '/' . pathinfo( $pptx_path, PATHINFO_FILENAME ) . '.pdf';

        if ( ! file_exists( $pdf_path ) ) {
            throw new RuntimeException( "LibreOffice ran but produced no PDF at: {$pdf_path}" );
        }

        return $pdf_path;
    }

    /**
     * Convert a PDF to per-page PNG images using pdftoppm.
     *
     * Output files: {output_dir}/slide-1.png, slide-2.png, …
     *
     * @throws RuntimeException If pdftoppm is not found or produces no output.
     */
    private static function pdf_to_images( string $pdf_path, string $output_dir ): array {
        self::require_binary( 'pdftoppm', 'pdftoppm (poppler-utils)' );

        $prefix = rtrim( $output_dir, '/' ) . '/slide';

        $cmd = sprintf(
            'pdftoppm -r 150 -png %s %s 2>&1',
            escapeshellarg( $pdf_path ),
            escapeshellarg( $prefix )
        );

        exec( $cmd, $output, $code );

        if ( 0 !== $code ) {
            throw new RuntimeException(
                'pdftoppm PDF→PNG conversion failed: ' . implode( ' ', $output )
            );
        }

        // pdftoppm names files slide-1.png, slide-01.png, or slide-001.png depending on page count.
        $images = glob( rtrim( $output_dir, '/' ) . '/slide-*.png' );

        if ( empty( $images ) ) {
            throw new RuntimeException( 'pdftoppm ran but produced no PNG images.' );
        }

        natsort( $images );
        return array_values( $images );
    }

    /**
     * Confirm a required binary is on the PATH, throw a helpful error if not.
     */
    private static function require_binary( string $binary, string $label ): void {
        exec( 'which ' . escapeshellarg( $binary ) . ' 2>/dev/null', $out, $code );
        if ( 0 !== $code ) {
            throw new RuntimeException(
                "{$label} is not installed or not on the system PATH. " .
                "Install it on the server before generating video."
            );
        }
    }
}
