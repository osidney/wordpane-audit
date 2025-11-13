<?php
/**
 * Plugin Name: WordPane Audit
 * Description: Registro de ações de usuários e exclusões de posts/páginas em arquivo de log, com comando WP-CLI para consulta.
 * Author: WordPane
 * Version: 1.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WordPane_Audit' ) ) {

    class WordPane_Audit {

        /**
         * Caminho completo do arquivo de log.
         *
         * @return string
         */
        public static function get_log_file() {
            return WP_CONTENT_DIR . '/wordpane-audit.log';
        }

        /**
         * Retorna IP do cliente.
         *
         * @return string
         */
        public static function get_ip() {
            if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
                $parts = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
                return trim( $parts[0] );
            }

            if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
                return $_SERVER['REMOTE_ADDR'];
            }

            return 'unknown_ip';
        }

        /**
         * Registra uma linha no log.
         *
         * @param string $category
         * @param string $message
         */
        public static function log( $category, $message ) {
            $file  = self::get_log_file();
            $user  = wp_get_current_user();
            $uid   = ( $user && $user->ID ) ? (int) $user->ID : 0;
            $login = ( $user && $user->ID ) ? $user->user_login : 'guest/cron';
            $ip    = self::get_ip();

            $line = sprintf(
                '[%s] category=%s user=%s(ID:%d) ip=%s | %s' . PHP_EOL,
                date( 'Y-m-d H:i:s' ),
                $category,
                $login,
                $uid,
                $ip,
                $message
            );

            error_log( $line, 3, $file );
        }

        /**
         * Inicializa os hooks.
         */
        public static function init() {
            // Usuário criado.
            add_action( 'user_register', array( __CLASS__, 'on_user_register' ) );

            // Perfil atualizado.
            add_action( 'profile_update', array( __CLASS__, 'on_profile_update' ), 10, 2 );

            // Usuário deletado.
            add_action( 'delete_user', array( __CLASS__, 'on_delete_user' ) );

            // Login.
            add_action( 'wp_login', array( __CLASS__, 'on_wp_login' ), 10, 2 );

            // Exclusão de post/página.
            add_action( 'before_delete_post', array( __CLASS__, 'on_before_delete_post' ) );
        }

        /**
         * Callback: usuário criado.
         *
         * @param int $user_id
         */
        public static function on_user_register( $user_id ) {
            $user = get_userdata( $user_id );
            if ( ! $user ) {
                return;
            }

            $roles = is_array( $user->roles ) ? implode( ',', $user->roles ) : '';

            $msg = sprintf(
                'USER_REGISTER | ID=%d | login=%s | email=%s | role=%s',
                $user_id,
                $user->user_login,
                $user->user_email,
                $roles
            );

            self::log( 'user_register', $msg );
        }

        /**
         * Callback: perfil atualizado.
         *
         * @param int      $user_id
         * @param WP_User  $old_user_data
         */
        public static function on_profile_update( $user_id, $old_user_data ) {
            $user = get_userdata( $user_id );
            if ( ! $user ) {
                return;
            }

            $msg = sprintf(
                'PROFILE_UPDATE | ID=%d | login=%s | email=%s',
                $user_id,
                $user->user_login,
                $user->user_email
            );

            self::log( 'profile_update', $msg );
        }

        /**
         * Callback: usuário deletado.
         *
         * @param int $user_id
         */
        public static function on_delete_user( $user_id ) {
            $user  = get_userdata( $user_id );
            $login = $user ? $user->user_login : 'unknown';
            $mail  = $user ? $user->user_email : 'unknown';

            $msg = sprintf(
                'DELETE_USER | ID=%d | login=%s | email=%s',
                $user_id,
                $login,
                $mail
            );

            self::log( 'delete_user', $msg );
        }

        /**
         * Callback: login de usuário.
         *
         * @param string  $user_login
         * @param WP_User $user
         */
        public static function on_wp_login( $user_login, $user ) {
            $msg = sprintf(
                'LOGIN | ID=%d | login=%s | email=%s',
                $user->ID,
                $user_login,
                $user->user_email
            );

            self::log( 'login', $msg );
        }

        /**
         * Callback: antes de deletar post/página.
         *
         * @param int $post_id
         */
        public static function on_before_delete_post( $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post ) {
                return;
            }

            $msg = sprintf(
                'DELETE_POST | ID=%d | type=%s | status=%s | title=%s',
                $post_id,
                $post->post_type,
                $post->post_status,
                '"' . $post->post_title . '"'
            );

            self::log( 'delete_post', $msg );
        }
    }

    WordPane_Audit::init();
}

/**
 * Integração com WP-CLI.
 */
if ( defined( 'WP_CLI' ) && WP_CLI && ! class_exists( 'WordPane_Audit_CLI' ) ) {

    class WordPane_Audit_CLI {

        /**
         * Exibe as últimas N linhas do log de auditoria.
         *
         * Uso:
         *   wp wordpane:audit last 50
         *
         * @param array $args
         * @param array $assoc_args
         */
        public function last( $args, $assoc_args ) {
            $lines_to_show = isset( $args[0] ) ? (int) $args[0] : 50;
            if ( $lines_to_show <= 0 ) {
                $lines_to_show = 50;
            }

            $file = WordPane_Audit::get_log_file();

            if ( ! file_exists( $file ) ) {
                \WP_CLI::warning( 'Arquivo de log ainda não existe: ' . $file );
                return;
            }

            $lines = @file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
            if ( ! is_array( $lines ) || empty( $lines ) ) {
                \WP_CLI::log( 'Log vazio.' );
                return;
            }

            $slice = array_slice( $lines, - $lines_to_show );

            foreach ( $slice as $line ) {
                \WP_CLI::log( $line );
            }
        }
    }

    \WP_CLI::add_command( 'wordpane:audit', 'WordPane_Audit_CLI' );
}
