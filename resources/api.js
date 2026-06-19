mws = window.mws || {};
mws.datastash = {
	get: async ( key ) => {
		return mws.datastash.getInternal( key, false );
	},
	getGlobal: async ( key ) => {
		return mws.datastash.getInternal( key, true );
	},
	set: async ( key, value ) => {
		return mws.datastash.setInternal( key, value, false );
	},
	setGlobal: async ( key, value ) => {
		return mws.datastash.setInternal( key, value, true );
	},
	getInternal: async ( key, getGlobal ) => {
		const restScript = mw.util.wikiScript( 'rest' );
		let url = `${restScript}/mws/v1/data-stash/${encodeURIComponent( key )}`;
		if ( getGlobal ) {
			url += '?global=1';
		}

		const response = await fetch( url, {
			method: 'GET',
			credentials: 'same-origin'
		} );
		if ( response.status === 204 ) {
			return null;
		}
		if ( !response.ok ) {
			throw new Error( `Failed to get stash data (${response.status})` );
		}
		return response.json();
	},
	setInternal: async ( key, value, isGlobal ) => {
		const restScript = mw.util.wikiScript( 'rest' );
		const url = `${restScript}/mws/v1/data-stash/${encodeURIComponent( key )}`;
		const body = {
			data: value,
			global: !!isGlobal
		};

		const response = await fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-CSRF-Token': mw.user.tokens.get( 'csrfToken' )
			},
			body: JSON.stringify( body )
		} );
		if ( !response.ok ) {
			throw new Error( `Failed to set stash data (${response.status})` );
		}
	}
};
