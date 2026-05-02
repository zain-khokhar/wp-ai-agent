<?php
/**
 * BossCode AI Agent — Background Job Manager
 *
 * Manages async background tasks (RAG indexing, long-running operations)
 * using non-blocking loopback requests via wp_remote_post. Jobs are
 * persisted in transients with crash recovery and automatic cleanup.
 *
 * @package BossCode_AI_Agent
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BossCode_Job_Manager {

    /** Job status constants */
    const PENDING  = 'pending';
    const RUNNING  = 'running';
    const COMPLETE = 'complete';
    const FAILED   = 'failed';

    /** Transient TTL in seconds (1 hour) */
    const JOB_TTL = 3600;

    /** Maximum job runtime before considered crashed (5 min) */
    const MAX_RUNTIME = 300;

    /** AJAX action name for loopback execution */
    const AJAX_ACTION = 'bosscode_run_job';

    /**
     * Create a new background job.
     *
     * @param string $type    Job type identifier (e.g., 'index', 'analyze').
     * @param array  $payload Data needed to execute the job.
     * @return array The created job record.
     */
    public function create( $type, $payload = array() ) {
        $id = wp_generate_uuid4();
        $job = array(
            'id'         => $id,
            'type'       => $type,
            'status'     => self::PENDING,
            'payload'    => $payload,
            'progress'   => array( 'current' => 0, 'total' => 0, 'message' => '' ),
            'result'     => null,
            'error'      => null,
            'created_at' => time(),
            'updated_at' => time(),
            'started_at' => null,
        );

        set_transient( 'bosscode_job_' . $id, $job, self::JOB_TTL );
        $this->add_to_list( $id );

        return $job;
    }

    /**
     * Get a job by ID.
     *
     * @param string $job_id The job UUID.
     * @return array|false Job data or false if not found.
     */
    public function get( $job_id ) {
        $job = get_transient( 'bosscode_job_' . sanitize_key( $job_id ) );
        return is_array( $job ) ? $job : false;
    }

    /**
     * Update job progress.
     *
     * @param string $job_id  The job UUID.
     * @param int    $current Current step.
     * @param int    $total   Total steps.
     * @param string $message Progress message.
     * @return bool
     */
    public function update_progress( $job_id, $current, $total, $message = '' ) {
        $job = $this->get( $job_id );
        if ( ! $job ) return false;

        $job['status']     = self::RUNNING;
        $job['progress']   = array( 'current' => $current, 'total' => $total, 'message' => $message );
        $job['updated_at'] = time();

        if ( ! $job['started_at'] ) {
            $job['started_at'] = time();
        }

        return set_transient( 'bosscode_job_' . $job_id, $job, self::JOB_TTL );
    }

    /**
     * Mark job as started/running.
     *
     * @param string $job_id The job UUID.
     * @return bool
     */
    public function start( $job_id ) {
        $job = $this->get( $job_id );
        if ( ! $job ) return false;

        $job['status']     = self::RUNNING;
        $job['started_at'] = time();
        $job['updated_at'] = time();

        return set_transient( 'bosscode_job_' . $job_id, $job, self::JOB_TTL );
    }

    /**
     * Mark job as complete with result data.
     *
     * @param string $job_id The job UUID.
     * @param mixed  $result Result data.
     * @return bool
     */
    public function complete( $job_id, $result = null ) {
        $job = $this->get( $job_id );
        if ( ! $job ) return false;

        $job['status']     = self::COMPLETE;
        $job['result']     = $result;
        $job['updated_at'] = time();
        $job['progress']   = array( 'current' => 1, 'total' => 1, 'message' => 'Done' );

        return set_transient( 'bosscode_job_' . $job_id, $job, self::JOB_TTL );
    }

    /**
     * Mark job as failed with error info.
     *
     * @param string $job_id The job UUID.
     * @param string $error  Error message.
     * @return bool
     */
    public function fail( $job_id, $error ) {
        $job = $this->get( $job_id );
        if ( ! $job ) return false;

        $job['status']     = self::FAILED;
        $job['error']      = $error;
        $job['updated_at'] = time();

        return set_transient( 'bosscode_job_' . $job_id, $job, self::JOB_TTL );
    }

    /**
     * Spawn async execution via non-blocking loopback POST.
     *
     * Fires a request to admin-ajax.php that will execute the job
     * in a separate PHP process, allowing the caller to return immediately.
     *
     * @param string $job_id The job UUID to execute.
     */
    public function spawn_async( $job_id ) {
        $url = admin_url( 'admin-ajax.php' );

        wp_remote_post( $url, array(
            'timeout'   => 0.01,    // Non-blocking — fire and forget
            'blocking'  => false,
            'sslverify' => false,   // Loopback — skip SSL verification
            'body'      => array(
                'action' => self::AJAX_ACTION,
                'job_id' => $job_id,
                'nonce'  => wp_create_nonce( 'bosscode_job_' . $job_id ),
            ),
            'cookies'   => is_array( $_COOKIE ) ? $_COOKIE : array(),
        ) );
    }

    /**
     * Recover crashed jobs (running longer than MAX_RUNTIME).
     *
     * @return int Number of jobs recovered.
     */
    public function recover_crashed() {
        $list      = $this->get_list();
        $recovered = 0;
        $now       = time();

        foreach ( $list as $job_id ) {
            $job = $this->get( $job_id );
            if ( ! $job ) {
                $this->remove_from_list( $job_id );
                continue;
            }

            // Check if running and exceeded max runtime
            if ( self::RUNNING === $job['status'] && $job['started_at'] ) {
                $elapsed = $now - $job['started_at'];
                if ( $elapsed > self::MAX_RUNTIME ) {
                    $this->fail( $job_id, 'Job timed out after ' . $elapsed . ' seconds.' );
                    $recovered++;
                }
            }

            // Clean up old completed/failed jobs (>1 hour)
            if ( in_array( $job['status'], array( self::COMPLETE, self::FAILED ), true ) ) {
                if ( ( $now - $job['updated_at'] ) > self::JOB_TTL ) {
                    delete_transient( 'bosscode_job_' . $job_id );
                    $this->remove_from_list( $job_id );
                }
            }
        }

        return $recovered;
    }

    /**
     * Get all active (non-expired) jobs.
     *
     * @return array Array of job records.
     */
    public function get_all() {
        $list = $this->get_list();
        $jobs = array();

        foreach ( $list as $job_id ) {
            $job = $this->get( $job_id );
            if ( $job ) {
                $jobs[] = $job;
            } else {
                $this->remove_from_list( $job_id );
            }
        }

        return $jobs;
    }

    // ── List management (tracks job IDs in a single transient) ──

    private function get_list() {
        $list = get_transient( 'bosscode_job_list' );
        return is_array( $list ) ? $list : array();
    }

    private function add_to_list( $job_id ) {
        $list = $this->get_list();
        if ( ! in_array( $job_id, $list, true ) ) {
            $list[] = $job_id;
            set_transient( 'bosscode_job_list', $list, self::JOB_TTL );
        }
    }

    private function remove_from_list( $job_id ) {
        $list = $this->get_list();
        $list = array_values( array_diff( $list, array( $job_id ) ) );
        set_transient( 'bosscode_job_list', $list, self::JOB_TTL );
    }
}
