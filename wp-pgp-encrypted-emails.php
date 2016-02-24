<?php
/**
 * The WP PGP Encrypted Emails plugin for WordPress.
 *
 * WordPress plugin header information:
 *
 * * Plugin Name: WP PGP Encrypted Emails
 * * Plugin URI: https://github.com/meitar/wp-pgp-encrypted-emails
 * * Description: Encrypts all emails sent to a given user if that user adds a PGP public key to their profile. <strong>Like this plugin? Please <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_donations&amp;business=TJLPJYXHSRBEE&amp;lc=US&amp;item_name=WP%20PGP%20Encrypted%20Emails&amp;item_number=wp-pgp-encrypted-emails&amp;currency_code=USD&amp;bn=PP%2dDonationsBF%3abtn_donate_SM%2egif%3aNonHosted" title="Send a donation to the developer of WP PGP Encrypted Emails">donate</a>. &hearts; Thank you!</strong>
 * * Version: 0.3.0
 * * Author: Maymay <bitetheappleback@gmail.com>
 * * Author URI: https://maymay.net/
 * * License: GPL-3
 * * License URI: https://www.gnu.org/licenses/gpl-3.0.en.html
 * * Text Domain: wp-pgp-encrypted-emails
 * * Domain Path: /languages
 *
 * @link https://developer.wordpress.org/plugins/the-basics/header-requirements/
 *
 * @license https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * @copyright Copyright (c) 2016 by Meitar "maymay" Moscovitz
 *
 * @package WordPress\Plugin\WP_PGP_Encrypted_Emails
 */

if (!defined('ABSPATH')) { exit; } // Disallow direct HTTP access.

/**
 * Base class that WordPress uses to register and initialize plugin.
 */
class WP_PGP_Encrypted_Emails {

    /**
     * Meta key where PGP private/public keypair is stored.
     *
     * This is intended to be the PGP private key used by the plugin
     * for signing outgoing emails. It is *not* intended to store any
     * user's private key material nor is it intended to be used for
     * saving any key material for any other purpose other than this
     * plugin's own use. **Do not**, under any circumstances, copy a key
     * used in any other application to this field.
     *
     * @var string
     */
    private static $meta_keypair = 'pgp_keypair';

    /**
     * Meta key where PGP public key is stored.
     *
     * @var string
     */
    public static $meta_key = 'pgp_public_key';

    /**
     * Meta key where subject line toggle is stored.
     *
     * @var string
     */
    public static $meta_key_empty_subject_line = 'pgp_empty_subject_line';

    /**
     * Entry point for the WordPress framework into plugin code.
     *
     * This is the method called when WordPress loads the plugin file.
     * It is responsible for "registering" the plugin's main functions
     * with the {@see https://codex.wordpress.org/Plugin_API WordPress Plugin API}.
     *
     * @uses add_action()
     * @uses add_filter()
     * @uses remove_filter()
     * @uses register_activation_hook()
     * @uses register_deactivation_hook()
     *
     * @return void
     */
    public static function register () {
        add_action('plugins_loaded', array(__CLASS__, 'registerL10n'));
        add_action('init', array(__CLASS__, 'initialize'));

        if (is_admin()) {
            add_action('admin_init', array(__CLASS__, 'registerAdminSettings'));
            add_action('admin_notices', array(__CLASS__, 'adminNoticeBadUserKey'));
            add_action('admin_notices', array(__CLASS__, 'adminNoticeBadAdminKey'));
            add_action('show_user_profile', array(__CLASS__, 'renderProfile'));
            add_action('personal_options_update', array(__CLASS__, 'saveProfile'));
        } else {
            remove_filter('comment_text', 'wptexturize'); // we do wptexturize() ourselves
            add_filter('comment_text', array(__CLASS__, 'commentText'));
            add_filter('comment_form_submit_field', array(__CLASS__, 'renderCommentFormFields'));
            add_filter('comment_class', array(__CLASS__, 'commentClass'), 10, 4);
            add_filter('preprocess_comment', array(__CLASS__, 'preprocessComment'));
        }

        add_filter('wp_mail', array(__CLASS__, 'wp_mail'));

        register_activation_hook(__FILE__, array(__CLASS__, 'activate'));
    }

    /**
     * Loads localization files from plugin's languages directory.
     *
     * @uses load_plugin_textdomain()
     *
     * @return void
     */
    public static function registerL10n () {
        load_plugin_textdomain('wp-pgp-encrypted-emails', false, dirname(plugin_basename(__FILE__)).'/languages/');
    }

