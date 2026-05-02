/**
 * BossCode AI Agent — Floating Widget v3
 * Features: Auto page context, @ mention system (@pages, @file, @folder, @plugin)
 */
(function () {
    'use strict';

    var cfg       = window.bosscodeWidget || {};
    var REST      = cfg.restUrl || '';
    var NONCE     = cfg.nonce  || '';
    var adminUrl  = cfg.adminUrl || '#';
    var postId    = parseInt( cfg.postId ) || 0;
    var isEditor  = cfg.isEditor || false;
    var currentPage = cfg.currentPage || window.location.href;

    // ── State ─────────────────────────────────────────────────
    var isOpen        = false;
    var isLoading     = false;
    var chatHistory   = [];
    var mentions      = [];      // Active @ context items
    var mentionMenu   = { open: false, query: '', type: '', items: [], selectedIdx: 0 };
    var autoCtxLoaded = false;

    // ── Init ──────────────────────────────────────────────────
    function init() {
        buildDOM();
        bindEvents();
        // Auto-load context when in a page editor
        if ( postId && isEditor ) {
            setTimeout( loadAutoContext, 800 );
        }
        // Pulse FAB
        setTimeout( function () {
            var fab = document.getElementById('bc-fab');
            if ( fab ) { fab.classList.add('bc-fab--pulse'); setTimeout( function () { fab.classList.remove('bc-fab--pulse'); }, 2000 ); }
        }, 1200 );
    }

    // ── DOM Builder ───────────────────────────────────────────
    function buildDOM() {
        var fab = el('div', { id:'bc-fab', title:'BossCode AI Agent' }, '🤖' );
        fab.addEventListener('click', toggleSidebar);

        var overlay = el('div', { id:'bc-overlay' });
        overlay.addEventListener('click', closeSidebar);

        var sidebar = el('div', { id:'bc-sidebar' });
        sidebar.innerHTML = getSidebarHTML();

        document.body.appendChild(fab);
        document.body.appendChild(overlay);
        document.body.appendChild(sidebar);
    }

    function getSidebarHTML() {
        return '' +
        '<div class="bc-hdr">' +
            '<div class="bc-hdr__left"><span class="bc-hdr__logo">🤖</span><span class="bc-hdr__title">BossCode AI</span>' +
            ( isEditor ? '<span class="bc-hdr__badge" id="bc-ctx-badge">auto-context</span>' : '' ) +
            '</div>' +
            '<div class="bc-hdr__acts">' +
                '<a href="' + adminUrl + '" class="bc-hdr__btn" title="Open full IDE">⛶</a>' +
                '<button id="bc-close" class="bc-hdr__btn bc-hdr__btn--close" title="Close">×</button>' +
            '</div>' +
        '</div>' +
        '<div id="bc-ctx-bar" class="bc-ctx-bar bc-ctx-bar--hidden"></div>' +
        '<div id="bc-mentions-bar" class="bc-mentions-bar"></div>' +
        '<div id="bc-msgs" class="bc-msgs">' +
            '<div class="bc-welcome" id="bc-welcome">' +
                '<div class="bc-welcome__ico">🚀</div>' +
                '<p class="bc-welcome__title">BossCode Agent</p>' +
                '<p class="bc-welcome__sub">Type <kbd>@</kbd> to mention pages, files, plugins, or folders as context.</p>' +
            '</div>' +
        '</div>' +
        '<div class="bc-input-wrap">' +
            '<div id="bc-mention-menu" class="bc-mention-menu bc-mention-menu--hidden"></div>' +
            '<form id="bc-form" class="bc-form" autocomplete="off">' +
                '<input type="text" id="bc-input" class="bc-input" placeholder="Ask anything… type @ for context" autocomplete="off" />' +
                '<button type="submit" id="bc-send" class="bc-send">➤</button>' +
            '</form>' +
        '</div>';
    }

    // ── Auto-load current page context ────────────────────────
    function loadAutoContext() {
        if ( autoCtxLoaded || !postId ) return;
        showCtxBar( 'loading', '⏳ Loading page context…' );
        fetchContextPost( postId, function ( data ) {
            if ( data && data.title ) {
                autoCtxLoaded = true;
                addMention({ type:'page', id:data.id, label:data.title, contextText: data.context_text, builder: data.builder, templatePath: data.template_path });
                showCtxBar( 'ok', '📄 ' + data.title + ( data.builder !== 'none' ? ' [' + data.builder + ']' : '' ) );
                var badge = document.getElementById('bc-ctx-badge');
                if ( badge ) { badge.textContent = '✓ context loaded'; badge.className = 'bc-hdr__badge bc-hdr__badge--ok'; }
                addSystemMsg( '📎 Auto-loaded context: **' + data.title + '**' + ( data.template_path ? '\n📁 Template: `' + data.template_path + '`' : '' ) + ( data.builder !== 'none' ? '\n🏗 Builder: ' + data.builder : '' ) );
            } else {
                showCtxBar( 'err', '⚠️ Could not auto-load page context' );
            }
        });
    }

    function fetchContextPost( id, cb ) {
        apiFetch( REST + '/context/post/' + id, 'GET', null, cb, function() { showCtxBar('err','⚠️ REST API error'); } );
    }

    // ── @ Mention System ──────────────────────────────────────
    var MENTION_TYPES = {
        'pages':   { label: 'Pages & Posts', icon: '📄', endpoint: REST + '/context/pages' },
        'page':    { label: 'Pages & Posts', icon: '📄', endpoint: REST + '/context/pages' },
        'plugin':  { label: 'Plugins',       icon: '🔌', endpoint: REST + '/context/plugins' },
        'plugins': { label: 'Plugins',       icon: '🔌', endpoint: REST + '/context/plugins' },
        'file':    { label: 'Files',         icon: '📁', endpoint: REST + '/files' },
        'folder':  { label: 'Folders',       icon: '📂', endpoint: REST + '/files' },
    };

    // Show the @ type picker (which categories to browse)
    function showMentionTypePicker() {
        mentionMenu.open   = true;
        mentionMenu.type   = '';
        mentionMenu.items  = [
            { key:'pages',  label:'Pages & Posts',  icon:'📄', desc:'WordPress pages, posts, custom types' },
            { key:'plugin', label:'Plugins',         icon:'🔌', desc:'Active WordPress plugins' },
            { key:'file',   label:'File',            icon:'📄', desc:'Browse and attach a file' },
            { key:'folder', label:'Folder',          icon:'📂', desc:'Attach an entire folder as context' },
        ];
        mentionMenu.selectedIdx = 0;
        renderMentionMenu();
    }

    function loadMentionItems( type, query ) {
        var cfg = MENTION_TYPES[ type ];
        if ( !cfg ) return;
        mentionMenu.items = [{ key:'__loading', label:'Loading…', icon:'⏳', desc:'' }];
        renderMentionMenu();

        var url = cfg.endpoint + ( query ? '?search=' + encodeURIComponent(query) : '' );
        apiFetch( url, 'GET', null, function(data) {
            if ( !Array.isArray(data) ) data = [];
            mentionMenu.items = data.map(function(d) {
                if ( type === 'pages' || type === 'page' ) {
                    return { key: 'page:'+d.id, label: d.title, icon:'📄', desc: d.type + ' · ' + d.status, raw: d };
                }
                if ( type === 'plugin' || type === 'plugins' ) {
                    return { key:'plugin:'+d.slug, label: d.name, icon:'🔌', desc: 'v' + d.version, raw:d };
                }
                if ( type === 'file' || type === 'folder' ) {
                    return { key: d.type+':'+d.path, label: d.name, icon: d.type==='directory'?'📂':'📄', desc: d.path, raw:d };
                }
                return { key: JSON.stringify(d), label: JSON.stringify(d), icon:'?', desc:'' };
            });
            if ( mentionMenu.items.length === 0 ) mentionMenu.items = [{ key:'__empty', label:'No results', icon:'💭', desc:'' }];
            mentionMenu.selectedIdx = 0;
            renderMentionMenu();
        }, function() {
            mentionMenu.items = [{ key:'__err', label:'Failed to load', icon:'⚠️', desc:'' }];
            renderMentionMenu();
        });
    }

    function renderMentionMenu() {
        var menu = document.getElementById('bc-mention-menu');
        if ( !menu ) return;
        if ( !mentionMenu.open ) { menu.className = 'bc-mention-menu bc-mention-menu--hidden'; return; }

        menu.className = 'bc-mention-menu';
        var html = '';
        if ( mentionMenu.type ) {
            html += '<div class="bc-mmenu__hdr"><button class="bc-mmenu__back" id="bc-mmenu-back">← Back</button><span>' + ( MENTION_TYPES[mentionMenu.type]||{} ).label + '</span></div>';
        } else {
            html += '<div class="bc-mmenu__hdr"><span>Add Context</span></div>';
        }
        mentionMenu.items.forEach(function(item, i) {
            var active = i === mentionMenu.selectedIdx ? ' bc-mmenu__item--active' : '';
            html += '<div class="bc-mmenu__item' + active + '" data-idx="' + i + '">' +
                '<span class="bc-mmenu__ico">' + item.icon + '</span>' +
                '<div class="bc-mmenu__info"><div class="bc-mmenu__label">' + escHtml(item.label) + '</div><div class="bc-mmenu__desc">' + escHtml(item.desc||'') + '</div></div>' +
            '</div>';
        });
        menu.innerHTML = html;

        // Bind back button
        var back = document.getElementById('bc-mmenu-back');
        if (back) back.addEventListener('click', function(e){ e.preventDefault(); mentionMenu.type=''; mentionMenu.query=''; showMentionTypePicker(); });

        // Bind item clicks
        menu.querySelectorAll('.bc-mmenu__item').forEach(function(row) {
            row.addEventListener('mousedown', function(e) {
                e.preventDefault();
                var idx = parseInt(row.getAttribute('data-idx'));
                selectMentionItem(idx);
            });
        });
    }

    function selectMentionItem( idx ) {
        var item = mentionMenu.items[ idx ];
        if ( !item || item.key.startsWith('__') ) return;

        if ( !mentionMenu.type ) {
            // User picked a category
            mentionMenu.type = item.key;
            mentionMenu.query = '';
            loadMentionItems( item.key, '' );
            // Update input placeholder
            var input = document.getElementById('bc-input');
            if ( input ) { var val = input.value; input.value = val.replace(/@\S*$/, '@' + item.key + ':'); }
        } else {
            // User picked an actual item
            closeMentionMenu();
            resolveAndAddMention( mentionMenu.type, item );
            // Clean the @mention text from input
            var input = document.getElementById('bc-input');
            if ( input ) input.value = input.value.replace(/@\S*$/, '').trimEnd();
        }
    }

    function resolveAndAddMention( type, item ) {
        if ( type === 'pages' || type === 'page' ) {
            var raw = item.raw;
            // Fetch full content
            showCtxBar('loading', '⏳ Loading ' + item.label + '…');
            fetchContextPost( raw.id, function(data) {
                if (data) {
                    addMention({ type:'page', id:raw.id, label:raw.title, contextText: data.context_text, builder: data.builder, templatePath: data.template_path });
                    showCtxBar('ok', '📄 ' + raw.title);
                } else {
                    showCtxBar('err','⚠️ Could not load page');
                }
            });
        } else if ( type === 'plugin' || type === 'plugins' ) {
            var raw = item.raw;
            addMention({ type:'plugin', label: raw.name, path: raw.path, contextText: 'Plugin: ' + raw.name + ' v' + raw.version + '\nPath: ' + raw.path });
            showCtxBar('ok', '🔌 ' + raw.name);
        } else if ( type === 'file' || type === 'folder' ) {
            var raw = item.raw;
            if ( raw.type === 'directory' ) {
                addMention({ type:'folder', label: raw.name, path: raw.path, contextText: 'Folder: ' + raw.path });
            } else {
                // Fetch file content
                showCtxBar('loading', '⏳ Loading ' + raw.name + '…');
                apiFetch( REST + '/file/read?path=' + encodeURIComponent(raw.path), 'GET', null, function(data) {
                    if ( data && data.content !== undefined ) {
                        addMention({ type:'file', label: raw.name, path: raw.path, contextText: '=== FILE: ' + raw.path + ' ===\n' + data.content });
                        showCtxBar('ok', '📄 ' + raw.name);
                    } else {
                        showCtxBar('err','⚠️ Could not read file');
                    }
                }, function() { showCtxBar('err','⚠️ File read failed'); });
            }
        }
    }

    function addMention( mention ) {
        // Avoid duplicates
        var dup = mentions.some(function(m){ return m.label === mention.label && m.type === mention.type; });
        if ( dup ) return;
        mentions.push( mention );
        renderMentionsBar();
    }

    function removeMention( idx ) {
        mentions.splice( idx, 1 );
        renderMentionsBar();
        if ( mentions.length === 0 ) hideCtxBar();
    }

    function renderMentionsBar() {
        var bar = document.getElementById('bc-mentions-bar');
        if ( !bar ) return;
        if ( mentions.length === 0 ) { bar.innerHTML = ''; return; }
        bar.innerHTML = mentions.map(function(m, i) {
            var ico = m.type==='page'?'📄':m.type==='plugin'?'🔌':m.type==='folder'?'📂':'📄';
            return '<div class="bc-mention-chip"><span>' + ico + ' ' + escHtml(m.label) + '</span><button data-idx="' + i + '" title="Remove">×</button></div>';
        }).join('');
        bar.querySelectorAll('button[data-idx]').forEach(function(btn){
            btn.addEventListener('click', function(){ removeMention( parseInt(btn.getAttribute('data-idx')) ); });
        });
    }

    function closeMentionMenu() {
        mentionMenu.open = false;
        mentionMenu.type = '';
        mentionMenu.items = [];
        renderMentionMenu();
    }

    // ── Context Bar ───────────────────────────────────────────
    function showCtxBar( status, text ) {
        var bar = document.getElementById('bc-ctx-bar');
        if ( !bar ) return;
        bar.className = 'bc-ctx-bar bc-ctx-bar--' + status;
        bar.textContent = text;
    }
    function hideCtxBar() {
        var bar = document.getElementById('bc-ctx-bar');
        if ( bar ) bar.className = 'bc-ctx-bar bc-ctx-bar--hidden';
    }

    // ── Events ────────────────────────────────────────────────
    function bindEvents() {
        var closeBtn = document.getElementById('bc-close');
        var form     = document.getElementById('bc-form');
        var input    = document.getElementById('bc-input');

        if ( closeBtn ) closeBtn.addEventListener('click', closeSidebar);
        if ( form )     form.addEventListener('submit', handleSubmit);
        if ( input )    input.addEventListener('input', handleInput);
        if ( input )    input.addEventListener('keydown', handleKeydown);

        document.addEventListener('keydown', function(e){ if (e.key==='Escape'&&isOpen) closeSidebar(); });
    }

    function handleInput( e ) {
        var val = e.target.value;
        var match = val.match(/@(\S*)$/);

        if ( match ) {
            var query = match[1];
            var parts = query.split(':');
            var type  = parts[0].toLowerCase();
            var subq  = parts[1] || '';

            if ( MENTION_TYPES[ type ] ) {
                mentionMenu.open  = true;
                mentionMenu.type  = type;
                mentionMenu.query = subq;
                if ( subq.length > 0 || mentionMenu.items.length === 0 ) {
                    loadMentionItems( type, subq );
                } else {
                    renderMentionMenu();
                }
            } else if ( query === '' ) {
                showMentionTypePicker();
            } else {
                // Partial match — filter type picker
                mentionMenu.open = true;
                mentionMenu.type = '';
                var types = ['pages','plugin','file','folder'];
                mentionMenu.items = [
                    { key:'pages',  label:'Pages & Posts',  icon:'📄', desc:'WordPress pages, posts, custom types' },
                    { key:'plugin', label:'Plugins',         icon:'🔌', desc:'Active WordPress plugins' },
                    { key:'file',   label:'File',            icon:'📄', desc:'Browse and attach a file' },
                    { key:'folder', label:'Folder',          icon:'📂', desc:'Attach an entire folder as context' },
                ].filter(function(t){ return t.key.startsWith(query.toLowerCase()); });
                mentionMenu.selectedIdx = 0;
                renderMentionMenu();
            }
        } else {
            closeMentionMenu();
        }
    }

    function handleKeydown( e ) {
        if ( !mentionMenu.open ) return;
        if ( e.key === 'ArrowDown' ) {
            e.preventDefault();
            mentionMenu.selectedIdx = Math.min( mentionMenu.selectedIdx + 1, mentionMenu.items.length - 1 );
            renderMentionMenu();
        } else if ( e.key === 'ArrowUp' ) {
            e.preventDefault();
            mentionMenu.selectedIdx = Math.max( mentionMenu.selectedIdx - 1, 0 );
            renderMentionMenu();
        } else if ( e.key === 'Enter' && mentionMenu.open ) {
            e.preventDefault();
            selectMentionItem( mentionMenu.selectedIdx );
        } else if ( e.key === 'Escape' ) {
            closeMentionMenu();
        }
    }

    // ── Chat ──────────────────────────────────────────────────
    function handleSubmit( e ) {
        e.preventDefault();
        var input = document.getElementById('bc-input');
        var text  = ( input ? input.value : '' ).trim();
        if ( !text || isLoading ) return;

        closeMentionMenu();
        addMsg('user', text, mentions.slice()); // snapshot mentions
        chatHistory.push({ role:'user', content: text });
        if ( input ) input.value = '';
        setLoading(true);

        // Build context string from all active mentions
        var contextParts = mentions.map(function(m){ return m.contextText || ''; }).filter(Boolean);
        var contextStr   = contextParts.join('\n\n---\n\n');

        // Build awareness note
        var awareness = 'Current location: ' + currentPage;
        if ( isEditor ) awareness += ' | Editor: ' + ( isEditor === 'elementor' ? 'Elementor' : isEditor === 'wpbakery' ? 'WPBakery' : 'WordPress Editor' );

        var body = {
            prompt: text + '\n\n[' + awareness + ']',
            history: chatHistory.slice(0, -1),
        };
        if ( contextStr ) body.page_context = contextStr;

        apiFetch( REST + '/chat', 'POST', body, function(data) {
            setLoading(false);
            if ( data && data.success ) {
                addMsg('assistant', data.response);
                chatHistory.push({ role:'assistant', content: data.response });
                if ( data.tool_log && data.tool_log.length ) {
                    var s = data.tool_log.map(function(t){ return (t.success?'✅':'❌') + ' ' + t.name; }).join('\n');
                    addMsg('tool', s);
                }
            } else {
                addMsg('assistant', '⚠️ ' + ( (data && (data.message||data.response)) || 'Error' ) );
            }
        }, function(err) {
            setLoading(false);
            addMsg('assistant', '⚠️ Connection failed: ' + err);
        });
    }

    // ── Messages ──────────────────────────────────────────────
    function addMsg( role, text, attachedMentions ) {
        var container = document.getElementById('bc-msgs');
        if ( !container ) return;
        var welcome = document.getElementById('bc-welcome');
        if ( welcome ) welcome.remove();

        var wrap   = el('div', { className: 'bc-msg bc-msg--' + role });
        var bubble = el('div', { className: 'bc-msg__bubble' });

        // Mention chips inside the message
        if ( attachedMentions && attachedMentions.length ) {
            var chipsDiv = el('div', { className: 'bc-msg__chips' });
            attachedMentions.forEach(function(m){
                var ico = m.type==='page'?'📄':m.type==='plugin'?'🔌':m.type==='folder'?'📂':'📄';
                chipsDiv.appendChild( el('span', { className:'bc-msg__chip'}, ico + ' ' + m.label) );
            });
            bubble.appendChild(chipsDiv);
        }

        var content = el('div');
        content.innerHTML = formatText( text );
        bubble.appendChild(content);
        wrap.appendChild(bubble);
        container.appendChild(wrap);
        container.scrollTop = container.scrollHeight;
    }

    function addSystemMsg( text ) {
        var container = document.getElementById('bc-msgs');
        if ( !container ) return;
        var welcome = document.getElementById('bc-welcome');
        if ( welcome ) welcome.remove();
        var d = el('div', { className:'bc-msg bc-msg--system' });
        d.innerHTML = '<div class="bc-msg__bubble">' + formatText(text) + '</div>';
        container.appendChild(d);
        container.scrollTop = container.scrollHeight;
    }

    function setLoading( state ) {
        isLoading = state;
        var btn    = document.getElementById('bc-send');
        var input  = document.getElementById('bc-input');
        var loader = document.getElementById('bc-loader');
        if ( btn )   btn.disabled = state;
        if ( input ) input.disabled = state;

        if ( state ) {
            var container = document.getElementById('bc-msgs');
            var ld = el('div', { id:'bc-loader', className:'bc-msg bc-msg--assistant' });
            ld.innerHTML = '<div class="bc-msg__bubble bc-thinking"><span class="bc-dot"></span><span class="bc-dot"></span><span class="bc-dot"></span></div>';
            if ( container ) { container.appendChild(ld); container.scrollTop = container.scrollHeight; }
        } else {
            if ( loader ) loader.remove();
            if ( input ) setTimeout(function(){ input.focus(); }, 100);
        }
    }

    // ── Sidebar toggle ────────────────────────────────────────
    function toggleSidebar() { isOpen ? closeSidebar() : openSidebar(); }

    function openSidebar() {
        isOpen = true;
        var sidebar  = document.getElementById('bc-sidebar');
        var overlay  = document.getElementById('bc-overlay');
        var fab      = document.getElementById('bc-fab');
        if ( sidebar ) sidebar.classList.add('bc-sidebar--open');
        if ( overlay ) overlay.classList.add('bc-overlay--vis');
        if ( fab )     fab.classList.add('bc-fab--active');
        setTimeout(function(){ var i=document.getElementById('bc-input'); if(i)i.focus(); }, 300);
    }

    function closeSidebar() {
        isOpen = false;
        var sidebar = document.getElementById('bc-sidebar');
        var overlay = document.getElementById('bc-overlay');
        var fab     = document.getElementById('bc-fab');
        if ( sidebar ) sidebar.classList.remove('bc-sidebar--open');
        if ( overlay ) overlay.classList.remove('bc-overlay--vis');
        if ( fab )     fab.classList.remove('bc-fab--active');
        closeMentionMenu();
    }

    // ── Helpers ───────────────────────────────────────────────
    function el( tag, attrs, text ) {
        var e = document.createElement(tag);
        if ( attrs ) Object.keys(attrs).forEach(function(k){ if(k==='className') e.className=attrs[k]; else e.setAttribute(k,attrs[k]); });
        if ( text !== undefined ) e.textContent = text;
        return e;
    }

    function escHtml(s) { var d=document.createElement('div'); d.appendChild(document.createTextNode(String(s||''))); return d.innerHTML; }

    function formatText( text ) {
        return escHtml(text)
            .replace(/```([\s\S]*?)```/g, '<pre class="bc-code">$1</pre>')
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/\n/g, '<br>');
    }

    function apiFetch( url, method, body, onSuccess, onError ) {
        var opts = { method: method || 'GET', headers: { 'X-WP-Nonce': NONCE } };
        if ( body ) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
        fetch(url, opts)
            .then(function(r){ return r.json(); })
            .then(function(d){ if(onSuccess) onSuccess(d); })
            .catch(function(e){ if(onError) onError(e.message||'Error'); });
    }

    // ── Bootstrap ─────────────────────────────────────────────
    if ( document.readyState === 'loading' ) { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
