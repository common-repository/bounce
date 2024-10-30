<?php

/*
  Plugin Name: Bounce
  Plugin URI: http://www.satollo.net/plugins/bounce
  Description: Bounce checking and wrong emails removal
  Version: 1.1.4
  Author: Stefano Lissa
  Author URI: http://www.satollo.net
  Disclaimer: Use at your own risk. No warranty expressed or implied is provided.
 */

define('BOUNCE_VERSION', '1.1.4');
define('BOUNCE_DIR', WP_PLUGIN_DIR . '/bounce');
define('BOUNCE_URL', WP_PLUGIN_URL . '/bounce');

$bounce = new Bounce();

class Bounce {

    const HOOK_RUN = 'bounce_run';
    const TRANSIENT = 'bounce';
    const OPTIONS = 'bounce';
    const SECRET = 'bounce_secret';
    const VERSION = 'bounce_version';

    var $time_limit;
    var $options = null;

    function __construct() {
        global $wpdb;

        $this->options = self::get_options();

        $max_time = (int) (@ini_get('max_execution_time') * 0.9);
        if ($max_time == 0) {
            $max_time = 600;
        }
        $this->time_limit = time() + $max_time;

        register_activation_hook(__FILE__, array(&$this, 'hook_activate'));
        register_deactivation_hook(__FILE__, array(&$this, 'hook_deactivate'));

        add_action('init', array(&$this, 'hook_init'));
        add_action('admin_init', array(&$this, 'hook_admin_init'));

        //add_action('shutdown', array(&$this, 'hook_shutdown'));

        add_action(self::HOOK_RUN, array(&$this, 'hook_run'));

        if (is_admin()) {
            add_action('admin_menu', array(&$this, 'hook_admin_menu'), 30);
            add_action('admin_head', array(&$this, 'hook_admin_head'));
        }

        add_action('phpmailer_init', array(&$this, 'hook_phpmailer_init'), 1000);
    }

    function hook_activate() {
        global $wpdb;

        $this->backup_options();
        delete_option(self::SECRET);
        delete_transient(self::TRANSIENT);
        wp_clear_scheduled_hook(self::HOOK_RUN);
        wp_schedule_event(time() + 60, 'hourly', self::HOOK_RUN);
        update_option(self::VERSION, BOUNCE_VERSION);



        // SQL to create the table
        $sql = 'create table if not exists ' . $wpdb->prefix . 'bounce_emails (
        `id` int unsigned not null AUTO_INCREMENT,
        `email` varchar (100) not null default \'\',
        primary key (`id`),
        unique key `email` (email)
        )';

