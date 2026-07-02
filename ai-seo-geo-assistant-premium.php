<?php
/**
 * Plugin Name:       AI SEO & GEO Assistant — Premium
 * Plugin URI:        https://aiseoassistant.io
 * Description:       Componente Premium di AI SEO & GEO Assistant. Si installa accanto al plugin free e ne sblocca le funzioni professionali quando la licenza è attiva. Viene scaricato e installato automaticamente all'attivazione di una licenza valida.
 * Version:           1.99.866
 * Author:            Ingenium Project
 * Author URI:        https://ingenium-project.com
 * Text Domain:       ai-seo-geo-assistant-premium
 * Requires PHP:      8.0
 * License:           GPL-2.0-or-later
 * Copyright:         © 2026 Ingenium Project
 *
 * NOTA ARCHITETTURA (LICENSING-TWO-PLUGIN.md): questo .zip NON sta su WordPress.org.
 * Viene consegnato dopo l'acquisto e auto-installato dal free quando la licenza è
 * valida. Il free espone i ganci (aisa_is_unlocked / aisa_feature_allowed); qui li
 * agganciamo. Lo sblocco resta condizionato alla licenza valida — un .zip nulled
 * senza licenza non sblocca nulla.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'AISA_PREMIUM_VERSION', '1.99.866' );
define( 'AISA_PREMIUM_MIN_FREE', '1.99.700' );

final class Aisa_Premium {

	public function __construct() {
		add_action( 'plugins_loaded', [ $this, 'boot' ], 20 );
		add_action( 'admin_notices', [ $this, 'requirement_notice' ] );
	}

	/** Il plugin free è presente e abbastanza recente? */
	private function free_ok(): bool {
		return defined( 'AISA_VERSION' ) && version_compare( AISA_VERSION, AISA_PREMIUM_MIN_FREE, '>=' );
	}

	/** La licenza è valida su questo sito? (delega al free, unica fonte di verità). */
	public static function license_valid(): bool {
		if ( class_exists( 'Aisa_License' ) && Aisa_License::instance() ) {
			return (bool) Aisa_License::instance()->is_active();
		}
		// Fallback: il free non ancora caricato → usa il filtro premium se disponibile.
		return function_exists( 'aisa_is_premium' ) && aisa_is_premium();
	}

	public function boot(): void {
		if ( ! $this->free_ok() ) return;

		// CONTRATTO: il componente premium è installato → conferma lo sblocco,
		// ma SOLO con licenza valida (no licenza = nessuno sblocco anche se installato).
		add_filter( 'aisa_is_unlocked', static function ( $unlocked ) {
			return self::license_valid() ? true : $unlocked;
		}, 100 );

		add_filter( 'aisa_feature_allowed', static function ( $allowed, $feature ) {
			return self::license_valid() ? true : $allowed;
		}, 100, 2 );

		// Punto di registrazione delle funzioni pro (oggi vivono ancora nel free,
		// gated da licenza; il gancio è pronto per lo spacchettamento futuro — STEP 3).
		do_action( 'aisa_register_pro_features' );

		// Badge "componente Premium attivo" nella pagina Licenza.
		add_action( 'admin_notices', [ $this, 'active_badge' ] );
	}

	/** Avviso se manca/è troppo vecchio il plugin free. */
	public function requirement_notice(): void {
		if ( $this->free_ok() ) return;
		if ( ! current_user_can( 'activate_plugins' ) ) return;
		$msg = defined( 'AISA_VERSION' )
			? sprintf( /* translators: %1$s: required version; %2$s: present version */ __( 'AI SEO &amp; GEO Assistant <strong>free</strong> è troppo vecchio per il componente Premium (serve ≥ %1$s, presente %2$s). Aggiorna il plugin free.', 'ai-seo-geo-assistant' ), esc_html( AISA_PREMIUM_MIN_FREE ), esc_html( AISA_VERSION ) )
			: __( 'Il componente <strong>AI SEO &amp; GEO Assistant — Premium</strong> richiede il plugin base <strong>AI SEO &amp; GEO Assistant</strong> (free) attivo.', 'ai-seo-geo-assistant' );
		echo '<div class="notice notice-warning"><p>' . $msg . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Conferma visiva che il componente premium è agganciato (solo pagina Licenza). */
	public function active_badge(): void {
		if ( ! function_exists( 'get_current_screen' ) ) return;
		$screen = get_current_screen();
		if ( ! $screen || strpos( (string) $screen->id, 'aisa-license' ) === false ) return;
		$on = self::license_valid();
		printf(
			'<div class="notice notice-%s"><p>%s</p></div>',
			$on ? 'success' : 'info',
			$on
				? sprintf( /* translators: %s: version */ __( '🧩 Componente <strong>Premium</strong> installato e attivo (v%s) — funzioni professionali sbloccate.', 'ai-seo-geo-assistant' ), esc_html( AISA_PREMIUM_VERSION ) )
				: sprintf( /* translators: %s: version */ __( '🧩 Componente <strong>Premium</strong> installato (v%s). In attesa di una licenza valida per sbloccare le funzioni.', 'ai-seo-geo-assistant' ), esc_html( AISA_PREMIUM_VERSION ) )
		);
	}
}

new Aisa_Premium();
