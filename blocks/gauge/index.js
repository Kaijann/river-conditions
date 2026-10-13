/**
 * River Conditions block: registration.
 */
import { registerBlockType } from '@wordpress/blocks';

import './style.scss';
import './editor.scss';
import metadata from './block.json';
import Edit from './edit';

registerBlockType( metadata.name, {
	edit: Edit,
	// Dynamic block: PHP renders the front end.
	save: () => null,
} );
