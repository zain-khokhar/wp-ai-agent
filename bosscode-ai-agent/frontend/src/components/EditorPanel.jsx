import React, { useEffect, useRef, useState } from 'react';

function getLang(ext) {
    const map = {php:'php',js:'javascript',jsx:'javascript',ts:'typescript',tsx:'typescript',css:'css',scss:'scss',html:'html',json:'json',md:'markdown',sql:'sql',xml:'xml',txt:'plaintext',py:'python'};
    return map[ext] || 'plaintext';
}

export default function EditorPanel({ openFile, setOpenFile, togglePanel, attachedFiles, setAttachedFiles }) {
    const [monacoReady, setMonacoReady] = useState(false);
    const editorContainerRef = useRef(null);
    const editorRef = useRef(null);

    useEffect(() => {
        if(window.monaco) { setMonacoReady(true); return; }
        const s = document.createElement('script'); s.src = 'https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs/loader.js';
        s.onload = () => {
            window.require.config({paths:{vs:'https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs'}});
            window.require(['vs/editor/editor.main'], () => setMonacoReady(true));
        };
        document.head.appendChild(s);
    }, []);

    useEffect(() => {
        if(!monacoReady || !editorContainerRef.current) return;
        if(!editorRef.current) {
            editorRef.current = window.monaco.editor.create(editorContainerRef.current, {
                value: openFile ? openFile.content : '// Select a file from the explorer',
                language: openFile ? getLang(openFile.ext) : 'plaintext',
                theme: 'vs-dark', readOnly: true, minimap: {enabled:true},
                fontSize: 13, fontFamily: "'JetBrains Mono',monospace", padding: {top:12},
                scrollBeyondLastLine: false, automaticLayout: true, wordWrap: 'on'
            });
        }
    }, [monacoReady]);

    useEffect(() => {
        if(editorRef.current && openFile) {
            editorRef.current.setValue(openFile.content);
            const m = editorRef.current.getModel();
            if(m) window.monaco.editor.setModelLanguage(m, getLang(openFile.ext));
        }
    }, [openFile]);

    const attach = () => {
        if(openFile && !attachedFiles.some(f => f.path === openFile.path)) {
            setAttachedFiles(p => [...p, { path: openFile.path, name: openFile.name, type: 'file' }]);
        }
    };

    return (
        <div className="bc-panel bc-panel--editor">
            <div className="bc-panel__head">
                <div style={{display:'flex', alignItems:'center', gap:'6px', minWidth:0, flex:1}}>
                    {openFile ? <span style={{overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap'}}>{openFile.name}</span> : <span>Editor</span>}
                </div>
                <div style={{display:'flex', alignItems:'center', gap:'4px', flexShrink:0}}>
                    {openFile && <button className="bc-btn bc-btn--sm" onClick={attach}>📎 Attach</button>}
                    <button className="bc-btn bc-btn--sm" onClick={() => togglePanel('editor')} style={{color:'#f85149'}}>✕</button>
                </div>
            </div>
            <div className="bc-panel__body bc-panel__body--editor" ref={editorContainerRef}>
                {!monacoReady && <div className="bc-panel__loading">Loading editor...</div>}
            </div>
        </div>
    );
}