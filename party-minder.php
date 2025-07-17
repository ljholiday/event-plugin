<?php
/**
 * Plugin Name: Party-Minder
 * Plugin URI: https://example.com/party-minder
 * Description: Allows visitors to create events like dinner parties and house parties and invite friends.
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('PARTY_MINDER_VERSION', '1.0.0');
define('PARTY_MINDER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PARTY_MINDER_PLUGIN_URL', plugin_dir_url(__FILE__));

class PartyMinder {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'create_tables'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_shortcode('event_form', array($this, 'event_form_shortcode'));
        add_shortcode('event_list', array($this, 'event_list_shortcode'));
        add_shortcode('my_events', array($this, 'my_events_shortcode'));
        
        // AJAX handlers
        add_action('wp_ajax_create_event', array($this, 'handle_create_event'));
        add_action('wp_ajax_nopriv_create_event', array($this, 'handle_create_event'));
        add_action('wp_ajax_send_invitation', array($this, 'handle_send_invitation'));
        add_action('wp_ajax_nopriv_send_invitation', array($this, 'handle_send_invitation'));
        add_action('wp_ajax_rsvp_response', array($this, 'handle_rsvp_response'));
        add_action('wp_ajax_nopriv_rsvp_response', array($this, 'handle_rsvp_response'));
        
        // Handle RSVP from email links
        add_action('template_redirect', array($this, 'handle_rsvp_link'));
    }
    
    public function enqueue_scripts() {
        wp_enqueue_script('party-minder-js', PARTY_MINDER_PLUGIN_URL . 'assets/party-minder.js', array('jquery'), PARTY_MINDER_VERSION, true);
        wp_enqueue_style('party-minder-css', PARTY_MINDER_PLUGIN_URL . 'assets/party-minder.css', array(), PARTY_MINDER_VERSION);
        
        wp_localize_script('party-minder-js', 'party_minder_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('party_minder_nonce')
        ));
    }
    
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Events table
        $events_table = $wpdb->prefix . 'events';
        $events_sql = "CREATE TABLE $events_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            description text,
            event_date datetime NOT NULL,
            location varchar(255),
            max_guests int(11) DEFAULT 0,
            creator_name varchar(100) NOT NULL,
            creator_email varchar(100) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            status enum('active', 'cancelled') DEFAULT 'active',
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        // Invitations table
        $invitations_table = $wpdb->prefix . 'event_invitations';
        $invitations_sql = "CREATE TABLE $invitations_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            event_id mediumint(9) NOT NULL,
            guest_name varchar(100) NOT NULL,
            guest_email varchar(100) NOT NULL,
            status enum('pending', 'accepted', 'declined') DEFAULT 'pending',
            invitation_token varchar(64) NOT NULL,
            sent_at datetime DEFAULT CURRENT_TIMESTAMP,
            responded_at datetime,
            PRIMARY KEY (id),
            FOREIGN KEY (event_id) REFERENCES $events_table(id) ON DELETE CASCADE
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($events_sql);
        dbDelta($invitations_sql);
    }
    
    public function deactivate() {
        // Clean up scheduled events if any
        wp_clear_scheduled_hook('party_minder_cleanup');
    }
    
    public function event_form_shortcode($atts) {
        ob_start();
        ?>
        <div id="party-minder-form">
            <h3>Create New Event</h3>
            <form id="create-event-form">
                <div class="form-group">
                    <label for="event-title">Event Title *</label>
                    <input type="text" id="event-title" name="title" required>
                </div>
                
                <div class="form-group">
                    <label for="event-description">Description</label>
                    <textarea id="event-description" name="description" rows="4"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="event-date">Date & Time *</label>
                    <input type="datetime-local" id="event-date" name="event_date" required>
                </div>
                
                <div class="form-group">
                    <label for="event-location">Location</label>
                    <input type="text" id="event-location" name="location">
                </div>
                
                <div class="form-group">
                    <label for="max-guests">Maximum Guests</label>
                    <input type="number" id="max-guests" name="max_guests" min="1" value="10">
                </div>
                
                <div class="form-group">
                    <label for="creator-name">Your Name *</label>
                    <input type="text" id="creator-name" name="creator_name" required>
                </div>
                
                <div class="form-group">
                    <label for="creator-email">Your Email *</label>
                    <input type="email" id="creator-email" name="creator_email" required>
                </div>
                
                <div class="form-group">
                    <label for="guest-emails">Guest Emails (comma-separated)</label>
                    <textarea id="guest-emails" name="guest_emails" rows="3" placeholder="friend1@email.com, friend2@email.com"></textarea>
                </div>
                
                <button type="submit">Create Event</button>
            </form>
            
            <div id="event-message"></div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function event_list_shortcode($atts) {
        global $wpdb;
        
        $atts = shortcode_atts(array(
            'limit' => 10,
            'upcoming' => true
        ), $atts);
        
        $events_table = $wpdb->prefix . 'events';
        $where_clause = $atts['upcoming'] ? "WHERE event_date > NOW() AND status = 'active'" : "WHERE status = 'active'";
        
        $events = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $events_table $where_clause ORDER BY event_date ASC LIMIT %d",
            $atts['limit']
        ));
        
        if (empty($events)) {
            return '<p>No events found.</p>';
        }
        
        ob_start();
        ?>
        <div id="event-list">
            <h3>Upcoming Events</h3>
            <?php foreach ($events as $event): ?>
                <div class="event-item">
                    <h4><?php echo esc_html($event->title); ?></h4>
                    <p><strong>Date:</strong> <?php echo date('F j, Y g:i A', strtotime($event->event_date)); ?></p>
                    <?php if ($event->location): ?>
                        <p><strong>Location:</strong> <?php echo esc_html($event->location); ?></p>
                    <?php endif; ?>
                    <p><strong>Host:</strong> <?php echo esc_html($event->creator_name); ?></p>
                    <?php if ($event->description): ?>
                        <p><?php echo nl2br(esc_html($event->description)); ?></p>
                    <?php endif; ?>
                    <p><strong>Max Guests:</strong> <?php echo $event->max_guests; ?></p>
                    
                    <div class="event-actions">
                        <button class="request-invite-btn" data-event-id="<?php echo $event->id; ?>">Request Invitation</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Invitation Request Modal -->
        <div id="invitation-modal" style="display: none;">
            <div class="modal-content">
                <h4>Request Invitation</h4>
                <form id="invitation-request-form">
                    <input type="hidden" id="request-event-id" name="event_id">
                    <div class="form-group">
                        <label for="requester-name">Your Name *</label>
                        <input type="text" id="requester-name" name="name" required>
                    </div>
                    <div class="form-group">
                        <label for="requester-email">Your Email *</label>
                        <input type="email" id="requester-email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="request-message">Message (optional)</label>
                        <textarea id="request-message" name="message" rows="3"></textarea>
                    </div>
                    <button type="submit">Send Request</button>
                    <button type="button" id="close-modal">Cancel</button>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function my_events_shortcode($atts) {
        $email = '';
        
        // Check if user is logged in first
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $email = $current_user->user_email;
        } 
        // Check for email in URL parameter
        elseif (isset($_GET['email']) && is_email($_GET['email'])) {
            $email = sanitize_email($_GET['email']);
        }
        // Check for email in POST data (from form submission)
        elseif (isset($_POST['user_email']) && is_email($_POST['user_email'])) {
            $email = sanitize_email($_POST['user_email']);
        }
        
        // If no valid email found, show email entry form
        if (empty($email)) {
            return $this->show_email_entry_form();
        }
        
        global $wpdb;
        
        // Get events created by this user
        $events_table = $wpdb->prefix . 'events';
        $invitations_table = $wpdb->prefix . 'event_invitations';
        
        $created_events = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $events_table WHERE creator_email = %s ORDER BY event_date ASC",
            $email
        ));
        
        // Get invitations for this user
        $invitations = $wpdb->get_results($wpdb->prepare(
            "SELECT i.*, e.title, e.event_date, e.location, e.creator_name 
             FROM $invitations_table i 
             JOIN $events_table e ON i.event_id = e.id 
             WHERE i.guest_email = %s 
             ORDER BY e.event_date ASC",
            $email
        ));
        
        ob_start();
        ?>
        <div id="my-events">
            <h3>My Events</h3>
            
            <?php if (!empty($created_events)): ?>
                <h4>Events I Created</h4>
                <?php foreach ($created_events as $event): ?>
                    <div class="my-event-item">
                        <h5><?php echo esc_html($event->title); ?></h5>
                        <p><strong>Date:</strong> <?php echo date('F j, Y g:i A', strtotime($event->event_date)); ?></p>
                        <?php if ($event->location): ?>
                            <p><strong>Location:</strong> <?php echo esc_html($event->location); ?></p>
                        <?php endif; ?>
                        
                        <?php
                        // Get RSVPs for this event
                        $rsvps = $wpdb->get_results($wpdb->prepare(
                            "SELECT * FROM $invitations_table WHERE event_id = %d ORDER BY sent_at ASC",
                            $event->id
                        ));
                        ?>
                        
                        <div class="rsvp-summary">
                            <h6>Guest Responses:</h6>
                            <?php if (empty($rsvps)): ?>
                                <p>No invitations sent yet.</p>
                            <?php else: ?>
                                <?php foreach ($rsvps as $rsvp): ?>
                                    <div class="rsvp-item">
                                        <span><?php echo esc_html($rsvp->guest_name); ?></span>
                                        <span class="rsvp-status status-<?php echo $rsvp->status; ?>">
                                            <?php echo ucfirst($rsvp->status); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <?php if (!empty($invitations)): ?>
                <h4>Events I'm Invited To</h4>
                <?php foreach ($invitations as $invitation): ?>
                    <div class="invitation-item">
                        <h5><?php echo esc_html($invitation->title); ?></h5>
                        <p><strong>Host:</strong> <?php echo esc_html($invitation->creator_name); ?></p>
                        <p><strong>Date:</strong> <?php echo date('F j, Y g:i A', strtotime($invitation->event_date)); ?></p>
                        <?php if ($invitation->location): ?>
                            <p><strong>Location:</strong> <?php echo esc_html($invitation->location); ?></p>
                        <?php endif; ?>
                        <p><strong>Status:</strong> <span class="rsvp-status status-<?php echo $invitation->status; ?>"><?php echo ucfirst($invitation->status); ?></span></p>
                        
                        <?php if ($invitation->status === 'pending'): ?>
                            <div class="rsvp-actions">
                                <button class="rsvp-btn" data-invitation-id="<?php echo $invitation->id; ?>" data-response="accepted">Accept</button>
                                <button class="rsvp-btn" data-invitation-id="<?php echo $invitation->id; ?>" data-response="declined">Decline</button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <?php if (empty($created_events) && empty($invitations)): ?>
                <p>No events found for this email address.</p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function show_email_entry_form() {
        ob_start();
        ?>
        <div id="email-entry-form">
            <h3>View My Events</h3>
            <p>Please enter your email address to view your events and invitations.</p>
            
            <form method="post" action="">
                <div class="form-group">
                    <label for="user_email">Email Address *</label>
                    <input type="email" id="user_email" name="user_email" required>
                </div>
                <button type="submit">View My Events</button>
            </form>
            
            <p><small>
                <?php if (!is_user_logged_in()): ?>
                    <a href="<?php echo wp_login_url(get_permalink()); ?>">Login</a> to automatically access your events.
                <?php endif; ?>
            </small></p>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function handle_create_event() {
        check_ajax_referer('party_minder_nonce', 'nonce');
        
        global $wpdb;
        
        $title = sanitize_text_field($_POST['title']);
        $description = sanitize_textarea_field($_POST['description']);
        $event_date = sanitize_text_field($_POST['event_date']);
        $location = sanitize_text_field($_POST['location']);
        $max_guests = intval($_POST['max_guests']);
        $creator_name = sanitize_text_field($_POST['creator_name']);
        $creator_email = sanitize_email($_POST['creator_email']);
        $guest_emails = sanitize_textarea_field($_POST['guest_emails']);
        
        // Validate required fields
        if (empty($title) || empty($event_date) || empty($creator_name) || empty($creator_email)) {
            wp_die('Missing required fields.');
        }
        
        // Insert event
        $events_table = $wpdb->prefix . 'events';
        $result = $wpdb->insert(
            $events_table,
            array(
                'title' => $title,
                'description' => $description,
                'event_date' => $event_date,
                'location' => $location,
                'max_guests' => $max_guests,
                'creator_name' => $creator_name,
                'creator_email' => $creator_email
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );
        
        if ($result === false) {
            wp_die('Error creating event.');
        }
        
        $event_id = $wpdb->insert_id;
        
        // Send invitations if guest emails provided
        if (!empty($guest_emails)) {
            $this->send_invitations($event_id, $guest_emails);
        }
        
        wp_send_json_success(array(
            'message' => 'Event created successfully!',
            'event_id' => $event_id
        ));
    }
    
    private function send_invitations($event_id, $guest_emails) {
        global $wpdb;
        
        $events_table = $wpdb->prefix . 'events';
        $invitations_table = $wpdb->prefix . 'event_invitations';
        
        // Get event details
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $events_table WHERE id = %d",
            $event_id
        ));
        
        if (!$event) {
            return;
        }
        
        // Parse email addresses
        $emails = array_map('trim', explode(',', $guest_emails));
        
        foreach ($emails as $email) {
            if (!is_email($email)) {
                continue;
            }
            
            // Generate invitation token
            $token = wp_generate_password(32, false);
            
            // Insert invitation
            $wpdb->insert(
                $invitations_table,
                array(
                    'event_id' => $event_id,
                    'guest_name' => '', // Will be filled when they RSVP
                    'guest_email' => $email,
                    'invitation_token' => $token
                ),
                array('%d', '%s', '%s', '%s')
            );
            
            // Send email
            $this->send_invitation_email($event, $email, $token);
        }
    }
    
    private function send_invitation_email($event, $guest_email, $token) {
        $subject = 'Invitation: ' . $event->title;
        
        $rsvp_url = home_url('/?event_rsvp=1&token=' . $token);
        
        $message = "
        Hi there!
        
        You're invited to: {$event->title}
        
        Date: " . date('F j, Y g:i A', strtotime($event->event_date)) . "
        Location: {$event->location}
        Host: {$event->creator_name}
        
        Description:
        {$event->description}
        
        Please RSVP by clicking the link below:
        Accept: {$rsvp_url}&response=accepted
        Decline: {$rsvp_url}&response=declined
        
        Looking forward to seeing you there!
        ";
        
        wp_mail($guest_email, $subject, $message);
    }
    
    public function handle_rsvp_link() {
        if (!isset($_GET['event_rsvp']) || !isset($_GET['token'])) {
            return;
        }
        
        global $wpdb;
        
        $token = sanitize_text_field($_GET['token']);
        $response = isset($_GET['response']) ? sanitize_text_field($_GET['response']) : '';
        
        $invitations_table = $wpdb->prefix . 'event_invitations';
        
        $invitation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $invitations_table WHERE invitation_token = %s",
            $token
        ));
        
        if (!$invitation) {
            wp_die('Invalid invitation token.');
        }
        
        if (in_array($response, array('accepted', 'declined'))) {
            $wpdb->update(
                $invitations_table,
                array(
                    'status' => $response,
                    'responded_at' => current_time('mysql')
                ),
                array('id' => $invitation->id),
                array('%s', '%s'),
                array('%d')
            );
            
            echo '<h2>RSVP Confirmed</h2>';
            echo '<p>Thank you for your response. You have ' . $response . ' the invitation.</p>';
            exit;
        }
        
        // Show RSVP form
        $this->show_rsvp_form($invitation);
    }
    
    private function show_rsvp_form($invitation) {
        global $wpdb;
        
        $events_table = $wpdb->prefix . 'events';
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $events_table WHERE id = %d",
            $invitation->event_id
        ));
        
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>RSVP - <?php echo esc_html($event->title); ?></title>
            <style>
                body { font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; }
                .event-details { background: #f9f9f9; padding: 20px; margin: 20px 0; border-radius: 5px; }
                .rsvp-form { background: white; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
                .form-group { margin: 15px 0; }
                label { display: block; margin-bottom: 5px; font-weight: bold; }
                input, select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 3px; }
                button { background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 3px; cursor: pointer; margin-right: 10px; }
                button.decline { background: #dc3232; }
                button:hover { opacity: 0.9; }
            </style>
        </head>
        <body>
            <h1>You're Invited!</h1>
            
            <div class="event-details">
                <h2><?php echo esc_html($event->title); ?></h2>
                <p><strong>Date:</strong> <?php echo date('F j, Y g:i A', strtotime($event->event_date)); ?></p>
                <p><strong>Location:</strong> <?php echo esc_html($event->location); ?></p>
                <p><strong>Host:</strong> <?php echo esc_html($event->creator_name); ?></p>
                <?php if ($event->description): ?>
                    <p><strong>Description:</strong><br><?php echo nl2br(esc_html($event->description)); ?></p>
                <?php endif; ?>
            </div>
            
            <div class="rsvp-form">
                <h3>Please RSVP</h3>
                <form method="post" action="">
                    <input type="hidden" name="token" value="<?php echo esc_attr($invitation->invitation_token); ?>">
                    
                    <div class="form-group">
                        <label for="guest_name">Your Name *</label>
                        <input type="text" id="guest_name" name="guest_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="response">Response *</label>
                        <select id="response" name="response" required>
                            <option value="">Please choose...</option>
                            <option value="accepted">Accept - I'll be there!</option>
                            <option value="declined">Decline - Sorry, can't make it</option>
                        </select>
                    </div>
                    
                    <button type="submit">Submit RSVP</button>
                </form>
            </div>
        </body>
        </html>
        <?php
        
        // Handle form submission
        if ($_POST) {
            $guest_name = sanitize_text_field($_POST['guest_name']);
            $response = sanitize_text_field($_POST['response']);
            
            if ($guest_name && in_array($response, array('accepted', 'declined'))) {
                $invitations_table = $wpdb->prefix . 'event_invitations';
                
                $wpdb->update(
                    $invitations_table,
                    array(
                        'guest_name' => $guest_name,
                        'status' => $response,
                        'responded_at' => current_time('mysql')
                    ),
                    array('id' => $invitation->id),
                    array('%s', '%s', '%s'),
                    array('%d')
                );
                
                echo '<script>alert("Thank you for your RSVP!"); window.close();</script>';
            }
        }
        
        exit;
    }
    
    public function handle_rsvp_response() {
        check_ajax_referer('party_minder_nonce', 'nonce');
        
        global $wpdb;
        
        $invitation_id = intval($_POST['invitation_id']);
        $response = sanitize_text_field($_POST['response']);
        
        if (!in_array($response, array('accepted', 'declined'))) {
            wp_die('Invalid response.');
        }
        
        $invitations_table = $wpdb->prefix . 'event_invitations';
        
        $result = $wpdb->update(
            $invitations_table,
            array(
                'status' => $response,
                'responded_at' => current_time('mysql')
            ),
            array('id' => $invitation_id),
            array('%s', '%s'),
            array('%d')
        );
        
        if ($result === false) {
            wp_die('Error updating RSVP.');
        }
        
        wp_send_json_success(array(
            'message' => 'RSVP updated successfully!'
        ));
    }
}

// Initialize the plugin
new PartyMinder();

// Create assets directory and files on activation
register_activation_hook(__FILE__, 'party_minder_create_assets');

function party_minder_create_assets() {
    $assets_dir = PARTY_MINDER_PLUGIN_DIR . 'assets';
    
    if (!file_exists($assets_dir)) {
        wp_mkdir_p($assets_dir);
    }
    
    // Create CSS file
    $css_content = '
/* Party-Minder Styles */
#party-minder-form,
#email-entry-form {
    max-width: 600px;
    margin: 20px 0;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
}

