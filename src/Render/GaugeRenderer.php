<?php
/**
 * The HTML for the block and the shortcode.
 *
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions\Render;

use RiverConditions\Cache\GaugeRepository;
use RiverConditions\Cache\GaugeResult;
use RiverConditions\Domain\GaugeReading;
use RiverConditions\Domain\Measurement;
use RiverConditions\Domain\Trend;

/**
 * Renders one gauge. The block (render.php), the shortcode and the editor
 * preview endpoint all call render(), so the markup exists in one place.
 */
class GaugeRenderer {

	/** Data older than this is shown as stale (seconds). */
	public const STALE_AFTER = 3 * HOUR_IN_SECONDS;

	/** Readings older than this show a date, not just a time, in the stale notice. */
	private const SAME_DAY = 20 * HOUR_IN_SECONDS;

	/**
	 * Returns the current Unix time.
	 *
	 * @var \Closure(): int
	 */
	private \Closure $clock;

	/**
	 * Constructor.
	 *
	 * @param GaugeRepository                                  $repository Data source.
	 * @param array{temp_unit: string, show_attribution: bool} $settings   Plugin settings.
	 * @param (\Closure(): int)|null                           $clock      Current time, for tests.
	 */
	public function __construct(
		private readonly GaugeRepository $repository,
		private readonly array $settings,
		?\Closure $clock = null,
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	/**
	 * Default block attributes. Keep in sync with blocks/gauge/block.json.
	 *
	 * @return array{siteId: string, title: string, layout: string, showFlow: bool, showGageHeight: bool, showTemp: bool, showSparkline: bool, tempUnit: string}
	 */
	public static function defaults(): array {
		return array(
			'siteId'         => '',
			'title'          => '',
			'layout'         => 'card',
			'showFlow'       => true,
			'showGageHeight' => true,
			'showTemp'       => true,
			'showSparkline'  => true,
			'tempUnit'       => '',
		);
	}

	/**
	 * Validate and fill in attributes. Unknown keys are dropped.
	 *
	 * @param array<string, mixed> $attributes Raw attributes (block or shortcode).
	 * @return array{siteId: string, title: string, layout: string, showFlow: bool, showGageHeight: bool, showTemp: bool, showSparkline: bool, tempUnit: string}
	 */
	public static function normalize( array $attributes ): array {
		$out = self::defaults();

		$out['siteId'] = strtoupper( trim( (string) ( $attributes['siteId'] ?? '' ) ) );
		// Accept a bare site number ("06037500") as a convenience.
		if ( 1 === preg_match( '/^\d{8,15}$/', $out['siteId'] ) ) {
			$out['siteId'] = 'USGS-' . $out['siteId'];
		}
		$out['title']  = trim( wp_strip_all_tags( (string) ( $attributes['title'] ?? '' ) ) );
		$out['layout'] = 'compact' === ( $attributes['layout'] ?? '' ) ? 'compact' : 'card';

		foreach ( array( 'showFlow', 'showGageHeight', 'showTemp', 'showSparkline' ) as $key ) {
			if ( array_key_exists( $key, $attributes ) ) {
				$out[ $key ] = self::to_bool( $attributes[ $key ] );
			}
		}

		$unit            = strtoupper( (string) ( $attributes['tempUnit'] ?? '' ) );
		$out['tempUnit'] = in_array( $unit, array( 'F', 'C' ), true ) ? $unit : '';
		return $out;
	}

	/**
	 * Render a gauge.
	 *
	 * @param array<string, mixed> $attributes         Block-style (camelCase) attributes.
	 * @param string               $wrapper_attributes Extra attributes from get_block_wrapper_attributes(); must include the class.
	 * @param bool                 $is_editor          True for the editor preview, where invalid sites show a notice.
	 */
	public function render( array $attributes, string $wrapper_attributes = '', bool $is_editor = false ): string {
		$a = self::normalize( $attributes );

		if ( '' === $a['siteId'] ) {
			return $is_editor ? $this->notice( __( 'Enter a USGS site ID to show a gauge.', 'river-conditions' ) ) : '';
		}

		$result = $this->repository->get( $a['siteId'] );

		if ( GaugeResult::INVALID === $result->status ) {
			if ( $is_editor ) {
				return $this->notice(
					sprintf(
						/* translators: %s: the site ID the editor entered. */
						__( 'No USGS gauge found for “%s”. Site IDs look like USGS-06037500.', 'river-conditions' ),
						$a['siteId']
					)
				);
			}
			return '<!-- River Conditions: no USGS gauge for ' . esc_html( (string) preg_replace( '/[^A-Za-z0-9\-]/', '', $a['siteId'] ) ) . ' -->';
		}

		$classes = 'rivcon-gauge' . ( 'compact' === $a['layout'] ? ' rivcon-gauge--compact' : '' );
		$open    = '' !== $wrapper_attributes
			? '<figure ' . $wrapper_attributes . '>'
			: '<figure class="' . esc_attr( $classes ) . '">';

		if ( null === $result->reading || ! $this->has_any_value( $result->reading ) ) {
			$name = '' !== $a['title'] ? $a['title'] : ( null !== $result->reading ? Format::site_name( $result->reading->name ) : $a['siteId'] );
			$body = 'compact' === $a['layout']
				? $this->compact_unavailable( $name, $a['siteId'] )
				: $this->card_unavailable( $name, $result->reading, $a['siteId'] );
			return $open . $body . '</figure>';
		}

		$view = $this->view( $result, $result->reading, $a );
		$body = 'compact' === $a['layout'] ? $this->compact( $view ) : $this->card( $view );
		return $open . $body . '</figure>';
	}

	/**
	 * CSS classes for the wrapper, for callers that build it themselves.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 */
	public static function classes( array $attributes ): string {
		$a = self::normalize( $attributes );
		return 'rivcon-gauge' . ( 'compact' === $a['layout'] ? ' rivcon-gauge--compact' : '' );
	}

	/**
	 * Work out everything the templates show. All strings here are raw;
	 * templates escape them.
	 *
	 * @param GaugeResult                                                                                                                                       $result Repository result.
	 * @param GaugeReading                                                                                                                                      $r      The reading in that result.
	 * @param array{siteId: string, title: string, layout: string, showFlow: bool, showGageHeight: bool, showTemp: bool, showSparkline: bool, tempUnit: string} $a      Normalized attributes.
	 * @return array<string, mixed>
	 */
	private function view( GaugeResult $result, GaugeReading $r, array $a ): array {
		$now    = ( $this->clock )();
		$latest = $r->latest_time() ?? $r->fetched_at;
		$age    = $now - $latest;
		$stale  = GaugeResult::FALLBACK === $result->status || $age >= self::STALE_AFTER;
		$unit   = '' !== $a['tempUnit'] ? $a['tempUnit'] : ( 'C' === $this->settings['temp_unit'] ? 'C' : 'F' );
		$trend  = $stale ? null : Trend::from_series( $r->flow_series );

		$stats = array();
		if ( $a['showGageHeight'] && null !== $r->gage_height ) {
			$stats[] = array(
				'label' => __( 'Gage height', 'river-conditions' ),
				'value' => Format::gage_height( $r->gage_height->value, $this->decimal() ) . ' ft',
			);
		}
		if ( $a['showTemp'] && null !== $r->water_temp ) {
			$stats[] = array(
				'label' => __( 'Water temp', 'river-conditions' ),
				'value' => Format::temperature( $r->water_temp->value, $unit, $this->decimal() ) . ' °' . $unit,
			);
		}

		$flow = ( $a['showFlow'] && null !== $r->flow ) ? Format::flow( $r->flow->value, $this->thousands() ) : null;

		$spark = null;
		if ( ! $stale && $a['showSparkline'] && $a['showFlow'] && 'card' === $a['layout'] ) {
			$spark = Sparkline::build( $r->flow_series );
		}

		return array(
			'site_id'     => $r->site_id,
			'name'        => '' !== $a['title'] ? $a['title'] : Format::site_name( $r->name, '' !== $r->state ),
			'state'       => $r->state,
			'flow'        => $flow,
			'trend'       => null !== $flow ? $trend : null,
			'stats'       => $stats,
			'spark'       => $spark,
			'stale'       => $stale,
			'fallback'    => GaugeResult::FALLBACK === $result->status,
			'latest'      => $latest,
			'age'         => $age,
			'provisional' => ! $stale && $this->shown_provisional( $r, $a ),
		);
	}

	/**
	 * Card layout.
	 *
	 * @param array<string, mixed> $v View data.
	 */
	private function card( array $v ): string {
		$html  = '<figcaption class="rivcon-gauge__head"><div>';
		$html .= '<div class="rivcon-gauge__name">' . esc_html( $v['name'] ) . '</div>';
		$html .= '<div class="rivcon-gauge__meta">' . esc_html( $this->meta( $v['state'], $v['site_id'] ) ) . '</div>';
		$html .= '</div>';
		if ( ! $v['stale'] ) {
			$html .= '<span class="rivcon-gauge__tag">' . esc_html__( 'Live', 'river-conditions' ) . '</span>';
		}
		$html .= '</figcaption>';

		if ( $v['stale'] ) {
			$html .= '<div class="rivcon-gauge__alert" role="status">' . esc_html( $this->stale_message( $v ) ) . '</div>';
		}

		if ( null !== $v['flow'] ) {
			$html .= '<div class="rivcon-gauge__primary">';
			$html .= '<span class="rivcon-gauge__value">' . esc_html( $v['flow'] ) . '</span>';
			$html .= '<span class="rivcon-gauge__unit">' . esc_html__( 'cfs', 'river-conditions' ) . '</span>';
			$html .= $this->trend_chip( $v['trend'] );
			$html .= '</div>';
		}

		if ( $v['stats'] ) {
			$html .= '<dl class="rivcon-gauge__stats">';
			foreach ( $v['stats'] as $stat ) {
				$html .= '<div class="rivcon-gauge__stat"><dt>' . esc_html( $stat['label'] ) . '</dt><dd>' . esc_html( $stat['value'] ) . '</dd></div>';
			}
			$html .= '</dl>';
		}

		if ( null !== $v['spark'] ) {
			$html .= $this->sparkline( $v['spark'], $v['trend'] );
		}

		$html .= '<div class="rivcon-gauge__foot"><span>' . $this->freshness( $v ) . '</span>';
		$html .= $this->source_link( $v['site_id'], __( 'Source: USGS', 'river-conditions' ), false );
		$html .= '</div>';
		return $html;
	}

	/**
	 * Compact (single row) layout.
	 *
	 * @param array<string, mixed> $v View data.
	 */
	private function compact( array $v ): string {
		$html  = '<figcaption class="rivcon-gauge__name">' . esc_html( $v['name'] ) . '</figcaption>';
		$html .= '<span class="rivcon-gauge__sep" aria-hidden="true"></span>';

		if ( null !== $v['flow'] ) {
			$html .= '<span><span class="rivcon-gauge__value">' . esc_html( $v['flow'] ) . '</span> <span class="rivcon-gauge__unit">' . esc_html__( 'cfs', 'river-conditions' ) . '</span></span>';
			$html .= $this->trend_chip( $v['trend'] );
		}

		$bits = array_column( $v['stats'], 'value' );
		if ( $v['stale'] ) {
			$html .= '<span class="rivcon-gauge__meta">' . esc_html( implode( ' · ', $bits ) ) . '</span>';
			$html .= '<span class="rivcon-gauge__flag" role="status">' . esc_html( $this->stale_message( $v ) ) . '</span>';
		} else {
			$bits[] = $this->age_text( (int) $v['age'] );
			$html  .= '<span class="rivcon-gauge__meta">' . esc_html( implode( ' · ', $bits ) ) . '</span>';
		}

		$html .= $this->source_link( $v['site_id'], __( 'USGS', 'river-conditions' ), false, 'rivcon-gauge__src' );
		return $html;
	}

	/**
	 * Card shown when there is no data and no cache.
	 *
	 * @param string            $name    Display name.
	 * @param GaugeReading|null $reading Reading with no values, if any.
	 * @param string            $site_id Site ID.
	 */
	private function card_unavailable( string $name, ?GaugeReading $reading, string $site_id ): string {
		$message = null === $reading
			? __( 'Live conditions are temporarily unavailable.', 'river-conditions' )
			: __( 'This gauge has no current flow, gage height or temperature readings.', 'river-conditions' );

		$html  = '<figcaption class="rivcon-gauge__head"><div>';
		$html .= '<div class="rivcon-gauge__name">' . esc_html( $name ) . '</div>';
		$html .= '<div class="rivcon-gauge__meta">' . esc_html( $this->meta( $reading->state ?? '', $site_id ) ) . '</div>';
		$html .= '</div></figcaption>';
		$html .= '<div class="rivcon-gauge__alert" role="status">' . esc_html( $message ) . '</div>';
		$html .= '<div class="rivcon-gauge__foot"><span>' . esc_html__( 'Check the gauge directly', 'river-conditions' ) . '</span>';
		$html .= $this->source_link( $site_id, __( 'View on USGS', 'river-conditions' ), true );
		$html .= '</div>';
		return $html;
	}

	/**
	 * Compact row shown when there is no data and no cache.
	 *
	 * @param string $name    Display name.
	 * @param string $site_id Site ID.
	 */
	private function compact_unavailable( string $name, string $site_id ): string {
		$html  = '<figcaption class="rivcon-gauge__name">' . esc_html( $name ) . '</figcaption>';
		$html .= '<span class="rivcon-gauge__sep" aria-hidden="true"></span>';
		$html .= '<span class="rivcon-gauge__flag" role="status">' . esc_html__( 'Live conditions are temporarily unavailable.', 'river-conditions' ) . '</span>';
		$html .= $this->source_link( $site_id, __( 'USGS', 'river-conditions' ), true, 'rivcon-gauge__src' );
		return $html;
	}

	/**
	 * Inline notice for editors.
	 *
	 * @param string $message Message.
	 */
	private function notice( string $message ): string {
		return '<div class="rivcon-gauge rivcon-gauge--notice"><div class="rivcon-gauge__alert" role="status">' . esc_html( $message ) . '</div></div>';
	}

	/**
	 * Trend chip, or nothing.
	 *
	 * @param string|null $trend Trend constant.
	 */
	private function trend_chip( ?string $trend ): string {
		if ( null === $trend ) {
			return '';
		}
		$arrows = array(
			Trend::RISING  => '↑',
			Trend::FALLING => '↓',
			Trend::STEADY  => '→',
		);
		return sprintf(
			'<span class="rivcon-gauge__trend rivcon-gauge__trend--%1$s"><span aria-hidden="true">%2$s</span> %3$s</span>',
			esc_attr( $trend ),
			esc_html( $arrows[ $trend ] ),
			esc_html( $this->trend_label( $trend ) )
		);
	}

	/**
	 * Inline SVG sparkline.
	 *
	 * @param array{line: string, area: string, last_x: float, last_y: float, min: float, max: float} $spark Path data.
	 * @param string|null                                                                             $trend Trend constant.
	 */
	private function sparkline( array $spark, ?string $trend ): string {
		$label = null === $trend
			? sprintf(
				/* translators: 1: lowest flow, 2: highest flow. */
				__( 'Flow over the last 7 days, from %1$s to %2$s cfs', 'river-conditions' ),
				Format::flow( $spark['min'], $this->thousands() ),
				Format::flow( $spark['max'], $this->thousands() )
			)
			: sprintf(
				/* translators: 1: lowest flow, 2: highest flow, 3: trend such as "falling". */
				__( 'Flow over the last 7 days, from %1$s to %2$s cfs, currently %3$s', 'river-conditions' ),
				Format::flow( $spark['min'], $this->thousands() ),
				Format::flow( $spark['max'], $this->thousands() ),
				strtolower( $this->trend_label( $trend ) )
			);

		return sprintf(
			'<div><svg class="rivcon-gauge__spark" viewBox="0 0 %1$d %2$d" preserveAspectRatio="none" role="img" aria-label="%3$s">'
			. '<path d="%4$s" fill="var(--rivcon-subtle)"></path>'
			. '<path d="%5$s" fill="none" stroke="var(--rivcon-accent)" stroke-width="2" vector-effect="non-scaling-stroke"></path>'
			. '<circle cx="%6$s" cy="%7$s" r="3" fill="var(--rivcon-accent)"></circle>'
			. '</svg><div class="rivcon-gauge__spark-axis" aria-hidden="true"><span>%8$s</span><span>%9$s</span></div></div>',
			Sparkline::WIDTH,
			Sparkline::HEIGHT,
			esc_attr( $label ),
			esc_attr( $spark['area'] ),
			esc_attr( $spark['line'] ),
			esc_attr( (string) $spark['last_x'] ),
			esc_attr( (string) $spark['last_y'] ),
			esc_html__( '7 days ago', 'river-conditions' ),
			esc_html__( 'Now', 'river-conditions' )
		);
	}

	/**
	 * Footer freshness text (already escaped HTML).
	 *
	 * @param array<string, mixed> $v View data.
	 */
	private function freshness( array $v ): string {
		if ( $v['stale'] ) {
			return esc_html(
				sprintf(
					/* translators: %s: age such as "7 hrs ago". */
					__( 'Last reading %s', 'river-conditions' ),
					$this->age_text( (int) $v['age'] )
				)
			);
		}

		$html = esc_html(
			sprintf(
				/* translators: %s: age such as "25 min ago". */
				__( 'Updated %s', 'river-conditions' ),
				$this->age_text( (int) $v['age'] )
			)
		);
		if ( $v['provisional'] ) {
			$tip   = wp_unique_id( 'rivcon-prov-' );
			$html .= ' · <span class="rivcon-gauge__prov" tabindex="0" aria-describedby="' . esc_attr( $tip ) . '">'
				. esc_html__( 'Provisional', 'river-conditions' )
				. '<span class="rivcon-gauge__tip" role="tooltip" id="' . esc_attr( $tip ) . '">'
				. esc_html__( 'Provisional data, subject to revision by USGS.', 'river-conditions' )
				. '</span></span>';
		}
		return $html;
	}

	/**
	 * Notice text for the stale state.
	 *
	 * @param array<string, mixed> $v View data.
	 */
	private function stale_message( array $v ): string {
		$when = (int) $v['age'] < self::SAME_DAY
			? wp_date( (string) get_option( 'time_format', 'g:i a' ), (int) $v['latest'] )
			: wp_date( _x( 'M j, g:i a', 'date and time of the last reading', 'river-conditions' ), (int) $v['latest'] );

		if ( $v['fallback'] && (int) $v['age'] < self::STALE_AFTER ) {
			return sprintf(
				/* translators: %s: time of the last reading. */
				__( 'Live conditions are temporarily unavailable. Showing the reading from %s.', 'river-conditions' ),
				(string) $when
			);
		}
		return sprintf(
			/* translators: %s: time of the last reading. */
			__( 'This gauge hasn’t reported since %s. Showing the last reading.', 'river-conditions' ),
			(string) $when
		);
	}

	/**
	 * Link to the USGS site page, if attribution is on (or forced).
	 *
	 * @param string $site_id Site ID.
	 * @param string $text    Link text.
	 * @param bool   $force   Show even when attribution is off (unavailable state).
	 * @param string $css_class CSS class.
	 */
	private function source_link( string $site_id, string $text, bool $force, string $css_class = '' ): string {
		if ( ! $force && ! $this->settings['show_attribution'] ) {
			return '';
		}
		return sprintf(
			'<a%1$s href="%2$s" target="_blank" rel="noopener noreferrer">%3$s <span aria-hidden="true">↗</span><span class="screen-reader-text"> %4$s</span></a>',
			'' !== $css_class ? ' class="' . esc_attr( $css_class ) . '"' : '',
			esc_url( self::usgs_url( $site_id ) ),
			esc_html( $text ),
			esc_html__( '(opens in a new tab)', 'river-conditions' )
		);
	}

	/**
	 * Public USGS page for a site.
	 *
	 * @param string $site_id USGS-prefixed site ID.
	 */
	public static function usgs_url( string $site_id ): string {
		return 'https://waterdata.usgs.gov/monitoring-location/' . rawurlencode( $site_id ) . '/';
	}

	/**
	 * "Montana · USGS-06037500".
	 *
	 * @param string $state   State name.
	 * @param string $site_id Site ID.
	 */
	private function meta( string $state, string $site_id ): string {
		return '' !== $state ? $state . ' · ' . $site_id : $site_id;
	}

	/**
	 * "25 min ago", "7 hrs ago", "3 days ago", "just now".
	 *
	 * @param int $seconds Age in seconds.
	 */
	private function age_text( int $seconds ): string {
		[ $count, $unit ] = Format::age( $seconds );
		if ( 'min' === $unit && 0 === $count ) {
			return __( 'just now', 'river-conditions' );
		}
		return match ( $unit ) {
			/* translators: %d: number of minutes. */
			'min'   => sprintf( _n( '%d min ago', '%d min ago', $count, 'river-conditions' ), $count ),
			/* translators: %d: number of hours. */
			'hour'  => sprintf( _n( '%d hr ago', '%d hrs ago', $count, 'river-conditions' ), $count ),
			/* translators: %d: number of days. */
			default => sprintf( _n( '%d day ago', '%d days ago', $count, 'river-conditions' ), $count ),
		};
	}

	/**
	 * Translated trend label.
	 *
	 * @param string $trend Trend constant.
	 */
	private function trend_label( string $trend ): string {
		return match ( $trend ) {
			Trend::RISING  => __( 'Rising', 'river-conditions' ),
			Trend::FALLING => __( 'Falling', 'river-conditions' ),
			default        => __( 'Steady', 'river-conditions' ),
		};
	}

	/**
	 * Whether a reading has at least one value.
	 *
	 * @param GaugeReading $r Reading.
	 */
	private function has_any_value( GaugeReading $r ): bool {
		return null !== $r->flow || null !== $r->gage_height || null !== $r->water_temp;
	}

	/**
	 * Whether any value we actually display is provisional.
	 *
	 * @param GaugeReading                                                                                                                                      $r Reading.
	 * @param array{siteId: string, title: string, layout: string, showFlow: bool, showGageHeight: bool, showTemp: bool, showSparkline: bool, tempUnit: string} $a Attributes.
	 */
	private function shown_provisional( GaugeReading $r, array $a ): bool {
		$shown = array_filter(
			array(
				$a['showFlow'] ? $r->flow : null,
				$a['showGageHeight'] ? $r->gage_height : null,
				$a['showTemp'] ? $r->water_temp : null,
			)
		);
		foreach ( $shown as $m ) {
			if ( $m->provisional ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Locale thousands separator.
	 */
	private function thousands(): string {
		global $wp_locale;
		return isset( $wp_locale->number_format['thousands_sep'] ) ? (string) $wp_locale->number_format['thousands_sep'] : ',';
	}

	/**
	 * Locale decimal point.
	 */
	private function decimal(): string {
		global $wp_locale;
		return isset( $wp_locale->number_format['decimal_point'] ) ? (string) $wp_locale->number_format['decimal_point'] : '.';
	}

	/**
	 * Interpret shortcode-style booleans ("false", "0", "no", "off").
	 *
	 * @param mixed $value Raw value.
	 */
	private static function to_bool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		return ! in_array( strtolower( trim( (string) $value ) ), array( '', '0', 'false', 'no', 'off' ), true );
	}
}
