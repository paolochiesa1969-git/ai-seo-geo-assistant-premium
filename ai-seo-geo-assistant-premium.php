<?php
/**
 * Plugin Name:       AISA — AI SEO & GEO Assistant — Premium
 * Plugin URI:        https://aiseoassistant.io
 * Description:       Componente Premium di AISA — AI SEO & GEO Assistant: One-Click SEO, Bulk SEO & GEO, automazione programmata, Extended SEO & Rotation. Si installa accanto al plugin free; le funzioni si attivano con licenza valida.
 * Version:           1.99.941
 * Author:            Ingenium Project
 * Author URI:        https://ingenium-project.com
 * Text Domain:       ai-seo-geo-assistant-premium
 * Requires PHP:      8.0
 * License:           GPL-2.0-or-later
 * Copyright:         © 2026 Ingenium Project
 *
 * ARCHITETTURA 2.0 (ORG-SPLIT-PLAN.md — review .org 07/2026, Guideline 5):
 * da questa versione il codice premium VIVE QUI (prima stava nel free, gated da
 * licenza): One-Click, Bulk, Scheduler, Rotation e la licenza LemonSqueezy sono
 * classi di questo plugin (copiate in build da build-companion.sh, fonte di
 * verità = repo del free). Il free .org non contiene né limiti né license-check.
 * Questo .zip NON sta su WordPress.org: si scarica da aiseoassistant.io dopo
 * l'acquisto e si installa a mano (l'auto-installer è stato rimosso dal free).
 * Un .zip nulled senza licenza non attiva nulla: le feature bootano solo con
 * licenza valida.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'AISA_PREMIUM_VERSION', '1.99.941' );
define( 'AISA_PREMIUM_MIN_FREE', '1.99.882' ); // prima coppia ALLINEATA free/companion (la Licenza vive nel free). Free più nuovo = sempre ok.
define( 'AISA_PREMIUM_DIR', plugin_dir_path( __FILE__ ) );
define( 'AISA_PREMIUM_URL', plugin_dir_url( __FILE__ ) );

/*
 * Le classi premium (copiate dal repo free in fase di build). Require al load
 * del file — sono solo dichiarazioni, niente side effect — così il filtro
 * aisa_rotation_engine (che il free applica a plugins_loaded:10) le trova pronte
 * QUALUNQUE sia l'ordine di caricamento dei due plugin. Guardie doppie: nel repo
 * di sviluppo includes/ può mancare; nella delivery monolitica le classi
 * esistono già nel free (mai ridefinirle).
 */
// NOTA: Aisa_License NON è più qui — vive nel plugin FREE (campo licenza + attivazione online).
// Il companion la usa a runtime (class_exists/instance), non la ridichiara (evita "Cannot redeclare").
foreach ( [
	'Aisa_OneClick'  => 'includes/class-aisa-oneclick.php',
	'Aisa_Bulk'      => 'includes/class-aisa-bulk.php',
	'Aisa_Scheduler' => 'includes/class-aisa-scheduler.php',
	'Aisa_Rotation'  => 'includes/class-aisa-rotation.php',
] as $aisa_premium_class => $aisa_premium_inc ) {
	if ( ! class_exists( $aisa_premium_class ) && file_exists( AISA_PREMIUM_DIR . $aisa_premium_inc ) ) {
		require_once AISA_PREMIUM_DIR . $aisa_premium_inc;
	}
}
unset( $aisa_premium_class, $aisa_premium_inc );

final class Aisa_Premium {

	public function __construct() {
		add_action( 'plugins_loaded', [ $this, 'boot' ], 20 ); // DOPO il free (10): la licenza (Aisa_License) la istanzia il free, qui la leggiamo pronta. Il filtro rotation è nel costruttore (già pronto a :10).
		add_action( 'admin_notices', [ $this, 'requirement_notice' ] );
		// Il companion senza il plugin base non fa nulla → si auto-disattiva se il free è spento.
		add_action( 'admin_init', [ $this, 'maybe_self_deactivate' ] );
		add_action( 'admin_notices', [ $this, 'self_deactivated_notice' ] );

		/*
		 * Engine Rotation per il frontend del free: il free (≥ MIN_FREE) chiede
		 * l'engine via filtro a plugins_loaded:10. Registrato QUI al load del
		 * file così c'è sempre, in qualunque ordine i plugin vengano inclusi.
		 */
		add_filter( 'aisa_rotation_engine', static function ( $engine ) {
			if ( $engine ) return $engine; // delivery monolitica: già istanziato dal free
			if ( ! class_exists( 'Aisa_Rotation' ) || ! class_exists( 'Aisa_Dashboard' ) ) return $engine;
			if ( ! self::license_valid() ) return $engine;
			return Aisa_Dashboard::is_active( 'rotation' ) ? new Aisa_Rotation() : $engine;
		} );
	}

	/** Il plugin free è presente e abbastanza recente? */
	private function free_ok(): bool {
		return defined( 'AISA_VERSION' ) && version_compare( AISA_VERSION, AISA_PREMIUM_MIN_FREE, '>=' );
	}

