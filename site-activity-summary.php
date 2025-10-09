<?php
/**
 * Plugin Name:       Site Activity Summary
 * Plugin URI: https://github.com/jsrothwell/site-activity-summary
 * Description:       Sends a summary of site activity (new posts, comments, users) to a specified email address at a chosen frequency.
 * Version:           1.0.0
 * Author:            Jamieson Rothwell
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       site-activity-summary
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Main plugin class
class Site_Activity_Summary {

    private static $instance;

    /**
     * Ensures only one instance of the class is loaded.
     */
    public static function get_instance() {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        // Add settings page
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'settings_init' ] );

        // Schedule cron job
        add_action( 'sas_send_summary_email', [ $this, 'send_summary_email' ] );

        // Activation and deactivation hooks
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );

        // Action to reschedule cron on settings update
        add_action( 'update_option_sas_settings', [ $this, 'reschedule_cron_on_settings_change' ], 10, 2 );
    }

    /**
     * Plugin activation.
     */
    public function activate() {
        $this->schedule_cron();
    }

    /**
     * Plugin deactivation.
     */
    public function deactivate() {
        wp_clear_scheduled_hook( 'sas_send_summary_email' );
    }

    /**
     * Reschedule cron when settings are updated.
     */
    public function reschedule_cron_on_settings_change( $old_value, $new_value ) {
        if ( !isset( $new_value['frequency'] ) || $old_value['frequency'] !== $new_value['frequency'] ) {
            wp_clear_scheduled_hook( 'sas_send_summary_email' );
            $this->schedule_cron();
        }
    }

    /**
     * Schedules the cron job based on plugin settings.
     */
    public function schedule_cron() {
        $options = get_option( 'sas_settings' );
        $frequency = isset( $options['frequency'] ) ? $options['frequency'] : 'daily'; // Default to daily
        $is_enabled = isset( $options['enable'] ) && $options['enable'] == '1';

        // Clear existing schedule first
        wp_clear_scheduled_hook( 'sas_send_summary_email' );

        // If plugin is enabled and a valid frequency is set
        if ( $is_enabled && ! wp_next_scheduled( 'sas_send_summary_email' ) ) {
            // Schedule the event to run at a consistent time, e.g., 9 AM server time
            wp_schedule_event( strtotime( '9:00:00' ), $frequency, 'sas_send_summary_email' );
        }
    }

    /**
     * Adds the admin menu page.
     */
    public function add_admin_menu() {
        add_options_page(
            'Site Activity Summary',
            'Site Activity Summary',
            'manage_options',
            'site-activity-summary',
            [ $this, 'options_page_html' ]
        );
    }

    /**
     * Initializes the settings using the Settings API.
     */
    public function settings_init() {
        register_setting( 'sas_settings_group', 'sas_settings' );

        add_settings_section(
            'sas_settings_section',
            __( 'Email Summary Settings', 'site-activity-summary' ),
            null,
            'sas_settings_group'
        );

        add_settings_field(
            'sas_field_enable',
            __( 'Enable Summary', 'site-activity-summary' ),
            [ $this, 'render_field_enable' ],
            'sas_settings_group',
            'sas_settings_section'
        );

        add_settings_field(
            'sas_field_email',
            __( 'Destination Email', 'site-activity-summary' ),
            [ $this, 'render_field_email' ],
            'sas_settings_group',
            'sas_settings_section'
        );

        add_settings_field(
            'sas_field_frequency',
            __( 'Frequency', 'site-activity-summary' ),
            [ $this, 'render_field_frequency' ],
            'sas_settings_group',
            'sas_settings_section'
        );
    }

    /**
     * Renders the HTML for the settings page.
     */
    public function options_page_html() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'sas_settings_group' );
                do_settings_sections( 'sas_settings_group' );
                submit_button( 'Save Settings' );
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Renders the enable checkbox field.
     */
    public function render_field_enable() {
        $options = get_option( 'sas_settings' );
        $checked = isset( $options['enable'] ) ? $options['enable'] : 0;
        ?>
        <input type="checkbox" name="sas_settings[enable]" value="1" <?php checked( 1, $checked, true ); ?> />
        <p class="description"><?php _e( 'Check this box to enable the email summaries.', 'site-activity-summary' ); ?></p>
        <?php
    }

    /**
     * Renders the email input field.
     */
    public function render_field_email() {
        $options = get_option( 'sas_settings' );
        $email = isset( $options['email'] ) ? $options['email'] : get_option('admin_email');
        ?>
        <input type="email" name="sas_settings[email]" value="<?php echo esc_attr( $email ); ?>" class="regular-text">
        <p class="description"><?php _e( 'The email address where the summary will be sent.', 'site-activity-summary' ); ?></p>
        <?php
    }

    /**
     * Renders the frequency dropdown field.
     */
    public function render_field_frequency() {
        $options = get_option( 'sas_settings' );
        $frequency = isset( $options['frequency'] ) ? $options['frequency'] : 'daily';
        ?>
        <select name="sas_settings[frequency]">
            <option value="daily" <?php selected( $frequency, 'daily' ); ?>><?php _e( 'Daily', 'site-activity-summary' ); ?></option>
            <option value="weekly" <?php selected( $frequency, 'weekly' ); ?>><?php _e( 'Weekly', 'site-activity-summary' ); ?></option>
            <option value="monthly" <?php _e( 'Monthly (Approximated as every 30 days)', 'site-activity-summary' ); ?></option> </select>
        <p class="description"><?php _e( 'How often the summary should be sent.', 'site-activity-summary' ); ?></p>
        <?php
    }

    /**
     * Gathers data and sends the summary email.
     */
    public function send_summary_email() {
        $options = get_option( 'sas_settings' );

        // Ensure email sending is enabled and an email is set
        if ( !isset( $options['enable'] ) || $options['enable'] != '1' || empty($options['email']) ) {
            return;
        }

        $to = $options['email'];
        $frequency = isset( $options['frequency'] ) ? $options['frequency'] : 'daily';

        $date_format = get_option( 'date_format' );
        $start_date = '';
        $time_diff = 0;

        switch ( $frequency ) {
            case 'weekly':
                $time_diff = WEEK_IN_SECONDS;
                $period = 'Last 7 Days';
                break;
            case 'monthly':
                 $time_diff = MONTH_IN_SECONDS; // Approx. 30 days
                 $period = 'Last 30 Days';
                 break;
            default: // daily
                $time_diff = DAY_IN_SECONDS;
                $period = 'Last 24 Hours';
                break;
        }

        // Calculate the start date for the query
        $start_date = date( 'Y-m-d H:i:s', time() - $time_diff );

        $site_name = get_bloginfo( 'name' );
        $subject = sprintf( '%s - Site Activity Summary for %s', $site_name, $period );

        // --- GATHER DATA ---

        // New Posts
        $new_posts = new WP_Query([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'date_query'     => [['after' => $start_date, 'inclusive' => true]],
            'posts_per_page' => -1,
        ]);

        // New Comments
        $new_comments = get_comments([
            'status'     => 'approve',
            'date_query' => [['after' => $start_date, 'inclusive' => true]],
        ]);

        // New Users
        $new_users = new WP_User_Query([
            'date_query' => [['after' => $start_date, 'inclusive' => true]],
        ]);

        // --- BUILD EMAIL BODY ---

        $body = "<html><body style='font-family: Arial, sans-serif; color: #333;'>";
        $body .= "<h1 style='color: #2a7a9c;'>Site Activity Summary for {$site_name}</h1>";
        $body .= "<p>Here is the activity summary for the <strong>{$period}</strong> (" . date($date_format, time() - $time_diff) . " - " . date($date_format) . ").</p>";

        // Posts Section
        $body .= "<h2 style='border-bottom: 2px solid #eee; padding-bottom: 5px;'>New Posts (" . $new_posts->found_posts . ")</h2>";
        if ( $new_posts->have_posts() ) {
            $body .= "<ul>";
            while ( $new_posts->have_posts() ) {
                $new_posts->the_post();
                $body .= "<li><a href='" . get_permalink() . "'>" . get_the_title() . "</a> by " . get_the_author() . "</li>";
            }
            $body .= "</ul>";
            wp_reset_postdata();
        } else {
            $body .= "<p>No new posts were published.</p>";
        }

        // Comments Section
        $body .= "<h2 style='border-bottom: 2px solid #eee; padding-bottom: 5px;'>New Comments (" . count( $new_comments ) . ")</h2>";
        if ( ! empty( $new_comments ) ) {
            $body .= "<ul>";
            foreach ( $new_comments as $comment ) {
                $body .= "<li>Comment by <strong>" . esc_html($comment->comment_author) . "</strong> on <a href='" . get_comment_link( $comment ) . "'>". get_the_title($comment->comment_post_ID) ."</a></li>";
            }
            $body .= "</ul>";
        } else {
            $body .= "<p>No new comments were approved.</p>";
        }

        // Users Section
        $body .= "<h2 style='border-bottom: 2px solid #eee; padding-bottom: 5px;'>New Users (" . $new_users->get_total() . ")</h2>";
        $users_results = $new_users->get_results();
        if ( ! empty( $users_results ) ) {
            $body .= "<ul>";
            foreach ( $users_results as $user ) {
                $body .= "<li>" . esc_html( $user->display_name ) . " (" . esc_html($user->user_email) . ")</li>";
            }
            $body .= "</ul>";
        } else {
            $body .= "<p>No new users registered.</p>";
        }

        $body .= "<hr><p style='font-size: 12px; color: #777;'>This email was generated by the Site Activity Summary plugin.</p>";
        $body .= "</body></html>";

        // --- SEND EMAIL ---

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        wp_mail( $to, $subject, $body, $headers );
    }
}

// Initialize the plugin
Site_Activity_Summary::get_instance();
