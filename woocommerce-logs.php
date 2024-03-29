<?php
/**
 * Plugin Name: View and delete logs  for WooCommerce
 * Plugin URI: https://michelmelo.pt
 * Description: This plugin deletes WooCommerce status log files automatically after a time period specified by the administrator.
 * Version: 2.3.0
 * Author URI: https://michelmelo.pt
 * Text Domain: woocommerce-logs
 * Author: michelmelo.
 */
defined('ABSPATH') || exit;

define('WOOCOMMERCE_LOGS_VERSION', '2.3.0');
define('WOOCOMMERCE_LOGS_PLUGIN_FILE', __FILE__);
define('WOOCOMMERCE_LOGS_PLUGIN_DIR', plugins_url('/', __FILE__));
define('WOOCOMMERCE_LOGS_TRANSLATE', plugins_url('/', __FILE__));
define('WOOCOMMERCE_LOGS_FILE', plugin_basename(__FILE__));

if (! class_exists('mmUpdateChecker')) {
    class mmUpdateChecker
    {
        public $plugin_slug;
        public $version;
        public $cache_key;
        public $cache_allowed;

        public function __construct()
        {
            $this->plugin_slug   = plugin_basename(__DIR__);
            $this->version       = WOOCOMMERCE_LOGS_VERSION;
            $this->cache_key     = 'custom_upd_7';
            $this->cache_allowed = false;

            // die($this->plugin_slug);

            add_filter('plugins_api', [$this, 'info'], 20, 3);
            add_filter('site_transient_update_plugins', [$this, 'update']);
            add_action('upgrader_process_complete', [$this, 'purge'], 10, 2);
        }

        public function request()
        {
            $remote = get_transient($this->cache_key);

            if (false === $remote || ! $this->cache_allowed) {
                $remote = wp_remote_get(
                    'https://raw.githubusercontent.com/michelmelo/woocommerce-logs/main/info.json',
                    [
                        'timeout' => 10,
                        'headers' => [
                            'Accept' => 'application/json',
                        ],
                    ]
                );

                if (
                    is_wp_error($remote)
                    || 200 !== wp_remote_retrieve_response_code($remote)
                    || empty(wp_remote_retrieve_body($remote))
                ) {
                    return false;
                }

                set_transient($this->cache_key, $remote, DAY_IN_SECONDS);
            }

            $remote = json_decode(wp_remote_retrieve_body($remote));

            return $remote;
        }

        public function info($res, $action, $args)
        {
            if ('plugin_information' !== $action) {
                return $res;
            }

            if ($this->plugin_slug !== $args->slug) {
                return $res;
            }

            $remote = $this->request();

            if (! $remote) {
                return $res;
            }

            $res = new stdClass();

            $res->name           = $remote->name;
            $res->slug           = $remote->slug;
            $res->version        = $remote->version;
            $res->tested         = $remote->tested;
            $res->requires       = $remote->requires;
            $res->author         = $remote->author;
            $res->author_profile = $remote->author_profile;
            $res->download_link  = $remote->download_url;
            $res->trunk          = $remote->download_url;
            $res->requires_php   = $remote->requires_php;
            $res->last_updated   = $remote->last_updated;

            $res->sections = [
                'description'  => $remote->sections->description,
                'installation' => $remote->sections->installation,
                'changelog'    => $remote->sections->changelog,
            ];

            if (! empty($remote->banners)) {
                $res->banners = [
                    'low'  => $remote->banners->low,
                    'high' => $remote->banners->high,
                ];
            }

            return $res;
        }

        public function update($transient)
        {
            if (empty($transient->checked)) {
                return $transient;
            }

            $remote = $this->request();

            if (
                $remote
                && version_compare($this->version, $remote->version, '<')
                && version_compare($remote->requires, get_bloginfo('version'), '<=')
                && version_compare($remote->requires_php, PHP_VERSION, '<')
            ) {
                $res              = new stdClass();
                $res->slug        = $this->plugin_slug;
                $res->plugin      = plugin_basename(__FILE__);
                $res->new_version = $remote->version;
                $res->tested      = $remote->tested;
                $res->package     = $remote->download_url;

                $transient->response[$res->plugin] = $res;
            }

            return $transient;
        }

        public function purge($upgrader, $options)
        {
            if (
                $this->cache_allowed
                && 'update' === $options['action']
                && 'plugin' === $options['type']
            ) {
                // just clean the cache when new plugin version is installed
                delete_transient($this->cache_key);
            }
        }
    }

    new mmUpdateChecker();
}

