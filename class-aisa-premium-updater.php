<?php
/**
 * Aggiornamenti del componente AISA Premium.
 *
 * PERCHÉ ESISTE: il plugin gratuito sta su WordPress.org e si aggiorna da lì, ma
 * il componente Premium no — e finché non aveva un updater, ogni versione nuova
 * andava scaricata e ricaricata a mano. Inaccettabile per chi paga.
 *
 * PERCHÉ È LECITO: questo componente NON è ospitato su WordPress.org. La
 * Guideline 8 vieta a un plugin DELLA DIRECTORY di installare codice da server
 * esterni; a un plugin distribuito fuori dalla directory non si applica. Il
 * plugin gratuito infatti non contiene nulla di tutto questo.
 *
 * COME: legge l'ultima release dal repository PUBBLICO del componente. Nessun
 * token, nessuna autenticazione: il file è pubblico e senza licenza valida il
 * componente non attiva nulla.
 *
 * CHANGELOG
 * 2026-10-19 11:45 — (2.0.6, voce 1b, FIX-2026-10-14) caricato anche fuori da wp-admin (cron, WP-CLI,
 *   REST, scrittura del transient: vedi il bootstrap); un solo updater per richiesta (boot());
 *   versione installata letta da `checked`/header, non dalla costante; cache svuotata solo dopo
 *   l'aggiornamento di QUESTO plugin, all'attivazione e con «Controlla di nuovo»; TTL 6 h → 3 h;
 *   cache negativa di 15 min anche su risposta non JSON o release senza zip; requires_php nel pacchetto.
 *
 * @package AISA_Premium
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Aisa_Premium_Updater {

	private const OWNER     = 'paolochiesa1969-git';
	private const REPO      = 'ai-seo-geo-assistant-premium';
	private const CACHE_KEY = 'aisa_premium_gh_release';
	/*
	 * 2026-10-19 (2.0.6, voce 1b): 6 h → 3 h. GitHub si chiama SOLO quando WordPress riscrive
	 * il transient update_plugins (cron ogni 12 h, plugins.php al massimo ogni ora): con 3 h una
	 * release nuova compare entro mezza giornata e il limite anonimo di GitHub (60 chiamate/ora
	 * per IP) regge anche un server con molte installazioni sullo stesso indirizzo.
	 * «Controlla di nuovo» in Aggiornamenti svuota la cache e richiede subito.
	 */
	private const CACHE_TTL = 3 * HOUR_IN_SECONDS;
	private const FAIL_TTL  = 15 * MINUTE_IN_SECONDS; // cache negativa: GitHub giù o risposta inutilizzabile

	private static ?self $instance = null;

	private string $file;   // percorso completo del file principale
	private string $base;   // "cartella/file.php"
	private string $slug;   // cartella

	/** Un solo updater per richiesta, qualunque sia il punto che lo carica (admin, cron, CLI, REST, filtro). */
	public static function boot( string $file ): self {
		if ( null === self::$instance ) {
			self::$instance = new self( $file );
		}
		return self::$instance;
	}

	public function __construct( string $file ) {
		$this->file = $file;
		$this->base = plugin_basename( $file );
		$this->slug = dirname( $this->base );

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api', [ $this, 'details' ], 10, 3 );
		// Dopo l'aggiornamento DI QUESTO plugin la cache va buttata, altrimenti WordPress
		// continua a proporre la versione che hai appena installato.
		add_action( 'upgrader_process_complete', [ $this, 'on_upgraded' ], 10, 2 );
		// «Controlla di nuovo» (update-core.php?force-check=1): prima del controllo di WordPress (priorità 10).
		add_action( 'load-update-core.php', [ $this, 'maybe_force_check' ], 0 );
	}

	/**
	 * Svuota la cache della release (solo un transient di cache, nessun dato di lavoro).
	 * Statico: lo usa anche l'attivazione del plugin, quando l'updater non è ancora istanziato.
	 */
	public static function purge(): void {
		delete_transient( self::CACHE_KEY );
	}

	/** Compatibilità con chi chiamava ->flush() (prima del 2026-10-19). */
	public function flush(): void {
		self::purge();
	}

	/**
	 * upgrader_process_complete: si svuota la cache solo se tra i plugin aggiornati c'è questo
	 * (aggiornamento singolo, di massa o «sostituisci con il caricato»).
	 *
	 * @param mixed $upgrader
	 * @param mixed $extra
	 */
	public function on_upgraded( $upgrader = null, $extra = [] ): void {
		$extra = is_array( $extra ) ? $extra : [];
		if ( 'plugin' !== ( $extra['type'] ?? '' ) ) return;
		$list = array_map( 'strval', (array) ( $extra['plugins'] ?? [] ) );
		if ( isset( $extra['plugin'] ) ) $list[] = (string) $extra['plugin'];
		// Caricamento di uno zip (action=install): il basename non c'è, ma può essere questo plugin.
		if ( in_array( $this->base, $list, true ) || 'install' === ( $extra['action'] ?? '' ) ) {
			self::purge();
		}
	}

	/** «Controlla di nuovo» nella pagina Aggiornamenti: si richiede GitHub subito, non fra 3 ore. */
	public function maybe_force_check(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo lettura del flag, come fa il core (nessun dato modificato: si svuota una cache).
		if ( empty( $_GET['force-check'] ) || ! current_user_can( 'update_plugins' ) ) return;
		self::purge();
	}

	/** Versione installata: quella che WordPress ha appena letto dall'header, poi il file, poi la costante. */
	private function installed_version( $transient ): string {
		if ( is_object( $transient ) && isset( $transient->checked ) && is_array( $transient->checked ) && ! empty( $transient->checked[ $this->base ] ) ) {
			return (string) $transient->checked[ $this->base ];
		}
		if ( function_exists( 'get_file_data' ) && is_readable( $this->file ) ) {
			$h = get_file_data( $this->file, [ 'Version' => 'Version' ] );
			if ( ! empty( $h['Version'] ) ) return (string) $h['Version'];
		}
		return defined( 'AISA_PREMIUM_VERSION' ) ? (string) AISA_PREMIUM_VERSION : '0';
	}

	/** Cache negativa breve: senza, ogni scrittura del transient richiamerebbe GitHub (rate limit). */
	private function remember_failure(): void {
		set_transient( self::CACHE_KEY, [ 'version' => '', 'package' => '', 'notes' => '' ], self::FAIL_TTL );
	}

	/** Ultima release dal repo pubblico. null se irraggiungibile: in quel caso non si tocca nulla. */
	private function latest(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			// Cache negativa (o voce rovinata): niente aggiornamento fino alla scadenza, nessun avviso.
			return ( '' !== (string) ( $cached['version'] ?? '' ) && '' !== (string) ( $cached['package'] ?? '' ) ) ? $cached : null;
		}

		$res = wp_remote_get(
			sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', self::OWNER, self::REPO ),
			[
				'timeout' => 12,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'AISA-Premium-Updater',
				],
			]
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			// Cache breve anche sull'errore: senza, ogni caricamento della pagina
			// plugin richiamerebbe GitHub e finiremmo nel rate limit.
			$this->remember_failure();
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) ) {
			$this->remember_failure();
			return null;
		}

		$version = ltrim( (string) ( $body['tag_name'] ?? '' ), 'vV' );
		$package = '';
		foreach ( (array) ( $body['assets'] ?? [] ) as $a ) {
			$name = (string) ( $a['name'] ?? '' );
			// Solo lo zip del componente: gli archivi "Source code" che GitHub
			// allega a ogni release contengono il caricatore, non il plugin.
			if ( str_ends_with( $name, '.zip' ) && false !== strpos( $name, self::REPO ) ) {
				$package = (string) ( $a['browser_download_url'] ?? '' );
				break;
			}
		}
		if ( '' === $version || '' === $package ) {
			$this->remember_failure(); // release senza lo zip del componente: si riprova fra 15 minuti
			return null;
		}

		$out = [
			'version' => $version,
			'package' => $package,
			'notes'   => (string) ( $body['body'] ?? '' ),
		];
		set_transient( self::CACHE_KEY, $out, self::CACHE_TTL );
		return $out;
	}

	/** @param mixed $transient */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) return $transient;

		$rel = $this->latest();
		if ( null === $rel ) return $transient;
		// 2026-10-19: la versione installata viene da `checked` (header letto da WordPress), non dalla
		// costante: dopo un aggiornamento nella stessa richiesta la costante in memoria è quella vecchia.
		$cur = $this->installed_version( $transient );

		$item = (object) [
			'id'          => self::OWNER . '/' . self::REPO,
			'slug'        => $this->slug,
			'plugin'      => $this->base,
			'new_version' => $rel['version'],
			'url'         => sprintf( 'https://github.com/%s/%s', self::OWNER, self::REPO ),
			'package'     => $rel['package'],
			'tested'      => get_bloginfo( 'version' ),
			'requires_php' => '8.0',
			'icons'       => $this->icons(),
		];

		if ( version_compare( $rel['version'], $cur, '>' ) ) {
			$transient->response[ $this->base ] = $item;
			if ( isset( $transient->no_update ) && is_array( $transient->no_update ) ) unset( $transient->no_update[ $this->base ] );
		} else {
			// Serve a WordPress per mostrare "Attiva aggiornamenti automatici"
			// anche quando sei già aggiornato: senza questa riga il link non compare.
			$transient->no_update[ $this->base ] = $item;
			if ( isset( $transient->response ) && is_array( $transient->response ) ) unset( $transient->response[ $this->base ] );
		}
		return $transient;
	}

	/** Scheda "Visualizza dettagli". @param mixed $result @param string $action @param mixed $args */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}
		$rel = $this->latest();
		if ( null === $rel ) return $result;

		return (object) [
			'name'          => 'AISA — AI SEO & GEO Assistant — Premium',
			'slug'          => $this->slug,
			'version'       => $rel['version'],
			'author'        => '<a href="https://ingenium-project.com">Ingenium Project</a>',
			'homepage'      => 'https://aiseoassistant.io/',
			'download_link' => $rel['package'],
			'icons'         => $this->icons(),
			'sections'      => [ 'changelog' => wpautop( esc_html( $rel['notes'] ) ) ],
		];
	}

	/**
	 * Icona del companion (stessa del plugin free + pillola "Premium"): senza queste
	 * URL WordPress mostra il riquadro vuoto in Aggiornamenti e in "Visualizza dettagli".
	 * @return array<string,string>
	 */
	private function icons(): array {
		return [
			'1x'      => plugins_url( 'assets/icon-premium-128x128.png', __FILE__ ),
			'2x'      => plugins_url( 'assets/icon-premium-256x256.png', __FILE__ ),
			'default' => plugins_url( 'assets/icon-premium-256x256.png', __FILE__ ),
		];
	}
}
