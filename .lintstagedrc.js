/**
 * lint-staged 設定。
 *
 * コミット前にステージされた PHP ファイルへ phpcs を実行する。
 * vendor/ と node_modules/ は対象外（phpcs.xml.dist 側でも除外済み）。
 */
module.exports = {
	'**/*.php': ( files ) => {
		const targets = files.filter(
			( file ) => ! file.includes( '/vendor/' ) && ! file.includes( '/node_modules/' )
		);

		if ( targets.length === 0 ) {
			return [];
		}

		const list = targets.map( ( file ) => `'${ file }'` ).join( ' ' );
		return [ `vendor/bin/phpcs ${ list }` ];
	},
};
