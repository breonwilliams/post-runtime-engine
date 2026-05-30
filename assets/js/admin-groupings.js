/**
 * Admin → Groupings edit form — source-type row visibility toggle.
 *
 * Toggles which source-specific rows show based on the selected
 * source_type dropdown:
 *
 *   .pre-source-row--auto      → shown for taxonomy_match + meta_match
 *                                (limit, exclude_self — common to both)
 *   .pre-source-row--taxonomy  → shown for taxonomy_match only
 *                                (taxonomy slug input)
 *   .pre-source-row--meta      → shown for meta_match only
 *                                (meta_key input)
 *
 * Manual + child_posts hide all source-config rows.
 *
 * Previously an inline <script> at the bottom of the grouping edit form;
 * extracted to this file in 0.4.1 for WordPress.org Plugin Check
 * compliance (no inline scripts in plugin output).
 */
( function () {
    var select = document.getElementById( 'pcptpages_source_type' );
    if ( ! select ) {
        return;
    }

    var rowsAuto     = document.querySelectorAll( '.pre-source-row--auto' );
    var rowsTaxonomy = document.querySelectorAll( '.pre-source-row--taxonomy' );
    var rowsMeta     = document.querySelectorAll( '.pre-source-row--meta' );

    function setVisible( rows, show ) {
        rows.forEach( function ( r ) {
            r.style.display = show ? '' : 'none';
        } );
    }

    function sync() {
        var v          = select.value;
        var isTaxonomy = ( v === 'taxonomy_match' );
        var isMeta     = ( v === 'meta_match' );
        setVisible( rowsTaxonomy, isTaxonomy );
        setVisible( rowsMeta, isMeta );
        setVisible( rowsAuto, isTaxonomy || isMeta );
    }

    select.addEventListener( 'change', sync );
    sync();
} )();
