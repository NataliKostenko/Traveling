import { useSelect, useDispatch } from '@wordpress/data';
import { STORE_NAME } from '@/store/constants';
import apiFetch from '@wordpress/api-fetch';
import GlobalSaveButton from '@/apps/admin-components/global-save-button';
import { isCurrentPage } from '@/functions/utils';
import {
	POST_SEO_DATA_URL,
	TERM_SEO_DATA_URL,
	USER_SEO_DATA_URL,
} from '@/global/constants/api';
import { useCallback, useEffect } from '@wordpress/element';
import { fetchMetaSettings } from '@/functions/api';
import { DotIcon } from '@/global/components/icons';
import { validateCustomJsonLdSchemas } from '@/apps/admin-components/schema-utils/custom-json-ld';

const SaveButton = () => {
	const unsavedMetaSettings = useSelect(
		( select ) => select( STORE_NAME ).getUnsavedMetaSettings(),
		[]
	);
	const { resetUnsavedMetaSettings, initMetaDataAndDefaults } =
		useDispatch( STORE_NAME );

	const updateMetaSettings = async () => {
		try {
			const response = await fetchMetaSettings();
			initMetaDataAndDefaults( {
				postSeoMeta: response.data,
				globalDefaults: response.global_default,
			} );
		} catch ( error ) {
			// Do nothing
		}
	};

	const save = async () => {
		const schemaValidation = validateCustomJsonLdSchemas(
			unsavedMetaSettings?.schemas
		);
		if ( ! schemaValidation.valid ) {
			throw new Error( schemaValidation.message );
		}

		const isUser = !! surerank_seo_popup?.is_user;
		const isTerm =
			! isUser &&
			( !! surerank_seo_popup.is_taxonomy ||
				isCurrentPage( 'term.php' ) );

		let idParam = { post_id: surerank_seo_popup?.post_id };
		let dataUrl = POST_SEO_DATA_URL;
		let savedType = 'post';
		if ( isUser ) {
			idParam = { user_id: surerank_seo_popup?.user_id };
			dataUrl = USER_SEO_DATA_URL;
			savedType = 'user';
		} else if ( isTerm ) {
			idParam = { term_id: surerank_seo_popup?.term_id };
			dataUrl = TERM_SEO_DATA_URL;
			savedType = 'taxonomy';
		}

		const queryParams = {
			metaData: unsavedMetaSettings,
			...idParam,
		};

		const response = await apiFetch( {
			path: dataUrl,
			method: 'POST',
			data: queryParams,
		} );
		if ( ! response.success ) {
			throw response;
		}
		// Update the store with the latest data
		updateMetaSettings();
		setTimeout( () => {
			resetUnsavedMetaSettings();
		}, 1000 );

		// Notify the seo-bar (listing page) to refresh the badge for this post.
		const savedId =
			queryParams.user_id ?? queryParams.term_id ?? queryParams.post_id;
		if ( savedId ) {
			window.dispatchEvent(
				new CustomEvent( 'surerank:seo-data-saved', {
					detail: {
						postId: savedId,
						type: savedType,
					},
				} )
			);
		}

		return response;
	};

	const handleBeforeUnload = useCallback( ( e ) => {
		e.preventDefault();
		e.returnValue = null;
	}, [] );

	// Alert user when they try to leave the page without saving
	useEffect( () => {
		if (
			! unsavedMetaSettings ||
			! Object.keys( unsavedMetaSettings ?? {} ).length
		) {
			return;
		}
		window.addEventListener( 'beforeunload', handleBeforeUnload );
		return () => {
			window.removeEventListener( 'beforeunload', handleBeforeUnload );
		};
	}, [ handleBeforeUnload ] );

	const hasUnsavedSettings =
		Object.keys( unsavedMetaSettings ?? {} ).length > 0;

	return (
		<GlobalSaveButton
			onClick={ save }
			className={
				! hasUnsavedSettings
					? 'opacity-60 bg-background-brand cursor-not-allowed pointer-events-none'
					: ''
			}
			icon={ hasUnsavedSettings ? <DotIcon /> : null }
			disabled={ ! hasUnsavedSettings }
		/>
	);
};

export default SaveButton;