//$plugin = plugin_basename(__FILE__);

add_action('admin_bar_menu', 'woocommerce_logs_register_in_wp_admin_bar', 50);
function woocommerce_logs_register_in_wp_admin_bar($wp_admin_bar)
{
    load_plugin_textdomain('woocommerce-logs', false, dirname(plugin_basename(__FILE__)) . '/');

    if (have_current_user_access_to_pexlechris_adminer()) {
        $args = [
            'id'    => 'autoDelete',
            'title' => __('Auto Delete', 'woocommerce-logs'),
            'href'  => esc_url(site_url() . '/wp-admin/options-general.php?page=woocommerce-logs-setting'),
        ];
        $args2 = [
            'id'    => 'MM Logs',
            'title' => esc_html__('MM logs', 'woocommerce-logs'),
            'href'  => esc_url(site_url() . '/wp-admin/admin.php?page=wc-status&tab=logs'),
        ];
        $wp_admin_bar->add_node($args);
        $wp_admin_bar->add_node($args2);
    }
}

// can be overridden in a must-use plugin
if (! function_exists('have_current_user_access_to_pexlechris_adminer')) {
    function have_current_user_access_to_pexlechris_adminer()
    {
        foreach (pexlechris_adminer_access_capabilities() as $capability) {
            if (current_user_can($capability)) {
                return true;
            }
        }

        return false;
    }
}

function woocommerce_logs_statuslogs_activation_logic()
{
    if (! is_plugin_active('woocommerce/woocommerce.php')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('Auto Delete status Logs for WooCommerce requires WooCommerce Plugin in order for it to work properly!', 'woocommerce-logs'));
    }
}
register_activation_hook(__FILE__, 'woocommerce_logs_statuslogs_activation_logic');

add_filter('cron_schedules', 'woocommerce_logs_statuslogs_add_every_twentyfour_hours');
function woocommerce_logs_statuslogs_add_every_twentyfour_hours($schedules)
{
    $schedules['every_twentyfour_hours'] = [
        'interval' => 86400,
        'display'  => __('Every Day', 'woocommerce-logs'),
    ];

    return $schedules;
}

// Schedule an action if it's not already scheduled
if (! wp_next_scheduled('woocommerce_logs_statuslogs_add_every_twentyfour_hours')) {
    wp_schedule_event(time(), 'every_twentyfour_hours', 'woocommerce_logs_statuslogs_add_every_twentyfour_hours');
}

if (! function_exists('woocommerce_logs_statuslogs_remove_files_from_dir_older_than_seven_days')) {
    function woocommerce_logs_statuslogs_remove_files_from_dir_older_than_seven_days($dir, $seconds = 3600)
    {
        $files = glob(rtrim($dir, '/') . '/*.log');
        $now   = time();
        foreach ($files as $file) {
            if (is_file($file)) {
                if ($now - filemtime($file) >= $seconds) {
                    unlink($file);
                }
            } else {
                woocommerce_logs_statuslogs_remove_files_from_dir_older_than_seven_days($file, $seconds);
            }
        }
    }
}

function woocommerce_logs_statuslogs_settings_link($links)
{
    $settings_link = '<a href="options-general.php?page=woocommerce-logs-setting">' . __('Settings', 'woocommerce-logs') . '</a>';
    array_unshift($links, $settings_link);

    return $links;
}

