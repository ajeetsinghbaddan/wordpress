/* IT Services Chatbot — Admin JS */
jQuery( function ( $ ) {
    'use strict';

    var cfg     = window.ITSC_Admin;
    var flows   = cfg.flows;           // initial data from PHP
    var activeFlowId = null;

    /* ------------------------------------------------------------------ */
    /* Utility                                                              */
    /* ------------------------------------------------------------------ */

    function ajax( action, data, cb ) {
        $.post( cfg.ajaxurl, Object.assign( { action: action, nonce: cfg.nonce }, data ), function ( r ) {
            if ( r.success ) {
                cb( null, r.data );
            } else {
                var msg = ( r.data && r.data.message ) ? r.data.message : 'An error occurred.';
                cb( msg );
            }
        } ).fail( function () { cb( 'Network error.' ); } );
    }

    function confirm_delete( msg, cb ) {
        if ( window.confirm( msg ) ) { cb(); }
    }

    /* ------------------------------------------------------------------ */
    /* Render flow sidebar                                                  */
    /* ------------------------------------------------------------------ */

    function render_flows() {
        ajax( 'itsc_get_flow_data', { flow_id: 0 }, function() {} ); // no-op, data comes from PHP on reload
        // For sidebar we just rebuild from current `flows` array
        var $list = $( '#itsc-flow-list' ).empty();
        flows.forEach( function ( f ) {
            var $li = $( '<li class="itsc-flow-item">' )
                .attr( 'data-id', f.id )
                .append( '<span class="itsc-flow-name">' + esc( f.title ) + '</span>' )
                .append(
                    $( '<span class="itsc-flow-actions">' )
                        .append( $( '<a href="#">Edit</a>' ).addClass( 'itsc-edit-flow' ).attr( { 'data-id': f.id, 'data-title': f.title } ) )
                        .append( ' ' )
                        .append( $( '<a href="#">Delete</a>' ).addClass( 'itsc-delete-flow itsc-danger' ).attr( 'data-id', f.id ) )
                );
            if ( activeFlowId === f.id ) { $li.addClass( 'active' ); }
            $list.append( $li );
        } );
    }

    /* ------------------------------------------------------------------ */
    /* Render question editor                                               */
    /* ------------------------------------------------------------------ */

    function load_flow_editor( flow_id ) {
        activeFlowId = flow_id;
        var flow = flows.find( f => f.id === flow_id );
        if ( ! flow ) return;

        // Mark active in sidebar
        $( '.itsc-flow-item' ).removeClass( 'active' );
        $( '.itsc-flow-item[data-id="' + flow_id + '"]' ).addClass( 'active' );

        $( '#itsc-editor-placeholder' ).hide();
        $( '#itsc-editor' ).show();
        $( '#itsc-editor-title' ).text( flow.title + ' — Questions' );

        render_questions( flow );
    }

    function render_questions( flow ) {
        var $c = $( '#itsc-questions-container' ).empty();
        if ( ! flow.questions || flow.questions.length === 0 ) {
            $c.append( '<p style="color:#8c8f94">No questions yet. Click "+ Add Question" to create one.</p>' );
            return;
        }
        // Sort by step
        var qs = flow.questions.slice().sort( ( a, b ) => a.step - b.step );
        qs.forEach( function ( q ) {
            var optPills = ( q.options || [] ).map( o => '<span class="itsc-option-pill">' + esc( o.label ) + '</span>' ).join( '' );
            var $card = $(
                '<div class="itsc-question-card" data-id="' + q.id + '">' +
                    '<div class="itsc-question-card-header">' +
                        '<div>' +
                            '<span class="itsc-step-badge">Step ' + q.step + '</span>' +
                            '<strong>' + esc( q.question ) + '</strong>' +
                        '</div>' +
                        '<div class="itsc-question-card-actions">' +
                            '<a href="#" class="itsc-edit-question" data-id="' + q.id + '">Edit</a>' +
                            '<a href="#" class="itsc-delete-question itsc-danger" data-id="' + q.id + '">Delete</a>' +
                        '</div>' +
                    '</div>' +
                    '<div class="itsc-options-preview">' + optPills + '</div>' +
                '</div>'
            );
            $c.append( $card );
        } );
    }

    /* ------------------------------------------------------------------ */
    /* Flow modal                                                           */
    /* ------------------------------------------------------------------ */

    function open_flow_modal( id, title ) {
        $( '#itsc-modal-flow-id' ).val( id || 0 );
        $( '#itsc-modal-flow-title' ).val( title || '' );
        $( '#itsc-modal-flow-heading' ).text( id ? 'Edit Flow' : 'Add Flow' );
        $( '#itsc-modal-flow' ).show();
    }

    $( '#itsc-modal-flow-save' ).on( 'click', function () {
        var id    = parseInt( $( '#itsc-modal-flow-id' ).val(), 10 );
        var title = $.trim( $( '#itsc-modal-flow-title' ).val() );
        if ( ! title ) { alert( 'Title is required.' ); return; }

        ajax( 'itsc_save_flow', { id: id, title: title }, function ( err, data ) {
            if ( err ) { alert( err ); return; }
            $( '#itsc-modal-flow' ).hide();
            // Update local array
            if ( id ) {
                var f = flows.find( f => f.id === id );
                if ( f ) { f.title = title; }
            } else {
                flows.push( { id: data.id, title: title, questions: [] } );
            }
            render_flows();
            if ( id && activeFlowId === id ) {
                $( '#itsc-editor-title' ).text( title + ' — Questions' );
            }
        } );
    } );

    $( document ).on( 'click', '.itsc-btn-add-flow', function ( e ) {
        e.preventDefault();
        open_flow_modal( 0, '' );
    } );

    $( document ).on( 'click', '.itsc-edit-flow', function ( e ) {
        e.preventDefault();
        open_flow_modal( $( this ).data( 'id' ), $( this ).data( 'title' ) );
    } );

    $( document ).on( 'click', '.itsc-delete-flow', function ( e ) {
        e.preventDefault();
        var id = $( this ).data( 'id' );
        confirm_delete( 'Delete this flow and all its questions? This cannot be undone.', function () {
            ajax( 'itsc_delete_flow', { id: id }, function ( err ) {
                if ( err ) { alert( err ); return; }
                flows = flows.filter( f => f.id !== id );
                if ( activeFlowId === id ) {
                    activeFlowId = null;
                    $( '#itsc-editor' ).hide();
                    $( '#itsc-editor-placeholder' ).show();
                }
                render_flows();
            } );
        } );
    } );

    $( document ).on( 'click', '.itsc-flow-item', function ( e ) {
        if ( $( e.target ).is( 'a' ) ) return;
        load_flow_editor( $( this ).data( 'id' ) );
    } );

    /* ------------------------------------------------------------------ */
    /* Question modal                                                       */
    /* ------------------------------------------------------------------ */

    var editingQuestion = null; // { id, options: [] }

    function open_question_modal( question ) {
        editingQuestion = question ? JSON.parse( JSON.stringify( question ) ) : { id: 0, flow_id: activeFlowId, step: 1, question: '', options: [] };

        $( '#itsc-modal-q-id' ).val( editingQuestion.id );
        $( '#itsc-modal-q-flow-id' ).val( editingQuestion.flow_id || activeFlowId );
        $( '#itsc-modal-q-text' ).val( editingQuestion.question );
        $( '#itsc-modal-q-step' ).val( editingQuestion.step || 1 );
        $( '#itsc-modal-q-heading' ).text( editingQuestion.id ? 'Edit Question' : 'Add Question' );
        render_modal_options();
        $( '#itsc-modal-question' ).show();
    }

    function render_modal_options() {
        var $ul = $( '#itsc-modal-options-list' ).empty();
        ( editingQuestion.options || [] ).forEach( function ( o, i ) {
            $ul.append(
                $( '<li class="itsc-option-edit-row">' )
                    .append( $( '<input type="text" class="regular-text itsc-option-label">' ).val( o.label ).attr( 'data-index', i ) )
                    .append( $( '<button class="itsc-rm-option" type="button" aria-label="Remove">&times;</button>' ).attr( 'data-index', i ) )
            );
        } );
    }

    $( document ).on( 'click', '.itsc-btn-add-question', function ( e ) {
        e.preventDefault();
        if ( ! activeFlowId ) { alert( 'Select a flow first.' ); return; }
        open_question_modal( null );
    } );

    $( document ).on( 'click', '.itsc-edit-question', function ( e ) {
        e.preventDefault();
        var qid   = $( this ).data( 'id' );
        var flow  = flows.find( f => f.id === activeFlowId );
        var q     = flow && flow.questions.find( q => q.id === qid );
        if ( q ) { open_question_modal( q ); }
    } );

    $( document ).on( 'click', '.itsc-btn-add-option', function ( e ) {
        e.preventDefault();
        editingQuestion.options.push( { id: 0, label: '' } );
        render_modal_options();
    } );

    $( document ).on( 'click', '.itsc-rm-option', function () {
        var i = $( this ).data( 'index' );
        editingQuestion.options.splice( i, 1 );
        render_modal_options();
    } );

    $( document ).on( 'change keyup', '.itsc-option-label', function () {
        var i = parseInt( $( this ).attr( 'data-index' ), 10 );
        editingQuestion.options[ i ].label = $( this ).val();
    } );

    $( '#itsc-modal-q-save' ).on( 'click', function () {
        var id      = parseInt( $( '#itsc-modal-q-id' ).val(), 10 );
        var flow_id = parseInt( $( '#itsc-modal-q-flow-id' ).val(), 10 );
        var text    = $.trim( $( '#itsc-modal-q-text' ).val() );
        var step    = parseInt( $( '#itsc-modal-q-step' ).val(), 10 ) || 1;

        if ( ! text ) { alert( 'Question text is required.' ); return; }

        // 1. Save question
        ajax( 'itsc_save_question', { id: id, flow_id: flow_id, step: step, question: text }, function ( err, data ) {
            if ( err ) { alert( err ); return; }
            var qid = id || data.id;

            // 2. Save options one by one then finish
            var opts = editingQuestion.options.filter( o => $.trim( o.label ) );
            var pending = opts.length;
            var savedOptions = [];

            function finish() {
                // Update local data
                var flow = flows.find( f => f.id === flow_id );
                if ( ! flow ) return;
                if ( id ) {
                    var qi = flow.questions.findIndex( q => q.id === id );
                    if ( qi >= 0 ) {
                        flow.questions[ qi ] = { id: qid, flow_id: flow_id, step: step, question: text, options: savedOptions };
                    }
                } else {
                    flow.questions.push( { id: qid, flow_id: flow_id, step: step, question: text, options: savedOptions } );
                }
                $( '#itsc-modal-question' ).hide();
                render_questions( flow );
            }

            if ( ! pending ) { finish(); return; }

            // Delete old options then re-insert to keep it simple
            // (A more sophisticated implementation would diff, but this is reliable)
            opts.forEach( function ( o, oi ) {
                ajax( 'itsc_save_option', { id: 0, question_id: qid, label: $.trim( o.label ), sort_order: oi }, function ( oErr, oData ) {
                    if ( ! oErr ) {
                        savedOptions.push( { id: oData.id, label: $.trim( o.label ) } );
                    }
                    pending--;
                    if ( pending === 0 ) { finish(); }
                } );
            } );
        } );
    } );

    $( document ).on( 'click', '.itsc-delete-question', function ( e ) {
        e.preventDefault();
        var qid = $( this ).data( 'id' );
        confirm_delete( 'Delete this question and all its options?', function () {
            ajax( 'itsc_delete_question', { id: qid }, function ( err ) {
                if ( err ) { alert( err ); return; }
                var flow = flows.find( f => f.id === activeFlowId );
                if ( flow ) {
                    flow.questions = flow.questions.filter( q => q.id !== qid );
                    render_questions( flow );
                }
            } );
        } );
    } );

    /* ------------------------------------------------------------------ */
    /* Leads                                                                */
    /* ------------------------------------------------------------------ */

    $( document ).on( 'click', '.itsc-delete-lead', function ( e ) {
        e.preventDefault();
        var id  = $( this ).data( 'id' );
        var $tr = $( '#itsc-lead-' + id );
        confirm_delete( 'Permanently delete this lead?', function () {
            ajax( 'itsc_delete_lead', { id: id }, function ( err ) {
                if ( err ) { alert( err ); return; }
                $tr.fadeOut( 300, function () { $tr.remove(); } );
            } );
        } );
    } );

    /* ------------------------------------------------------------------ */
    /* Modal: close on overlay click or Cancel                             */
    /* ------------------------------------------------------------------ */

    $( document ).on( 'click', '.itsc-modal-close', function () {
        $( this ).closest( '.itsc-modal' ).hide();
    } );
    $( document ).on( 'click', '.itsc-modal', function ( e ) {
        if ( $( e.target ).hasClass( 'itsc-modal' ) ) { $( this ).hide(); }
    } );

    /* ------------------------------------------------------------------ */
    /* Escape HTML                                                          */
    /* ------------------------------------------------------------------ */

    function esc( str ) {
        return $( '<span>' ).text( str ).html();
    }

    /* ------------------------------------------------------------------ */
    /* Init: render sidebar                                                 */
    /* ------------------------------------------------------------------ */

    render_flows();
} );
