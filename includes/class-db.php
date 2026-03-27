<?php
defined( 'ABSPATH' ) || exit;

class Pitchdeck_DB {

    const TABLE_NAME = 'pitchdeck_slides';

    /**
     * Create the custom table on plugin activation.
     * dbDelta() is idempotent — safe to call multiple times.
     */
    public static function create_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE_NAME;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id       VARCHAR(36)         NOT NULL,
            slide_number INT(11)             NOT NULL,
            slide_text   LONGTEXT            NOT NULL DEFAULT '',
            extra_info   LONGTEXT            NOT NULL DEFAULT '',
            script_text  LONGTEXT            NOT NULL DEFAULT '',
            audio_file   VARCHAR(500)        NOT NULL DEFAULT '',
            created_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_job_id (job_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'pitchdeck_db_version', PITCHDECK_VERSION );
    }

    /**
     * Insert or replace all slides for a given job.
     * Deletes existing rows first so re-saves don't create duplicates.
     *
     * @param string $job_id UUID identifying this upload session.
     * @param array  $slides Each element: {slide_number, slide_text, extra_info}.
     * @return bool          True on full success.
     */
    public static function save_slides( string $job_id, array $slides ): bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $wpdb->delete( $table, [ 'job_id' => $job_id ], [ '%s' ] );

        $success = true;
        foreach ( $slides as $slide ) {
            $result = $wpdb->insert(
                $table,
                [
                    'job_id'       => $job_id,
                    'slide_number' => (int) $slide['slide_number'],
                    'slide_text'   => sanitize_textarea_field( $slide['slide_text'] ),
                    'extra_info'   => sanitize_textarea_field( $slide['extra_info'] ?? '' ),
                    'created_at'   => current_time( 'mysql' ),
                ],
                [ '%s', '%d', '%s', '%s', '%s' ]
            );
            if ( false === $result ) {
                $success = false;
            }
        }
        return $success;
    }

    /**
     * Retrieve all slides for a job, ordered by slide_number.
     *
     * @param string $job_id
     * @return array Array of row objects.
     */
    public static function get_slides_by_job( string $job_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE job_id = %s ORDER BY slide_number ASC",
                $job_id
            )
        );
    }

    /**
     * Persist generated script text for each slide of a job.
     * Updates only the script_text column; leaves all other columns intact.
     *
     * @param string $job_id
     * @param array  $scripts  Keyed by slide_number (int) => script string.
     * @return bool True if all updates succeeded.
     */
    public static function save_scripts( string $job_id, array $scripts ): bool {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE_NAME;
        $success = true;

        foreach ( $scripts as $slide_number => $script_text ) {
            $result = $wpdb->update(
                $table,
                [ 'script_text' => sanitize_textarea_field( $script_text ) ],
                [
                    'job_id'       => $job_id,
                    'slide_number' => (int) $slide_number,
                ],
                [ '%s' ],
                [ '%s', '%d' ]
            );
            if ( false === $result ) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Persist the path to the generated audio file for a single slide.
     *
     * @param string $job_id
     * @param int    $slide_number
     * @param string $file_path  Absolute filesystem path to the saved MP3.
     * @return bool
     */
    public static function save_audio_file( string $job_id, int $slide_number, string $file_path ): bool {
        global $wpdb;
        $table  = $wpdb->prefix . self::TABLE_NAME;
        $result = $wpdb->update(
            $table,
            [ 'audio_file' => $file_path ],
            [
                'job_id'       => $job_id,
                'slide_number' => $slide_number,
            ],
            [ '%s' ],
            [ '%s', '%d' ]
        );
        return false !== $result;
    }
}
