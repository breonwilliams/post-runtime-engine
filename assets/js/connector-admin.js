/**
 * Connector admin page — Generate / Revoke / Copy-Command workflow.
 *
 * Reads dynamic data (ajax URL, nonce, connector script URL, site URL,
 * translated strings) from window.pcptpagesConnectorAdmin which is injected via
 * wp_localize_script() in PHP. No PHP interpolation in this file —
 * Plugin Check stays happy and the file is browser-cacheable.
 *
 * Mirrors the FRE connector-admin pattern.
 */
(function() {
    'use strict';

    var data = window.pcptpagesConnectorAdmin || {};
    if ( ! data.ajaxUrl ) {
        return;
    }
    var i18n = data.i18n || {};

    /**
     * Build the one-line bash setup command. Identical install pattern to
     * FRE / Promptless connectors — installs into ~/post-runtime-mcp, uses
     * Claude Desktop config key "post-runtime-engine", password passed via
     * argv[2] so it never appears in shell history.
     */
    function buildSetupCommand( username, password ) {
        var escapedPassword = password.replace(/'/g, "'\\''");
        var escapedSiteUrl  = data.siteUrl.replace(/'/g, "'\\''");
        var escapedUsername = username.replace(/'/g, "'\\''");

        // Local HTTPS trust: Node does NOT read the macOS keychain. It ships its
        // own Mozilla CA bundle, so trusting a dev certificate in Local or
        // Keychain Access fixes browsers and does nothing for the connector
        // process — every call fails with DEPTH_ZERO_SELF_SIGNED_CERT. The remedy
        // is NODE_EXTRA_CA_CERTS pointing at the site's certificate.
        //
        // Only attempted for NON-PUBLIC hosts on https: a public host with an
        // untrusted certificate is a real problem that should surface, not be
        // worked around, and over plain http there is no handshake to trust so
        // the variable would be inert noise. Local by Flywheel's per-site
        // self-signed leaf is its own trust anchor (CA:FALSE), so pointing at the
        // leaf is enough — no CA-generation step.
        //
        // The path is PROBED, never assumed — wp-env, Herd and Valet keep certs
        // elsewhere. On a miss CERT stays empty and nothing is emitted; the
        // notice on the connector page tells the user what to set by hand.
        var certHost = '';
        try {
            var u = new URL( data.siteUrl );
            if ( u.protocol === 'https:' && /(^localhost$|\.local$|\.test$)/i.test( u.hostname ) ) {
                // Restrict to hostname characters — interpolated into a
                // double-quoted shell string below.
                certHost = u.hostname.replace(/[^A-Za-z0-9.-]/g, '');
            }
        } catch ( e ) { /* unparseable URL: emit no probe */ }

        var certProbe = certHost
            ? 'CERT="$HOME/Library/Application Support/Local/run/router/nginx/certs/' + certHost + '.crt" ; [ -f "$CERT" ] || CERT="" ; \\'
            : 'CERT="" ; \\';

        return [
            'mkdir -p ~/post-runtime-mcp && \\',
            "curl -fsSL -A 'WordPress/PostRuntimeEngine' '" + data.connectorScriptUrl + "' -o ~/post-runtime-mcp/post-runtime-connector.js && \\",
            'NODE_PATH=$(ls -d ~/.nvm/versions/node/v*/bin/node 2>/dev/null | sort -V | tail -1) ; [ -z "$NODE_PATH" ] && NODE_PATH=$(which node) ; \\',
            certProbe,
            'CONFIG="$HOME/Library/Application Support/Claude/claude_desktop_config.json" && \\',
            'mkdir -p "$HOME/Library/Application Support/Claude" && \\',
            '"$NODE_PATH" -e \'' +
            'var fs=require("fs");' +
            'var p=process.env.HOME+"/Library/Application Support/Claude/claude_desktop_config.json";' +
            'var c;try{c=JSON.parse(fs.readFileSync(p,"utf8"))}catch(e){c={}}' +
            'c.mcpServers=c.mcpServers||{};' +
            // MERGE at BOTH levels, never replace. The entry may carry env keys
            // the user added by hand (NODE_EXTRA_CA_CERTS, HTTP_PROXY) and
            // server-level keys (cwd, disabled, anything Claude Desktop adds
            // later); a wholesale assignment silently discarded all of them on
            // every regenerate. Only command, args and the three owned env keys
            // are overwritten.
            'var prev=c.mcpServers["post-runtime-engine"]||{};' +
            'var env=Object.assign({},prev.env||{},{' +
            'POST_RUNTIME_SITE_URL:"' + escapedSiteUrl + '",' +
            'POST_RUNTIME_USERNAME:"' + escapedUsername + '",' +
            'POST_RUNTIME_APP_PASSWORD:process.argv[2]' +
            '});' +
            // Set only when the probe found a certificate. NEVER deleted when it
            // misses: an existing value may have been set by hand for tooling
            // whose path cannot be probed (wp-env, Herd, Valet).
            'if(process.argv[3]){env.NODE_EXTRA_CA_CERTS=process.argv[3];}' +
            'c.mcpServers["post-runtime-engine"]=Object.assign({},prev,{' +
            'command:process.argv[1],' +
            'args:[process.env.HOME+"/post-runtime-mcp/post-runtime-connector.js"],' +
            'env:env});' +
            'fs.writeFileSync(p,JSON.stringify(c,null,2))' +
            '\' "$NODE_PATH" \'' + escapedPassword + '\' "$CERT" && \\',
            'echo "" && echo "Setup complete. Quit Claude Desktop (Cmd+Q) and reopen it."'
        ].join('\n');
    }

    function showSetupCommand( username, password ) {
        var cmd = buildSetupCommand( username, password );
        document.getElementById( 'pre-setup-command' ).textContent = cmd;
        var container = document.getElementById( 'pre-setup-command-container' );
        if ( container ) {
            container.style.display = 'block';
        }
        var placeholder = document.getElementById( 'pre-setup-command-placeholder' );
        if ( placeholder ) {
            placeholder.style.display = 'none';
        }
    }

    async function post( action, extra ) {
        extra = extra || {};
        var body = new URLSearchParams( Object.assign( { action: action, nonce: data.nonce }, extra ) );
        var res = await fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } );
        return res.json();
    }

    function showStatus( id, text, ok ) {
        var el = document.getElementById( id );
        if ( ! el ) {
            return;
        }
        el.textContent = text;
        el.style.color = ( ok === false ) ? '#b32d2e' : '#2271b1';
        clearTimeout( el._t );
        el._t = setTimeout( function() { el.textContent = ''; }, 2500 );
    }

    // Kill-switch toggle.
    document.querySelectorAll( '[data-ajax-action]' ).forEach( function( cb ) {
        cb.addEventListener( 'change', async function() {
            var action = cb.dataset.ajaxAction;
            var enabled = cb.checked ? '1' : '0';
            var statusId = 'pre-enabled-status';
            try {
                var r = await post( action, { enabled: enabled } );
                if ( r.success ) {
                    showStatus( statusId, cb.checked ? i18n.enabled : i18n.disabled );
                } else {
                    cb.checked = ! cb.checked;
                    showStatus( statusId, ( r.data && r.data.message ) || 'Error', false );
                }
            } catch ( err ) {
                cb.checked = ! cb.checked;
                showStatus( statusId, String( err ), false );
            }
        } );
    } );

    var genBtn = document.getElementById( 'pre-generate-password-btn' );
    if ( genBtn ) {
        genBtn.addEventListener( 'click', async function() {
            // No confirm() dialog — the prior password is revoked
            // atomically server-side, so misclicks are recoverable
            // simply by re-clicking Generate.
            var originalLabel = genBtn.textContent;
            genBtn.disabled = true;
            genBtn.textContent = i18n.generating;
            var r = await post( 'pcptpages_connector_generate_password' );
            genBtn.disabled = false;
            if ( r.success ) {
                var display = document.getElementById( 'pre-credential-display' );
                if ( display ) {
                    display.style.display = 'block';
                }

                showSetupCommand( r.data.username, r.data.password );

                var pill = document.getElementById( 'pre-connector-status-pill' );
                if ( pill ) {
                    pill.textContent = i18n.configured;
                    pill.classList.remove( 'pre-connector-status-inactive' );
                    pill.classList.add( 'pre-connector-status-active' );
                }

                genBtn.textContent = i18n.regenerate;
            } else {
                genBtn.textContent = originalLabel;
                window.alert( ( r.data && r.data.message ) || 'Error' );
            }
        } );
    }

    var copyBtn = document.getElementById( 'pre-copy-setup-command' );
    if ( copyBtn ) {
        var originalCopyLabel = copyBtn.textContent;
        var flashCopied = function() {
            copyBtn.textContent = i18n.copied;
            setTimeout( function() { copyBtn.textContent = originalCopyLabel; }, 2000 );
        };
        copyBtn.addEventListener( 'click', async function() {
            var pre = document.getElementById( 'pre-setup-command' );
            var cmd = pre.textContent;
            // Path 1: modern Clipboard API (HTTPS + true localhost only).
            if ( navigator.clipboard && navigator.clipboard.writeText ) {
                try {
                    await navigator.clipboard.writeText( cmd );
                    flashCopied();
                    return;
                } catch ( e ) { /* fall through */ }
            }
            // Path 2: legacy execCommand fallback for HTTP custom hostnames.
            var sel = window.getSelection();
            var range = document.createRange();
            range.selectNodeContents( pre );
            sel.removeAllRanges();
            sel.addRange( range );
            try {
                var ok = document.execCommand( 'copy' );
                sel.removeAllRanges();
                if ( ok ) {
                    flashCopied();
                }
            } catch ( e ) { /* leave selection so user can Cmd+C */ }
        } );
    }

    var revokeBtn = document.getElementById( 'pre-revoke-password-btn' );
    if ( revokeBtn ) {
        revokeBtn.addEventListener( 'click', async function() {
            if ( ! window.confirm( i18n.revokeConfirm ) ) {
                return;
            }
            revokeBtn.disabled = true;
            var r = await post( 'pcptpages_connector_revoke_password' );
            revokeBtn.disabled = false;
            if ( r.success ) {
                window.location.reload();
            } else {
                window.alert( ( r.data && r.data.message ) || 'Error' );
            }
        } );
    }
})();
