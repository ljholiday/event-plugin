<?php
/**
 * Plugin Name: Party Events Organizer
 * Description: A comprehensive plugin for organizing dinner parties and house parties with RSVP functionality
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PartyEventsOrganizer {
    
    private $table_name;
    private $rsvp_table_name;
    private $invitations_table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'party_events';
        $this->rsvp_table_name = $wpdb->prefix . 'party_rsvps';
        $this->invitations_table_name = $wpdb->prefix . 'party_invitations';
        
        // Hook into WordPress
        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        
        // Public hooks
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_shortcode('party_events', array($this, 'display_events_shortcode'));
        add_shortcode('party_event_form', array($this, 'display_event_form_shortcode'));
        add_shortcode('party_dashboard', array($this, 'display_dashboard_shortcode'));
        add_shortcode('party_create_event', array($this, 'display_create_event_shortcode'));
        
        // AJAX handlers
        add_action('wp_ajax_party_rsvp', array($this, 'handle_rsvp'));
        add_action('wp_ajax_nopriv_party_rsvp', array($this, 'handle_rsvp'));
        add_action('wp_ajax_delete_event', array($this, 'delete_event'));
        add_action('wp_ajax_create_frontend_event', array($this, 'handle_frontend_event_creation'));
        add_action('wp_ajax_update_frontend_event', array($this, 'handle_frontend_event_update'));
        add_action('wp_ajax_delete_user_event', array($this, 'handle_user_event_deletion'));
        add_action('wp_ajax_get_event_rsvps', array($this, 'get_event_rsvps'));
        add_action('wp_ajax_send_invitations', array($this, 'handle_send_invitations'));
        add_action('wp_ajax_get_event_invitations', array($this, 'get_event_invitations'));
    }
    
    public function activate() {
        $this->create_tables();
        $this->create_pages();
    }
    
    public function deactivate() {
        // Clean up if needed
    }
    
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Events table
        $sql = "CREATE TABLE $this->table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            description text,
            event_date datetime NOT NULL,
            location varchar(255),
            max_guests int DEFAULT 0,
            host_name varchar(255),
            host_email varchar(255),
            event_type varchar(50) DEFAULT 'dinner',
            user_id int DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        // RSVP table
        $sql2 = "CREATE TABLE $this->rsvp_table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            event_id mediumint(9) NOT NULL,
            guest_name varchar(255) NOT NULL,
            guest_email varchar(255) NOT NULL,
            guest_count int DEFAULT 1,
            dietary_restrictions text,
            rsvp_status varchar(20) DEFAULT 'yes',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            FOREIGN KEY (event_id) REFERENCES $this->table_name(id) ON DELETE CASCADE
        ) $charset_collate;";
        
        // Invitations table
        $sql3 = "CREATE TABLE $this->invitations_table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            event_id mediumint(9) NOT NULL,
            guest_email varchar(255) NOT NULL,
            guest_name varchar(255),
            invitation_token varchar(64) NOT NULL,
            status varchar(20) DEFAULT 'sent',
            sent_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            FOREIGN KEY (event_id) REFERENCES $this->table_name(id) ON DELETE CASCADE,
            UNIQUE KEY unique_event_email (event_id, guest_email)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($sql2);
        dbDelta($sql3);
    }
    
    private function create_pages() {
        // Create events page
        $events_page = array(
            'post_title' => 'Party Events',
            'post_content' => '[party_events]',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_slug' => 'party-events'
        );
        
        if (!get_page_by_title('Party Events')) {
            wp_insert_post($events_page);
        }
        
        // Create user dashboard page
        $dashboard_page = array(
            'post_title' => 'My Events Dashboard',
            'post_content' => '[party_dashboard]',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_slug' => 'my-events-dashboard'
        );
        
        if (!get_page_by_title('My Events Dashboard')) {
            wp_insert_post($dashboard_page);
        }
        
        // Create event creation page
        $create_page = array(
            'post_title' => 'Create New Event',
            'post_content' => '[party_create_event]',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_slug' => 'create-event'
        );
        
        if (!get_page_by_title('Create New Event')) {
            wp_insert_post($create_page);
        }
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Party Events',
            'Party Events',
            'manage_options',
            'party-events',
            array($this, 'admin_page'),
            'dashicons-calendar-alt',
            30
        );
    }
    
    public function admin_enqueue_scripts() {
        wp_enqueue_script('party-events-admin', plugin_dir_url(__FILE__) . 'admin.js', array('jquery'), '1.0.0', true);
        wp_localize_script('party-events-admin', 'party_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('party_events_nonce')
        ));
        
        wp_enqueue_style('party-events-admin', plugin_dir_url(__FILE__) . 'admin.css', array(), '1.0.0');
    }
    
    public function enqueue_scripts() {
        wp_enqueue_script('party-events-public', plugin_dir_url(__FILE__) . 'public.js', array('jquery'), '1.0.0', true);
        wp_localize_script('party-events-public', 'party_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('party_events_nonce')
        ));
        
        wp_enqueue_style('party-events-public', plugin_dir_url(__FILE__) . 'public.css', array(), '1.0.0');
    }
    
    public function admin_page() {
        global $wpdb;
        
        // Handle form submission
        if (isset($_POST['submit_event'])) {
            $this->save_event();
        }
        
        // Get events
        $events = $wpdb->get_results("SELECT * FROM $this->table_name ORDER BY event_date DESC");
        
        ?>
        <div class="wrap">
            <h1>Party Events Organizer</h1>
            
            <div class="party-admin-container">
                <div class="party-form-section">
                    <h2>Add New Event</h2>
                    <form method="post" class="party-event-form">
                        <?php wp_nonce_field('party_events_nonce'); ?>
                        
                        <table class="form-table">
                            <tr>
                                <th><label for="event_title">Event Title</label></th>
                                <td><input type="text" id="event_title" name="event_title" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th><label for="event_description">Description</label></th>
                                <td><textarea id="event_description" name="event_description" rows="4" class="large-text"></textarea></td>
                            </tr>
                            <tr>
                                <th><label for="event_date">Date & Time</label></th>
                                <td><input type="datetime-local" id="event_date" name="event_date" required></td>
                            </tr>
                            <tr>
                                <th><label for="event_location">Location</label></th>
                                <td><input type="text" id="event_location" name="event_location" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><label for="max_guests">Max Guests</label></th>
                                <td><input type="number" id="max_guests" name="max_guests" min="1" value="10"></td>
                            </tr>
                            <tr>
                                <th><label for="host_name">Host Name</label></th>
                                <td><input type="text" id="host_name" name="host_name" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><label for="host_email">Host Email</label></th>
                                <td><input type="email" id="host_email" name="host_email" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><label for="event_type">Event Type</label></th>
                                <td>
                                    <select id="event_type" name="event_type">
                                        <option value="dinner">Dinner Party</option>
                                        <option value="house">House Party</option>
                                        <option value="cocktail">Cocktail Party</option>
                                        <option value="bbq">BBQ Party</option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <input type="submit" name="submit_event" class="button-primary" value="Create Event">
                        </p>
                    </form>
                </div>
                
                <div class="party-events-list">
                    <h2>Existing Events</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Date</th>
                                <th>Type</th>
                                <th>RSVPs</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $event): ?>
                            <tr>
                                <td><strong><?php echo esc_html($event->title); ?></strong></td>
                                <td><?php echo date('M j, Y g:i A', strtotime($event->event_date)); ?></td>
                                <td><?php echo ucfirst($event->event_type); ?></td>
                                <td><?php echo $this->get_rsvp_count($event->id); ?>/<?php echo $event->max_guests; ?></td>
                                <td>
                                    <a href="#" class="view-rsvps" data-event-id="<?php echo $event->id; ?>">View RSVPs</a> |
                                    <a href="#" class="delete-event" data-event-id="<?php echo $event->id; ?>">Delete</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <style>
        .party-admin-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }
        
        .party-form-section, .party-events-list {
            background: #fff;
            padding: 20px;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        
        .party-event-form .form-table th {
            width: 150px;
        }
        
        .view-rsvps, .delete-event {
            text-decoration: none;
        }
        
        .delete-event {
            color: #a00;
        }
        
        .delete-event:hover {
            color: #dc3232;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('.delete-event').click(function(e) {
                e.preventDefault();
                
                if (confirm('Are you sure you want to delete this event?')) {
                    var eventId = $(this).data('event-id');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'delete_event',
                            event_id: eventId,
                            nonce: '<?php echo wp_create_nonce('party_events_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                location.reload();
                            } else {
                                alert('Error deleting event');
                            }
                        }
                    });
                }
            });
        });
        </script>
        <?php
    }
    
    private function save_event() {
        global $wpdb;
        
        if (!wp_verify_nonce($_POST['_wpnonce'], 'party_events_nonce')) {
            return;
        }
        
        $wpdb->insert(
            $this->table_name,
            array(
                'title' => sanitize_text_field($_POST['event_title']),
                'description' => sanitize_textarea_field($_POST['event_description']),
                'event_date' => sanitize_text_field($_POST['event_date']),
                'location' => sanitize_text_field($_POST['event_location']),
                'max_guests' => intval($_POST['max_guests']),
                'host_name' => sanitize_text_field($_POST['host_name']),
                'host_email' => sanitize_email($_POST['host_email']),
                'event_type' => sanitize_text_field($_POST['event_type'])
            )
        );
        
        echo '<div class="notice notice-success"><p>Event created successfully!</p></div>';
    }
    
    public function delete_event() {
        global $wpdb;
        
        if (!wp_verify_nonce($_POST['nonce'], 'party_events_nonce')) {
            wp_die('Security check failed');
        }
        
        $event_id = intval($_POST['event_id']);
        
        $result = $wpdb->delete($this->table_name, array('id' => $event_id));
        
        if ($result) {
            wp_send_json_success();
        } else {
            wp_send_json_error();
        }
    }
    
    private function get_rsvp_count($event_id) {
        global $wpdb;
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(guest_count) FROM $this->rsvp_table_name WHERE event_id = %d AND rsvp_status = 'yes'",
            $event_id
        ));
        
        return $count ? $count : 0;
    }
    
    public function display_events_shortcode($atts) {
        global $wpdb;
        
        $events = $wpdb->get_results("SELECT * FROM $this->table_name WHERE event_date > NOW() ORDER BY event_date ASC");
        
        // Check if this is an invitation link
        $invitation_token = isset($_GET['invite']) ? sanitize_text_field($_GET['invite']) : '';
        $invited_event_id = 0;
        $invited_email = '';
        
        if ($invitation_token) {
            $invitation = $wpdb->get_row($wpdb->prepare(
                "SELECT i.*, e.title as event_title 
                 FROM $this->invitations_table_name i 
                 JOIN $this->table_name e ON i.event_id = e.id 
                 WHERE i.invitation_token = %s",
                $invitation_token
            ));
            
            if ($invitation) {
                $invited_event_id = $invitation->event_id;
                $invited_email = $invitation->guest_email;
            }
        }
        
        ob_start();
        ?>
        <div class="party-events-container">
            <?php if ($invitation_token && $invitation): ?>
                <div class="invitation-welcome">
                    <h2>🎉 You're Invited!</h2>
                    <p>You've been invited to <strong><?php echo esc_html($invitation->event_title); ?></strong>. Please scroll down to find the event and RSVP.</p>
                </div>
            <?php else: ?>
                <h2>Upcoming Events</h2>
            <?php endif; ?>
            
            <?php if (empty($events)): ?>
                <p>No upcoming events scheduled.</p>
            <?php else: ?>
                <div class="party-events-grid">
                    <?php foreach ($events as $event): ?>
                        <?php $is_invited_event = ($event->id == $invited_event_id); ?>
                        <div class="party-event-card <?php echo $is_invited_event ? 'invited-event' : ''; ?>">
                            <?php if ($is_invited_event): ?>
                                <div class="invitation-badge">You're Invited! 🎉</div>
                            <?php endif; ?>
                            
                            <h3><?php echo esc_html($event->title); ?></h3>
                            <div class="event-meta">
                                <p><strong>Date:</strong> <?php echo date('M j, Y g:i A', strtotime($event->event_date)); ?></p>
                                <p><strong>Type:</strong> <?php echo ucfirst($event->event_type); ?> Party</p>
                                <?php if ($event->location): ?>
                                    <p><strong>Location:</strong> <?php echo esc_html($event->location); ?></p>
                                <?php endif; ?>
                                <p><strong>Host:</strong> <?php echo esc_html($event->host_name); ?></p>
                                <p><strong>Spots:</strong> <?php echo $this->get_rsvp_count($event->id); ?>/<?php echo $event->max_guests; ?></p>
                            </div>
                            
                            <?php if ($event->description): ?>
                                <div class="event-description">
                                    <p><?php echo nl2br(esc_html($event->description)); ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <div class="rsvp-section">
                                <button class="rsvp-btn" data-event-id="<?php echo $event->id; ?>">RSVP</button>
                            </div>
                            
                            <div class="rsvp-form" id="rsvp-form-<?php echo $event->id; ?>" style="display: none;">
                                <h4>RSVP for <?php echo esc_html($event->title); ?></h4>
                                <form class="party-rsvp-form" data-event-id="<?php echo $event->id; ?>">
                                    <p>
                                        <label>Name:</label>
                                        <input type="text" name="guest_name" required>
                                    </p>
                                    <p>
                                        <label>Email:</label>
                                        <input type="email" name="guest_email" value="<?php echo $is_invited_event ? esc_attr($invited_email) : ''; ?>" required>
                                    </p>
                                    <p>
                                        <label>Number of Guests:</label>
                                        <input type="number" name="guest_count" min="1" max="<?php echo $event->max_guests; ?>" value="1">
                                    </p>
                                    <p>
                                        <label>Dietary Restrictions:</label>
                                        <textarea name="dietary_restrictions" rows="3"></textarea>
                                    </p>
                                    <p>
                                        <label>RSVP Status:</label>
                                        <select name="rsvp_status">
                                            <option value="yes">Yes, I'll attend</option>
                                            <option value="no">No, I can't attend</option>
                                            <option value="maybe">Maybe</option>
                                        </select>
                                    </p>
                                    <p>
                                        <button type="submit">Submit RSVP</button>
                                        <button type="button" class="cancel-rsvp">Cancel</button>
                                    </p>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <style>
        .party-events-container {
            margin: 20px 0;
        }
        
        .party-events-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .party-event-card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .party-event-card h3 {
            margin-top: 0;
            color: #333;
        }
        
        .event-meta p {
            margin: 8px 0;
            font-size: 14px;
        }
        
        .event-description {
            margin: 15px 0;
            padding: 10px;
            background: #f9f9f9;
            border-radius: 4px;
        }
        
        .rsvp-section {
            margin-top: 15px;
        }
        
        .rsvp-btn {
            background: #007cba;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        
        .rsvp-btn:hover {
            background: #005a87;
        }
        
        .rsvp-form {
            margin-top: 15px;
            padding: 15px;
            background: #f0f0f0;
            border-radius: 4px;
        }
        
        .rsvp-form label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .rsvp-form input,
        .rsvp-form textarea,
        .rsvp-form select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        
        .rsvp-form button {
            background: #007cba;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            margin-right: 10px;
        }
        
        .cancel-rsvp {
            background: #666 !important;
        }
        
        .rsvp-form button:hover {
            opacity: 0.8;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Auto-open RSVP form for invited events
            $('.invited-event .rsvp-btn').click();
            
            // Show/hide RSVP form
            $('.rsvp-btn').click(function() {
                var eventId = $(this).data('event-id');
                $('#rsvp-form-' + eventId).toggle();
            });
            
            $('.cancel-rsvp').click(function() {
                $(this).closest('.rsvp-form').hide();
            });
            
            // Handle RSVP form submission
            $('.party-rsvp-form').submit(function(e) {
                e.preventDefault();
                
                var formData = {
                    action: 'party_rsvp',
                    event_id: $(this).data('event-id'),
                    guest_name: $(this).find('input[name="guest_name"]').val(),
                    guest_email: $(this).find('input[name="guest_email"]').val(),
                    guest_count: $(this).find('input[name="guest_count"]').val(),
                    dietary_restrictions: $(this).find('textarea[name="dietary_restrictions"]').val(),
                    rsvp_status: $(this).find('select[name="rsvp_status"]').val(),
                    nonce: party_ajax.nonce
                };
                
                $.ajax({
                    url: party_ajax.ajax_url,
                    type: 'POST',
                    data: formData,
                    success: function(response) {
                        if (response.success) {
                            alert('RSVP submitted successfully!');
                            $('.rsvp-form').hide();
                            location.reload();
                        } else {
                            alert('Error submitting RSVP: ' + response.data);
                        }
                    }
                });
            });
        });
        </script>
        <?php
        
        return ob_get_clean();
    }
    
    public function handle_rsvp() {
        global $wpdb;
        
        if (!wp_verify_nonce($_POST['nonce'], 'party_events_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        $event_id = intval($_POST['event_id']);
        $guest_name = sanitize_text_field($_POST['guest_name']);
        $guest_email = sanitize_email($_POST['guest_email']);
        $guest_count = intval($_POST['guest_count']);
        $dietary_restrictions = sanitize_textarea_field($_POST['dietary_restrictions']);
        $rsvp_status = sanitize_text_field($_POST['rsvp_status']);
        
        // Check if already RSVPed
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->rsvp_table_name WHERE event_id = %d AND guest_email = %s",
            $event_id, $guest_email
        ));
        
        if ($existing) {
            // Update existing RSVP
            $result = $wpdb->update(
                $this->rsvp_table_name,
                array(
                    'guest_name' => $guest_name,
                    'guest_count' => $guest_count,
                    'dietary_restrictions' => $dietary_restrictions,
                    'rsvp_status' => $rsvp_status
                ),
                array('id' => $existing->id)
            );
        } else {
            // Insert new RSVP
            $result = $wpdb->insert(
                $this->rsvp_table_name,
                array(
                    'event_id' => $event_id,
                    'guest_name' => $guest_name,
                    'guest_email' => $guest_email,
                    'guest_count' => $guest_count,
                    'dietary_restrictions' => $dietary_restrictions,
                    'rsvp_status' => $rsvp_status
                )
            );
        }
        
        if ($result !== false) {
            wp_send_json_success('RSVP submitted successfully');
        } else {
            wp_send_json_error('Failed to submit RSVP');
        }
    }
    
    // Frontend event creation shortcode
    public function display_create_event_shortcode($atts) {
        if (!is_user_logged_in()) {
            return '<div class="party-login-required">
                        <p>Please <a href="' . wp_login_url(get_permalink()) . '">login</a> or <a href="' . wp_registration_url() . '">register</a> to create events.</p>
                    </div>';
        }
        
        ob_start();
        ?>
        <div class="party-create-event-container">
            <h2>Create New Event</h2>
            
            <form id="frontend-event-form" class="party-frontend-form">
                <div class="form-row">
                    <label for="fe_event_title">Event Title *</label>
                    <input type="text" id="fe_event_title" name="event_title" required>
                </div>
                
                <div class="form-row">
                    <label for="fe_event_description">Description</label>
                    <textarea id="fe_event_description" name="event_description" rows="4"></textarea>
                </div>
                
                <div class="form-row">
                    <label for="fe_event_date">Date & Time *</label>
                    <input type="datetime-local" id="fe_event_date" name="event_date" required>
                </div>
                
                <div class="form-row">
                    <label for="fe_event_location">Location</label>
                    <input type="text" id="fe_event_location" name="event_location">
                </div>
                
                <div class="form-row">
                    <label for="fe_max_guests">Maximum Guests *</label>
                    <input type="number" id="fe_max_guests" name="max_guests" min="1" value="10" required>
                </div>
                
                <div class="form-row">
                    <label for="fe_host_name">Host Name *</label>
                    <input type="text" id="fe_host_name" name="host_name" value="<?php echo esc_attr(wp_get_current_user()->display_name); ?>" required>
                </div>
                
                <div class="form-row">
                    <label for="fe_host_email">Host Email *</label>
                    <input type="email" id="fe_host_email" name="host_email" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" required>
                </div>
                
                <div class="form-row">
                    <label for="fe_event_type">Event Type</label>
                    <select id="fe_event_type" name="event_type">
                        <option value="dinner">Dinner Party</option>
                        <option value="house">House Party</option>
                        <option value="cocktail">Cocktail Party</option>
                        <option value="bbq">BBQ Party</option>
                    </select>
                </div>
                
                <div class="invitation-section">
                    <h3>Invite Guests</h3>
                    <div class="form-row">
                        <label for="guest_emails">Guest Email Addresses</label>
                        <textarea id="guest_emails" name="guest_emails" rows="4" placeholder="Enter email addresses, one per line or separated by commas&#10;example@email.com&#10;friend@email.com&#10;colleague@email.com"></textarea>
                        <small>Enter one email address per line or separate multiple emails with commas</small>
                    </div>
                    
                    <div class="form-row">
                        <label for="invitation_message">Personal Message (Optional)</label>
                        <textarea id="invitation_message" name="invitation_message" rows="3" placeholder="Add a personal message to your invitation..."></textarea>
                    </div>
                    
                    <div class="form-row">
                        <label>
                            <input type="checkbox" id="send_invitations" name="send_invitations" checked>
                            Send invitations immediately after creating the event
                        </label>
                    </div>
                </div>
                
                <div class="form-row">
                    <button type="submit" class="party-submit-btn">Create Event</button>
                    <a href="<?php echo get_permalink(get_page_by_title('My Events Dashboard')); ?>" class="party-cancel-btn">Cancel</a>
                </div>
            </form>
            
            <div id="event-creation-message" class="party-message" style="display: none;"></div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#frontend-event-form').submit(function(e) {
                e.preventDefault();
                
                var formData = {
                    action: 'create_frontend_event',
                    event_title: $('#fe_event_title').val(),
                    event_description: $('#fe_event_description').val(),
                    event_date: $('#fe_event_date').val(),
                    event_location: $('#fe_event_location').val(),
                    max_guests: $('#fe_max_guests').val(),
                    host_name: $('#fe_host_name').val(),
                    host_email: $('#fe_host_email').val(),
                    event_type: $('#fe_event_type').val(),
                    guest_emails: $('#guest_emails').val(),
                    invitation_message: $('#invitation_message').val(),
                    send_invitations: $('#send_invitations').is(':checked') ? 1 : 0,
                    nonce: party_ajax.nonce
                };
                
                $.ajax({
                    url: party_ajax.ajax_url,
                    type: 'POST',
                    data: formData,
                    beforeSend: function() {
                        $('.party-submit-btn').text('Creating...').prop('disabled', true);
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#event-creation-message')
                                .removeClass('error')
                                .addClass('success')
                                .text('Event created successfully!')
                                .show();
                            
                            // Redirect to dashboard after 2 seconds
                            setTimeout(function() {
                                window.location.href = '<?php echo get_permalink(get_page_by_title('My Events Dashboard')); ?>';
                            }, 2000);
                        } else {
                            $('#event-creation-message')
                                .removeClass('success')
                                .addClass('error')
                                .text('Error: ' + response.data)
                                .show();
                        }
                    },
                    complete: function() {
                        $('.party-submit-btn').text('Create Event').prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
        
        return ob_get_clean();
    }
    
    // User dashboard shortcode
    public function display_dashboard_shortcode($atts) {
        if (!is_user_logged_in()) {
            return '<div class="party-login-required">
                        <p>Please <a href="' . wp_login_url(get_permalink()) . '">login</a> to view your dashboard.</p>
                    </div>';
        }
        
        global $wpdb;
        $user_id = get_current_user_id();
        
        // Get user's events
        $user_events = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $this->table_name WHERE user_id = %d ORDER BY event_date DESC",
            $user_id
        ));
        
        ob_start();
        ?>
        <div class="party-dashboard-container">
            <div class="dashboard-header">
                <h2>My Events Dashboard</h2>
                <a href="<?php echo get_permalink(get_page_by_title('Create New Event')); ?>" class="party-create-btn">Create New Event</a>
            </div>
            
            <?php if (empty($user_events)): ?>
                <div class="no-events">
                    <p>You haven't created any events yet.</p>
                    <a href="<?php echo get_permalink(get_page_by_title('Create New Event')); ?>" class="party-create-btn">Create Your First Event</a>
                </div>
            <?php else: ?>
                <div class="user-events-list">
                    <?php foreach ($user_events as $event): ?>
                        <div class="user-event-card" data-event-id="<?php echo $event->id; ?>">
                            <div class="event-header">
                                <h3><?php echo esc_html($event->title); ?></h3>
                                <div class="event-actions">
                                    <button class="edit-event-btn" data-event-id="<?php echo $event->id; ?>">Edit</button>
                                    <button class="view-rsvps-btn" data-event-id="<?php echo $event->id; ?>">View RSVPs</button>
                                    <button class="view-invitations-btn" data-event-id="<?php echo $event->id; ?>">Invitations</button>
                                    <button class="send-more-invites-btn" data-event-id="<?php echo $event->id; ?>">Invite More</button>
                                    <button class="delete-user-event-btn" data-event-id="<?php echo $event->id; ?>">Delete</button>
                                </div>
                            </div>
                            
                            <div class="event-details">
                                <p><strong>Date:</strong> <?php echo date('M j, Y g:i A', strtotime($event->event_date)); ?></p>
                                <p><strong>Type:</strong> <?php echo ucfirst($event->event_type); ?> Party</p>
                                <p><strong>Location:</strong> <?php echo esc_html($event->location); ?></p>
                                <p><strong>RSVPs:</strong> <?php echo $this->get_rsvp_count($event->id); ?>/<?php echo $event->max_guests; ?></p>
                                <p><strong>Status:</strong> 
                                    <?php 
                                    $now = current_time('timestamp');
                                    $event_time = strtotime($event->event_date);
                                    echo $event_time > $now ? '<span class="status-upcoming">Upcoming</span>' : '<span class="status-past">Past</span>';
                                    ?>
                                </p>
                            </div>
                            
                            <?php if ($event->description): ?>
                                <div class="event-description">
                                    <p><?php echo nl2br(esc_html($event->description)); ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Edit form (hidden by default) -->
                            <div class="edit-event-form" id="edit-form-<?php echo $event->id; ?>" style="display: none;">
                                <h4>Edit Event</h4>
                                <form class="party-edit-form" data-event-id="<?php echo $event->id; ?>">
                                    <div class="form-row">
                                        <label>Event Title *</label>
                                        <input type="text" name="event_title" value="<?php echo esc_attr($event->title); ?>" required>
                                    </div>
                                    
                                    <div class="form-row">
                                        <label>Description</label>
                                        <textarea name="event_description" rows="3"><?php echo esc_textarea($event->description); ?></textarea>
                                    </div>
                                    
                                    <div class="form-row">
                                        <label>Date & Time *</label>
                                        <input type="datetime-local" name="event_date" value="<?php echo date('Y-m-d\TH:i', strtotime($event->event_date)); ?>" required>
                                    </div>
                                    
                                    <div class="form-row">
                                        <label>Location</label>
                                        <input type="text" name="event_location" value="<?php echo esc_attr($event->location); ?>">
                                    </div>
                                    
                                    <div class="form-row">
                                        <label>Maximum Guests *</label>
                                        <input type="number" name="max_guests" min="1" value="<?php echo $event->max_guests; ?>" required>
                                    </div>
                                    
                                    <div class="form-row">
                                        <label>Host Name *</label>
                                        <input type="text" name="host_name" value="<?php echo esc_attr($event->host_name); ?>" required>
                                    </div>
                                    
                                    <div class="form-row">
                                        <label>Host Email *</label>
                                        <input type="email" name="host_email" value="<?php echo esc_attr($event->host_email); ?>" required>
                                    </div>
                                    
                                    <div class="form-row">
                                        <label>Event Type</label>
                                        <select name="event_type">
                                            <option value="dinner" <?php selected($event->event_type, 'dinner'); ?>>Dinner Party</option>
                                            <option value="house" <?php selected($event->event_type, 'house'); ?>>House Party</option>
                                            <option value="cocktail" <?php selected($event->event_type, 'cocktail'); ?>>Cocktail Party</option>
                                            <option value="bbq" <?php selected($event->event_type, 'bbq'); ?>>BBQ Party</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-row">
                                        <button type="submit" class="party-save-btn">Save Changes</button>
                                        <button type="button" class="party-cancel-edit-btn">Cancel</button>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- RSVP list (hidden by default) -->
                            <div class="event-rsvps" id="rsvps-<?php echo $event->id; ?>" style="display: none;">
                                <h4>RSVPs</h4>
                                <div class="rsvp-list"></div>
                            </div>
                            
                            <!-- Invitations list (hidden by default) -->
                            <div class="event-invitations" id="invitations-<?php echo $event->id; ?>" style="display: none;">
                                <h4>Sent Invitations</h4>
                                <div class="invitations-list"></div>
                            </div>
                            
                            <!-- Send more invitations form (hidden by default) -->
                            <div class="send-invitations-form" id="invite-form-<?php echo $event->id; ?>" style="display: none;">
                                <h4>Send More Invitations</h4>
                                <form class="party-invite-form" data-event-id="<?php echo $event->id; ?>">
                                    <div class="form-row">
                                        <label>Guest Email Addresses *</label>
                                        <textarea name="guest_emails" rows="4" placeholder="Enter email addresses, one per line or separated by commas" required></textarea>
                                        <small>Enter one email address per line or separate multiple emails with commas</small>
                                    </div>
                                    
                                    <div class="form-row">
                                        <label>Personal Message (Optional)</label>
                                        <textarea name="invitation_message" rows="3" placeholder="Add a personal message to your invitation..."></textarea>
                                    </div>
                                    
                                    <div class="form-row">
                                        <button type="submit" class="party-send-invites-btn">Send Invitations</button>
                                        <button type="button" class="party-cancel-invite-btn">Cancel</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Edit event
            $('.edit-event-btn').click(function() {
                var eventId = $(this).data('event-id');
                $('#edit-form-' + eventId).toggle();
            });
            
            $('.party-cancel-edit-btn').click(function() {
                $(this).closest('.edit-event-form').hide();
            });
            
            // Handle edit form submission
            $('.party-edit-form').submit(function(e) {
                e.preventDefault();
                
                var eventId = $(this).data('event-id');
                var formData = {
                    action: 'update_frontend_event',
                    event_id: eventId,
                    event_title: $(this).find('input[name="event_title"]').val(),
                    event_description: $(this).find('textarea[name="event_description"]').val(),
                    event_date: $(this).find('input[name="event_date"]').val(),
                    event_location: $(this).find('input[name="event_location"]').val(),
                    max_guests: $(this).find('input[name="max_guests"]').val(),
                    host_name: $(this).find('input[name="host_name"]').val(),
                    host_email: $(this).find('input[name="host_email"]').val(),
                    event_type: $(this).find('select[name="event_type"]').val(),
                    nonce: party_ajax.nonce
                };
                
                $.ajax({
                    url: party_ajax.ajax_url,
                    type: 'POST',
                    data: formData,
                    beforeSend: function() {
                        $('.party-save-btn').text('Saving...').prop('disabled', true);
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Event updated successfully!');
                            location.reload();
                        } else {
                            alert('Error updating event: ' + response.data);
                        }
                    },
                    complete: function() {
                        $('.party-save-btn').text('Save Changes').prop('disabled', false);
                    }
                });
            });
            
            // Delete event
            $('.delete-user-event-btn').click(function() {
                if (confirm('Are you sure you want to delete this event? This action cannot be undone.')) {
                    var eventId = $(this).data('event-id');
                    
                    $.ajax({
                        url: party_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'delete_user_event',
                            event_id: eventId,
                            nonce: party_ajax.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                $('[data-event-id="' + eventId + '"]').fadeOut();
                            } else {
                                alert('Error deleting event');
                            }
                        }
                    });
                }
            });
            
            // View RSVPs
            $('.view-rsvps-btn').click(function() {
                var eventId = $(this).data('event-id');
                var rsvpContainer = $('#rsvps-' + eventId);
                
                if (rsvpContainer.is(':visible')) {
                    rsvpContainer.hide();
                    return;
                }
                
                $.ajax({
                    url: party_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'get_event_rsvps',
                        event_id: eventId,
                        nonce: party_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            rsvpContainer.find('.rsvp-list').html(response.data);
                            rsvpContainer.show();
                        } else {
                            alert('Error loading RSVPs');
                        }
                    }
                });
            });
            
            // View Invitations
            $('.view-invitations-btn').click(function() {
                var eventId = $(this).data('event-id');
                var invitationsContainer = $('#invitations-' + eventId);
                
                if (invitationsContainer.is(':visible')) {
                    invitationsContainer.hide();
                    return;
                }
                
                $.ajax({
                    url: party_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'get_event_invitations',
                        event_id: eventId,
                        nonce: party_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            invitationsContainer.find('.invitations-list').html(response.data);
                            invitationsContainer.show();
                        } else {
                            alert('Error loading invitations');
                        }
                    }
                });
            });
            
            // Send more invitations
            $('.send-more-invites-btn').click(function() {
                var eventId = $(this).data('event-id');
                $('#invite-form-' + eventId).toggle();
            });
            
            $('.party-cancel-invite-btn').click(function() {
                $(this).closest('.send-invitations-form').hide();
            });
            
            // Handle invitation form submission
            $('.party-invite-form').submit(function(e) {
                e.preventDefault();
                
                var eventId = $(this).data('event-id');
                var formData = {
                    action: 'send_invitations',
                    event_id: eventId,
                    guest_emails: $(this).find('textarea[name="guest_emails"]').val(),
                    invitation_message: $(this).find('textarea[name="invitation_message"]').val(),
                    nonce: party_ajax.nonce
                };
                
                $.ajax({
                    url: party_ajax.ajax_url,
                    type: 'POST',
                    data: formData,
                    beforeSend: function() {
                        $('.party-send-invites-btn').text('Sending...').prop('disabled', true);
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Invitations sent successfully!');
                            $('.send-invitations-form').hide();
                            // Clear form
                            $('.party-invite-form')[0].reset();
                        } else {
                            alert('Error sending invitations: ' + response.data);
                        }
                    },
                    complete: function() {
                        $('.party-send-invites-btn').text('Send Invitations').prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
        
        return ob_get_clean();
    }
    
    // Handle frontend event creation
    public function handle_frontend_event_creation() {
        if (!is_user_logged_in()) {
            wp_send_json_error('You must be logged in to create events');
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'party_events_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        global $wpdb;
        $user_id = get_current_user_id();
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'title' => sanitize_text_field($_POST['event_title']),
                'description' => sanitize_textarea_field($_POST['event_description']),
                'event_date' => sanitize_text_field($_POST['event_date']),
                'location' => sanitize_text_field($_POST['event_location']),
                'max_guests' => intval($_POST['max_guests']),
                'host_name' => sanitize_text_field($_POST['host_name']),
                'host_email' => sanitize_email($_POST['host_email']),
                'event_type' => sanitize_text_field($_POST['event_type']),
                'user_id' => $user_id
            )
        );
        
        if ($result !== false) {
            $event_id = $wpdb->insert_id;
            
            // Handle invitations if requested
            if (!empty($_POST['guest_emails']) && !empty($_POST['send_invitations'])) {
                $this->process_invitations($event_id, $_POST['guest_emails'], $_POST['invitation_message']);
            }
            
            wp_send_json_success('Event created successfully');
        } else {
            wp_send_json_error('Failed to create event');
        }
    }
    
    // Handle frontend event update
    public function handle_frontend_event_update() {
        if (!is_user_logged_in()) {
            wp_send_json_error('You must be logged in to update events');
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'party_events_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        global $wpdb;
        $user_id = get_current_user_id();
        $event_id = intval($_POST['event_id']);
        
        // Check if user owns this event
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->table_name WHERE id = %d AND user_id = %d",
            $event_id, $user_id
        ));
        
        if (!$event) {
            wp_send_json_error('You do not have permission to update this event');
        }
        
        $result = $wpdb->update(
            $this->table_name,
            array(
                'title' => sanitize_text_field($_POST['event_title']),
                'description' => sanitize_textarea_field($_POST['event_description']),
                'event_date' => sanitize_text_field($_POST['event_date']),
                'location' => sanitize_text_field($_POST['event_location']),
                'max_guests' => intval($_POST['max_guests']),
                'host_name' => sanitize_text_field($_POST['host_name']),
                'host_email' => sanitize_email($_POST['host_email']),
                'event_type' => sanitize_text_field($_POST['event_type'])
            ),
            array('id' => $event_id)
        );
        
        if ($result !== false) {
            wp_send_json_success('Event updated successfully');
        } else {
            wp_send_json_error('Failed to update event');
        }
    }
    
    // Handle user event deletion
    public function handle_user_event_deletion() {
        if (!is_user_logged_in()) {
            wp_send_json_error('You must be logged in to delete events');
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'party_events_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        global $wpdb;
        $user_id = get_current_user_id();
        $event_id = intval($_POST['event_id']);
        
        // Check if user owns this event
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->table_name WHERE id = %d AND user_id = %d",
            $event_id, $user_id
        ));
        
        if (!$event) {
            wp_send_json_error('You do not have permission to delete this event');
        }
        
        $result = $wpdb->delete($this->table_name, array('id' => $event_id));
        
        if ($result) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Failed to delete event');
        }
    }
    
    // Get event RSVPs
    public function get_event_rsvps() {
        if (!is_user_logged_in()) {
            wp_send_json_error('You must be logged in to view RSVPs');
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'party_events_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        global $wpdb;
        $user_id = get_current_user_id();
        $event_id = intval($_POST['event_id']);
        
        // Check if user owns this event
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->table_name WHERE id = %d AND user_id = %d",
            $event_id, $user_id
        ));
        
        if (!$event) {
            wp_send_json_error('You do not have permission to view these RSVPs');
        }
        
        $rsvps = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $this->rsvp_table_name WHERE event_id = %d ORDER BY created_at DESC",
            $event_id
        ));
        
        if (empty($rsvps)) {
            wp_send_json_success('<p>No RSVPs yet.</p>');
        }
        
        $html = '<table class="rsvp-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Guests</th>
                            <th>Status</th>
                            <th>Dietary Restrictions</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>';
        
        foreach ($rsvps as $rsvp) {
            $status_class = 'status-' . $rsvp->rsvp_status;
            $html .= '<tr>
                        <td>' . esc_html($rsvp->guest_name) . '</td>
                        <td>' . esc_html($rsvp->guest_email) . '</td>
                        <td>' . $rsvp->guest_count . '</td>
                        <td><span class="' . $status_class . '">' . ucfirst($rsvp->rsvp_status) . '</span></td>
                        <td>' . esc_html($rsvp->dietary_restrictions) . '</td>
                        <td>' . date('M j, Y', strtotime($rsvp->created_at)) . '</td>
                      </tr>';
        }
        
        $html .= '</tbody></table>';
        
        wp_send_json_success($html);
    }
    
    // Process invitations for an event
    private function process_invitations($event_id, $guest_emails, $message = '') {
        global $wpdb;
        
        // Get event details
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->table_name WHERE id = %d",
            $event_id
        ));
        
        if (!$event) {
            return false;
        }
        
        // Parse email addresses
        $emails = $this->parse_email_addresses($guest_emails);
        
        if (empty($emails)) {
            return false;
        }
        
        $sent_count = 0;
        
        foreach ($emails as $email) {
            $email = trim($email);
            
            if (!is_email($email)) {
                continue;
            }
            
            // Generate unique invitation token
            $token = wp_generate_password(32, false);
            
            // Check if invitation already exists
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $this->invitations_table_name WHERE event_id = %d AND guest_email = %s",
                $event_id, $email
            ));
            
            if ($existing) {
                // Update existing invitation
                $wpdb->update(
                    $this->invitations_table_name,
                    array(
                        'invitation_token' => $token,
                        'status' => 'sent',
                        'sent_at' => current_time('mysql')
                    ),
                    array('id' => $existing->id)
                );
            } else {
                // Insert new invitation
                $wpdb->insert(
                    $this->invitations_table_name,
                    array(
                        'event_id' => $event_id,
                        'guest_email' => $email,
                        'invitation_token' => $token,
                        'status' => 'sent'
                    )
                );
            }
            
            // Send email invitation
            if ($this->send_invitation_email($event, $email, $token, $message)) {
                $sent_count++;
            }
        }
        
        return $sent_count;
    }
    
    // Parse email addresses from text input
    private function parse_email_addresses($input) {
        $input = sanitize_textarea_field($input);
        
        // Split by commas and newlines
        $emails = preg_split('/[,\n\r]+/', $input);
        
        // Clean up emails
        $clean_emails = array();
        foreach ($emails as $email) {
            $email = trim($email);
            if (!empty($email)) {
                $clean_emails[] = $email;
            }
        }
        
        return array_unique($clean_emails);
    }
    
    // Send invitation email
    private function send_invitation_email($event, $guest_email, $token, $custom_message = '') {
        $event_url = get_permalink(get_page_by_title('Party Events')) . '?invite=' . $token;
        
        $subject = sprintf('You\'re invited to %s!', $event->title);
        
        $message = "Hello!\n\n";
        $message .= sprintf("You've been invited to %s by %s.\n\n", $event->title, $event->host_name);
        
        $message .= "Event Details:\n";
        $message .= sprintf("📅 Date: %s\n", date('F j, Y \a\t g:i A', strtotime($event->event_date)));
        $message .= sprintf("🎉 Type: %s Party\n", ucfirst($event->event_type));
        
        if ($event->location) {
            $message .= sprintf("📍 Location: %s\n", $event->location);
        }
        
        if ($event->description) {
            $message .= sprintf("\nAbout this event:\n%s\n", $event->description);
        }
        
        if (!empty($custom_message)) {
            $message .= sprintf("\nPersonal message from %s:\n%s\n", $event->host_name, $custom_message);
        }
        
        $message .= sprintf("\nPlease RSVP by visiting: %s\n\n", $event_url);
        $message .= "We hope to see you there!\n\n";
        $message .= sprintf("Best regards,\n%s", $event->host_name);
        
        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            sprintf('From: %s <%s>', $event->host_name, $event->host_email),
            sprintf('Reply-To: %s', $event->host_email)
        );
        
        return wp_mail($guest_email, $subject, $message, $headers);
    }
    
    // Handle sending invitations via AJAX
    public function handle_send_invitations() {
        if (!is_user_logged_in()) {
            wp_send_json_error('You must be logged in to send invitations');
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'party_events_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        global $wpdb;
        $user_id = get_current_user_id();
        $event_id = intval($_POST['event_id']);
        
        // Check if user owns this event
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->table_name WHERE id = %d AND user_id = %d",
            $event_id, $user_id
        ));
        
        if (!$event) {
            wp_send_json_error('You do not have permission to send invitations for this event');
        }
        
        $guest_emails = $_POST['guest_emails'];
        $message = sanitize_textarea_field($_POST['invitation_message']);
        
        if (empty($guest_emails)) {
            wp_send_json_error('Please enter at least one email address');
        }
        
        $sent_count = $this->process_invitations($event_id, $guest_emails, $message);
        
        if ($sent_count > 0) {
            wp_send_json_success(sprintf('%d invitation(s) sent successfully', $sent_count));
        } else {
            wp_send_json_error('Failed to send invitations');
        }
    }
    
    // Get event invitations
    public function get_event_invitations() {
        if (!is_user_logged_in()) {
            wp_send_json_error('You must be logged in to view invitations');
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'party_events_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        global $wpdb;
        $user_id = get_current_user_id();
        $event_id = intval($_POST['event_id']);
        
        // Check if user owns this event
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->table_name WHERE id = %d AND user_id = %d",
            $event_id, $user_id
        ));
        
        if (!$event) {
            wp_send_json_error('You do not have permission to view these invitations');
        }
        
        $invitations = $wpdb->get_results($wpdb->prepare(
            "SELECT i.*, 
                    CASE WHEN r.guest_email IS NOT NULL THEN r.rsvp_status ELSE 'no_response' END as rsvp_status,
                    r.guest_count
             FROM $this->invitations_table_name i
             LEFT JOIN $this->rsvp_table_name r ON i.event_id = r.event_id AND i.guest_email = r.guest_email
             WHERE i.event_id = %d 
             ORDER BY i.sent_at DESC",
            $event_id
        ));
        
        if (empty($invitations)) {
            wp_send_json_success('<p>No invitations sent yet.</p>');
        }
        
        $html = '<table class="invitations-table">
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>Sent</th>
                            <th>Status</th>
                            <th>RSVP Response</th>
                            <th>Guest Count</th>
                        </tr>
                    </thead>
                    <tbody>';
        
        foreach ($invitations as $invitation) {
            $rsvp_class = 'status-' . $invitation->rsvp_status;
            $rsvp_text = ($invitation->rsvp_status == 'no_response') ? 'No Response' : ucfirst($invitation->rsvp_status);
            $guest_count = ($invitation->guest_count) ? $invitation->guest_count : '-';
            
            $html .= '<tr>
                        <td>' . esc_html($invitation->guest_email) . '</td>
                        <td>' . date('M j, Y g:i A', strtotime($invitation->sent_at)) . '</td>
                        <td><span class="status-sent">Sent</span></td>
                        <td><span class="' . $rsvp_class . '">' . $rsvp_text . '</span></td>
                        <td>' . $guest_count . '</td>
                      </tr>';
        }
        
        $html .= '</tbody></table>';
        
        wp_send_json_success($html);
    }
}

// Initialize the plugin
new PartyEventsOrganizer();

// Add comprehensive CSS styles
add_action('wp_head', function() {
    ?>
    <style>
    /* Frontend Form Styles */
    .party-frontend-form {
        max-width: 600px;
        margin: 0 auto;
        background: #fff;
        padding: 30px;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    
    .party-frontend-form .form-row {
        margin-bottom: 20px;
    }
    
    .party-frontend-form label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
        color: #333;
    }
    
    .party-frontend-form input,
    .party-frontend-form textarea,
    .party-frontend-form select {
        width: 100%;
        padding: 12px;
        border: 2px solid #ddd;
        border-radius: 4px;
        font-size: 16px;
        transition: border-color 0.3s ease;
    }
    
    .party-frontend-form input:focus,
    .party-frontend-form textarea:focus,
    .party-frontend-form select:focus {
        outline: none;
        border-color: #007cba;
    }
    
    .party-submit-btn, .party-create-btn {
        background: #007cba;
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 16px;
        text-decoration: none;
        display: inline-block;
        transition: background-color 0.3s ease;
    }
    
    .party-submit-btn:hover, .party-create-btn:hover {
        background: #005a87;
    }
    
    .party-cancel-btn {
        background: #666;
        color: white;
        padding: 12px 24px;
        border-radius: 4px;
        text-decoration: none;
        margin-left: 10px;
        display: inline-block;
    }
    
    .party-cancel-btn:hover {
        background: #555;
    }
    
    /* Dashboard Styles */
    .party-dashboard-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
    }
    
    .dashboard-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 2px solid #eee;
    }
    
    .dashboard-header h2 {
        margin: 0;
        color: #333;
    }
    
    .no-events {
        text-align: center;
        padding: 60px 20px;
        background: #f9f9f9;
        border-radius: 8px;
    }
    
    .no-events p {
        font-size: 18px;
        color: #666;
        margin-bottom: 20px;
    }
    
    .user-events-list {
        display: grid;
        gap: 20px;
    }
    
    .user-event-card {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 25px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        transition: box-shadow 0.3s ease;
    }
    
    .user-event-card:hover {
        box-shadow: 0 4px 15px rgba(0,0,0,0.15);
    }
    
    .event-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        padding-bottom: 15px;
        border-bottom: 1px solid #eee;
    }
    
    .event-header h3 {
        margin: 0;
        color: #333;
        font-size: 24px;
    }
    
    .event-actions {
        display: flex;
        gap: 10px;
    }
    
    .event-actions button {
        padding: 8px 12px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        transition: background-color 0.3s ease;
    }
    
    .edit-event-btn {
        background: #007cba;
        color: white;
    }
    
    .edit-event-btn:hover {
        background: #005a87;
    }
    
    .view-rsvps-btn {
        background: #28a745;
        color: white;
    }
    
    .view-rsvps-btn:hover {
        background: #218838;
    }
    
    .view-invitations-btn {
        background: #17a2b8;
        color: white;
    }
    
    .view-invitations-btn:hover {
        background: #138496;
    }
    
    .send-more-invites-btn {
        background: #fd7e14;
        color: white;
    }
    
    .send-more-invites-btn:hover {
        background: #e56b0c;
    }
    
    .delete-user-event-btn {
        background: #dc3545;
        color: white;
    }
    
    .delete-user-event-btn:hover {
        background: #c82333;
    }
    
    .event-details {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 10px;
        margin-bottom: 15px;
    }
    
    .event-details p {
        margin: 5px 0;
        font-size: 14px;
    }
    
    .status-upcoming {
        color: #28a745;
        font-weight: bold;
    }
    
    .status-past {
        color: #6c757d;
        font-weight: bold;
    }
    
    .event-description {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 4px;
        margin: 15px 0;
    }
    
    .edit-event-form {
        margin-top: 20px;
        padding: 20px;
        background: #f8f9fa;
        border-radius: 4px;
        border: 1px solid #ddd;
    }
    
    .edit-event-form h4 {
        margin-top: 0;
        color: #333;
    }
    
    .party-save-btn {
        background: #28a745;
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 4px;
        cursor: pointer;
        margin-right: 10px;
    }
    
    .party-save-btn:hover {
        background: #218838;
    }
    
    .party-cancel-edit-btn {
        background: #6c757d;
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 4px;
        cursor: pointer;
    }
    
    .party-cancel-edit-btn:hover {
        background: #5a6268;
    }
    
    .event-rsvps {
        margin-top: 20px;
        padding: 20px;
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 4px;
    }
    
    .event-invitations {
        margin-top: 20px;
        padding: 20px;
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 4px;
    }
    
    .send-invitations-form {
        margin-top: 20px;
        padding: 20px;
        background: #f8f9fa;
        border: 1px solid #ddd;
        border-radius: 4px;
    }
    
    .send-invitations-form h4 {
        margin-top: 0;
        color: #333;
    }
    
    .party-send-invites-btn {
        background: #fd7e14;
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 4px;
        cursor: pointer;
        margin-right: 10px;
    }
    
    .party-send-invites-btn:hover {
        background: #e56b0c;
    }
    
    .party-cancel-invite-btn {
        background: #6c757d;
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 4px;
        cursor: pointer;
    }
    
    .party-cancel-invite-btn:hover {
        background: #5a6268;
    }
    
    .invitation-section {
        margin-top: 30px;
        padding: 20px;
        background: #f8f9fa;
        border: 1px solid #ddd;
        border-radius: 4px;
    }
    
    .invitation-section h3 {
        margin-top: 0;
        color: #333;
        border-bottom: 2px solid #007cba;
        padding-bottom: 10px;
    }
    
    .invitation-welcome {
        background: linear-gradient(135deg, #007cba, #005a87);
        color: white;
        padding: 20px;
        border-radius: 8px;
        text-align: center;
        margin-bottom: 30px;
    }
    
    .invitation-welcome h2 {
        margin: 0 0 10px 0;
        font-size: 28px;
    }
    
    .invitation-welcome p {
        margin: 0;
        font-size: 16px;
        opacity: 0.9;
    }
    
    .invited-event {
        border: 3px solid #007cba;
        position: relative;
        box-shadow: 0 4px 15px rgba(0, 124, 186, 0.2);
    }
    
    .invitation-badge {
        background: linear-gradient(135deg, #007cba, #005a87);
        color: white;
        padding: 8px 15px;
        border-radius: 20px;
        position: absolute;
        top: -10px;
        right: 20px;
        font-weight: bold;
        font-size: 14px;
        box-shadow: 0 2px 8px rgba(0, 124, 186, 0.3);
    }
    
    .rsvp-table, .invitations-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }
    
    .rsvp-table th, .invitations-table th,
    .rsvp-table td, .invitations-table td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid #ddd;
    }
    
    .rsvp-table th, .invitations-table th {
        background: #f8f9fa;
        font-weight: bold;
        color: #333;
    }
    
    .rsvp-table tr:hover, .invitations-table tr:hover {
        background: #f8f9fa;
    }
    
    .status-yes {
        color: #28a745;
        font-weight: bold;
    }
    
    .status-no {
        color: #dc3545;
        font-weight: bold;
    }
    
    .status-maybe {
        color: #ffc107;
        font-weight: bold;
    }
    
    .status-sent {
        color: #17a2b8;
        font-weight: bold;
    }
    
    .status-no_response {
        color: #6c757d;
        font-weight: bold;
    }
    
    /* Message Styles */
    .party-message {
        padding: 15px;
        border-radius: 4px;
        margin: 20px 0;
    }
    
    .party-message.success {
        background: #d4edda;
        border: 1px solid #c3e6cb;
        color: #155724;
    }
    
    .party-message.error {
        background: #f8d7da;
        border: 1px solid #f5c6cb;
        color: #721c24;
    }
    
    /* Login Required Styles */
    .party-login-required {
        text-align: center;
        padding: 40px 20px;
        background: #f8f9fa;
        border-radius: 8px;
        border: 1px solid #ddd;
    }
    
    .party-login-required p {
        font-size: 16px;
        color: #666;
    }
    
    .party-login-required a {
        color: #007cba;
        text-decoration: none;
        font-weight: bold;
    }
    
    .party-login-required a:hover {
        text-decoration: underline;
    }
    
    /* Responsive Design */
    @media (max-width: 768px) {
        .dashboard-header {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }
        
        .event-header {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }
        
        .event-actions {
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .event-actions button {
            margin: 2px;
            padding: 6px 10px;
            font-size: 12px;
        }
        
        .event-details {
            grid-template-columns: 1fr;
        }
        
        .party-frontend-form {
            margin: 20px;
            padding: 20px;
        }
        
        .rsvp-table, .invitations-table {
            font-size: 14px;
        }
        
        .rsvp-table th, .invitations-table th,
        .rsvp-table td, .invitations-table td {
            padding: 8px;
        }
        
        .invitation-welcome h2 {
            font-size: 24px;
        }
        
        .invitation-badge {
            position: static;
            margin-bottom: 15px;
            display: inline-block;
        }
        
        .invited-event {
            padding-top: 15px;
        }
        
        .invitation-section {
            margin: 20px 0;
            padding: 15px;
        }
    }
    </style>
    <?php
});
?>