add_filter('plugin_action_links_' . WOOCOMMERCE_LOGS_FILE, 'woocommerce_logs_statuslogs_settings_link');

add_action('woocommerce_logs_statuslogs_add_every_twentyfour_hours', 'woocommerce_logs_statuslogs_every_twentyfour_hours_event_func');
function woocommerce_logs_statuslogs_every_twentyfour_hours_event_func()
{
    $uploads     = wp_upload_dir();
    $upload_path = $uploads['basedir'];
    $dir         = $upload_path . '/wc-logs/';

    $woocommerce_logs_intervaldays = (int) get_option('woocommerce_logs_intervaldays');
    $week_days                     = 1;

    if (! empty($woocommerce_logs_intervaldays)) {
        $total_days = $woocommerce_logs_intervaldays;
    } else {
        $total_days = $week_days;
    }
    woocommerce_logs_statuslogs_remove_files_from_dir_older_than_seven_days($dir, (60 * 60 * 24 * $total_days)); // 1 day
}

function woocommerce_logs_statuslogs_atonce()
{
    $log_dir = WC_LOG_DIR;

    foreach (scandir($log_dir) as $file) {
        $path = pathinfo($file);

        // Only delete log files, don't delete the test.log file
        if ($path['extension'] === 'log' && $path['filename'] !== 'test-log') {
            unlink("{$log_dir}/{$file}");
        }
    }
}

function woocommerce_logs_statuslogs_register_options_page()
{
    add_options_page('WooCommerce Logs', 'WooCommerce Logs', 'manage_options', 'woocommerce-logs-setting', 'woocommerce_logs_statuslogs_options_page');
}
add_action('admin_menu', 'woocommerce_logs_statuslogs_register_options_page');

function woocommerce_logs_statuslogs_register_settings()
{
    register_setting('woocommerce_logs_statuslogs_options_group', 'woocommerce_logs_intervaldays');
}
add_action('admin_init', 'woocommerce_logs_statuslogs_register_settings');

function woocommerce_logs_statuslogs_options_page()
{
    echo '<div class="woocommerce-logs-autoexpired-main">
            <div class="woocommerce-logs-autoexpired"><form class="woocommerce-logs-clearlog-form" method="post" action="options.php">
            <h1>' . __('WooCommerce Logs for WooCommerce', 'woocommerce-logs') . '</h1>';
    settings_fields('woocommerce_logs_statuslogs_options_group');
    do_settings_sections('woocommerce_logs_statuslogs_options_group');

    echo ' <div class="woocommerce-logs-form-field" style="margin-top:50px">
                <label for="woocommerce_logs_set_interval">' . __('Schedule days to auto delete status logs', 'woocommerce-logs') . '</label>';
    echo "<input class='woocommerce-logs-input-field' type='text' id='woocommerce_logs_set_interval' name='woocommerce_logs_intervaldays' 
			   value='" . get_option('woocommerce_logs_intervaldays') . "' /> 
            </div>";
    submit_button();
    echo '</form> </div></div>';
    echo '	<div class="woocommerce_log-divider" style="margin:10px;font-weight:bold;"> ' . __('OR', 'woocommerce-logs') . ' </div>
		 <h1 class="woocommerce_log-title">' . __('Clear log files right now!', 'woocommerce-logs') . '</h1>  
		<div class="woocommerce-logs-form-field" style="margin-top:50px">';
    echo "<form action=' " . woocommerce_logs_statuslogs_atonce() . "' method='post'>";
    echo '<input type="hidden" name="action" value="my_action">';
    echo '<input type="submit" class="woocommerce_log-clearbtn" value="' . __('Clear All', 'woocommerce-logs') . '" onclick="woocommerce_log_showMessage()">';
    echo '</form>
		 </div>'; ?>
       
<script type="text/javascript">
function woocommerce_log_showMessage() { alert("<?php echo __('Status Logs Cleared', 'woocommerce-logs')?>");}</script>
    <?php
}