.form-group input,
.form-group textarea,
.form-group select {
    width: 100%;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.form-group textarea {
    resize: vertical;
}

#create-event-form button,
#email-entry-form button {
    background: #007cba;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 16px;
}

#create-event-form button:hover,
#email-entry-form button:hover {
    background: #005a87;
}

.event-item {
    border: 1px solid #ddd;
    padding: 20px;
    margin-bottom: 20px;
    border-radius: 5px;
    background: #f9f9f9;
}

.event-item h4 {
    margin: 0 0 10px 0;
    color: #333;
}

.event-actions {
    margin-top: 15px;
}

.request-invite-btn,
.rsvp-btn {
    background: #007cba;
    color: white;
    padding: 8px 16px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    margin-right: 10px;
}

.request-invite-btn:hover,
.rsvp-btn:hover {
    background: #005a87;
}

.rsvp-btn[data-response="declined"] {
    background: #dc3232;
}

.rsvp-btn[data-response="declined"]:hover {
    background: #a00;
}

#invitation-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
}

.modal-content {
    background: white;
    max-width: 500px;
    margin: 50px auto;
    padding: 20px;
    border-radius: 5px;
}

.my-event-item,
.invitation-item {
    border: 1px solid #ddd;
    padding: 15px;
    margin-bottom: 15px;
    border-radius: 4px;
}

