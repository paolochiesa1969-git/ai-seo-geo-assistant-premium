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
 * @package AISA_Premium
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Aisa_Premium_Updater {

	private const OWNER     = 'paolochiesa1969-git';
	private const REPO      = 'ai-seo-geo-assistant-premium';
	private const CACHE_KEY = 'aisa_premium_gh_release';
	private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	private string $file;   // percorso completo del file principale
	private string $base;   // "cartella/file.php"
	private string $slug;   // cartella

	public function __construct( string $file ) {
		$this->file = $file;
		$this->base = plugin_basename( $file );
		$this->slug = dirname( $this->base );

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api', [ $this, 'details' ], 10, 3 );
		// Dopo un aggiornamento la cache va buttata, altrimenti WordPress
		// continua a proporre la versione che hai appena installato.
		add_action( 'upgrader_process_complete', [ $this, 'flush' ], 10, 0 );
	}

	public function flush(): void {
		delete_transient( self::CACHE_KEY );
	}

	/** Ultima release dal repo pubblico. null se irraggiungibile: in quel caso non si tocca nulla. */
	private function latest(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) return $cached;

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
			set_transient( self::CACHE_KEY, [ 'version' => '', 'package' => '', 'notes' => '' ], 15 * MINUTE_IN_SECONDS );
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) ) return null;

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
		if ( '' === $version || '' === $package ) return null;

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
		$cur = defined( 'AISA_PREMIUM_VERSION' ) ? AISA_PREMIUM_VERSION : '0';
		if ( null === $rel || '' === $rel['version'] ) return $transient;

		$item = (object) [
			'id'          => self::OWNER . '/' . self::REPO,
			'slug'        => $this->slug,
			'plugin'      => $this->base,
			'new_version' => $rel['version'],
			'url'         => sprintf( 'https://github.com/%s/%s', self::OWNER, self::REPO ),
			'package'     => $rel['package'],
			'tested'      => get_bloginfo( 'version' ),
		];

		if ( version_compare( $rel['version'], (string) $cur, '>' ) ) {
			$transient->response[ $this->base ] = $item;
		} else {
			// Serve a WordPress per mostrare "Attiva aggiornamenti automatici"
			// anche quando sei già aggiornato: senza questa riga il link non compare.
			$transient->no_update[ $this->base ] = $item;
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
			'sections'      => [ 'changelog' => wpautop( esc_html( $rel['notes'] ) ) ],
		];
	}
}
