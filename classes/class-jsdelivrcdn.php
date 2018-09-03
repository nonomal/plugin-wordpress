<?php
/**
 * Class JsDelivrCdn
 */
class JsDelivrCdn
{
	const SOURCE_LIST = 'source_list';

	const ADVANCED_MODE = 'advanced_mode';

	const AUTOENABLE = 'autoenable';

	const PLUGIN_SETTINGS = 'jsdelivrcdn_settings';

	const JSDELIVR_SOURCE_URL = 'jsdelivr_url';

	const ORIGINAL_SOURCE_URL = 'original_url';

	const SOURCE_LAST_LOADED = 'last_loaded_datetime';

	const JSDELIVR_ANALYZE_CRON_HOOK = 'jsdelivr_analyze_cron_hook';

	const JSDELIVR_REMOVE_OLD_CRON_HOOK = 'jsdelivr_remove_old_cron_hook';

	const JSDELIVR_CDN_URL = 'https://cdn.jsdelivr.net/';

	/**
	 * Set true when plugin initialized
	 *
	 * @var bool
	 */
	private static $initiated = false;
	/**
	 * Hash lookup URL
	 *
	 * @var string
	 */
	private static $jsdelivr_hash_lookup_url = 'https://data.jsdelivr.com/v1/lookup/hash/';
	/**
	 * Options
	 *
	 * @var array
	 */
	private static $options;

	/**
	 * Init function
	 */
	public static function init() {
		self::$options = get_option( self::PLUGIN_SETTINGS );
		if ( ! self::$initiated ) {
			self::init_hooks();
		}

		if ( ! wp_next_scheduled( self::JSDELIVR_ANALYZE_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'five_minutes', self::JSDELIVR_ANALYZE_CRON_HOOK );
		}
	}

	/**
	 * Init hooks
	 */
	private static function init_hooks() {
		self::$initiated = true;

		add_action( self::JSDELIVR_ANALYZE_CRON_HOOK, [ 'JsDelivrCdn', 'analyze' ] );
		add_action( self::JSDELIVR_REMOVE_OLD_CRON_HOOK, [ 'JsDelivrCdn', 'clear_old_sources' ] );

		if ( is_admin() ) {
			add_action( 'admin_menu', [ 'JsDelivrCdn', 'add_admin_pages' ] );
			add_action( 'admin_init', [ 'JsDelivrCdn', 'admin_init' ] );
			add_filter( 'plugin_action_links_' . JSDELIVRCDN_PLUGIN_NAME, [ 'JsDelivrCdn', 'settings_link' ] );
			add_action( 'admin_enqueue_scripts', [ 'JsDelivrCdn', 'admin_enqueue_scripts' ] );

			add_action( 'wp_ajax_clear_source_list', [ 'JsDelivrCdn', 'clear_source_list' ] );
			add_action( 'wp_ajax_clear_source', [ 'JsDelivrCdn', 'clear_source' ] );
			add_action( 'wp_ajax_get_source_list', [ 'JsDelivrCdn', 'get_source_list' ] );
			add_action( 'wp_ajax_jsdelivr_analyze', [ 'JsDelivrCdn', 'jsdelivr_analyze' ] );
			add_action( 'wp_ajax_delete_source_list', [ 'JsDelivrCdn', 'delete_source_list' ] );
			add_action( 'wp_ajax_advanced_mode_switch', [ 'JsDelivrCdn', 'advanced_mode_switch' ] );
		} else {
			add_action( 'wp_print_scripts', [ 'JsDelivrCdn', 'custom_enqueue_scripts' ], 999 );
			add_action( 'wp_print_styles', [ 'JsDelivrCdn', 'custom_enqueue_styles' ], 999 );
		}
	}

