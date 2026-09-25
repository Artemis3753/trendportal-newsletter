( function () {
	var buttons = document.querySelectorAll( '.tp-kakao-share' );

	// If the SDK did not load (ad blocker, network error), leave the buttons hidden.
	if ( typeof Kakao === 'undefined' || ! window.tpKakaoShare ) {
		return;
	}

	// Calling init twice on the same page makes the SDK throw, so check first.
	if ( ! Kakao.isInitialized() ) {
		Kakao.init( window.tpKakaoShare.jsKey );
	}

	buttons.forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			// Scrap method: Kakao fetches this URL itself to build the card.
			// Kakao takes the title and image from the page, so they are not passed here.
			Kakao.Share.sendScrap( { requestUrl: button.dataset.url } );
		} );
		button.hidden = false;
	} );
} )();
