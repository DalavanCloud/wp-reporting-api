<?php
/**
 * Class Google\WP_Reporting_API\Reports
 *
 * @package Google\WP_Reporting_API
 * @license GNU General Public License, version 2
 * @link    https://wordpress.org/plugins/reporting-api/
 */

namespace Google\WP_Reporting_API;

use WP_Error;

/**
 * Class for accessing reports.
 *
 * @since 0.1.0
 */
class Reports {

	const DB_TABLE    = 'ra_reports';
	const CACHE_GROUP = 'ra_reports';

	/**
	 * Registers the reports infrastructure with WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register() {
		$this->register_db_table_name();
		// TODO.
	}

	/**
	 * Gets multiple reports using a query.
	 *
	 * @since 0.1.0
	 *
	 * @param array $query_vars Array of report query arguments. See {@see Report_Query::__construct()} for a list of
	 *                          supported arguments. The $fields and $no_found_rows arguments are automatically set,
	 *                          i.e. not supported here.
	 * @return array List of {@see Report} instances.
	 */
	public function get_reports( array $query_vars ) {
		$query_args['fields']        = 'all';
		$query_vars['no_found_rows'] = true;

		$query = $this->get_report_query( $query_vars );
		return $query->get_results();
	}

	/**
	 * Gets an existing report.
	 *
	 * @since 0.1.0
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param int $id Report ID.
	 * @return Report|null Report instance, or null if not found.
	 */
	public function get_report( $id ) {
		global $wpdb;

		$id = (int) $id;

		$props = wp_cache_get( $id, self::CACHE_GROUP );
		if ( false === $props ) {
			$props = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL
					"SELECT * FROM {$this->get_db_table_name()} WHERE id = %d",
					$id
				),
				\ARRAY_A
			);
			if ( ! $props ) {
				return null;
			}

			wp_cache_add( $id, $props, self::CACHE_GROUP );
		}

		return new Report( $props );
	}

	/**
	 * Inserts a new report into the database.
	 *
	 * @since 0.1.0
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param Report $report Report to insert. Must not have an ID set.
	 * @return Report|WP_Error New report instance on success, WP_Error on failure.
	 */
	public function insert_report( Report $report ) {
		global $wpdb;

		if ( $report->id > 0 ) {
			return new WP_Error( 'report_already_exists', __( 'Cannot insert an existing report.', 'reporting-api' ) );
		}

		$db_args = $this->prepare_report_db_args( $report );

		$status = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->get_db_table_name(),
			$db_args,
			array_fill( 0, count( $db_args ), '%s' )
		);
		if ( ! $status ) {
			return new WP_Error( 'report_insertion_failed', __( 'Cannot insert report due to an internal error.', 'reporting-api' ) );
		}

		$new_id = (int) $wpdb->insert_id;

		$this->clean_report_cache( $new_id );

		return $this->get_report( $new_id );
	}

	/**
	 * Updates a report in the database.
	 *
	 * @since 0.1.0
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param Report $report Report to update. Must have an ID set.
	 * @return Report|WP_Error Updated report instance on success, WP_Error on failure.
	 */
	public function update_report( Report $report ) {
		global $wpdb;

		if ( ! $report->id ) {
			return new WP_Error( 'report_not_exists', __( 'Cannot update an non-existing report.', 'reporting-api' ) );
		}

		$db_args = $this->prepare_report_db_args( $report );

		$status = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->get_db_table_name(),
			$db_args,
			array( 'id' => $report->id ),
			array_fill( 0, count( $db_args ), '%s' ),
			array( '%d' )
		);
		if ( ! $status ) {
			return new WP_Error( 'report_update_failed', __( 'Cannot update report due to an internal error.', 'reporting-api' ) );
		}

		$this->clean_report_cache( $report->id );

		return $this->get_report( $report->id );
	}

	/**
	 * Deletes a report from the database.
	 *
	 * @since 0.1.0
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param Report $report Report to delete. Must have an ID set.
	 * @return Report|WP_Error Deleted report instance on success, WP_Error on failure.
	 */
	public function delete_report( Report $report ) {
		global $wpdb;

		if ( 0 === $report->id ) {
			return new WP_Error( 'report_not_exists', __( 'Cannot delete an non-existing report.', 'reporting-api' ) );
		}

		$status = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->get_db_table_name(),
			array( 'id' => $report->id ),
			array( '%d' )
		);
		if ( ! $status ) {
			return new WP_Error( 'report_deletion_failed', __( 'Cannot delete report due to an internal error.', 'reporting-api' ) );
		}

		$this->clean_report_cache( $report->id );

		$db_args = $this->prepare_report_db_args( $report );

		return new Report( $db_args );
	}

	/**
	 * Cleans the cache for a specific report.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Report ID for which to clean the cache.
	 */
	public function clean_report_cache( $id ) {
		$id = (int) $id;

		wp_cache_delete( $id, self::CACHE_GROUP );
		wp_cache_set( 'last_changed', microtime(), self::CACHE_GROUP );
	}

	/**
	 * Gets a new report query instance without executing it.
	 *
	 * @since 0.1.0
	 *
	 * @param array $query_vars Array of report query arguments. See {@see Report_Query::__construct()} for a list of
	 *                          supported arguments.
	 * @return Report_Query New report query instance.
	 */
	public function get_report_query( array $query_vars ) {
		return new Report_Query( $this, $query_vars );
	}

	/**
	 * Gets the full database table name.
	 *
	 * @since 0.1.0
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return string Full Database table name.
	 */
	public function get_db_table_name() {
		global $wpdb;

		$unprefixed = self::DB_TABLE;

		return $wpdb->{$unprefixed};
	}

	/**
	 * Prepares a report's properties as arguments for the database.
	 *
	 * @since 0.1.0
	 *
	 * @param Report $report The report to prepare database arguments for.
	 * @return array The database arguments for the report.
	 */
	protected function prepare_report_db_args( Report $report ) {
		$args = array(
			'type'            => $report->type,
			'first_triggered' => $report->first_triggered,
			'first_reported'  => $report->first_reported,
			'last_triggered'  => $report->last_triggered,
			'last_reported'   => $report->last_reported,
			'url'             => $report->url,
			'user_agent'      => $report->user_agent,
			'body'            => wp_json_encode( $report->body ),
		);

		return $args;
	}

	/**
	 * Registers the database table name.
	 *
	 * @since 0.1.0
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	protected function register_db_table_name() {
		global $wpdb;

		$unprefixed = self::DB_TABLE;

		$wpdb->tables[]      = $unprefixed;
		$wpdb->{$unprefixed} = $wpdb->prefix . $unprefixed;
	}
}