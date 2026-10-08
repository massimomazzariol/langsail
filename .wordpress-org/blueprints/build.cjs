/*
 * Builds the WordPress Playground demos from demo-setup.php and demo.json.
 * Usage: node build.cjs             blueprint.json (WordPress.org Live Preview, plugin from WordPress.org)
 *                                    and blueprint-github.json (README button, plugin from the GitHub repository)
 *        node build.cjs <out.json>  a local test copy that activates a mounted plugin instead
 */
const fs = require( 'fs' );
const path = require( 'path' );

const here = __dirname;
const sources = {
	wporg: { step: 'installPlugin', pluginData: { resource: 'wordpress.org/plugins', slug: 'langsail' } },
	github: {
		step: 'installPlugin',
		pluginData: { resource: 'git:directory', url: 'https://github.com/massimomazzariol/langsail', ref: 'main', refType: 'branch' },
	},
	local: { step: 'activatePlugin', pluginPath: 'langsail/langsail.php' },
};
const build = ( install ) => ( {
	$schema: 'https://playground.wordpress.net/blueprint-schema.json',
	landingPage: '/it/',
	login: true,
	preferredVersions: { php: '8.3', wp: 'latest' },
	steps: [
		install,
		{ step: 'setSiteOptions', options: { permalink_structure: '/%postname%/' } },
		{ step: 'writeFile', path: '/tmp/langsail-demo.json', data: fs.readFileSync( path.join( here, 'demo.json' ), 'utf8' ) },
		{ step: 'runPHP', code: fs.readFileSync( path.join( here, 'demo-setup.php' ), 'utf8' ) },
	],
} );
const write = ( file, install ) => fs.writeFileSync( file, JSON.stringify( build( install ), null, '\t' ) + '\n' );

if ( process.argv[ 2 ] ) {
	write( process.argv[ 2 ], sources.local );
} else {
	write( path.join( here, 'blueprint.json' ), sources.wporg );
	write( path.join( here, 'blueprint-github.json' ), sources.github );
}
