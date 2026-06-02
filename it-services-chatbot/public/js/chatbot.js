/* =========================================================
   IT Services Chatbot — Frontend JS
   Depends on: jQuery, ITSC (localised from PHP)
   ========================================================= */
( function ( $ ) {
    'use strict';

    /* ------------------------------------------------------------------ */
    /* Config & state                                                       */
    /* ------------------------------------------------------------------ */

    var flows    = ITSC.flows;
    var ajaxurl  = ITSC.ajaxurl;
    var nonce    = ITSC.nonce;
    var welcome  = ITSC.welcome || 'Hi there! Welcome. Which IT service are you interested in today?';
    var floating = ITSC.floating === '1';

    var answers    = {};
    var activeFlow = null;
    var stepIndex  = 0;

    var $bot    = $( '#itsc-chatbot' );
    var $msgs   = $( '#itsc-messages' );
    var $typing = $( '#itsc-typing' );
    var $input  = $( '#itsc-input-area' );

    /* ------------------------------------------------------------------ */
    /* Boot                                                                 */
    /* ------------------------------------------------------------------ */

    function boot() {
        answers    = {};
        activeFlow = null;
        stepIndex  = 0;
        $msgs.empty();
        $input.empty();
        $typing.removeClass( 'visible' );

        bot_say( welcome, function () {
            show_flow_options();
        } );
    }

    /* ------------------------------------------------------------------ */
    /* Message helpers                                                      */
    /* ------------------------------------------------------------------ */

    function bot_say( text, cb, delay ) {
        delay = delay || 600;
        $typing.addClass( 'visible' );
        scroll_bottom();

        setTimeout( function () {
            $typing.removeClass( 'visible' );
            append_message( 'bot', text );
            scroll_bottom();
            if ( cb ) { cb(); }
        }, delay );
    }

    function user_say( text ) {
        append_message( 'user', text );
        scroll_bottom();
    }

    function append_message( who, text ) {
        var avatar = who === 'bot' ? '🤖' : '👤';
        var $msg   = $(
            '<div class="itsc-msg itsc-' + who + '">' +
                '<div class="itsc-msg-avatar" aria-hidden="true">' + avatar + '</div>' +
                '<div class="itsc-msg-bubble">' + esc( text ) + '</div>' +
            '</div>'
        );
        $msgs.append( $msg );
    }

    function scroll_bottom() {
        $msgs.scrollTop( $msgs[ 0 ].scrollHeight );
    }

    /* ------------------------------------------------------------------ */
    /* Step 1: Service / Flow selection                                    */
    /* ------------------------------------------------------------------ */

    function show_flow_options() {
        var $opts = $( '<div class="itsc-options">' );
        flows.forEach( function ( flow ) {
            $( '<button class="itsc-option-btn" type="button">' )
                .text( flow.title )
                .on( 'click', function () {
                    select_flow( flow );
                } )
                .appendTo( $opts );
        } );
        $input.html( $opts );
    }

    function select_flow( flow ) {
        activeFlow = flow;
        activeFlow.questions = flow.questions.slice().sort( function ( a, b ) { return a.step - b.step; } );
        stepIndex = 0;
        answers['Service'] = flow.title;

        user_say( flow.title );
        $input.empty();

        setTimeout( function () {
            ask_current_question();
        }, 300 );
    }

    /* ------------------------------------------------------------------ */
    /* Step 2-5: Branch questions                                          */
    /* ------------------------------------------------------------------ */

    function ask_current_question() {
        if ( ! activeFlow ) { return; }
        var q = activeFlow.questions[ stepIndex ];
        if ( ! q ) {
            bot_say( 'Perfect. Share a few details and our team will reach out within 24 hours.', function () {
                show_contact_form();
            } );
            return;
        }

        bot_say( q.question, function () {
            show_question_options( q );
        } );
    }

    function show_question_options( q ) {
        var $opts = $( '<div class="itsc-options">' );
        ( q.options || [] ).forEach( function ( opt ) {
            $( '<button class="itsc-option-btn" type="button">' )
                .text( opt.label )
                .on( 'click', function () {
                    select_answer( q, opt.label );
                } )
                .appendTo( $opts );
        } );
        $input.html( $opts );
    }

    function select_answer( q, label ) {
        answers[ q.question ] = label;
        user_say( label );
        $input.empty();
        stepIndex++;
        setTimeout( ask_current_question, 300 );
    }

    /* ------------------------------------------------------------------ */
    /* Contact form                                                         */
    /* ------------------------------------------------------------------ */

    function show_contact_form() {
        var $form = $(
            '<div class="itsc-contact-form" id="itsc-contact-form">' +
                '<div class="itsc-field">' +
                    '<label for="itsc-name">Full Name <span aria-hidden="true">*</span></label>' +
                    '<input type="text" id="itsc-name" name="full_name" autocomplete="name" required>' +
                    '<span class="itsc-field-error" id="itsc-err-name"></span>' +
                '</div>' +
                '<div class="itsc-field">' +
                    '<label for="itsc-email">Email Address <span aria-hidden="true">*</span></label>' +
                    '<input type="email" id="itsc-email" name="email" autocomplete="email" required>' +
                    '<span class="itsc-field-error" id="itsc-err-email"></span>' +
                '</div>' +
                '<div class="itsc-field">' +
                    '<label for="itsc-phone">Phone Number <span aria-hidden="true">*</span></label>' +
                    '<input type="tel" id="itsc-phone" name="phone" autocomplete="tel" required>' +
                    '<span class="itsc-field-error" id="itsc-err-phone"></span>' +
                '</div>' +
                '<div class="itsc-field">' +
                    '<label for="itsc-message">Anything else?</label>' +
                    '<textarea id="itsc-message" name="message" placeholder="Optional notes\u2026"></textarea>' +
                '</div>' +
                '<button class="itsc-submit-btn" id="itsc-submit" type="button">Send Request</button>' +
            '</div>'
        );
        $input.html( $form );

        $( '#itsc-submit' ).on( 'click', validate_and_submit );
    }

    /* ------------------------------------------------------------------ */
    /* Validation & submission                                              */
    /* ------------------------------------------------------------------ */

    function validate_and_submit() {
        var name    = $.trim( $( '#itsc-name' ).val() );
        var email   = $.trim( $( '#itsc-email' ).val() );
        var phone   = $.trim( $( '#itsc-phone' ).val() );
        var message = $.trim( $( '#itsc-message' ).val() );

        var valid = true;

        $( '.itsc-field-error' ).text( '' );

        if ( ! name ) {
            $( '#itsc-err-name' ).text( 'Please enter your full name.' );
            valid = false;
        }
        if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email ) ) {
            $( '#itsc-err-email' ).text( 'Please enter a valid email address.' );
            valid = false;
        }
        if ( ! /^[+\d\s\-().]{6,20}$/.test( phone ) ) {
            $( '#itsc-err-phone' ).text( 'Please enter a valid phone number.' );
            valid = false;
        }

        if ( ! valid ) { return; }

        $( '#itsc-submit' ).prop( 'disabled', true ).text( 'Sending\u2026' );

        $.post( ajaxurl, {
            action:    'itsc_submit_lead',
            nonce:     nonce,
            full_name: name,
            email:     email,
            phone:     phone,
            message:   message,
            answers:   JSON.stringify( answers ),
        }, function ( r ) {
            if ( r.success ) {
                $input.empty();
                user_say( name + ' — details submitted.' );
                show_summary( name, email, phone, message );
            } else {
                $( '#itsc-submit' ).prop( 'disabled', false ).text( 'Send Request' );
                var msg = ( r.data && r.data.message ) ? r.data.message : 'Something went wrong. Please try again.';
                bot_say( msg );
            }
        } ).fail( function () {
            $( '#itsc-submit' ).prop( 'disabled', false ).text( 'Send Request' );
            bot_say( 'Network error. Please check your connection and try again.' );
        } );
    }

    /* ------------------------------------------------------------------ */
    /* Summary screen                                                       */
    /* ------------------------------------------------------------------ */

    function show_summary( name, email, phone, note ) {
        bot_say( 'Thanks ' + esc( name ) + '! Here is a summary of your request.', function () {

            var rows = '';
            $.each( answers, function ( q, a ) {
                rows += '<div class="itsc-summary-row">' +
                    '<span class="itsc-summary-label">' + esc( q ) + '</span>' +
                    '<span class="itsc-summary-value">'  + esc( a ) + '</span>' +
                '</div>';
            } );

            rows += '<div class="itsc-summary-row"><span class="itsc-summary-label">Name</span><span class="itsc-summary-value">'  + esc( name )  + '</span></div>';
            rows += '<div class="itsc-summary-row"><span class="itsc-summary-label">Email</span><span class="itsc-summary-value">' + esc( email ) + '</span></div>';
            rows += '<div class="itsc-summary-row"><span class="itsc-summary-label">Phone</span><span class="itsc-summary-value">' + esc( phone ) + '</span></div>';
            if ( note ) {
                rows += '<div class="itsc-summary-row"><span class="itsc-summary-label">Note</span><span class="itsc-summary-value">' + esc( note ) + '</span></div>';
            }

            var $summary = $( '<div class="itsc-summary"><h3>Your Request</h3>' + rows + '</div>' );
            $msgs.append( $summary );

            bot_say( 'Our team will contact you at ' + esc( email ) + ' shortly.', function () {
                var $restart = $( '<button class="itsc-restart-btn" type="button">\u21ba Start over</button>' )
                    .on( 'click', boot );
                $input.html( $restart );
            }, 800 );

            scroll_bottom();
        }, 600 );
    }

    /* ------------------------------------------------------------------ */
    /* Utility                                                              */
    /* ------------------------------------------------------------------ */

    function esc( str ) {
        return $( '<span>' ).text( String( str ) ).html();
    }

    /* ------------------------------------------------------------------ */
    /* Floating widget launcher                                             */
    /* ------------------------------------------------------------------ */

    function init_floating() {
        var $wrap    = $( '#itsc-floating-wrap' );
        var $panel   = $( '#itsc-panel' );
        var $launcher = $( '#itsc-launcher' );
        var $badge   = $( '#itsc-launcher-badge' );
        var $closeBtn = $( '#itsc-panel-close' );
        var booted   = false;
        var open     = false;

        if ( ! $wrap.length ) { return; }

        function open_panel() {
            open = true;
            $panel.removeAttr( 'hidden' );
            $launcher.attr( 'aria-expanded', 'true' );
            $( '.itsc-launcher-icon.itsc-icon-chat' ).hide();
            $( '.itsc-launcher-icon.itsc-icon-close' ).show();
            $badge.attr( 'hidden', true );

            // Boot chatbot on first open
            if ( ! booted ) {
                booted = true;
                boot();
            }
        }

        function close_panel() {
            open = false;
            $panel.attr( 'hidden', true );
            $launcher.attr( 'aria-expanded', 'false' );
            $( '.itsc-launcher-icon.itsc-icon-chat' ).show();
            $( '.itsc-launcher-icon.itsc-icon-close' ).hide();
        }

        $launcher.on( 'click', function () {
            if ( open ) { close_panel(); } else { open_panel(); }
        } );

        $closeBtn.on( 'click', close_panel );

        // Close on Escape key
        $( document ).on( 'keydown', function ( e ) {
            if ( open && e.key === 'Escape' ) { close_panel(); }
        } );
    }

    /* ------------------------------------------------------------------ */
    /* Start                                                                */
    /* ------------------------------------------------------------------ */

    $( document ).ready( function () {
        if ( floating ) {
            init_floating();
        } else if ( $bot.length ) {
            boot();
        }
    } );

} )( jQuery );
