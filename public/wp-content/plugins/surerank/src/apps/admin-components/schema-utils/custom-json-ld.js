import { __ } from '@wordpress/i18n';

export const CUSTOM_JSON_LD_SCHEMA_TITLE = 'Custom JSON-LD';
const SMART_TAG_PATTERN = /%[\w\-_.]+%/;

const normalizeNodes = ( decoded ) => {
	if ( Array.isArray( decoded?.[ '@graph' ] ) ) {
		return decoded[ '@graph' ].filter(
			( node ) =>
				node && typeof node === 'object' && ! Array.isArray( node )
		);
	}

	if ( Array.isArray( decoded ) ) {
		return decoded.filter(
			( node ) =>
				node && typeof node === 'object' && ! Array.isArray( node )
		);
	}

	if ( decoded && typeof decoded === 'object' ) {
		return [ decoded ];
	}

	return [];
};

export const validateCustomJsonLd = ( rawValue ) => {
	const raw = typeof rawValue === 'string' ? rawValue.trim() : '';

	if ( ! raw ) {
		return {
			valid: false,
			message: __(
				'Add valid JSON-LD markup before saving.',
				'surerank'
			),
		};
	}

	try {
		const decoded = JSON.parse( raw );
		const nodes = normalizeNodes( decoded );

		if ( ! nodes.length ) {
			return {
				valid: false,
				message: __(
					'Provide a JSON-LD object or @graph payload with at least one node.',
					'surerank'
				),
			};
		}

		const hasContext =
			!! decoded?.[ '@context' ] ||
			nodes.some( ( node ) => !! node?.[ '@context' ] );
		if ( ! hasContext ) {
			return {
				valid: false,
				message: __(
					'Include an @context value such as https://schema.org.',
					'surerank'
				),
			};
		}

		const missingType = nodes.some( ( node ) => ! node?.[ '@type' ] );
		if ( missingType ) {
			return {
				valid: false,
				message: __(
					'Each JSON-LD object must include an @type value.',
					'surerank'
				),
			};
		}

		return { valid: true, message: '' };
	} catch ( error ) {
		if ( SMART_TAG_PATTERN.test( raw ) ) {
			return {
				valid: false,
				message: __(
					'Smart tags are supported only inside quoted JSON string values.',
					'surerank'
				),
			};
		}

		return {
			valid: false,
			message:
				error?.message ||
				__( 'The JSON-LD markup could not be parsed.', 'surerank' ),
		};
	}
};

export const validateCustomJsonLdSchemas = ( schemas = {} ) => {
	for ( const schema of Object.values( schemas || {} ) ) {
		if ( schema?.title !== CUSTOM_JSON_LD_SCHEMA_TITLE ) {
			continue;
		}

		const label =
			schema?.fields?.schema_name || CUSTOM_JSON_LD_SCHEMA_TITLE;
		const validation = validateCustomJsonLd(
			schema?.fields?.custom_json_ld || ''
		);

		if ( ! validation.valid ) {
			return {
				valid: false,
				message: `${ label }: ${ validation.message }`,
			};
		}
	}

	return { valid: true, message: '' };
};
