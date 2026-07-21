/**
 * Admin → Groupings edit form — conditional row visibility.
 *
 * 1) Source-type rows, keyed on the source_type dropdown:
 *
 *   .pre-source-row--auto      → shown for taxonomy_match + meta_match
 *                                (limit, exclude_self — common to both)
 *   .pre-source-row--taxonomy  → shown for taxonomy_match only
 *                                (taxonomy slug input)
 *   .pre-source-row--meta      → shown for meta_match only
 *                                (meta_key input)
 *
 *   Manual + child_posts hide all source-config rows.
 *
 * 2) Gallery rows, keyed on the default_variant dropdown:
 *
 *   .pre-gallery-row → shown only when default_variant === 'gallery'
 *                      (gallery_image_aspect select). The value still
 *                      submits when hidden — harmless, since the
 *                      renderer only reads it for the gallery variant.
 *
 * Previously an inline <script> at the bottom of the grouping edit form;
 * extracted to this file in 0.4.1 for WordPress.org Plugin Check
 * compliance (no inline scripts in plugin output).
 */
( function () {
    function setVisible( rows, show ) {
        rows.forEach( function ( r ) {
            r.style.display = show ? '' : 'none';
        } );
    }

    // --- Source-type rows -------------------------------------------------
    var sourceSelect = document.getElementById( 'pcptpages_source_type' );
    if ( sourceSelect ) {
        var rowsAuto     = document.querySelectorAll( '.pre-source-row--auto' );
        var rowsTaxonomy = document.querySelectorAll( '.pre-source-row--taxonomy' );
        var rowsMeta     = document.querySelectorAll( '.pre-source-row--meta' );

        var syncSource = function () {
            var v          = sourceSelect.value;
            var isTaxonomy = ( v === 'taxonomy_match' );
            var isMeta     = ( v === 'meta_match' );
            setVisible( rowsTaxonomy, isTaxonomy );
            setVisible( rowsMeta, isMeta );
            setVisible( rowsAuto, isTaxonomy || isMeta );
        };

        sourceSelect.addEventListener( 'change', syncSource );
        syncSource();
    }

    // --- Gallery rows -----------------------------------------------------
    var variantSelect = document.getElementById( 'pcptpages_default_variant' );
    if ( variantSelect ) {
        var rowsGallery = document.querySelectorAll( '.pre-gallery-row' );

        var syncGallery = function () {
            setVisible( rowsGallery, variantSelect.value === 'gallery' );
        };

        variantSelect.addEventListener( 'change', syncGallery );
        syncGallery();
    }
} )();
