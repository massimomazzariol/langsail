/* Scan: request every page of the site as a scan, one at a time, then reload the table. */
( function () {
	const button = document.getElementById( 'langsail-scan' );
	const config = window.langsailScan;
	if ( ! button || ! config ) {
		return;
	}
	const status = document.getElementById( 'langsail-scan-status' );
	const format = ( text, ...values ) =>
		values.reduce(
			( out, value, i ) =>
				out
					.replace( `%${ i + 1 }$d`, value )
					.replace( `%${ i + 1 }$s`, value ),
			text.replace( '%s', values[ 0 ] )
		);

	button.addEventListener( 'click', async () => {
		button.disabled = true;
		let found = 0;
		for ( let i = 0; i < config.urls.length; i++ ) {
			status.textContent = format(
				config.i18n.progress,
				i + 1,
				config.urls.length
			);
			const url = new URL( config.urls[ i ] );
			url.searchParams.set( config.arg, config.nonce );
			try {
				const response = await fetch( url, {
					credentials: 'same-origin',
				} );
				const report = await response.json();
				found += report.new || 0;
			} catch {
				status.textContent = format(
					config.i18n.failed,
					config.urls[ i ]
				);
			}
		}
		status.textContent = format(
			config.i18n.done,
			config.urls.length,
			found
		);
		window.location.reload();
	} );
} )();