    /**
     * Loads plugin componentry. Called at the WordPress `init` hook.
     *
     * @return void
     */
    public static function initialize () {
        require_once plugin_dir_path(__FILE__).'class-wp-openpgp.php';
        WP_OpenPGP::register();
    }

    /**
     * Method to run when the plugin is activated by a user in the
     * WordPress Dashboard admin screen.
     *
     * @uses WP_PGP_Encrypted_Emails::checkPrereqs()
     *
     * @return void
     */
    public static function activate () {
        self::checkPrereqs();

        if (!get_option(self::$meta_keypair)) {
            require_once plugin_dir_path(__FILE__).'class-wp-openpgp.php';

            // Make up an email address for this website.
            // This is also what the WordPress core wp_mail() function does.
            // See: https://core.trac.wordpress.org/browser/tags/4.4.2/src/wp-includes/pluggable.php#L371
            $sitename = strtolower( $_SERVER['SERVER_NAME'] );
            if (substr($sitename, 0, 4) == 'www.') {
                $sitename = substr($sitename, 4);
            }
            $from_email = 'wordpress@'.$sitename;

            $keypair = WP_OpenPGP::generateKeypair("WordPress <$from_email>");
            $ascii_keypair = array();
            $ascii_keypair['privatekey'] = apply_filters('openpgp_enarmor', $keypair['privatekey'], 'PGP PRIVATE KEY BLOCK');
            $ascii_keypair['publickey']  = apply_filters('openpgp_enarmor', $keypair['publickey'], 'PGP PUBLIC KEY BLOCK');
            update_option(self::$meta_keypair, $ascii_keypair);
        }
    }

