<?php
/**
 * class-white-label.php — Drift admin branding and login screen.
 *
 * Replaces the parts of White
 * Label CMS we actually use, as a "White Label" tab in the Drift screen (added via the
 * `drift_website_admin_tabs` filter, same as the theme's Site Settings tab):
 *
 *   Branding — hide WordPress branding (admin bar logo/links, footer credit,
 *              version), a custom admin bar logo, custom admin footer text.
 *   Login    — logo (size + link to the site), page background colour/image,
 *              form/label/button/link colours, on top of the default Drift
 *              design in assets/login.css (black stage, pink accent, one
 *              white card holding the logo, form and links).
 *
 * Admin menu hiding is Drift_Website_Admin_Access (class-admin-access.php).
 *
 * Settings live in one option (self::OPTION). Anything left blank falls back
 * to self::defaults(), so a fresh site is branded without anyone opening the
 * screen. Default logos are files shipped in assets/branding/ (see
 * self::bundled_asset()) — used only if the file is actually there.
 *
 * White Label CMS should be deactivated once this is set up; both running at
 * once would fight over the same login/admin bar output. The tab shows a
 * warning while it's active.
 *
 * @package Drift_Website
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Drift_Website_White_Label {

    const OPTION = 'drift_website_white_label';

    public static function init(): void {
        add_filter( 'drift_website_admin_tabs', [ __CLASS__, 'register_tab' ] );
        add_action( 'admin_post_drift_website_save_white_label', [ __CLASS__, 'handle_save' ] );

        // Branding.
        // Logo added early so it sits at the far left; the WordPress logo
        // (added by core at priority 10) removed late.
        add_action( 'admin_bar_menu', [ __CLASS__, 'admin_bar_logo' ], 5 );
        add_action( 'admin_bar_menu', [ __CLASS__, 'admin_bar_remove_wp_logo' ], 999 );
        add_action( 'admin_head', [ __CLASS__, 'admin_bar_styles' ] );
        add_action( 'wp_head', [ __CLASS__, 'admin_bar_styles' ] );
        add_filter( 'admin_footer_text', [ __CLASS__, 'footer_text' ], 99 );
        add_filter( 'update_footer', [ __CLASS__, 'footer_version' ], 99 );

        // Login screen.
        add_action( 'login_enqueue_scripts', [ __CLASS__, 'login_styles' ] );
        add_filter( 'login_headerurl', [ __CLASS__, 'login_logo_url' ] );
        add_filter( 'login_headertext', [ __CLASS__, 'login_logo_text' ] );
        // No language switcher under the form — Drift sites are English-only.
        add_filter( 'login_display_language_dropdown', '__return_false' );
    }

    /**
     * Drift defaults — used for any field left blank. Bonsai palette:
     * #ee4367 (pink), #e2ecf3 (mist), #000000. Logos come from
     * assets/branding/ if the files exist (see the README there).
     */
    public static function defaults(): array {
        return [
            // Branding.
            'hide_wp_branding'      => '1',
            'admin_bar_logo'        => self::bundled_asset( 'drift-admin-bar-logo.png' ),
            'admin_bar_logo_url'    => '',
            'footer_text'           => 'Website by The Bonsai Digital Collective',
            'footer_url'            => '',

            // Login screen.
            // drift-login-logo.png at 2× (640 × 230) stays sharp on retina.
            'login_logo'            => self::bundled_asset( 'drift-login-logo.png' ),
            'login_logo_width'      => 320,
            'login_logo_height'     => 115,
            // Blank = the Drift black stage in assets/login.css. A colour
            // picked here replaces it with that flat colour.
            'login_bg_color'        => '',
            'login_bg_image'        => '',
            'login_form_bg_color'   => '#ffffff',
            'login_label_color'     => '#000000',
            'login_button_color'    => '#ee4367',
            'login_button_text'     => '#ffffff',
            'login_button_hover'    => '#000000',
            'login_link_color'      => '#000000',
        ];
    }

    /**
     * Describes a default that isn't a plain value, for the "Blank uses the
     * Drift default: …" hint — e.g. the login background, whose default is
     * the gradient in assets/login.css rather than a colour.
     */
    const DEFAULT_LABELS = [
        'login_bg_color' => 'Drift black stage',
    ];

    /**
     * URL of a file in assets/branding/, or '' if it isn't there — so a
     * missing default logo means "no logo", never a broken image.
     */
    private static function bundled_asset( string $file ): string {
        return file_exists( DRIFT_WEBSITE_DIR . 'assets/branding/' . $file )
            ? DRIFT_WEBSITE_URL . 'assets/branding/' . rawurlencode( $file )
            : '';
    }

    /**
     * What's saved on this site, with blanks filled from defaults().
     * hide_wp_branding is a checkbox, so a saved '0' is kept, not defaulted.
     */
    public static function settings(): array {
        $defaults = self::defaults();
        $saved    = get_option( self::OPTION, [] );
        $saved    = is_array( $saved ) ? $saved : [];
        $out      = $defaults;

        foreach ( $defaults as $key => $default ) {
            if ( 'hide_wp_branding' === $key ) {
                $out[ $key ] = isset( $saved[ $key ] ) ? (string) $saved[ $key ] : $default;
                continue;
            }
            if ( isset( $saved[ $key ] ) && '' !== $saved[ $key ] && 0 !== $saved[ $key ] ) {
                $out[ $key ] = $saved[ $key ];
            }
        }

        return $out;
    }

    /**
     * The raw saved values, no defaults — what the form fields show, so a
     * blank field visibly means "using the Drift default".
     */
    private static function saved(): array {
        $saved = get_option( self::OPTION, [] );
        return is_array( $saved ) ? $saved : [];
    }

    /* ── Drift tab ───────────────────────────────────────────────────── */

    /**
     * Adds the White Label tab. Only agency users see it once any exist
     * (Drift_Website_Admin_Access) — clients shouldn't be able to rebrand
     * the admin or change which menus they can see.
     */
    public static function register_tab( array $tabs ): array {
        if ( ! Drift_Website_Admin_Access::can_manage() ) {
            return $tabs;
        }

        $tabs['white-label'] = [
            'label'       => __( 'White Label', 'drift-website' ),
            'description' => __( 'Admin branding, the login screen, and which menus non-agency users can see. Blank fields use the Drift default.', 'drift-website' ),
            'position'    => 55,
            'load'        => [ __CLASS__, 'load_tab' ],
            'render'      => [ __CLASS__, 'render_tab' ],
        ];

        return $tabs;
    }

    /** Colour pickers for this tab only. The media picker is already loaded. */
    public static function load_tab(): void {
        add_action( 'admin_enqueue_scripts', static function () {
            wp_enqueue_style( 'wp-color-picker' );
            wp_enqueue_script( 'wp-color-picker' );
        } );
    }

    public static function render_tab(): void {
        $saved    = self::saved();
        $defaults = self::defaults();
        ?>
        <?php if ( isset( $_GET['white_label_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'White label settings saved.', 'drift-website' ); ?></p></div>
        <?php endif; ?>

        <?php if ( defined( 'WLCMS_VERSION' ) ) : ?>
            <div class="notice notice-warning"><p><?php esc_html_e( 'White Label CMS is still active. Deactivate it once you\'re happy with these settings — both plugins change the login screen and admin bar, and they\'ll conflict.', 'drift-website' ); ?></p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'drift_website_save_white_label', 'drift_website_white_label_nonce' ); ?>
            <input type="hidden" name="action" value="drift_website_save_white_label">

            <section class="drift-card">
                <h3><?php esc_html_e( 'Branding', 'drift-website' ); ?></h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'WordPress branding', 'drift-website' ); ?></th>
                        <td>
                            <input type="hidden" name="white_label[hide_wp_branding]" value="0">
                            <label>
                                <input type="checkbox" name="white_label[hide_wp_branding]" value="1" <?php checked( self::settings()['hide_wp_branding'], '1' ); ?>>
                                <?php esc_html_e( 'Hide the WordPress logo and links in the admin bar, the "Thank you for creating with WordPress" footer and the version number.', 'drift-website' ); ?>
                            </label>
                        </td>
                    </tr>
                    <?php
                    self::image_row( 'admin_bar_logo', __( 'Admin bar logo', 'drift-website' ), $saved, $defaults, __( 'Shown at the top-left of the admin bar, about 20px tall.', 'drift-website' ) );
                    self::text_row( 'admin_bar_logo_url', __( 'Admin bar logo link', 'drift-website' ), $saved, $defaults, 'url', __( 'Leave blank to link to the dashboard.', 'drift-website' ) );
                    self::text_row( 'footer_text', __( 'Admin footer text', 'drift-website' ), $saved, $defaults );
                    self::text_row( 'footer_url', __( 'Admin footer link', 'drift-website' ), $saved, $defaults, 'url', __( 'Optional. Makes the footer text a link.', 'drift-website' ) );
                    ?>
                </table>
            </section>

            <section class="drift-card">
                <h3><?php esc_html_e( 'Login screen', 'drift-website' ); ?></h3>
                <table class="form-table" role="presentation">
                    <?php
                    self::image_row( 'login_logo', __( 'Logo', 'drift-website' ), $saved, $defaults, __( 'Links to this site\'s home page.', 'drift-website' ) );
                    self::number_row( 'login_logo_width', __( 'Logo width (px)', 'drift-website' ), $saved, $defaults );
                    self::number_row( 'login_logo_height', __( 'Logo height (px)', 'drift-website' ), $saved, $defaults );
                    self::colour_row( 'login_bg_color', __( 'Background colour', 'drift-website' ), $saved, $defaults );
                    self::image_row( 'login_bg_image', __( 'Background image', 'drift-website' ), $saved, $defaults, __( 'Optional. Covers the whole page.', 'drift-website' ) );
                    self::colour_row( 'login_form_bg_color', __( 'Login box background', 'drift-website' ), $saved, $defaults );
                    self::colour_row( 'login_label_color', __( 'Form label colour', 'drift-website' ), $saved, $defaults );
                    self::colour_row( 'login_button_color', __( 'Button colour', 'drift-website' ), $saved, $defaults );
                    self::colour_row( 'login_button_text', __( 'Button text colour', 'drift-website' ), $saved, $defaults );
                    self::colour_row( 'login_button_hover', __( 'Button hover colour', 'drift-website' ), $saved, $defaults );
                    self::colour_row( 'login_link_color', __( 'Link colour', 'drift-website' ), $saved, $defaults );
                    ?>
                </table>
            </section>

            <?php Drift_Website_Admin_Access::render_settings_card(); ?>

            <p class="drift-save-row"><?php submit_button( __( 'Save white label settings', 'drift-website' ), 'primary', 'submit', false ); ?></p>
        </form>
        <?php
    }

    /** "Default: …" hint under a field, so blank visibly means Drift default. */
    private static function default_hint( string $key, array $defaults ): void {
        $default = self::DEFAULT_LABELS[ $key ] ?? $defaults[ $key ] ?? '';
        if ( '' === $default || null === $default ) {
            printf( '<p class="description">%s</p>', esc_html__( 'No default — leave blank for none.', 'drift-website' ) );
            return;
        }
        printf(
            '<p class="description">%s <code>%s</code></p>',
            esc_html__( 'Blank uses the Drift default:', 'drift-website' ),
            esc_html( is_string( $default ) && 0 === strpos( $default, DRIFT_WEBSITE_URL ) ? basename( rawurldecode( $default ) ) : (string) $default )
        );
    }

    private static function text_row( string $key, string $label, array $saved, array $defaults, string $type = 'text', string $help = '' ): void {
        $id = 'drift-wl-' . $key;
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
            <td>
                <input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $id ); ?>" class="regular-text" name="white_label[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) ( $saved[ $key ] ?? '' ) ); ?>">
                <?php if ( $help ) : ?>
                    <p class="description"><?php echo esc_html( $help ); ?></p>
                <?php endif; ?>
                <?php self::default_hint( $key, $defaults ); ?>
            </td>
        </tr>
        <?php
    }

    private static function number_row( string $key, string $label, array $saved, array $defaults ): void {
        $id    = 'drift-wl-' . $key;
        $value = $saved[ $key ] ?? '';
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
            <td>
                <input type="number" min="1" max="1000" step="1" id="<?php echo esc_attr( $id ); ?>" class="small-text" name="white_label[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ? (string) absint( $value ) : '' ); ?>">
                <?php self::default_hint( $key, $defaults ); ?>
            </td>
        </tr>
        <?php
    }

    private static function colour_row( string $key, string $label, array $saved, array $defaults ): void {
        $id = 'drift-wl-' . $key;
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
            <td>
                <input type="text" id="<?php echo esc_attr( $id ); ?>" class="drift-colour-field" data-default-color="<?php echo esc_attr( (string) ( $defaults[ $key ] ?? '' ) ); ?>" name="white_label[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) ( $saved[ $key ] ?? '' ) ); ?>">
                <?php self::default_hint( $key, $defaults ); ?>
            </td>
        </tr>
        <?php
    }

    /** Media Library picker — reuses admin.js's generic .drift-media-field. */
    private static function image_row( string $key, string $label, array $saved, array $defaults, string $help = '' ): void {
        $id    = 'drift-wl-' . $key;
        $value = (string) ( $saved[ $key ] ?? '' );
        ?>
        <tr>
            <th scope="row"><?php echo esc_html( $label ); ?></th>
            <td>
                <div class="drift-media-field">
                    <input type="hidden" id="<?php echo esc_attr( $id ); ?>" name="white_label[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>">
                    <div class="drift-media-field__preview" <?php echo '' === $value ? 'style="display:none;"' : ''; ?>>
                        <?php if ( '' !== $value ) : ?>
                            <img src="<?php echo esc_url( $value ); ?>" alt="">
                        <?php endif; ?>
                    </div>
                    <p>
                        <button type="button" class="button drift-media-field__select"><?php echo '' !== $value ? esc_html__( 'Change Image', 'drift-website' ) : esc_html__( 'Select Image', 'drift-website' ); ?></button>
                        <button type="button" class="button drift-media-field__remove" <?php echo '' === $value ? 'style="display:none;"' : ''; ?>><?php esc_html_e( 'Remove', 'drift-website' ); ?></button>
                    </p>
                </div>
                <?php if ( $help ) : ?>
                    <p class="description"><?php echo esc_html( $help ); ?></p>
                <?php endif; ?>
                <?php self::default_hint( $key, $defaults ); ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Saves the White Label tab — branding/login here, the menu settings via
     * Drift_Website_Admin_Access::save_settings().
     */
    public static function handle_save(): void {
        if ( ! current_user_can( 'manage_options' ) || ! Drift_Website_Admin_Access::can_manage() ) {
            wp_die( esc_html__( 'You do not have permission to change these settings.', 'drift-website' ) );
        }

        check_admin_referer( 'drift_website_save_white_label', 'drift_website_white_label_nonce' );

        $input = wp_unslash( $_POST['white_label'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised per field below.
        $input = is_array( $input ) ? $input : [];
        $out   = [];

        foreach ( array_keys( self::defaults() ) as $key ) {
            $raw = $input[ $key ] ?? '';

            switch ( $key ) {
                case 'hide_wp_branding':
                    $out[ $key ] = ! empty( $raw ) ? '1' : '0';
                    break;
                case 'login_logo_width':
                case 'login_logo_height':
                    $out[ $key ] = '' === $raw ? '' : min( 1000, absint( $raw ) );
                    break;
                case 'admin_bar_logo':
                case 'admin_bar_logo_url':
                case 'footer_url':
                case 'login_logo':
                case 'login_bg_image':
                    $out[ $key ] = esc_url_raw( (string) $raw );
                    break;
                case 'footer_text':
                    $out[ $key ] = sanitize_text_field( (string) $raw );
                    break;
                default:
                    // Every remaining field is a colour.
                    $out[ $key ] = (string) sanitize_hex_color( (string) $raw );
            }
        }

        update_option( self::OPTION, $out, false );
        Drift_Website_Admin_Access::save_settings( wp_unslash( $_POST['admin_access'] ?? [] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised in save_settings().

        wp_safe_redirect( Drift_Website_Admin_Page::tab_url( 'white-label', [ 'white_label_saved' => '1' ] ) );
        exit;
    }

    /* ── Branding ────────────────────────────────────────────────────── */

    /** Removes the WordPress logo menu (About, WordPress.org, Support…). */
    public static function admin_bar_remove_wp_logo( WP_Admin_Bar $bar ): void {
        if ( '1' === self::settings()['hide_wp_branding'] ) {
            $bar->remove_node( 'wp-logo' );
        }
    }

    /** Adds the custom logo at the far left of the admin bar. */
    public static function admin_bar_logo( WP_Admin_Bar $bar ): void {
        $s = self::settings();

        if ( '' !== $s['admin_bar_logo'] ) {
            $bar->add_node( [
                'id'    => 'drift-admin-logo',
                'title' => sprintf( '<img src="%s" alt="%s">', esc_url( $s['admin_bar_logo'] ), esc_attr( get_bloginfo( 'name' ) ) ),
                'href'  => $s['admin_bar_logo_url'] ? esc_url( $s['admin_bar_logo_url'] ) : admin_url(),
                'meta'  => [ 'class' => 'drift-admin-logo' ],
            ] );
        }
    }

    /** Sizes the admin bar logo. Printed only when there's a logo and a bar. */
    public static function admin_bar_styles(): void {
        if ( ! is_admin_bar_showing() || '' === self::settings()['admin_bar_logo'] ) {
            return;
        }
        echo '<style id="drift-admin-logo-css">#wpadminbar .drift-admin-logo > .ab-item{display:flex;align-items:center;}#wpadminbar .drift-admin-logo img{display:block;height:20px;width:auto;max-width:160px;}</style>' . "\n";
    }

    /** Admin footer credit, replacing "Thank you for creating with WordPress". */
    public static function footer_text( $text ) {
        $s = self::settings();

        if ( '' === $s['footer_text'] ) {
            return '1' === $s['hide_wp_branding'] ? '' : $text;
        }

        return $s['footer_url']
            ? sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $s['footer_url'] ), esc_html( $s['footer_text'] ) )
            : esc_html( $s['footer_text'] );
    }

    /** Hides the WordPress version in the admin footer. */
    public static function footer_version( $text ) {
        return '1' === self::settings()['hide_wp_branding'] ? '' : $text;
    }

    /* ── Login screen ────────────────────────────────────────────────── */

    public static function login_logo_url(): string {
        return home_url( '/' );
    }

    public static function login_logo_text(): string {
        return get_bloginfo( 'name' );
    }

    /**
     * Login screen CSS: the default Drift design (assets/login.css), then
     * this site's White Label settings inline after it, so they win. Every
     * value is sanitised on save (hex colours, URLs, integers) and escaped
     * again here, so nothing raw reaches the stylesheet.
     */
    public static function login_styles(): void {
        $handle = 'drift-website-login';
        $file   = DRIFT_WEBSITE_DIR . 'assets/login.css';
        // File modified time as the version, so edits aren't hidden by a
        // browser-cached copy under an unchanged plugin version.
        wp_enqueue_style( $handle, DRIFT_WEBSITE_URL . 'assets/login.css', [ 'login' ], file_exists( $file ) ? (string) filemtime( $file ) : DRIFT_WEBSITE_VERSION );

        $s   = self::settings();
        $css = '';

        if ( $s['login_logo'] ) {
            $w    = absint( $s['login_logo_width'] ) ?: 320;
            $h    = absint( $s['login_logo_height'] ) ?: 115;
            $css .= sprintf(
                '.login h1 a{background-image:url("%1$s");background-size:contain;background-position:center;width:%2$dpx;height:%3$dpx;max-width:100%%;}',
                esc_url( $s['login_logo'] ),
                $w,
                $h
            );
        }

        $bg_color = sanitize_hex_color( $s['login_bg_color'] );
        if ( $bg_color ) {
            // Shorthand, so a picked colour replaces login.css's gradient.
            $css .= 'body.login{background:' . $bg_color . ';}';
        }
        if ( $s['login_bg_image'] ) {
            $css .= sprintf( 'body.login{background-image:url("%s");background-size:cover;background-position:center;background-repeat:no-repeat;}', esc_url( $s['login_bg_image'] ) );
        }

        $colour_rules = [
            // The whole card (logo, form, links) — assets/login.css makes the form itself transparent.
            'login_form_bg_color' => '.login #login{background-color:%s;}',
            'login_label_color'   => '.login form label,.login form .forgetmenot label{color:%s;}',
            'login_button_color'  => '.login .button-primary{background-color:%1$s;border-color:%1$s;}',
            'login_button_text'   => '.login .button-primary{color:%s;}',
            'login_button_hover'  => '.login .button-primary:hover,.login .button-primary:focus{background-color:%1$s;border-color:%1$s;}',
            'login_link_color'    => '.login #nav a,.login #backtoblog a,.login .privacy-policy-link{color:%s;}',
        ];

        foreach ( $colour_rules as $key => $rule ) {
            $colour = sanitize_hex_color( $s[ $key ] );
            if ( $colour ) {
                $css .= sprintf( $rule, $colour );
            }
        }

        if ( '' !== $css ) {
            wp_add_inline_style( $handle, $css );
        }
    }
}
