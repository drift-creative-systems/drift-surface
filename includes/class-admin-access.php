<?php
/**
 * class-admin-access.php — agency users and admin menu hiding.
 *
 * Replaces White Label CMS's "hide menus from everyone except the WLCMS
 * admin" feature, which was tied to user ID 1 — not safe across a network
 * where user 1 might be a client, or not exist.
 *
 * Instead:
 *   - An "Agency user" checkbox on each user's profile (user meta
 *     self::META). Agency users see the full admin; everyone else — whatever
 *     their role, since some clients are Administrators — gets the trimmed
 *     menu chosen on Drift → White Label.
 *   - Hidden menus are also blocked by URL (self::block_hidden_pages()), not
 *     just removed from the sidebar.
 *
 * Lockout safety:
 *   - Until at least one agency user exists on the site, nothing is hidden
 *     and any administrator can tick the checkbox. Once one exists, only
 *     agency users can change it (or the White Label settings).
 *   - If the last agency user unticks themselves, hiding switches off again.
 *   - The Dashboard and the user's own Profile are never hidden or blocked,
 *     so everyone can always reach the checkbox's page.
 *
 * @package Drift_Website
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Drift_Website_Admin_Access {

    const META   = 'drift_website_agency_user';
    const OPTION = 'drift_website_hidden_menus';

    /** Never hidden or blocked — see the lockout notes above. */
    const PROTECTED_SLUGS = [ 'index.php', 'profile.php' ];

    /** @var bool|null Cached per request. */
    private static $agency_exists = null;

    /** @var string[] Slugs blocked for this request, filled by hide_menus(). */
    private static $blocked = [];

    public static function init(): void {
        add_action( 'show_user_profile', [ __CLASS__, 'render_profile_field' ] );
        add_action( 'edit_user_profile', [ __CLASS__, 'render_profile_field' ] );
        add_action( 'personal_options_update', [ __CLASS__, 'save_profile_field' ] );
        add_action( 'edit_user_profile_update', [ __CLASS__, 'save_profile_field' ] );

        // Late, so every plugin has added its menus first.
        add_action( 'admin_menu', [ __CLASS__, 'hide_menus' ], 9999 );
        add_action( 'current_screen', [ __CLASS__, 'block_hidden_pages' ] );
    }

    /**
     * Top-level menus hidden from clients until someone saves a choice:
     * Appearance, Plugins, Users, Tools, Settings and ACF. Band members get
     * their content (synced from Airtable) and nothing they can break.
     */
    public static function default_hidden_menus(): array {
        return [
            'themes.php',
            'plugins.php',
            'users.php',
            'tools.php',
            'options-general.php',
            'edit.php?post_type=acf-field-group',
            'drift-website',
        ];
    }

    /** @return string[] */
    public static function hidden_menus(): array {
        $saved = get_option( self::OPTION, null );
        $menus = is_array( $saved ) ? $saved : self::default_hidden_menus();

        return array_values( array_diff( array_map( 'strval', $menus ), self::PROTECTED_SLUGS ) );
    }

    /* ── Agency users ────────────────────────────────────────────────── */

    public static function is_agency( int $user_id = 0 ): bool {
        $user_id = $user_id ?: get_current_user_id();
        return $user_id && '1' === (string) get_user_meta( $user_id, self::META, true );
    }

    public static function agency_users_exist(): bool {
        if ( null === self::$agency_exists ) {
            self::$agency_exists = (bool) get_users( [
                'meta_key'    => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One indexed lookup, cached per request.
                'meta_value'  => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'number'      => 1,
                'fields'      => 'ID',
                'count_total' => false,
            ] );
        }
        return self::$agency_exists;
    }

    /**
     * Can the current user change agency status and the White Label settings?
     * Any administrator until an agency user exists; agency users after that.
     */
    public static function can_manage(): bool {
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }
        return ! self::agency_users_exist() || self::is_agency();
    }

    /** Does the trimmed menu apply to the current user? */
    public static function is_restricted(): bool {
        return is_user_logged_in() && self::agency_users_exist() && ! self::is_agency();
    }

    public static function render_profile_field( WP_User $user ): void {
        if ( ! self::can_manage() || ! current_user_can( 'edit_user', $user->ID ) ) {
            return;
        }
        ?>
        <h2><?php esc_html_e( 'Drift access', 'drift-website' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Agency user', 'drift-website' ); ?></th>
                <td>
                    <label for="drift-website-agency-user">
                        <input type="checkbox" id="drift-website-agency-user" name="drift_website_agency_user" value="1" <?php checked( self::is_agency( $user->ID ) ); ?>>
                        <?php esc_html_e( 'Sees the full admin menu and can change Drift → White Label.', 'drift-website' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'Everyone else gets the trimmed menu set on Drift → White Label. Menu hiding only switches on once at least one agency user exists.', 'drift-website' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Core has already checked the 'update-user_{$user_id}' nonce before
     * these hooks fire (wp-admin/user-edit.php).
     */
    public static function save_profile_field( int $user_id ): void {
        if ( ! self::can_manage() || ! current_user_can( 'edit_user', $user_id ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by core, see above.
        if ( ! empty( $_POST['drift_website_agency_user'] ) ) {
            update_user_meta( $user_id, self::META, '1' );
        } else {
            delete_user_meta( $user_id, self::META );
        }

        self::$agency_exists = null;
    }

    /* ── Menu hiding ─────────────────────────────────────────────────── */

    /**
     * Removes hidden top-level menus for restricted users, and records them
     * plus all their submenu pages for block_hidden_pages().
     */
    public static function hide_menus(): void {
        if ( ! self::is_restricted() ) {
            return;
        }

        global $submenu;

        foreach ( self::hidden_menus() as $slug ) {
            self::$blocked[] = $slug;

            foreach ( (array) ( $submenu[ $slug ] ?? [] ) as $item ) {
                if ( ! empty( $item[2] ) && ! in_array( $item[2], self::PROTECTED_SLUGS, true ) ) {
                    self::$blocked[] = (string) $item[2];
                }
            }

            remove_menu_page( $slug );
        }

        self::$blocked = array_values( array_unique( self::$blocked ) );
    }

    /**
     * Stops restricted users reaching a hidden page by typing its URL.
     * Runs on current_screen, which fires after the menu (and so
     * hide_menus()) is built.
     */
    public static function block_hidden_pages(): void {
        if ( ! self::$blocked || ! self::is_restricted() ) {
            return;
        }

        global $pagenow, $plugin_page;

        foreach ( self::$blocked as $slug ) {
            if ( self::request_matches( $slug, (string) $pagenow, (string) $plugin_page ) ) {
                wp_die(
                    esc_html__( 'Sorry, you are not allowed to access this page.', 'drift-website' ),
                    esc_html__( 'Not allowed', 'drift-website' ),
                    [ 'response' => 403, 'back_link' => true ]
                );
            }
        }
    }

    /**
     * Does the current request open this menu slug?
     *   - 'site-text-files' (a plugin page)  → ?page=site-text-files
     *   - 'themes.php'                       → themes.php
     *   - 'edit.php?post_type=acf-field-group' → edit.php with that post_type
     */
    private static function request_matches( string $slug, string $pagenow, string $plugin_page ): bool {
        if ( '' !== $plugin_page ) {
            return $slug === $plugin_page;
        }

        $path = (string) wp_parse_url( $slug, PHP_URL_PATH );
        if ( $path !== $pagenow ) {
            return false;
        }

        parse_str( (string) wp_parse_url( $slug, PHP_URL_QUERY ), $args );
        foreach ( $args as $key => $value ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only comparison.
            if ( ! isset( $_GET[ $key ] ) || (string) wp_unslash( $_GET[ $key ] ) !== (string) $value ) {
                return false;
            }
        }

        return true;
    }

    /* ── White Label tab card ────────────────────────────────────────── */

    /**
     * The "Admin menus" card on Drift → White Label. Rendered inside that
     * tab's form; saved by save_settings().
     */
    public static function render_settings_card(): void {
        global $menu;

        $hidden = self::hidden_menus();
        $items  = [];

        foreach ( (array) $menu as $item ) {
            $slug = (string) ( $item[2] ?? '' );
            if ( '' === $slug || false !== strpos( (string) ( $item[4] ?? '' ), 'wp-menu-separator' ) || in_array( $slug, self::PROTECTED_SLUGS, true ) ) {
                continue;
            }
            // Drop update/comment count bubbles from the label.
            $label          = trim( wp_strip_all_tags( preg_replace( '/<span.*$/s', '', (string) $item[0] ) ) );
            $items[ $slug ] = '' !== $label ? $label : $slug;
        }

        // Keep saved choices for menus not currently registered (e.g. a
        // deactivated plugin), so saving doesn't silently drop them.
        foreach ( $hidden as $slug ) {
            if ( ! isset( $items[ $slug ] ) ) {
                /* translators: %s: admin menu slug. */
                $items[ $slug ] = sprintf( __( '%s (not currently in the menu)', 'drift-website' ), $slug );
            }
        }

        $agency = get_users( [
            'meta_key'   => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'fields'     => [ 'ID', 'display_name', 'user_email' ],
        ] );
        ?>
        <section class="drift-card">
            <h3><?php esc_html_e( 'Admin menus', 'drift-website' ); ?></h3>
            <p class="description"><?php esc_html_e( 'Ticked menus are hidden from everyone who isn\'t an agency user, whatever their role, and their pages are blocked if someone types the address. The Dashboard and each user\'s own Profile are always available.', 'drift-website' ); ?></p>

            <?php if ( ! $agency ) : ?>
                <div class="notice notice-warning inline"><p>
                    <?php esc_html_e( 'Menu hiding is off: no agency users yet.', 'drift-website' ); ?>
                    <a href="<?php echo esc_url( admin_url( 'profile.php#drift-website-agency-user' ) ); ?>"><?php esc_html_e( 'Tick "Agency user" on your profile', 'drift-website' ); ?></a>
                </p></div>
            <?php else : ?>
                <p>
                    <strong><?php esc_html_e( 'Agency users:', 'drift-website' ); ?></strong>
                    <?php
                    echo wp_kses(
                        implode( ', ', array_map( static function ( $u ) {
                            return sprintf( '<a href="%s">%s</a>', esc_url( get_edit_user_link( (int) $u->ID ) ), esc_html( $u->display_name ) );
                        }, $agency ) ),
                        [ 'a' => [ 'href' => [] ] ]
                    );
                    ?>
                </p>
            <?php endif; ?>

            <input type="hidden" name="admin_access[submitted]" value="1">
            <div class="drift-menu-list">
                <?php foreach ( $items as $slug => $label ) : ?>
                    <label class="drift-menu-list__item">
                        <input type="checkbox" name="admin_access[hidden_menus][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $hidden, true ) ); ?>>
                        <span><?php echo esc_html( $label ); ?><small><?php echo esc_html( $slug ); ?></small></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    /**
     * Saves the ticked menus. Called from the White Label tab's save handler,
     * which has already checked the nonce and can_manage().
     *
     * @param mixed $input $_POST['admin_access'], unslashed.
     */
    public static function save_settings( $input ): void {
        if ( ! is_array( $input ) || empty( $input['submitted'] ) ) {
            return;
        }

        $slugs = [];
        foreach ( (array) ( $input['hidden_menus'] ?? [] ) as $slug ) {
            // Menu slugs are file names, query strings or plugin page slugs.
            $slug = preg_replace( '/[^A-Za-z0-9._\-?=&\/]/', '', (string) $slug );
            if ( '' !== $slug && ! in_array( $slug, self::PROTECTED_SLUGS, true ) ) {
                $slugs[] = $slug;
            }
        }

        update_option( self::OPTION, array_values( array_unique( $slugs ) ), false );
    }
}
