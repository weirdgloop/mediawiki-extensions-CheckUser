( function () {
	// Include resources for specific special pages
	switch ( mw.config.get( 'wgCanonicalSpecialPageName' ) ) {
		case 'Investigate':
			require( './investigate/init.js' );
			break;
		case 'InvestigateBlock':
			require( './investigateblock/investigateblock.js' );
			break;
		case 'CheckUser':
			require( './checkuser/cidr.js' );
			require( './checkuser/caMultiLock.js' );
			require( './checkuser/checkUserHelper.js' );
			break;
		case 'CheckUserLog':
			require( './checkuserlog/highlightScroll.js' );
			break;
		case 'Block':
			require( './temporaryaccount/SpecialBlock.js' );
			break;
		case 'Contributions':
			if ( mw.util.isTemporaryUser( mw.config.get( 'wgRelevantUserName' ) ) ) {
				require( './temporaryaccount/SpecialContributions.js' );
			}
			break;
	}

	// Include resources for all but a few specific special pages
	// and for non-special pages that load this module
	var excludePages = [
		'Investigate',
		'InvestigateBlock',
		'CheckUser',
		'Contributions'
	];
	if (
		!mw.config.get( 'wgCanonicalSpecialPageName' ) ||
		excludePages.indexOf( mw.config.get( 'wgCanonicalSpecialPageName' ) ) === -1
	) {
		require( './temporaryaccount/init.js' );
	}
}() );