	/** La licenza è valida su questo sito? (la classe Aisa_License ora vive qui). */
	public static function license_valid(): bool {
		if ( class_exists( 'Aisa_License' ) && Aisa_License::instance() ) {
			return (bool) Aisa_License::instance()->is_active();
		}
		return function_exists( 'aisa_is_premium' ) && aisa_is_premium();
	}

	public function boot(): void {
		if ( ! $this->free_ok() ) return;

		/*
		 * CONTRATTO storico free↔premium: i filtri di sblocco restano per
		 * compatibilità con i free pre-split (delivery) che gate-ano via
		 * aisa_is_unlocked/aisa_feature_allowed. Nel free .org post-split non
		 * c'è nulla da sbloccare: sono no-op innocui.
		 */
		add_filter( 'aisa_is_unlocked', static function ( $unlocked ) {
			return self::license_valid() ? true : $unlocked;
		}, 100 );
		add_filter( 'aisa_feature_allowed', static function ( $allowed, $feature ) {
			return self::license_valid() ? true : $allowed;
		}, 100, 2 );

		/*
		 * Boot delle funzioni premium — SOLO in architettura 2.0 (free .org:
		 * AISA_ORG_BUILD segnala che il free NON le istanzia lui). Nella delivery
		 * monolitica il free le istanzia già: qui non tocchiamo nulla.
		 */
		// La pagina Licenza + validazione la fornisce il plugin FREE (Aisa_License istanziata lì):
		// qui NON la ri-istanziamo. Con licenza valida eroghiamo le FUNZIONI premium.
		if ( defined( 'AISA_ORG_BUILD' ) && self::license_valid() ) {
			if ( class_exists( 'Aisa_OneClick' ) )  new Aisa_OneClick();
			if ( class_exists( 'Aisa_Bulk' ) )      new Aisa_Bulk();
			if ( class_exists( 'Aisa_Scheduler' ) ) new Aisa_Scheduler();
		}

		// Punto di registrazione di eventuali estensioni pro esterne.
		do_action( 'aisa_register_pro_features' );

		// Badge "componente Premium attivo" nella pagina Licenza.
		add_action( 'admin_notices', [ $this, 'active_badge' ] );
	}

	/**
	 * Se il plugin base (free) NON è attivo, il companion si disattiva da solo:
	 * senza il free non ha su cosa agganciarsi. (Se il free è attivo ma solo
	 * troppo vecchio, NON disattiviamo: mostriamo requirement_notice per invitare
	 * ad aggiornare.)
	 */
	public function maybe_self_deactivate(): void {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		// Il base può avere lo slug .org (aisa-…) o quello delivery (ai-seo-geo-assistant).
		if ( is_plugin_active( 'aisa-ai-seo-geo-assistant/aisa-ai-seo-geo-assistant.php' )
			|| is_plugin_active( 'ai-seo-geo-assistant/ai-seo-geo-assistant.php' ) ) return; // free attivo → ok
		if ( defined( 'AISA_VERSION' ) ) return; // il free è caricato per altra via → non toccare
		deactivate_plugins( plugin_basename( __FILE__ ) );
		set_transient( 'aisa_premium_self_deactivated', 1, 60 );
	}

	/** Avviso una tantum dopo l'auto-disattivazione. */
	public function self_deactivated_notice(): void {
		if ( ! get_transient( 'aisa_premium_self_deactivated' ) ) return;
		delete_transient( 'aisa_premium_self_deactivated' );
		if ( ! current_user_can( 'activate_plugins' ) ) return;
		echo '<div class="notice notice-warning is-dismissible"><p>'
			. esc_html__( 'AISA — AI SEO & GEO Assistant — Premium è stato disattivato automaticamente perché il plugin base "AISA — AI SEO & GEO Assistant" non è attivo. Riattiva prima il plugin base, poi il componente Premium.', 'ai-seo-geo-assistant' )
			. '</p></div>';
	}

	/** Avviso se manca/è troppo vecchio il plugin free. */
	public function requirement_notice(): void {
		if ( $this->free_ok() ) return;
		if ( ! current_user_can( 'activate_plugins' ) ) return;
		$msg = defined( 'AISA_VERSION' )
			? sprintf( /* translators: %1$s: required version; %2$s: present version */ __( 'AISA — AI SEO &amp; GEO Assistant <strong>free</strong> è troppo vecchio per il componente Premium (serve ≥ %1$s, presente %2$s). Aggiorna il plugin free.', 'ai-seo-geo-assistant' ), esc_html( AISA_PREMIUM_MIN_FREE ), esc_html( AISA_VERSION ) )
			: __( 'Il componente <strong>AISA — AI SEO &amp; GEO Assistant — Premium</strong> richiede il plugin base <strong>AISA — AI SEO &amp; GEO Assistant</strong> (free) attivo.', 'ai-seo-geo-assistant' );
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
