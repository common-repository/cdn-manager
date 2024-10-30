function copyClipboard(className) {

	var copyText = jQuery('.site-name-' + className).data('text');

	navigator.clipboard.writeText(copyText).then(function() {
		jQuery('.copied-' + className).html("copied!!");
		jQuery('.copied-' + className).show();

		setTimeout(function(){
			jQuery( '.copied-' + className ).fadeOut( "slow" );
		}, 3000);

	}, function(err) {
		jQuery('.copied-' + className).html("not copied!!");
	});

}