	/**
	 * Plugin activation hook
	 */
	public static function activate() {
		self::$options = get_option( self::PLUGIN_SETTINGS );
		if ( ! self::$options ) {
			self::$options = [
				self::SOURCE_LIST   => [],
				self::ADVANCED_MODE => false,
				self::AUTOENABLE    => true,
			];
			add_option( self::PLUGIN_SETTINGS, self::$options );
		}

		if ( ! wp_next_scheduled( self::JSDELIVR_ANALYZE_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'fifteen_minutes', self::JSDELIVR_ANALYZE_CRON_HOOK );
		}

		if ( ! wp_next_scheduled( self::JSDELIVR_REMOVE_OLD_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::JSDELIVR_REMOVE_OLD_CRON_HOOK );
		}

        //flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation hook
     */
    public static function deactivate() {
        wp_clear_scheduled_hook(self::JSDELIVR_ANALYZE_CRON_HOOK );
        wp_clear_scheduled_hook(self::JSDELIVR_REMOVE_OLD_CRON_HOOK );

        //flush rewrite rules
        flush_rewrite_rules();
    }
    /**
     * Enqueue admin page styles and scripts
     * @param $hook
     */
    public static function admin_enqueue_scripts($hook) {
        if('toplevel_page_jsdelivrcdn' === $hook) {
            wp_register_script( 'jsdelivrcdn-script', JSDELIVRCDN_PLUGIN_URL.'assets/js/script.js', array( 'jquery' ), '1.0', true);
            wp_enqueue_script('jsdelivrcdn-script');

            wp_register_style('jsdelivrcdn-style',JSDELIVRCDN_PLUGIN_URL.'assets/css/style.css');
            wp_enqueue_style('jsdelivrcdn-style');
        }
    }

    /**
     * Add link to admin page
     * @param $links
     * @return mixed
     */
    public static function settings_link($links) {
        array_push($links, '<a href="admin.php?page=jsdelivrcdn">Settings</a>');
        return $links;
    }



    /**
     * get jsdelivr url for script
     * @param $source
     * @return bool|string
     */
    public static function get_jsdelivr_source_url($source) {
        $script = $source['handle'];
        $jsdelivrcdn_url = '';
        $plugin_data = [];

        if($script->src) {
            $jsdelivr_data = self::get_jsdelivr_data($source[self::ORIGINAL_SOURCE_URL]);
            if($jsdelivr_data) {
                if (isset($jsdelivr_data['file'])) {
                    if($jsdelivr_data['name'] === 'WordPress/WordPress') {
                        $jsdelivr_data['version'] = get_bloginfo( 'version' );
                    }
                    $jsdelivrcdn_url = self::JSDELIVR_CDN_URL."{$jsdelivr_data['type']}/{$jsdelivr_data['name']}@{$jsdelivr_data['version']}{$jsdelivr_data['file']}";
                } elseif(preg_match("/wp-content\/plugins\/(?<plugin>[^\/]*)\/(?<file>.*)/i", $script->src, $matches)) {
                    if($matches['plugin'] && $matches['file']) {
                        $pluginFile = ABSPATH."wp-content/plugins/{$matches['plugin']}/{$matches['plugin']}.php";
                        if(file_exists($pluginFile)) {
                            $plugin_data = get_plugin_data($pluginFile);
                        } else {
                            $phpFiles = glob(ABSPATH."wp-content/plugins/{$matches['plugin']}/*.php");
                            foreach ($phpFiles as $path) {
                                $plugin_data = get_plugin_data($path);
                                if($plugin_data['Version']) {
                                    break;
                                }
                            }
                        }
                        if($plugin_data['Version']) {
                            $jsdelivrcdn_url = self::JSDELIVR_CDN_URL."wp/plugins/{$matches['plugin']}/tags/{$plugin_data['Version']}/{$matches['file']}";
                        }
                    }
                } elseif(preg_match("/wp-content\/themes\/(?<theme>[^\/]*)\/(?<file>.*)/i", $script->src, $matches)) {
                    if($matches['theme'] && $matches['file']) {
                        $theme = wp_get_theme($matches['theme']);
                        if($theme->exists()) {
                            $jsdelivrcdn_url = self::JSDELIVR_CDN_URL."wp/themes/{$matches['theme']}/{$theme->get('Version')}/{$matches['file']}";
                        }
                    }
                }
            }
        }
        return $jsdelivrcdn_url;
    }

    /**
     * Replace source with jsdelivr url
     * @param $handle
     * @param $type
     * @return bool
     */
    private static function replace_source($handle, $type) {
        global $wp_scripts;
        global $wp_styles;
        $updated = false;
        $index = $type.'-'.$handle;
        $source = ($type === 'script')? $wp_scripts->registered[$handle] : $wp_styles->registered[$handle];

        if(preg_match( "/cdn.jsdelivr.net|googleapi/i", $source->src)) {
            return $updated;
        }

        if(!isset(self::$options[self::SOURCE_LIST][$index])) {
            self::$options[self::SOURCE_LIST][$index] = [
                'handle' => $source,
                self::JSDELIVR_SOURCE_URL => '',
                self::ORIGINAL_SOURCE_URL => self::get_file_path($source->src),
                'active' => self::$options[self::AUTOENABLE],
                self::SOURCE_LAST_LOADED => time()
            ];
            $updated = true;
        } elseif(time() - self::$options[self::SOURCE_LIST][$index][self::SOURCE_LAST_LOADED] > 60*60*24) {
            self::$options[self::SOURCE_LIST][$index][self::SOURCE_LAST_LOADED] = time();
            $updated = true;
        }
        if(isset(self::$options[self::SOURCE_LIST][$index])) {
            if(self::$options[self::SOURCE_LIST][$index][self::JSDELIVR_SOURCE_URL] && self::$options[self::SOURCE_LIST][$index]['active']) {
                if($type === 'script') { //Script
                    wp_deregister_script($handle);
                    wp_register_script($handle, self::$options[self::SOURCE_LIST][$index][self::JSDELIVR_SOURCE_URL], $source->deps, $source->ver);
                    foreach($source->extra as $key => $value) {
                        wp_script_add_data($handle, $key, $value);
                    }
                } elseif($type === 'style') { // Style
                    wp_deregister_style($handle);
                    wp_register_style($handle, self::$options[self::SOURCE_LIST][$index][self::JSDELIVR_SOURCE_URL], $source->deps, $source->ver);
                    foreach($source->extra as $key => $value) {
                        wp_style_add_data($index, $key, $value);
                    }
                }

            }

        }
        foreach ($source->deps as $dep) {
            $updated = $updated ||  self::replace_source($dep, $type);
        }
        return $updated;
    }

    /**
     * Action for scripts replacement
     */
    public static function custom_enqueue_scripts() {
        global $wp_scripts;
        $updated = false;

        foreach( $wp_scripts->queue  as $handle) {
            $updated = $updated || self::replace_source($handle, 'script');
        }

        if($updated) {
            update_option(self::PLUGIN_SETTINGS, self::$options);
        }
    }

    /**
     * Action for styles replacement
     */
    public static function custom_enqueue_styles() {
        global $wp_styles;
        $updated = false;

        foreach( $wp_styles->queue as $handle) {
            $updated = $updated || self::replace_source($handle, 'style');
        }

        if($updated) {
            update_option(self::PLUGIN_SETTINGS, self::$options);
        }
    }

    /**
     * get file path (url, local)
     * @param $src
     * @return string
     */
    public static function get_file_path($src) {
        if(strpos($src, '//') !== false) {
            $site_url = site_url();

            if (strpos($src, $site_url) === 0)
                $file_path = ltrim(str_replace($site_url, '', $src),'/');
            else
                $file_path = $src;
        } else {
            $file_path = ltrim($src, '/');
        }
        return $file_path;
    }

    /**
     * Check files by hash with jsdelivr api
     * @param $file_path
     * @return array|bool|mixed|object
     */
    private static function get_jsdelivr_data($file_path) {
        $result = false;
        if(defined('ABSPATH')) {
            chdir(ABSPATH);

            $file_content = file_get_contents($file_path);
            if($file_content) {
                $sha256 = hash('sha256', $file_content);
                $context = stream_context_create(array(
                    'http' => array('ignore_errors' => true),
                ));
                $result = json_decode(file_get_contents( self::$jsdelivr_hash_lookup_url . $sha256, false, $context ), true);
                $result['sha256'] = $sha256;
                $result['file_path'] = $file_path;
            }
        } else {
            trigger_error('ABSPATH is not defined');
        }
        return $result;
    }

    /**
     * Add admin pages
     */
    public static function add_admin_pages() {
        add_menu_page('jsDelivr CDN', 'jsDelivr CDN', 'manage_options', 'jsdelivrcdn',function(){
            require_once( JSDELIVRCDN_PLUGIN_PATH . 'templates/admin.php' );
        },JSDELIVRCDN_PLUGIN_URL.'assets/img/jsdelivr-icon.png',110);

    }

    /**
     * Init Admin page setting, sections and fields
     */
    public static function admin_init() {
        register_setting( self::PLUGIN_SETTINGS, self::PLUGIN_SETTINGS , ['JsDelivrCdn','validate_settings']);
        add_settings_section(self::PLUGIN_SETTINGS, '', '', 'main_settings');
        add_settings_field(self::ADVANCED_MODE, 'Advanced mode', function() {
            echo '<input type="checkbox" name="'.self::ADVANCED_MODE.'" id="'.self::ADVANCED_MODE.'" '.(self::$options[self::ADVANCED_MODE] ? 'checked':'').' title="Advanced mode">';
        }, 'main_settings', self::PLUGIN_SETTINGS);
        add_settings_field(self::AUTOENABLE, 'Automatically enable', function() {
            echo '<input type="checkbox" name="'.self::AUTOENABLE.'" id="'.self::AUTOENABLE.'" '.(self::$options[self::AUTOENABLE] ? 'checked':'').' title="Automatically enable">';
        }, 'main_settings', self::PLUGIN_SETTINGS);
    }

    /**
     * Validate settings update
     * @param $data
     * @return array
     */
    public static function validate_settings($data) {
        if(isset($_POST['action']) && $_POST['action'] === 'update') {

            if(isset($data[self::SOURCE_LIST])) {
                foreach (self::$options[self::SOURCE_LIST] as $key => $item) {
                    if(isset($data[self::SOURCE_LIST][$key]['active'])) {
                        self::$options[self::SOURCE_LIST][$key]['active'] = true;
                    } else {
                        self::$options[self::SOURCE_LIST][$key]['active'] = false;
                    }
                }
            }

            if(isset($_POST[self::ADVANCED_MODE])) {
                self::$options[self::ADVANCED_MODE] = true;
            } else {
                self::$options[self::ADVANCED_MODE] = false;
            }

            if(isset($_POST[self::AUTOENABLE])) {
                self::$options[self::AUTOENABLE] = true;

            } else {
                self::$options[self::AUTOENABLE] = false;
            }

            return self::$options;
        }
        return $data;
    }

    /**
     * Ajax action clear source list
     */
    public static function clear_source_list() {
        check_ajax_referer( JSDELIVRCDN_PLUGIN_NAME, 'security' );

        foreach (self::$options[self::SOURCE_LIST] as $index => $source) {
            self::$options[self::SOURCE_LIST][$index][self::JSDELIVR_SOURCE_URL] = '';

            $data[$index] = self::$options[self::SOURCE_LIST][$index][self::JSDELIVR_SOURCE_URL];

        }
        update_option(self::PLUGIN_SETTINGS, self::$options);

        echo json_encode(['result' => 'OK']);

        wp_die();
    }

    /**
     * Ajax action remove one row
     */
    public static function clear_source() {
        check_ajax_referer( JSDELIVRCDN_PLUGIN_NAME, 'security' );

        $index = $_POST['handle'];
        if(isset(self::$options[self::SOURCE_LIST][$index])) {
            self::$options[self::SOURCE_LIST][$index][self::JSDELIVR_SOURCE_URL] = '';
        }
        update_option(self::PLUGIN_SETTINGS, self::$options);

        echo json_encode(['result' => 'OK']);

        wp_die();

    }

    /**
     * Ajax Get saved data
     */
    public static function get_source_list() {
        check_ajax_referer( JSDELIVRCDN_PLUGIN_NAME, 'security' );

        $data = [];
        foreach (self::$options[self::SOURCE_LIST] as $index => $source) {
            if(time() - $source[self::SOURCE_LAST_LOADED] <= 60*60*24 || self::$options[self::ADVANCED_MODE]) {
                if($source[self::ORIGINAL_SOURCE_URL]) {
                    $data[$index] = [
                        'original_url' => $source[self::ORIGINAL_SOURCE_URL],
                        'jsdelivr_url' => $source[self::JSDELIVR_SOURCE_URL],
                        'active' => $source['active']
                    ];
                    if(self::$options[self::ADVANCED_MODE]) {
                        $data[$index]['ver'] = $source['handle']->ver;
                        $data[$index]['handle'] = $source['handle']->handle;
                    }
                }
            }
        }

        echo json_encode(['result' => 'OK', 'data' => $data]);

        wp_die();
    }

    /**
     * Analyze sources
     * @return array
     */
    public static function analyze() {

        $data = [];
        $updated = false;
        foreach (self::$options[self::SOURCE_LIST] as $index => $source) {
            if(!$source[self::JSDELIVR_SOURCE_URL] && $source['handle']->src) {
                self::$options[self::SOURCE_LIST][$index][self::JSDELIVR_SOURCE_URL] = self::get_jsdelivr_source_url($source);
                if(self::$options[self::AUTOENABLE]) {
                    self::$options[self::SOURCE_LIST][$index]['active'] = true;
                }

                $updated = true;
            }

            $data[$index] = self::$options[self::SOURCE_LIST][$index][self::JSDELIVR_SOURCE_URL];

        }

        if($updated) {
            update_option(self::PLUGIN_SETTINGS, self::$options);
        }

        return $data;
    }

    /**
     * Ajax Analyze sources
     */
    public static function jsdelivr_analyze(){
        check_ajax_referer( JSDELIVRCDN_PLUGIN_NAME, 'security' );

        $data = self::analyze();

        echo json_encode(['result' => 'OK', 'data' => $data]);

        wp_die();
    }

    /**
     * Ajax Remove all saved data
     */
    public static function delete_source_list() {
        check_ajax_referer( JSDELIVRCDN_PLUGIN_NAME, 'security' );

        self::$options[self::SOURCE_LIST] = [];

        update_option(self::PLUGIN_SETTINGS, self::$options);

        echo json_encode(['result' => 'OK']);

        wp_die();
    }

    /**
     * Remove Sources last loaded more then 48 hours ago
     */
    public static function clear_old_sources() {
        $updated = false;
        foreach (self::$options[self::SOURCE_LIST] as $index => $source) {
            if(time() - $source[self::SOURCE_LAST_LOADED] > 60*60*24*2) {
                unset(self::$options[self::SOURCE_LIST][$index]);
                $updated = true;
            }
        }

        if($updated) {
            update_option(self::PLUGIN_SETTINGS, self::$options);
        }
    }

    public static function advanced_mode_switch() {
        self::$options[self::ADVANCED_MODE] = filter_var($_POST[self::ADVANCED_MODE], FILTER_VALIDATE_BOOLEAN);

        update_option(self::PLUGIN_SETTINGS, self::$options);

        echo json_encode(['result' => 'OK']);

        wp_die();
    }
}