    /**
     * Checks system requirements and exits if they are not met.
     *
     * This first checks to ensure minimum WordPress and PHP versions
     * have been satisfied. If not, the plugin deactivates and exits.
     *
     * @global $wp_version
     *
     * @uses $wp_version
     * @uses WP_PGP_Encrypted_Emails::get_minimum_wordpress_version()
     * @uses deactivate_plugins()
     * @uses plugin_basename()
     *
     * @return void
     */
    public static function checkPrereqs () {
        global $wp_version;
        $min_wp_version = self::get_minimum_wordpress_version();
        if (version_compare($min_wp_version, $wp_version) > 0) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(sprintf(
                __('WP PGP Encrypted Emails requires at least WordPress version %1$s. You have WordPress version %2$s.', 'buoy'),
                $min_wp_version, $wp_version
            ));
        }
    }

    /**
     * Returns the "Requires at least" value from plugin's readme.txt.
     *
     * @link https://wordpress.org/plugins/about/readme.txt WordPress readme.txt standard
     *
     * @return string
     */
    public static function get_minimum_wordpress_version () {
        $lines = @file(plugin_dir_path(__FILE__).'readme.txt');
        foreach ($lines as $line) {
            preg_match('/^Requires at least: ([0-9.]+)$/', $line, $m);
            if ($m) {
                return $m[1];
            }
        }
    }

    /**
     * Gets a user's PGP public key.
     *
     * @param WP_User|int|string $user
     *
     * @return OpenPGP_Message|false
     */
    public static function getUserKey ($user = null) {
        $wp_user = false;
        $ascii_key = false;

        if ($user instanceof WP_User) {
            $wp_user = $user;
        } else if (get_user_by('email', $user)) {
            $wp_user = get_user_by('email', $user);
        } else if (get_userdata($user)) {
            $wp_user = get_userdata($user);
        } else {
            $wp_user = wp_get_current_user();
        }

        if ($wp_user) {
            $ascii_key = $wp_user->{self::$meta_key};
        }

        return apply_filters('openpgp_key', $ascii_key);
    }

    /**
     * Gets the admin's PGP public key.
     *
     * @return OpenPGP_Message|false
     */
    public static function getAdminKey () {
        return apply_filters('openpgp_key', get_option(self::$meta_key));
    }

    /**
     * Registers the plugin settings with WordPress.
     *
     * @link https://codex.wordpress.org/Settings_API
     *
     * @uses add_settings_field()
     * @uses register_setting()
     *
     * @return void
     */
    public static function registerAdminSettings () {
        // PGP Public Key
        add_settings_field(
            self::$meta_key,
            __('PGP Public Key', 'wp-pgp-encrypted-emails'),
            array(__CLASS__, 'renderAdminKeySetting'),
            'general',
            'default',
            array(
                'label_for' => self::$meta_key
            )
        );
        register_setting(
            'general',
            self::$meta_key,
            array(__CLASS__, 'sanitizeTextArea')
        );

        // PGP empty subject line?
        add_settings_field(
            self::$meta_key_empty_subject_line,
            __('Always empty subject lines for PGP-encrypted emails', 'wp-pgp-encrypted-emails'),
            array(__CLASS__, 'renderEmptySubjectLineSetting'),
            'general',
            'default',
            array(
                'label_for' => self::$meta_key_empty_subject_line
            )
        );
        register_setting(
            'general',
            self::$meta_key_empty_subject_line,
            array(__CLASS__, 'sanitizeCheckBox')
        );
    }

    /**
     * A helper function that sanitizes multi-line inputs.
     *
     * @param string $input
     *
     * @return string
     */
    public static function sanitizeTextArea ($input) {
        return implode("\n", array_map('sanitize_text_field', explode("\n", $input)));
    }

    /**
     * A helper function that sanitizes check boxes.
     *
     * @param mixed $input
     *
     * @return bool
     */
    public static function sanitizeCheckBox ($input) {
        return isset($input);
    }

    /**
     * Prints a warning to the user if their PGP public key can't be used.
     *
     * @uses WP_PGP_Encrypted_Emails::getUserKey()
     * @uses wp_get_current_user()
     * @uses admin_url()
     *
     * @return void
     */
    public static function adminNoticeBadUserKey () {
        $wp_user = wp_get_current_user();
        if (!empty($wp_user->{self::$meta_key}) && !self::getUserKey($wp_user)) {
?>
<div class="notice error is-dismissible">
    <p><strong><?php esc_html_e('There is a problem with your PGP public key.', 'wp-pgp-encrypted-emails');?></strong></p>
    <p class="description"><?php print sprintf(
        esc_html__('Your PGP public key is what WordPress uses to encrypt emails it sends to you so that only you can read them. Unfortunately, something is wrong or missing in %1$sthe public key saved in your profile%2$s.', 'wp-pgp-encrypted-emails'),
        '<a href="'.admin_url('profile.php#'.self::$meta_key).'">', '</a>'
    );?></p>
</div>
<?php
        }
    }

    /**
     * Prints a warning to the admin if their PGP public key can't be used.
     *
     * @uses get_option()
     * @uses WP_PGP_Encrypted_Emails::getAdminKey()
     * @uses admin_url()
     *
     * @return void
     */
    public static function adminNoticeBadAdminKey () {
        $options = get_option(self::$meta_key);
        if (current_user_can('manage_options')
            && !empty($options)
            && !self::getAdminKey())
        {
?>
<div class="notice error is-dismissible">
    <p><strong><?php esc_html_e('There is a problem with your admin email PGP public key.', 'wp-pgp-encrypted-emails');?></strong></p>
    <p class="description"><?php print sprintf(
        esc_html__('Your PGP public key is what WordPress uses to encrypt emails it sends to you so that only you can read them. Unfortunately, something is wrong or missing in %1$sthe admin email public key option%2$s.', 'wp-pgp-encrypted-emails'),
        '<a href="'.admin_url('options-general.php#'.self::$meta_key).'">', '</a>'
    );?></p>
</div>
<?php
        }
    }

    /**
     * Prints the HTML for the custom profile fields.
     *
     * @param WP_User $profileuser
     *
     * @return void
     */
    public static function renderProfile ($profileuser) {
        require_once 'pages/profile.php';
    }

    /**
     * Prints the HTML for the plugin's admin key setting.
     *
     * @return void
     */
    public static function renderAdminKeySetting () {
?>
<textarea
    id="<?php print esc_attr(self::$meta_key);?>"
    name="<?php print esc_attr(self::$meta_key);?>"
    style="width:100%;min-height:5em;"
><?php print esc_textarea(get_option(self::$meta_key));?></textarea>
<p class="description">
    <?php print sprintf(
        esc_html__('Paste the PGP public key for the admin email here to have WordPress encrypt admin emails it sends. Leave this blank if you do not want to get or know how to decrypt encrypted emails.', 'wp-pgp-encrypted-emails')
    );?>
</p>
<?php
    }

    /**
     * Prints the HTML for the plugin's admin subject line setting.
     *
     * @return void
     */
    public static function renderEmptySubjectLineSetting () {
?>
<input type="checkbox"
    id="<?php print esc_attr(self::$meta_key_empty_subject_line);?>"
    name="<?php print esc_attr(self::$meta_key_empty_subject_line);?>"
    <?php checked(get_option(self::$meta_key_empty_subject_line));?>
    value="1"
/>
<span class="description">
    <?php print sprintf(
        esc_html__('PGP encryption cannot encrypt envelope information (such as the subject) of an email, so if you want maximum privacy, make sure this option is enabled to always erase the subject line from encrypted emails you receive.', 'wp-pgp-encrypted-emails')
    );?>
</span>
<?php
    }

    /**
     * Adds a "Private" checkbox to the comment form.
     *
     * @link https://developer.wordpress.org/reference/hooks/comment_form_submit_field/
     *
     * @param string $submit_field
     *
     * @return string
     */
    public static function renderCommentFormFields ($submit_field) {
        $post = get_post();
        $html = '';
        if (self::getUserKey($post->post_author)) {
            $author = get_userdata($post->post_author);
            $html .= '<p class="comment-form-openpgp-encryption">';
            $html .= '<label for="openpgp-encryption">'.esc_html__('Private', 'wp-pgp-encrypted-emails').'</label>';
            $html .= '<input type="checkbox" id="openpgp-encryption" name="openpgp-encryption" value="1" />';
            $html .= ' <span class="description">'.sprintf(esc_html__('You can encrypt your comment so that only %s can read it.', 'wp-pgp-encrypted-emails'), $author->display_name).'</span>';
            $html .= '</p>';
        }
        return $html.$submit_field;
    }

    /**
     * Adds an "openpgp-encryption" class to encrypted comments.
     *
     * @link https://developer.wordpress.org/reference/hooks/comment_class/
     *
     * @param array       $classes    An array of comment classes.
     * @param string      $class      A comma-separated list of additional classes added to the list.
     * @param int         $comment_id The comment id.
     * @param WP_Comment  $comment    The comment object.
     *
     * @return array
     */
    public static function commentClass ($classes, $class, $comment_id, $comment) {
        if (self::isEncrypted($comment->comment_content)) {
            $classes[] = 'openpgp-encryption';
        }
        return $classes;
    }

    /**
     * Whether the given text is an encrypted PGP MESSAGE block.
     *
     * @param string $text
     *
     * @return bool
     */
    public static function isEncrypted ($text) {
        $lines = explode("\n", $text);
        $first_line = trim(array_shift($lines));
        return (0 === strpos($first_line, '-----BEGIN PGP MESSAGE')) ? true : false;
    }

    /**
     * Texturizes comment text if it is not encrypted.
     *
     * @param string $text
     *
     * @return string
     */
    public static function commentText ($text) {
        return (self::isEncrypted($text)) ? $text : wptexturize($text);
    }

    /**
     * Saves profile field values to the database on profile update.
     *
     * @global $_POST Used to access values submitted by profile form.
     *
     * @param int $user_id
     *
     * @uses WP_PGP_Encrypted_Emails::$meta_key
     * @uses WP_PGP_Encrypted_Emails::sanitizeTextArea()
     * @uses update_user_meta()
     *
     * @return void
     */
    public static function saveProfile ($user_id) {
        update_user_meta(
            $user_id,
            self::$meta_key,
            self::sanitizeTextArea($_POST[self::$meta_key])
        );
        update_user_meta(
            $user_id,
            self::$meta_key_empty_subject_line,
            isset($_POST[self::$meta_key_empty_subject_line])
        );
    }

    /**
     * Encrypts messages that WordPress sends when it sends email.
     *
     * @param array $args
     *
     * @return array
     */
    public static function wp_mail ($args) {
        $pub_key = false;
        $erase_subject = null;

        if (get_option('admin_email') === $args['to']) {
            $pub_key = self::getAdminKey();
            $erase_subject = get_option(self::$meta_key_empty_subject_line);
        } else if ($wp_user = get_user_by('email', $args['to'])) {
            $pub_key = self::getUserKey($wp_user);
            $erase_subject = $wp_user->{self::$meta_key_empty_subject_line};
        }

        if ($erase_subject) {
            $args['subject'] = '';
        }

        if ($pub_key instanceof OpenPGP_Message) {
            try {
                $args['message'] = apply_filters('openpgp_encrypt', $args['message'], $pub_key);
            } catch (Exception $e) {
                error_log(sprintf(
                    __('Cannot send encrypted email to %1$s', 'wp-pgp-encrypted-emails'),
                    $args['to']
                ));
            }
        }

        return $args;
    }

    /**
     * Encrypts comment content with the post author's public key.
     *
     * @link https://developer.wordpress.org/reference/hooks/preprocess_comment/
     *
     * @param array $comment_data
     *
     * @return array
     */
    public static function preprocessComment ($comment_data) {
        $post = get_post($comment_data['comment_post_ID']);
        $key = self::getUserKey($post->post_author);
        if (!empty($_POST['openpgp-encryption']) && !self::isEncrypted($comment_data['comment_content']) && $key) {
            $comment_data['comment_content'] = apply_filters('openpgp_encrypt', wp_unslash($comment_data['comment_content']), $key);
        }
        return $comment_data;
    }

}

WP_PGP_Encrypted_Emails::register();
