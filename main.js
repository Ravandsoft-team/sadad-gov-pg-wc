jQuery(function ($) {

    $(document.body).on('added_to_cart', function () {
        $('.woocommerce-error, .woocommerce-message').remove();
    });

    $(document.body).on('wc_fragments_refreshed', function () {

        var notices = $('.woocommerce-error');

        if (notices.length) {
            notices.first().prependTo('body').fadeIn();
        }
    });

});