        @$wpdb->query($sql);
    }

    function hook_deactivate() {
        wp_clear_scheduled_hook(self::HOOK_RUN);
        delete_transient(self::TRANSIENT);
    }

    function hook_init() {
        // Unsafe upgrade, cause people just override the files without deactivate.
        if (get_option(self::VERSION) != BOUNCE_VERSION) {
            error_log('Bounce: version change with no deactivation from ' . get_option(self::VERSION) . ' to ' . BOUNCE_VERSION);
            $this->hook_activate();
        }
    }

    function hook_shutdown() {

    }

    function hook_phpmailer_init($phpmailer) {
        if (!empty($this->options['return_path'])) {
            $phpmailer->Sender = $this->options['return_path'];
        }
    }

    function clean_email($email) {
        $email = preg_replace('/[^a-zA-Z0-9@+.\\-_]/', '', $email);
        $email = trim($email, "\n\r\t .:;\0\x0B");
        if (!is_email($email))
            return false;
        return $email;
    }

    function check_line(&$line) {

        $x = stripos($line, 'x-failed-recipients:');
        if ($x === 0) {
            $email = substr($line, 20);
            $email = $this->clean_email($email);
            return $email;
        }

        $x = stripos($line, 'original-recipient: rfc822;');
        if ($x === 0) {
            $email = substr($line, 27);
            $email = $this->clean_email($email);
            return $email;
        }

        $x = stripos($line, 'final-recipient: rfc822;');
        if ($x === 0) {
            $email = substr($line, 24);
            $email = $this->clean_email($email);
            return $email;
        }

        $x = stripos($line, 'rcpt to:');
        if ($x !== false) {
            $email = substr($line, $x + 8);
            $email = $this->clean_email($email);
            return $email;
        }

        $line = strtolower(trim($line));
        if (strpos($line, 'after rcpt to:') !== false) {
            $x = strpos($line, ':');
            $email = substr($line, $x + 1);
            $email = $this->clean_email($email);
            return $email;
        }

        $x = stripos($line, 'to:');

        if ($x !== false) {
            $email = substr($line, $x + 3);
            $email = $this->clean_email($email);
            return $email;
        }

        return false;
    }

    function hook_run($force = false) {
        global $wpdb;

        //error_log('Bounce: run');

        if (!$force && !$this->check_transient()) {
            return;
        }


        $options = self::get_options();
        if ($options['enabled'] != 1) {
            return;
        }

        require_once(ABSPATH . WPINC . '/class-pop3.php');
        $pop3 = new POP3();

        if (!$pop3->connect($options['host'], $options['port']) || !$pop3->user($options['login'])) {
            error_log('Bounce: ' . $pop3->ERROR);
            return;
        }

        $count = $pop3->pass($options['password']);

        if (false === $count) {
            //error_log('Bounce: ' . $pop3->ERROR);
            return;
        }

        //error_log('Bounce: messages found ' . $count);

        for ($i = 1; $i <= $count; $i++) {

            $message = $pop3->get($i);

            $bodysignal = false;
            foreach ($message as $line) {

                if (strlen($line) < 3) {
                    $bodysignal = true;
                }

                if (!$bodysignal) {
                    continue;
                }

                $email = $this->check_line($line);
                if (!$email) {
                    continue;
                }


                $this->bounce($email);

                break;
            }

            if (!$pop3->delete($i)) {
                error_log('Bounce: ' . $pop3->ERROR);
                //$pop3->reset();
                $pop3->quit();
                return;
            }

            if ($this->limits_exceeded()) {
                break;
            }
        }

        $pop3->quit();
        //error_log('Bounce: end');
    }

    function bounce($email) {
        global $wpdb;

        $email = strtolower(trim($email));

        $options = self::get_options();

        @$wpdb->query($wpdb->prepare("insert ignore into " . $wpdb->prefix . "bounce_emails (email) values (%s)", $email));

        do_action('bounce_email', $email);


        // Newsletter
        if (class_exists('Newsletter')) {
            $res = @$wpdb->query($wpdb->prepare("update " . $wpdb->prefix . "newsletter set status='B' where lower(email)=%s", $email));
            //error_log('Bounce: Newsletter clean up: ' . $res);
        }

        // Comment Plus
        if (class_exists('CommentPlus')) {
            $res = @$wpdb->query($wpdb->prepare("delete from " . $wpdb->prefix . "comment_plus where lower(email)=%s", $email));
            //error_log('Bounce: Comment Plus clean up:' . $res);
        }

        // Comment Notifier
        if (function_exists('cmnt_init')) {
            $res = @$wpdb->query($wpdb->prepare("delete from " . $wpdb->prefix . "comment_notifier where lower(email)=%s", $email));
            //error_log('Bounce: comment notifier clean up:' . $res);
        }

        // Users
        if ($options['clean_users'] != 0) {

            if ($options['clean_users'] == 1) {
                $email2 = str_replace('@', '#', $email);
            } else {
                $email2 = '';
            }

            $res = @$wpdb->query($wpdb->prepare("update " . $wpdb->prefix . "users set user_email=%s where lower(user_email)=%s", $email2, $email));
        }

        if ($options['clean_comments'] != 0) {
            if ($options['clean_comments'] == 1) {
                $email2 = str_replace('@', '#', $email);
            } else {
                $email2 = '';
            }

            // Comments
            $res = @$wpdb->query($wpdb->prepare("update " . $wpdb->prefix . "comments set comment_author_email=%s where lower(comment_author_email)=%s", $email2, $email));
        }
    }

    function limits_exceeded() {
        if (time() > $this->time_limit) {
            error_log('Bounce: time limit exceeded, suspended');
            return true;
        }
        return false;
    }

    function hook_admin_init() {
        if (isset($_GET['page']) && strpos($_GET['page'], 'bounce/') === 0) {
            wp_enqueue_script('jquery-ui-tabs');
        }
    }

    function hook_admin_head() {
        if (isset($_GET['page']) && strpos($_GET['page'], 'bounce/') === 0) {
            echo '<link type="text/css" rel="stylesheet" href="' . BOUNCE_URL . '/admin.css?' . BOUNCE_VERSION . '"/>';
        }
    }

    function hook_admin_menu() {
        add_options_page('Bounce', 'Bounce', 'manage_options', 'bounce/bounce.php');
    }

    // TODO: no static and cache
    static function update_options($options) {
        add_option(self::OPTIONS, '', null, 'no');
        update_option(self::OPTIONS, $options);
    }

    // TODO: no static and internal cache
    static function get_options() {
        // TODO: merge default options and cache
        $options = get_option(self::OPTIONS, array());
        return $options;
    }

    function backup_options() {
        $options = self::get_options();
        add_option(self::OPTIONS . '_backup', '', null, 'no');
        update_option(self::OPTIONS . '_backup', $options);
    }

    function check_transient() {
        usleep(rand(0, 1000000));
        if (get_transient(self::TRANSIENT) !== false) {
            error_log('Bounce: called too early');
            return false;
        }
        set_transient(self::TRANSIENT, 1, 300);
        return true;
    }

}
