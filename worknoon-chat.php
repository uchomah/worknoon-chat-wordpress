<?php
/**
 * Plugin Name: Worknoon Chat
 * Plugin URI: https://worknoon.com
 * Description: Real-time chat integration for eCommerce platforms
 * Version: 1.0.0
 * Author: Worknoon
 * License: GPL v2 or later
 * Text Domain: worknoon-chat
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WORKNOON_CHAT_VERSION', '1.0.0');
define('WORKNOON_CHAT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WORKNOON_CHAT_PLUGIN_URL', plugin_dir_url(__FILE__));

// Main plugin class
class WorknoonChat {
    
    private static $instance = null;
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_shortcode('worknoon_chat', array($this, 'chat_shortcode'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('wp_ajax_worknoon_send_message', array($this, 'handle_ajax_send'));
        add_action('wp_ajax_nopriv_worknoon_send_message', array($this, 'handle_ajax_send'));
    }
    
    public function init() {
        // Register Custom Post Type for chat sessions
        register_post_type('chat_session', array(
            'labels' => array(
                'name' => 'Chat Sessions',
                'singular_name' => 'Chat Session'
            ),
            'public' => true,
            'has_archive' => false,
            'supports' => array('title', 'custom-fields'),
            'show_in_rest' => true
        ));
    }
    
    public function enqueue_scripts() {
        // Only load on pages with chat shortcode
        global $post;
        if (has_shortcode($post->post_content, 'worknoon_chat')) {
            wp_enqueue_script('worknoon-chat-js', WORKNOON_CHAT_PLUGIN_URL . 'js/chat.js', array('jquery'), WORKNOON_CHAT_VERSION, true);
            wp_enqueue_style('worknoon-chat-css', WORKNOON_CHAT_PLUGIN_URL . 'css/chat.css', array(), WORKNOON_CHAT_VERSION);
            
            wp_localize_script('worknoon-chat-js', 'worknoon_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('worknoon_chat_nonce'),
                'api_url' => 'http://localhost:5000'
            ));
        }
    }
    
    public function chat_shortcode($atts) {
        $atts = shortcode_atts(array(
            'title' => 'Customer Support',
            'color' => '#007bff'
        ), $atts);
        
        ob_start();
        ?>
        <div class="worknoon-chat-widget" data-color="<?php echo esc_attr($atts['color']); ?>">
            <div class="chat-header" style="background-color: <?php echo esc_attr($atts['color']); ?>">
                <h3><?php echo esc_html($atts['title']); ?></h3>
                <button class="chat-toggle">💬</button>
            </div>
            <div class="chat-body" style="display: none;">
                <div class="chat-messages" id="chat-messages">
                    <div class="message system">Welcome! How can we help you?</div>
                </div>
                <div class="chat-input">
                    <input type="text" id="chat-input" placeholder="Type your message..." />
                    <button id="chat-send">Send</button>
                </div>
                <div class="chat-status" id="chat-status">Connecting...</div>
            </div>
        </div>
        <style>
            .worknoon-chat-widget {
                position: fixed;
                bottom: 20px;
                right: 20px;
                width: 350px;
                z-index: 9999;
                box-shadow: 0 5px 20px rgba(0,0,0,0.2);
                border-radius: 10px;
                overflow: hidden;
            }
            .chat-header {
                color: white;
                padding: 15px;
                cursor: pointer;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .chat-header h3 {
                margin: 0;
                font-size: 16px;
            }
            .chat-toggle {
                background: none;
                border: none;
                color: white;
                font-size: 20px;
                cursor: pointer;
            }
            .chat-body {
                background: white;
                height: 400px;
                display: flex;
                flex-direction: column;
            }
            .chat-messages {
                flex: 1;
                overflow-y: auto;
                padding: 10px;
            }
            .message {
                margin-bottom: 10px;
                padding: 8px 12px;
                border-radius: 10px;
                max-width: 80%;
            }
            .message.user {
                background: #007bff;
                color: white;
                margin-left: auto;
            }
            .message.support {
                background: #e9ecef;
                color: black;
            }
            .message.system {
                background: #f8f9fa;
                color: #6c757d;
                text-align: center;
                font-size: 12px;
            }
            .chat-input {
                display: flex;
                padding: 10px;
                border-top: 1px solid #ddd;
            }
            .chat-input input {
                flex: 1;
                padding: 8px;
                border: 1px solid #ddd;
                border-radius: 5px;
                margin-right: 10px;
            }
            .chat-input button {
                padding: 8px 15px;
                background: #007bff;
                color: white;
                border: none;
                border-radius: 5px;
                cursor: pointer;
            }
            .chat-status {
                padding: 5px;
                text-align: center;
                font-size: 10px;
                color: #999;
                border-top: 1px solid #eee;
            }
        </style>
        <?php
        return ob_get_clean();
    }
    
    public function register_rest_routes() {
        register_rest_route('worknoon/v1', '/messages', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_send_message'),
            'permission_callback' => '__return_true'
        ));
        
        register_rest_route('worknoon/v1', '/messages', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_messages'),
            'permission_callback' => '__return_true'
        ));
    }
    
    public function rest_send_message($request) {
        $data = $request->get_json_params();
        $message = sanitize_text_field($data['message']);
        $session_id = sanitize_text_field($data['session_id']);
        
        // Save to WordPress options (temporary storage)
        $messages = get_option('worknoon_messages_' . $session_id, array());
        $messages[] = array(
            'text' => $message,
            'sender' => 'user',
            'time' => current_time('mysql')
        );
        update_option('worknoon_messages_' . $session_id, $messages);
        
        // Forward to your Node.js backend
        $response = wp_remote_post('http://localhost:5000/api/messages', array(
            'body' => json_encode(array('text' => $message)),
            'headers' => array('Content-Type' => 'application/json')
        ));
        
        return rest_ensure_response(array('success' => true, 'message' => $message));
    }
    
    public function rest_get_messages($request) {
        $session_id = $request->get_param('session_id');
        $messages = get_option('worknoon_messages_' . $session_id, array());
        return rest_ensure_response($messages);
    }
    
    public function handle_ajax_send() {
        check_ajax_referer('worknoon_chat_nonce', 'nonce');
        
        $message = sanitize_text_field($_POST['message']);
        $session_id = sanitize_text_field($_POST['session_id']);
        
        $messages = get_option('worknoon_messages_' . $session_id, array());
        $messages[] = array(
            'text' => $message,
            'sender' => 'user',
            'time' => current_time('mysql')
        );
        update_option('worknoon_messages_' . $session_id, $messages);
        
        wp_send_json_success(array('message' => $message));
    }
}

// Initialize plugin
WorknoonChat::getInstance();
?>