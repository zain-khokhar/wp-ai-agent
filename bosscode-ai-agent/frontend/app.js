var useState = React.useState, useEffect = React.useEffect, useRef = React.useRef, useCallback = React.useCallback;

function getLang(ext) {
    var map = {php:'php',js:'javascript',jsx:'javascript',ts:'typescript',tsx:'typescript',css:'css',scss:'scss',html:'html',json:'json',md:'markdown',sql:'sql',xml:'xml',txt:'plaintext',py:'python'};
    return map[ext] || 'plaintext';
}
function getIcon(name, isDir) {
    if (isDir) return '\uD83D\uDCC1';
    var ext = (name.split('.').pop() || '').toLowerCase();
    var icons = {php:'\uD83D\uDC18',js:'\uD83D\uDFE8',css:'\uD83C\uDFA8',html:'\uD83C\uDF10',json:'\uD83D\uDCCB',md:'\uD83D\uDCDD',png:'\uD83D\uDDBC',jpg:'\uD83D\uDDBC',svg:'\uD83D\uDDBC'};
    return icons[ext] || '\uD83D\uDCC4';
}
function fmtSize(b) { if (b < 1024) return b + ' B'; return (b/1024).toFixed(1) + ' KB'; }

// ─── FileTree ───────────────────────────────────────────────
function FileTree(p) {
    return React.createElement('div', {className:'bc-tree'},
        p.nodes.map(function(n) {
            return React.createElement(FileNode, {key:n.path, node:n, depth:p.depth||0, activeFile:p.activeFile, onFileClick:p.onFileClick, onLoadChildren:p.onLoadChildren, onAttach:p.onAttach});
        })
    );
}
function FileNode(p) {
    var n=p.node, d=p.depth, isDir=n.type==='directory', isOpen=n._open||false, isActive=p.activeFile===n.path;
    var pad={paddingLeft:(d*16+8)+'px'};
    function click(){if(isDir){if(!n._children&&!n._loading)p.onLoadChildren(n);else{n._open=!n._open;p.onLoadChildren(n,true);}}else p.onFileClick(n);}
    function attach(e){e.stopPropagation();if(p.onAttach)p.onAttach(n);}
    var cls='bc-tree__node'+(isActive?' bc-tree__node--active':'')+(isDir?' bc-tree__node--dir':'');
    return React.createElement('div',null,
        React.createElement('div',{className:cls,style:pad,onClick:click},
            isDir?React.createElement('span',{className:'bc-tree__arrow'+(isOpen?' bc-tree__arrow--open':'')},'▶'):React.createElement('span',{className:'bc-tree__arrow'},''),
            React.createElement('span',{className:'bc-tree__icon'},getIcon(n.name,isDir)),
            React.createElement('span',{className:'bc-tree__name'},n.name),
            !isDir&&n.size?React.createElement('span',{className:'bc-tree__size'},fmtSize(n.size)):null,
            p.onAttach?React.createElement('button',{className:'bc-tree__attach',onClick:attach,title:'Attach as context'},'📎'):null
        ),
        isDir&&isOpen&&n._children?React.createElement(FileTree,{nodes:n._children,depth:d+1,activeFile:p.activeFile,onFileClick:p.onFileClick,onLoadChildren:p.onLoadChildren,onAttach:p.onAttach}):null
    );
}

// ─── AttachmentChips ────────────────────────────────────────
function AttachmentChips(p) {
    if (!p.files||p.files.length===0) return null;
    return React.createElement('div',{className:'bc-attachments'},
        p.files.map(function(f,i){
            var ico = f.type==='directory'?'📁':f.type==='page'?'📄':f.type==='plugin'?'🔌':'📄';
            var name = f.name || (f.path ? f.path.split('/').pop() : '');
            return React.createElement('div',{key:i,className:'bc-attach-chip'},
                React.createElement('span',{className:'bc-attach-chip__icon'},ico),
                React.createElement('span',{className:'bc-attach-chip__name'},name),
                React.createElement('button',{className:'bc-attach-chip__remove',onClick:function(){p.onRemove(i);}},'×')
            );
        })
    );
}

