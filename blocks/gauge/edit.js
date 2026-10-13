/**
 * River Conditions block: editor UI.
 *
 * The canvas shows HTML from our REST render endpoint, which calls the same
 * PHP renderer as the front end, so the editor preview matches exactly.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	Button,
	ExternalLink,
	PanelBody,
	Placeholder,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { RawHTML, useEffect, useState } from '@wordpress/element';
import { useDebounce } from '@wordpress/compose';
import apiFetch from '@wordpress/api-fetch';

const EXAMPLE_SITE = 'USGS-06037500';
const SITE_PATTERN = /^USGS-\d{8,15}$/;
const FIND_SITE_URL = 'https://waterdata.usgs.gov/';

/**
 * Accept "06037500" or "usgs-06037500" and return "USGS-06037500".
 *
 * @param {string} value Raw input.
 * @return {string} Normalized ID.
 */
export function normalizeSiteId( value ) {
	const id = ( value || '' ).trim().toUpperCase();
	return /^\d{8,15}$/.test( id ) ? `USGS-${ id }` : id;
}

/**
 * Live validation against /river-conditions/v1/sites/{id}.
 *
 * @param {string} siteId Site ID.
 * @return {{status: string, site?: Object, message?: string}} State.
 */
function useSiteValidation( siteId ) {
	const [ state, setState ] = useState( { status: 'idle' } );

	useEffect( () => {
		if ( ! siteId ) {
			setState( { status: 'idle' } );
			return;
		}
		if ( ! SITE_PATTERN.test( siteId ) ) {
			setState( {
				status: 'error',
				message: __(
					'Site IDs look like USGS-06037500.',
					'river-conditions'
				),
			} );
			return;
		}
		let cancelled = false;
		setState( { status: 'checking' } );
		apiFetch( {
			path: `/river-conditions/v1/sites/${ encodeURIComponent(
				siteId
			) }`,
		} )
			.then( ( site ) => {
				if ( ! cancelled ) {
					setState( { status: 'ok', site } );
				}
			} )
			.catch( ( error ) => {
				if ( ! cancelled ) {
					setState( {
						status: 'error',
						message:
							error?.message ||
							__(
								'Could not check this site.',
								'river-conditions'
							),
					} );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ siteId ] );

	return state;
}

/**
 * Server-rendered preview, refreshed (debounced) when attributes change.
 *
 * @param {Object} attributes Block attributes.
 * @return {{html: string, loading: boolean}} Preview state.
 */
function usePreview( attributes ) {
	const [ preview, setPreview ] = useState( { html: '', loading: true } );
	const [ request, setRequest ] = useState( attributes );
	const debouncedSet = useDebounce( setRequest, 300 );

	useEffect( () => {
		debouncedSet( attributes );
	}, [ attributes, debouncedSet ] );

	useEffect( () => {
		let cancelled = false;
		setPreview( ( prev ) => ( { ...prev, loading: true } ) );
		apiFetch( {
			path: '/river-conditions/v1/render',
			method: 'POST',
			data: request,
		} )
			.then( ( response ) => {
				if ( ! cancelled ) {
					setPreview( { html: response.html, loading: false } );
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setPreview( { html: '', loading: false } );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ request ] );

	return preview;
}

function SitePlaceholder( { onSubmit } ) {
	const [ value, setValue ] = useState( '' );
	const submit = ( event ) => {
		event.preventDefault();
		onSubmit( normalizeSiteId( value ) );
	};

	return (
		<Placeholder
			icon="chart-line"
			label={ __( 'River Conditions', 'river-conditions' ) }
			instructions={ __(
				'Enter a USGS site ID to show live flow, gage height and water temperature.',
				'river-conditions'
			) }
		>
			<form className="rivcon-placeholder__form" onSubmit={ submit }>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'USGS site ID', 'river-conditions' ) }
					hideLabelFromVision
					placeholder="USGS-06037500"
					value={ value }
					onChange={ setValue }
				/>
				<Button
					__next40pxDefaultSize
					variant="primary"
					type="submit"
					disabled={ ! value.trim() }
				>
					{ __( 'Show gauge', 'river-conditions' ) }
				</Button>
			</form>
			<p className="rivcon-placeholder__links">
				<Button
					variant="link"
					onClick={ () => onSubmit( EXAMPLE_SITE ) }
				>
					{ __( 'Use an example gauge', 'river-conditions' ) }
				</Button>
				{ ' · ' }
				<ExternalLink href={ FIND_SITE_URL }>
					{ __( 'Find a site ID', 'river-conditions' ) }
				</ExternalLink>
			</p>
		</Placeholder>
	);
}

function ValidationMessage( { validation } ) {
	switch ( validation.status ) {
		case 'checking':
			return (
				<p className="rivcon-validation">
					<Spinner /> { __( 'Checking…', 'river-conditions' ) }
				</p>
			);
		case 'ok':
			return (
				<p className="rivcon-validation is-ok" role="status">
					{ '✓ ' +
						( validation.site.state
							? sprintf(
									/* translators: 1: site name, 2: state. */
									__( '%1$s, %2$s', 'river-conditions' ),
									validation.site.name,
									validation.site.state
								)
							: validation.site.name ) }
				</p>
			);
		case 'error':
			return (
				<p className="rivcon-validation is-error" role="alert">
					{ validation.message }
				</p>
			);
		default:
			return null;
	}
}

export default function Edit( { attributes, setAttributes } ) {
	const {
		siteId,
		title,
		layout,
		showFlow,
		showGageHeight,
		showTemp,
		showSparkline,
		tempUnit,
	} = attributes;
	const blockProps = useBlockProps();
	const validation = useSiteValidation( siteId );
	const preview = usePreview( attributes );

	const inspector = (
		<InspectorControls>
			<PanelBody title={ __( 'Gauge', 'river-conditions' ) }>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'USGS site ID', 'river-conditions' ) }
					value={ siteId }
					placeholder="USGS-06037500"
					onChange={ ( value ) =>
						setAttributes( { siteId: normalizeSiteId( value ) } )
					}
				/>
				<ValidationMessage validation={ validation } />
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Title (optional)', 'river-conditions' ) }
					value={ title }
					placeholder={ __(
						'Uses the USGS name',
						'river-conditions'
					) }
					onChange={ ( value ) => setAttributes( { title: value } ) }
				/>
			</PanelBody>
			<PanelBody title={ __( 'Display', 'river-conditions' ) }>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Layout', 'river-conditions' ) }
					value={ layout }
					options={ [
						{
							label: __( 'Card', 'river-conditions' ),
							value: 'card',
						},
						{
							label: __( 'Compact', 'river-conditions' ),
							value: 'compact',
						},
					] }
					onChange={ ( value ) => setAttributes( { layout: value } ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Flow', 'river-conditions' ) }
					checked={ showFlow }
					onChange={ ( value ) =>
						setAttributes( { showFlow: value } )
					}
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Gage height', 'river-conditions' ) }
					checked={ showGageHeight }
					onChange={ ( value ) =>
						setAttributes( { showGageHeight: value } )
					}
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Water temperature', 'river-conditions' ) }
					help={ __(
						'Hidden automatically when the gauge has no temperature sensor.',
						'river-conditions'
					) }
					checked={ showTemp }
					onChange={ ( value ) =>
						setAttributes( { showTemp: value } )
					}
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( '7-day sparkline', 'river-conditions' ) }
					help={
						'compact' === layout
							? __( 'Card layout only.', 'river-conditions' )
							: undefined
					}
					checked={ showSparkline }
					disabled={ 'compact' === layout }
					onChange={ ( value ) =>
						setAttributes( { showSparkline: value } )
					}
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Temperature unit', 'river-conditions' ) }
					value={ tempUnit }
					options={ [
						{
							label: __( 'Site default', 'river-conditions' ),
							value: '',
						},
						{ label: '°F', value: 'F' },
						{ label: '°C', value: 'C' },
					] }
					onChange={ ( value ) =>
						setAttributes( { tempUnit: value } )
					}
				/>
			</PanelBody>
		</InspectorControls>
	);

	if ( ! siteId ) {
		return (
			<div { ...blockProps }>
				{ inspector }
				<SitePlaceholder
					onSubmit={ ( id ) => setAttributes( { siteId: id } ) }
				/>
			</div>
		);
	}

	return (
		<div { ...blockProps }>
			{ inspector }
			<div
				className="rivcon-editor-preview"
				aria-busy={ preview.loading }
			>
				{ preview.html ? (
					<RawHTML>{ preview.html }</RawHTML>
				) : (
					<Spinner />
				) }
			</div>
		</div>
	);
}