.rsvp-summary {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #eee;
}

.rsvp-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 5px 0;
}

.rsvp-status {
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: bold;
}

.status-pending {
    background: #ffc107;
    color: #333;
}

.status-accepted {
    background: #28a745;
    color: white;
}

.status-declined {
    background: #dc3232;
    color: white;
}

#event-message {
    margin-top: 15px;
    padding: 10px;
    border-radius: 4px;
    display: none;
}

#event-message.success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

#event-message.error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}
';
    
    file_put_contents($assets_dir . '/party-minder.css', $css_content);
    
    // Create JavaScript file
    $js_content = '
jQuery(document).ready(function($) {
    // Handle event creation form
    $("#create-event-form").on("submit", function(e) {
        e.preventDefault();
        
        var formData = $(this).serialize();
        formData += "&action=create_event&nonce=" + party_minder_ajax.nonce;
        
        $.ajax({
            url: party_minder_ajax.ajax_url,
            type: "POST",
            data: formData,
            success: function(response) {
                if (response.success) {
                    $("#event-message").removeClass("error").addClass("success").text(response.data.message).show();
                    $("#create-event-form")[0].reset();
                } else {
                    $("#event-message").removeClass("success").addClass("error").text("Error creating event.").show();
                }
            },
            error: function() {
                $("#event-message").removeClass("success").addClass("error").text("Error creating event.").show();
            }
        });
    });
    
    // Handle invitation request
    $(".request-invite-btn").on("click", function() {
        var eventId = $(this).data("event-id");
        $("#request-event-id").val(eventId);
        $("#invitation-modal").show();
    });
    
    $("#close-modal").on("click", function() {
        $("#invitation-modal").hide();
    });
    
    // Handle RSVP responses
    $(".rsvp-btn").on("click", function() {
        var invitationId = $(this).data("invitation-id");
        var response = $(this).data("response");
        
        $.ajax({
            url: party_minder_ajax.ajax_url,
            type: "POST",
            data: {
                action: "rsvp_response",
                invitation_id: invitationId,
                response: response,
                nonce: party_minder_ajax.nonce
            },
            success: function(result) {
                if (result.success) {
                    location.reload();
                } else {
                    alert("Error updating RSVP.");
                }
            },
            error: function() {
                alert("Error updating RSVP.");
            }
        });
    });
    
    // Close modal when clicking outside
    $("#invitation-modal").on("click", function(e) {
        if (e.target === this) {
            $(this).hide();
        }
    });
});
';
    
    file_put_contents($assets_dir . '/party-minder.js', $js_content);
}
?>
