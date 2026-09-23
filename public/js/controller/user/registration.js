(function() {

    $(document).ready(function() {

        /* Security related */

        if ($("#form-nickname-error").length) {
            $('input[name="rf-nickname"]').show();
        }
    });

})();