// ─── Main App ───────────────────────────────────────────────
function App() {
    var panelState=useState({files:true,editor:true,chat:true});
    var panels=panelState[0],setPanels=panelState[1];

    var settingsState=useState({provider:'openai_compatible',base_url:'http://localhost:11434/v1',api_key:'',api_key_is_set:false,model:'local-model',max_loop_iterations:15,embedding_model:'text-embedding-3-small',gemini_auto_url:'http://localhost:3200',gemini_auto_enabled:false,allowed_paths:[]});
    var settings=settingsState[0],setSettings=settingsState[1];
    var showSettingsState=useState(false); var showSettings=showSettingsState[0],setShowSettings=showSettingsState[1];
    var saveStatusState=useState(''); var saveStatus=saveStatusState[0],setSaveStatus=saveStatusState[1];
    var geminiHealthState=useState(null); var geminiHealth=geminiHealthState[0],setGeminiHealth=geminiHealthState[1];

    var fileTreeState=useState([]); var fileTree=fileTreeState[0],setFileTree=fileTreeState[1];
    var openFileState=useState(null); var openFile=openFileState[0],setOpenFile=openFileState[1];
    var monacoReadyState=useState(false); var monacoReady=monacoReadyState[0],setMonacoReady=monacoReadyState[1];
    var editorRef=useRef(null); var editorContainerRef=useRef(null);

    var chatState=useState([]); var chatHistory=chatState[0],setChatHistory=chatState[1];
    var promptState=useState(''); var prompt=promptState[0],setPrompt=promptState[1];
    var loadingState=useState(false); var isLoading=loadingState[0],setIsLoading=loadingState[1];
    var toolLogState=useState([]); var toolLog=toolLogState[0],setToolLog=toolLogState[1];
    var streamStatusState=useState(null); var streamStatus=streamStatusState[0],setStreamStatus=streamStatusState[1];

    // File attachments for context
    var attachState=useState([]); var attachedFiles=attachState[0],setAttachedFiles=attachState[1];

    // Mention Menu State
    var mentionMenuState=useState({open:false,type:'',query:'',items:[],selectedIdx:0});
    var mentionMenu=mentionMenuState[0],setMentionMenu=mentionMenuState[1];

    var messagesEndRef=useRef(null); var inputRef=useRef(null); var abortRef=useRef(null);

    var R=window.bosscodeAI.restUrl; var N=window.bosscodeAI.nonce; var V=window.bosscodeAI.version||'2.0.0';

    useEffect(function(){if(messagesEndRef.current)messagesEndRef.current.scrollIntoView({behavior:'smooth'});},[chatHistory,isLoading]);
    useEffect(function(){fetch(R+'/settings',{headers:{'X-WP-Nonce':N}}).then(function(r){return r.json();}).then(function(d){if(d)setSettings(function(p){var m={};for(var k in p)m[k]=p[k];for(var k in d)m[k]=d[k];return m;});}).catch(function(){});},[]);
    useEffect(function(){fetch(R+'/files',{headers:{'X-WP-Nonce':N}}).then(function(r){return r.json();}).then(function(d){if(Array.isArray(d))setFileTree(d);}).catch(function(){});},[]);

    // Load Monaco
    useEffect(function(){
        if(window.monaco){setMonacoReady(true);return;}
        var s=document.createElement('script');s.src='https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs/loader.js';
        s.onload=function(){window.require.config({paths:{vs:'https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs'}});window.require(['vs/editor/editor.main'],function(){setMonacoReady(true);});};
        document.head.appendChild(s);
    },[]);
    useEffect(function(){
        if(!monacoReady||!editorContainerRef.current||!panels.editor)return;
        if(!editorRef.current){editorRef.current=window.monaco.editor.create(editorContainerRef.current,{value:openFile?openFile.content:'// Select a file from the explorer',language:openFile?getLang(openFile.ext):'plaintext',theme:'vs-dark',readOnly:true,minimap:{enabled:true},fontSize:13,fontFamily:"'JetBrains Mono',monospace",padding:{top:12},scrollBeyondLastLine:false,automaticLayout:true,wordWrap:'on',renderLineHighlight:'gutter',lineNumbers:'on'});}
    },[monacoReady,panels.editor]);
    useEffect(function(){if(editorRef.current&&openFile){editorRef.current.setValue(openFile.content);var m=editorRef.current.getModel();if(m)window.monaco.editor.setModelLanguage(m,getLang(openFile.ext));}},[openFile]);
    useEffect(function(){if(editorRef.current)setTimeout(function(){if(editorRef.current)editorRef.current.layout();},100);},[panels]);

    // Check Gemini health when provider is gemini_auto
    useEffect(function(){
        if(settings.provider!=='gemini_auto')return;
        fetch(R+'/gemini/health',{headers:{'X-WP-Nonce':N}}).then(function(r){return r.json();}).then(function(d){setGeminiHealth(d);}).catch(function(){setGeminiHealth({status:'offline'});});
    },[settings.provider]);

    function loadChildren(node,toggle){
        if(toggle){setFileTree(function(t){return[].concat(t);});return;}
        node._loading=true;setFileTree(function(t){return[].concat(t);});
        fetch(R+'/files?path='+encodeURIComponent(node.path),{headers:{'X-WP-Nonce':N}}).then(function(r){return r.json();}).then(function(d){node._children=Array.isArray(d)?d:[];node._open=true;node._loading=false;setFileTree(function(t){return[].concat(t);});}).catch(function(){node._loading=false;setFileTree(function(t){return[].concat(t);});});
    }
    function handleFileClick(node){
        fetch(R+'/file/read?path='+encodeURIComponent(node.path),{headers:{'X-WP-Nonce':N}}).then(function(r){return r.json();}).then(function(d){if(d&&d.content!==undefined){setOpenFile(d);if(!panels.editor)setPanels(function(p){return{files:p.files,editor:true,chat:p.chat};});}}).catch(function(){});
    }
    function handleAttachFile(node){
        // Convert absolute path to relative for context
        var relPath=node.path;
        // Avoid duplicates
        var exists=attachedFiles.some(function(f){return f.path===relPath;});
        if(!exists){setAttachedFiles(function(p){return p.concat([{path:relPath,name:node.name,type:node.type}]);});}
    }
    function handleAttachCurrentFile(){
        if(openFile){var exists=attachedFiles.some(function(f){return f.path===openFile.path;});if(!exists){setAttachedFiles(function(p){return p.concat([{path:openFile.path,name:openFile.name,type:'file'}]);});}}
    }
    function handleRemoveAttachment(idx){setAttachedFiles(function(p){var n=[].concat(p);n.splice(idx,1);return n;});}

    // ─── Mention logic ────────────────────────────────────────
    var MENTION_TYPES = {
        'pages':   { label: 'Pages & Posts', icon: '📄', endpoint: R + '/context/pages' },
        'page':    { label: 'Pages & Posts', icon: '📄', endpoint: R + '/context/pages' },
        'plugin':  { label: 'Plugins',       icon: '🔌', endpoint: R + '/context/plugins' },
        'plugins': { label: 'Plugins',       icon: '🔌', endpoint: R + '/context/plugins' },
        'file':    { label: 'Files',         icon: '📁', endpoint: R + '/files' },
        'folder':  { label: 'Folders',       icon: '📂', endpoint: R + '/files' },
    };

    function loadMentionItems(type, query) {
        var cfg = MENTION_TYPES[type];
        if (!cfg) return;
        setMentionMenu(function(prev){return Object.assign({},prev,{items:[{key:'__loading',label:'Loading...',icon:'⏳'}]});});
        var url = cfg.endpoint + (query ? '?search=' + encodeURIComponent(query) : '');
        fetch(url, {headers:{'X-WP-Nonce':N}}).then(function(r){return r.json();}).then(function(data){
            if(!Array.isArray(data)) data=[];
            var items = data.map(function(d){
                if (type==='pages'||type==='page') return {key:'page:'+d.id, label:d.title, icon:'📄', desc:d.type+' · '+d.status, raw:d};
                if (type==='plugin'||type==='plugins') return {key:'plugin:'+d.slug, label:d.name, icon:'🔌', desc:'v'+d.version, raw:d};
                if (type==='file'||type==='folder') return {key:d.type+':'+d.path, label:d.name, icon:d.type==='directory'?'📂':'📄', desc:d.path, raw:d};
                return {key:JSON.stringify(d),label:JSON.stringify(d),icon:'?'};
            });
            if(items.length===0) items=[{key:'__empty',label:'No results',icon:'💭'}];
            setMentionMenu(function(prev){return Object.assign({},prev,{items:items,selectedIdx:0});});
        }).catch(function(){
            setMentionMenu(function(prev){return Object.assign({},prev,{items:[{key:'__err',label:'Failed to load',icon:'⚠️'}]});});
        });
    }

    function showMentionTypePicker() {
        setMentionMenu({
            open: true, type: '', query: '', selectedIdx: 0,
            items: [
                { key:'pages',  label:'Pages & Posts',  icon:'📄', desc:'WordPress pages, posts' },
                { key:'plugin', label:'Plugins',        icon:'🔌', desc:'Active plugins' },
                { key:'file',   label:'File',           icon:'📄', desc:'Attach a file' },
                { key:'folder', label:'Folder',         icon:'📂', desc:'Attach a folder' }
            ]
        });
    }

    function selectMentionItem(idx) {
        var item = mentionMenu.items[idx];
        if (!item || item.key.startsWith('__')) return;

        if (!mentionMenu.type) {
            setMentionMenu(function(p){return Object.assign({},p,{type:item.key,query:''});});
            loadMentionItems(item.key, '');
            var v = prompt; setPrompt(v.replace(/@\S*$/, '@' + item.key + ':'));
            inputRef.current&&inputRef.current.focus();
        } else {
            setMentionMenu({open:false,type:'',query:'',items:[],selectedIdx:0});
            var v = prompt; setPrompt(v.replace(/@\S*$/, '').trimEnd());
            
            var type = mentionMenu.type;
            var raw = item.raw;
            if (type==='pages'||type==='page') {
                fetch(R+'/context/post/'+raw.id, {headers:{'X-WP-Nonce':N}}).then(function(r){return r.json();}).then(function(d){
                    if(d) setAttachedFiles(function(p){return p.concat([{type:'page', name:raw.title, contextText:d.context_text}]);});
                });
            } else if (type==='plugin'||type==='plugins') {
                setAttachedFiles(function(p){return p.concat([{type:'plugin', name:raw.name, contextText:'Plugin: '+raw.name+' v'+raw.version+'\nPath: '+raw.path}]);});
            } else if (type==='file'||type==='folder') {
                if (raw.type==='directory') {
                    setAttachedFiles(function(p){return p.concat([{path:raw.path, name:raw.name, type:'directory'}]);});
                } else {
                    fetch(R+'/file/read?path='+encodeURIComponent(raw.path), {headers:{'X-WP-Nonce':N}}).then(function(r){return r.json();}).then(function(d){
                        if(d&&d.content!==undefined) setAttachedFiles(function(p){return p.concat([{path:raw.path, name:raw.name, type:'file', contextText:'=== FILE: '+raw.path+' ===\n'+d.content}]);});
                    });
                }
            }
            inputRef.current&&inputRef.current.focus();
        }
    }

    function handlePromptChange(e) {
        var val = e.target.value;
        setPrompt(val);
        var match = val.match(/@(\S*)$/);
        if (match) {
            var query = match[1];
            var parts = query.split(':');
            var type = parts[0].toLowerCase();
            var subq = parts[1] || '';

            if (MENTION_TYPES[type]) {
                setMentionMenu(function(p){return Object.assign({},p,{open:true,type:type,query:subq});});
                loadMentionItems(type, subq);
            } else if (query === '') {
                showMentionTypePicker();
            } else {
                var items = [
                    { key:'pages',  label:'Pages & Posts',  icon:'📄', desc:'WordPress pages, posts' },
                    { key:'plugin', label:'Plugins',        icon:'🔌', desc:'Active plugins' },
                    { key:'file',   label:'File',           icon:'📄', desc:'Attach a file' },
                    { key:'folder', label:'Folder',         icon:'📂', desc:'Attach a folder' }
                ].filter(function(t){ return t.key.startsWith(query.toLowerCase()); });
                setMentionMenu({open:true, type:'', query:query, items:items, selectedIdx:0});
            }
        } else {
            setMentionMenu({open:false, type:'', query:'', items:[], selectedIdx:0});
        }
    }

    function handlePromptKeyDown(e) {
        if (!mentionMenu.open) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setMentionMenu(function(p){return Object.assign({},p,{selectedIdx:Math.min(p.selectedIdx+1, Math.max(0,p.items.length-1))});});
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setMentionMenu(function(p){return Object.assign({},p,{selectedIdx:Math.max(p.selectedIdx-1, 0)});});
        } else if (e.key === 'Enter') {
            e.preventDefault();
            selectMentionItem(mentionMenu.selectedIdx);
        } else if (e.key === 'Escape') {
            setMentionMenu({open:false, type:'', query:'', items:[], selectedIdx:0});
        }
    }

    function handleSaveSettings(e){
        e.preventDefault();setSaveStatus('saving');
        fetch(R+'/settings',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':N},body:JSON.stringify(settings)}).then(function(r){return r.json();}).then(function(d){if(d&&d.success){setSaveStatus('success');setTimeout(function(){setSaveStatus('');},3000);}else setSaveStatus('error');}).catch(function(){setSaveStatus('error');});
    }

    function handleChatSubmit(e){
        e.preventDefault();
        if(!prompt.trim()||isLoading)return;
        var userMsg={role:'user',content:prompt,attachments:attachedFiles.length>0?[].concat(attachedFiles):undefined};
        var prev=[].concat(chatHistory);
        setChatHistory(function(p){return p.concat([userMsg]);});
        setPrompt('');setIsLoading(true);setToolLog([]);setStreamStatus(null);
        var ctrl=new AbortController();abortRef.current=ctrl;

        // Build request body with file context
        var body={prompt:userMsg.content,history:prev};
        var contextStrParts = [];
        if(attachedFiles.length>0){
            var files=[],dirs=[];
            attachedFiles.forEach(function(f){
                if(f.type==='directory')dirs.push(f.path);
                else if(f.type==='file'&&!f.contextText)files.push(f.path);
                else if(f.contextText)contextStrParts.push(f.contextText);
            });
            if(files.length>0)body.context_files=files;
            if(dirs.length>0)body.context_dirs=dirs;
            if(contextStrParts.length>0)body.page_context=contextStrParts.join('\n\n---\n\n');
        }

        fetch(R+'/chat/stream',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':N},body:JSON.stringify(body),signal:ctrl.signal}).then(function(res){
            if(!res.ok)return res.json().then(function(d){throw new Error((d&&d.message)||'Error '+res.status);});
            var reader=res.body.getReader(),decoder=new TextDecoder(),buf='',tl=[],fc='',done=false;
            function pump(){
                return reader.read().then(function(r){
                    if(r.done||done)return finish();
                    buf+=decoder.decode(r.value,{stream:true});
                    var lines=buf.split('\n');buf=lines.pop()||'';
                    for(var i=0;i<lines.length;i++){
                        var ln=lines[i].trim();if(!ln||ln.indexOf('data: ')!==0)continue;
                        try{var d=JSON.parse(ln.substring(6));}catch(e2){continue;}
                        if(d.type==='iteration')setStreamStatus({current:d.current,max:d.max});
                        else if(d.type==='tool_call'){tl.push({name:d.name,args:d.args,success:null,result:'Running...'});setToolLog([].concat(tl));}
                        else if(d.type==='tool_status'){for(var t=tl.length-1;t>=0;t--){if(tl[t].name===d.name&&tl[t].success===null){tl[t].success=d.success;tl[t].result=d.result;break;}}setToolLog([].concat(tl));}
                        else if(d.type==='done'){fc=d.content||'';if(d.tool_log)tl=d.tool_log;done=true;}
                        else if(d.type==='error'){fc='\u26a0\ufe0f '+(d.message||'Error');done=true;}
                    }
                    if(done)return finish();return pump();
                });
            }
            function finish(){
                setChatHistory(function(p){return p.concat([{role:'assistant',content:fc,toolLog:tl}]);});
                setToolLog(tl);setIsLoading(false);setStreamStatus(null);abortRef.current=null;
                if(inputRef.current)inputRef.current.focus();
            }
            return pump();
        }).catch(function(err){
            if(err.name!=='AbortError')setChatHistory(function(p){return p.concat([{role:'assistant',content:'\u26a0\ufe0f '+(err.message||'Connection failed.')}]);});
            setIsLoading(false);setStreamStatus(null);abortRef.current=null;
        });
    }

    function handleCancel(){if(abortRef.current){abortRef.current.abort();abortRef.current=null;}setIsLoading(false);setStreamStatus(null);}
    function togglePanel(p){setPanels(function(s){var n={};for(var k in s)n[k]=s[k];n[p]=!n[p];return n;});}
    function updateSetting(k,v){setSettings(function(s){var n={};for(var kk in s)n[kk]=s[kk];n[k]=v;return n;});}

    // ─── Render ────────────────────────────────────────────
    return (
        React.createElement('div',{className:'bc-ide'},
            // Header
            React.createElement('div',{className:'bc-ide__header'},
                React.createElement('div',{className:'bc-ide__brand'},
                    React.createElement('div',{className:'bc-ide__logo'},'\uD83E\uDD16'),
                    React.createElement('span',{className:'bc-ide__title'},'BossCode AI'),
                    React.createElement('span',{className:'bc-ide__ver'},'v'+V),
                    settings.provider==='gemini_auto'&&geminiHealth?React.createElement('span',{className:'bc-ide__status bc-ide__status--'+(geminiHealth.status==='ready'?'on':'off')},geminiHealth.status==='ready'?'🟢 Gemini Active':'🔴 Gemini Offline'):null
                ),
                React.createElement('div',{className:'bc-ide__actions'},
                    React.createElement('button',{className:'bc-ide__toggle'+(panels.files?' bc-ide__toggle--on':''),onClick:function(){togglePanel('files');},title:'Files'},'\uD83D\uDCC2'),
                    React.createElement('button',{className:'bc-ide__toggle'+(panels.editor?' bc-ide__toggle--on':''),onClick:function(){togglePanel('editor');},title:'Editor'},'\uD83D\uDCDD'),
                    React.createElement('button',{className:'bc-ide__toggle'+(panels.chat?' bc-ide__toggle--on':''),onClick:function(){togglePanel('chat');},title:'Chat'},'\uD83D\uDCAC'),
                    React.createElement('div',{className:'bc-ide__sep'}),
                    React.createElement('button',{className:'bc-ide__btn',onClick:function(){setShowSettings(true);}},'\u2699\ufe0f Settings')
                )
            ),
            // Body
            React.createElement('div',{className:'bc-ide__body'},
                // File Explorer
                panels.files&&React.createElement('div',{className:'bc-panel bc-panel--files'},
                    React.createElement('div',{className:'bc-panel__head'},'EXPLORER'),
                    React.createElement('div',{className:'bc-panel__body'},
                        fileTree.length===0?React.createElement('div',{className:'bc-panel__empty'},'No paths configured.'):React.createElement(FileTree,{nodes:fileTree,activeFile:openFile?openFile.path:'',onFileClick:handleFileClick,onLoadChildren:loadChildren,onAttach:handleAttachFile})
                    )
                ),
                // Editor
                panels.editor&&React.createElement('div',{className:'bc-panel bc-panel--editor'},
                    React.createElement('div',{className:'bc-panel__head'},
                        openFile?React.createElement('span',null,getIcon(openFile.name,false),' ',openFile.name,React.createElement('button',{className:'bc-btn bc-btn--sm',onClick:handleAttachCurrentFile,title:'Use as context',style:{marginLeft:'8px'}},'📎 Attach')):'Editor'
                    ),
                    React.createElement('div',{className:'bc-panel__body bc-panel__body--editor',ref:editorContainerRef},
                        !monacoReady&&React.createElement('div',{className:'bc-panel__loading'},'Loading editor...')
                    )
                ),
                // Chat
                panels.chat&&React.createElement('div',{className:'bc-panel bc-panel--chat'},
                    React.createElement('div',{className:'bc-panel__head'},'\uD83D\uDCAC Agent Chat'),
                    React.createElement('div',{className:'bc-chat__messages'},
                        chatHistory.length===0&&React.createElement('div',{className:'bc-welcome'},
                            React.createElement('div',{className:'bc-welcome__icon'},'\uD83D\uDE80'),
                            React.createElement('h2',{className:'bc-welcome__title'},'BossCode Agent'),
                            React.createElement('p',{className:'bc-welcome__text'},'Ask me to read, write, or modify files. Attach files from the explorer for context.')
                        ),
                        chatHistory.map(function(msg,i){
                            return React.createElement('div',{key:i,className:'bc-msg bc-msg--'+msg.role},
                                React.createElement('div',{className:'bc-msg__av'},msg.role==='user'?'U':'AI'),
                                React.createElement('div',{className:'bc-msg__body'},
                                    React.createElement('div',{className:'bc-msg__label'},msg.role==='user'?'You':'BossCode'),
                                    msg.attachments&&msg.attachments.length>0&&React.createElement('div',{className:'bc-msg__attachments'},msg.attachments.map(function(a,ai){return React.createElement('span',{key:ai,className:'bc-attach-chip bc-attach-chip--sm'},(a.type==='directory'?'📁':'📄')+' '+a.name);})),
                                    React.createElement('div',{className:'bc-msg__text',dangerouslySetInnerHTML:{__html:
                                        (msg.content||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                                        .replace(/```([\s\S]*?)```/g,'<pre class="bc-code">$1</pre>')
                                        .replace(/`([^`]+)`/g,'<code>$1</code>')
                                        .replace(/\n/g,'<br>')
                                    }}),
                                    msg.toolLog&&msg.toolLog.length>0&&React.createElement('div',{className:'bc-tools'},
                                        msg.toolLog.map(function(t,ti){return React.createElement('div',{key:ti,className:'bc-tools__item'},React.createElement('span',null,t.success?'\u2705':'\u274c'),React.createElement('span',null,t.name),React.createElement('span',{className:'bc-tools__status'},t.success?'done':'failed'));})
                                    )
                                )
                            );
                        }),
                        isLoading&&React.createElement('div',{className:'bc-msg bc-msg--assistant'},
                            React.createElement('div',{className:'bc-msg__av'},'AI'),
                            React.createElement('div',{className:'bc-thinking'},
                                React.createElement('div',{className:'bc-thinking__dots'},React.createElement('div',{className:'bc-dot'}),React.createElement('div',{className:'bc-dot'}),React.createElement('div',{className:'bc-dot'})),
                                streamStatus?React.createElement('span',{className:'bc-thinking__info'},'Iteration '+streamStatus.current+'/'+streamStatus.max):React.createElement('span',{className:'bc-thinking__info'},'Connecting...'),
                                toolLog.length>0&&React.createElement('div',{className:'bc-thinking__tools'},toolLog.map(function(t,i){return React.createElement('div',{key:i,className:'bc-tools__item bc-tools__item--sm'},React.createElement('span',null,t.success===null?'\u23f3':(t.success?'\u2705':'\u274c')),React.createElement('span',null,t.name));}))
                            )
                        ),
                        React.createElement('div',{ref:messagesEndRef})
                    ),
                    // Attachments bar
                    React.createElement(AttachmentChips,{files:attachedFiles,onRemove:handleRemoveAttachment}),
                    // Input
                    React.createElement('div',{className:'bc-chat__input', style:{position:'relative'}},
                        mentionMenu.open && React.createElement('div', {className:'bc-mention-menu'},
                            mentionMenu.type && React.createElement('div',{className:'bc-mmenu__hdr'},
                                React.createElement('button',{className:'bc-mmenu__back',onClick:function(e){e.preventDefault();showMentionTypePicker();}},'← Back'),
                                React.createElement('span',null,(MENTION_TYPES[mentionMenu.type]||{}).label)
                            ),
                            !mentionMenu.type && React.createElement('div',{className:'bc-mmenu__hdr'}, React.createElement('span',null,'Add Context')),
                            mentionMenu.items.map(function(item, i) {
                                var active = i === mentionMenu.selectedIdx ? ' bc-mmenu__item--active' : '';
                                return React.createElement('div', {
                                    key: item.key + '_' + i,
                                    className: 'bc-mmenu__item' + active,
                                    onMouseDown: function(e){e.preventDefault();selectMentionItem(i);},
                                    onMouseEnter: function(){setMentionMenu(function(p){return Object.assign({},p,{selectedIdx:i});});}
                                },
                                    React.createElement('span',{className:'bc-mmenu__ico'},item.icon),
                                    React.createElement('div',{className:'bc-mmenu__info'},
                                        React.createElement('div',{className:'bc-mmenu__label'},item.label),
                                        React.createElement('div',{className:'bc-mmenu__desc'},item.desc||'')
                                    )
                                );
                            })
                        ),
                        isLoading&&React.createElement('button',{className:'bc-btn bc-btn--cancel',onClick:handleCancel},'■ Stop'),
                        React.createElement('form',{className:'bc-chat__form',onSubmit:handleChatSubmit},
                            React.createElement('input',{ref:inputRef,type:'text',value:prompt,onChange:handlePromptChange,onKeyDown:handlePromptKeyDown,className:'bc-input',placeholder:attachedFiles.length>0?'Ask about attached files...':'Ask anything... type @ for context',disabled:isLoading}),
                            React.createElement('button',{type:'submit',className:'bc-btn bc-btn--send',disabled:isLoading||!prompt.trim()},'➤')
                        )
                    )
                )
            ),
            // Settings Modal
            showSettings&&React.createElement('div',{className:'bc-modal__overlay',onClick:function(){setShowSettings(false);}},
                React.createElement('div',{className:'bc-modal',onClick:function(e){e.stopPropagation();}},
                    React.createElement('div',{className:'bc-modal__head'},
                        React.createElement('h2',null,'\u2699\ufe0f Settings'),
                        React.createElement('button',{className:'bc-modal__close',onClick:function(){setShowSettings(false);}},'×')
                    ),
                    React.createElement('form',{className:'bc-modal__body',onSubmit:handleSaveSettings},
                        React.createElement('div',{className:'bc-field'},
                            React.createElement('label',{className:'bc-field__label'},'Provider'),
                            React.createElement('select',{className:'bc-field__select',value:settings.provider,onChange:function(e){updateSetting('provider',e.target.value);}},
                                React.createElement('option',{value:'openai_compatible'},'OpenAI Compatible (Ollama, LM Studio)'),
                                React.createElement('option',{value:'openai'},'OpenAI'),
                                React.createElement('option',{value:'groq'},'GroqCloud'),
                                React.createElement('option',{value:'anthropic'},'Anthropic (Claude)'),
                                React.createElement('option',{value:'gemini_auto'},'🤖 Gemini Auto (Browser Automation)'),
                                React.createElement('option',{value:'custom'},'Custom Endpoint')
                            )
                        ),
                        // Gemini Auto settings
                        settings.provider==='gemini_auto'&&React.createElement('div',{className:'bc-field bc-field--highlight'},
                            React.createElement('label',{className:'bc-field__label'},'Gemini Automation Server URL'),
                            React.createElement('input',{type:'text',className:'bc-field__input',value:settings.gemini_auto_url,onChange:function(e){updateSetting('gemini_auto_url',e.target.value);}}),
                            React.createElement('small',{className:'bc-field__help'},'Run: cd gemini-automation && npm install && node server.js'),
                            geminiHealth&&React.createElement('div',{className:'bc-field__status bc-field__status--'+(geminiHealth.status==='ready'?'ok':'err')},
                                geminiHealth.status==='ready'?'🟢 Connected — '+geminiHealth.message:'🔴 '+(geminiHealth.message||'Not connected')
                            )
                        ),
                        // Standard API settings (hidden for gemini_auto)
                        settings.provider!=='gemini_auto'&&React.createElement('div',null,
                            React.createElement('div',{className:'bc-field'},
                                React.createElement('label',{className:'bc-field__label'},'API Base URL'),
                                React.createElement('input',{type:'text',className:'bc-field__input',value:settings.base_url,onChange:function(e){updateSetting('base_url',e.target.value);}})
                            ),
                            React.createElement('div',{className:'bc-field'},
                                React.createElement('label',{className:'bc-field__label'},'API Key'),
                                React.createElement('input',{type:'password',className:'bc-field__input',value:settings.api_key,placeholder:settings.api_key_is_set?'Key is set (hidden)':'Enter API key',onChange:function(e){updateSetting('api_key',e.target.value);}})
                            ),
                            React.createElement('div',{className:'bc-field'},
                                React.createElement('label',{className:'bc-field__label'},'Model'),
                                React.createElement('input',{type:'text',className:'bc-field__input',value:settings.model,onChange:function(e){updateSetting('model',e.target.value);}})
                            )
                        ),
                        React.createElement('div',{className:'bc-field'},
                            React.createElement('label',{className:'bc-field__label'},'Max Loop Iterations'),
                            React.createElement('input',{type:'number',className:'bc-field__input',value:settings.max_loop_iterations,min:1,max:50,onChange:function(e){updateSetting('max_loop_iterations',parseInt(e.target.value)||15);}})
                        ),
                        React.createElement('div',{className:'bc-modal__foot'},
                            React.createElement('button',{type:'submit',className:'bc-btn bc-btn--send'},saveStatus==='saving'?'Saving...':'Save Settings'),
                            saveStatus==='success'&&React.createElement('span',{className:'bc-status--ok'},'\u2705 Saved'),
                            saveStatus==='error'&&React.createElement('span',{className:'bc-status--err'},'\u274c Error')
                        )
                    )
                )
            )
        )
    );
}

ReactDOM.createRoot(document.getElementById('bosscode-ai-app')).render(React.createElement(App));
