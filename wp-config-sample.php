<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * Localized language
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'local' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', 'root' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',          ';zNt YL(HC7P2!6h<sjXM|)]&Pzd~DPU* uCCPW&>InV_!:&mRHC9Q$s@c<Ig/Q4' );
define( 'SECURE_AUTH_KEY',   'FB&^)~}HGC3S!Bw#QC$G2B5f!K,.1U6vM2WgY$8+k*>,;2>ioWHSUcFm8#2{Cua]' );
define( 'LOGGED_IN_KEY',     '%F*(7X:>`Xg PfL_cz(s(~H7j5R=R{RdAljQ,I6MvmEGE7q$8S^!)!7aA4&/xdr!' );
define( 'NONCE_KEY',         'Bjxs|JkV-amJfSr,%B(|MbEq`g`*@@J6VU@}Sk<r`|_@c%z/<F=,koz-`I0ze5D:' );
define( 'AUTH_SALT',         '[js zz]7~oB.OLA%*FuZKwLKV2)kCBt8+hm:NZGt3jbOG-YBn897rr7C,q}v;!gc' );
define( 'SECURE_AUTH_SALT',  'S?ZopygerVM.-]-fnGgN(]_s?@w8|3Y+4N5RN1{e VTr,b&+Xe>y^3^:G7%$aqR@' );
define( 'LOGGED_IN_SALT',    'g(a3i`UT^sdE/Tn,<$i~%[SZXvwgV]XdmDMV9ptBJbLt/T@?!)02IN~6H 5zk|fp' );
define( 'NONCE_SALT',        'RMzN%9p[r&BtJU+ H&zAn-6_g{ifG$+igD#;,5pM.FVZz.ZS|T1#p4Jm{lzyDgd_' );
define( 'WP_CACHE_KEY_SALT', 'H0QzBnf#UL6!h.W%yi05FHptaui5D&_SmWtXpoU(`*ibbg<wq:5_b;EH5DIBW;x;' );


/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';


/* Add any custom values between this line and the "stop editing" line. */



/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', true );
}

define( 'WP_DEBUG_LOG', true );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';

/**  */
define( 'upload_max_filesize' , '1024M' );
define( 'post_max_size', '256M' );
define( 'memory_limit', '512M' );
define( 'max_execution_time', '600' );
define( 'max_input_time', '600' );

/**
 * Define constants if they are not already defined.
 * These should ideally be defined in wp-config.php for security.
 */
if ( ! defined( 'METFORM_TARGET_FORM_ID' ) ) {
  // IMPORTANT: Replace 'YOUR_METFORM_ID' with the actual ID or name of the form to target.
  // You can often find this ID in the MetForm builder URL or shortcode.
  define( 'METFORM_TARGET_FORM_ID', '646' );
}
if ( ! defined( 'LARAVEL_API_BASE_URL' ) ) {
  define( 'LARAVEL_API_BASE_URL', 'http://127.0.0.1:8001/api/v1/' ); // Example, replace with actual
}
if ( ! defined( 'LARAVEL_API_LOGIN_ENDPOINT' ) ) {
  define( 'LARAVEL_API_LOGIN_ENDPOINT', '/login' ); // Example, replace with actual
}
if ( ! defined( 'LARAVEL_API_CREATE_ENDPOINT' ) ) {
  define( 'LARAVEL_API_CREATE_ENDPOINT', '/loan-applications' ); // Example, replace with actual
}
if ( ! defined( 'LARAVEL_API_USERNAME' ) ) {
  define( 'LARAVEL_API_USERNAME', 'id.bernabel@gmail.com' ); // Example, replace with actual
}
if ( ! defined( 'LARAVEL_API_PASSWORD' ) ) {
  define( 'LARAVEL_API_PASSWORD', 'Zd/sK3iD/u53/QM' ); // Example, replace with actual
}
// Optional: Endpoint to verify token status
if ( ! defined( 'LARAVEL_API_STATUS_ENDPOINT' ) ) {
   define( 'LARAVEL_API_STATUS_ENDPOINT', '/tokenStatus' );
}