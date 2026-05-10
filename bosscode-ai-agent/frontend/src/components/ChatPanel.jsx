import React, { useState, useRef, useEffect } from 'react';

// For brevity, pasting simplified ChatPanel derived from app.js ...
export default function ChatPanel({ chatHistory, setChatHistory, attachedFiles, setAttachedFiles, sessionUuid, setSessionUuid, setOpenFile, setPanels, setPendingConfirm }) {
    const [prompt, setPrompt] = useState('');
    const [isLoading, setIsLoading] = useState(false);
    const [toolLog, setToolLog] = useState([]);
    const [streamStatus, setStreamStatus] = useState(null);
    const inputRef = useRef(null);
    const messagesEndRef = useRef(null);
    const abortRef = useRef(null);
    const N = window.bosscodeAI?.nonce;
    const R = window.bosscodeAI?.restUrl;

    useEffect(() => { if(messagesEndRef.current) messagesEndRef.current.scrollIntoView({behavior:'smooth'}); }, [chatHistory, isLoading]);

    const handleSubmit = (e) => {
        e.preventDefault();
        if(!prompt.trim() || isLoading) return;
        const userMsg = { role: 'user', content: prompt, attachments: attachedFiles.length > 0 ? [...attachedFiles] : undefined };
        setChatHistory(p => [...p, userMsg]);
        setPrompt(''); setIsLoading(true); setToolLog([]); setStreamStatus(null);
        
        const ctrl = new AbortController(); abortRef.current = ctrl;
        const body = { prompt: userMsg.content, history: chatHistory, session_uuid: sessionUuid };
        
        // Simplified file context sending...
        const filePaths = attachedFiles.filter(f => f.type === 'file').map(f => f.path);
        if(filePaths.length > 0) body.context_files = filePaths;

        fetch(R+'/chat/stream', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': N }, body: JSON.stringify(body), signal: ctrl.signal })
        .then(res => {
            if(!res.ok) throw new Error("Error " + res.status);
            const reader = res.body.getReader(), decoder = new TextDecoder();
            let buf = '', tl = [], fc = '', done = false;
            const pump = () => {
                return reader.read().then(({done: d, value}) => {
                    if(d || done) { finish(fc, tl); return; }
                    buf += decoder.decode(value, {stream: true});
                    const lines = buf.split('\n'); buf = lines.pop() || '';
                    for(const ln of lines) {
                        if(!ln.trim() || !ln.startsWith('data: ')) continue;
                        try {
                            const d = JSON.parse(ln.substring(6));
                            if(d.type === 'session') setSessionUuid(d.session_uuid);
                            else if(d.type === 'iteration') setStreamStatus({current: d.current, max: d.max});
                            else if(d.type === 'tool_call') { tl.push({name: d.name, args: d.args, success: null, result: 'Running...'}); setToolLog([...tl]); }
                            else if(d.type === 'tool_status') { 
                                for(let i=tl.length-1; i>=0; i--) if(tl[i].name===d.name && tl[i].success===null) { tl[i].success=d.success; tl[i].result=d.result; break; }
                                setToolLog([...tl]);
                            }
                            else if(d.type === 'token') {
                                fc += d.content;
                                // Update UI iteratively
                                setChatHistory(p => {
                                    const next = [...p];
                                    // If last message is assistant, append, else create new
                                    if(next.length > 0 && next[next.length-1].role === 'assistant' && !next[next.length-1].isFinal) {
                                        next[next.length-1].content = fc;
                                    } else {
                                        next.push({ role: 'assistant', content: fc, toolLog: [...tl], isFinal: false });
                                    }
                                    return next;
                                });
                            }
                            else if(d.type === 'confirm_required') {
                                setPendingConfirm({ tool_call_id: d.tool_call_id, name: d.name, args: d.args });
                                done = true; // wait for user
                                fc = "Action requires your confirmation. See modal.";
                            }
                            else if(d.type === 'done') { 
                                fc = d.content || fc; 
                                if(d.tool_log) tl = d.tool_log; 
                                done = true; 
                            }
                            else if(d.type === 'error') { fc = '⚠️ ' + (d.message || 'Error'); done = true; }
                        } catch(e) {}
                    }
                    if(done) finish(fc, tl); else pump();
                });
            };
            pump();
        }).catch(err => {
            if(err.name !== 'AbortError') setChatHistory(p => [...p, {role: 'assistant', content: '⚠️ ' + err.message}]);
            setIsLoading(false); setStreamStatus(null); abortRef.current = null;
        });
    };

    const finish = (fc, tl) => {
        setChatHistory(p => {
            const next = [...p];
            if(next.length > 0 && next[next.length-1].role === 'assistant' && !next[next.length-1].isFinal) {
                next[next.length-1].content = fc;
                next[next.length-1].toolLog = tl;
                next[next.length-1].isFinal = true;
                return next;
            }
            return [...next, {role: 'assistant', content: fc, toolLog: tl, isFinal: true}];
        });
        setToolLog(tl); setIsLoading(false); setStreamStatus(null); abortRef.current = null;
    };

    return (
        <div className="bc-panel bc-panel--chat">
            <div className="bc-panel__head">💬 Agent Chat</div>
            <div className="bc-chat__messages">
                {chatHistory.length === 0 && <div className="bc-welcome"><h2>BossCode Agent</h2></div>}
                {chatHistory.map((msg, i) => (
                    <div key={i} className={`bc-msg bc-msg--${msg.role}`}>
                        <div className="bc-msg__av">{msg.role==='user'?'U':'AI'}</div>
                        <div className="bc-msg__body">
                            <div className="bc-msg__label">{msg.role==='user'?'You':'BossCode'}</div>
                            <div className="bc-msg__text">{msg.content}</div>
                            {msg.toolLog && msg.toolLog.length > 0 && (
                                <div className="bc-tools">
                                    {msg.toolLog.map((t, ti) => <div key={ti} className="bc-tools__item"><span>{t.success?'✅':'❌'}</span><span>{t.name}</span></div>)}
                                </div>
                            )}
                        </div>
                    </div>
                ))}
                {isLoading && (
                    <div className="bc-msg bc-msg--assistant">
                        <div className="bc-msg__av">AI</div>
                        <div className="bc-thinking">
                            <div className="bc-thinking__info">Thinking... {streamStatus ? `Iter ${streamStatus.current}/${streamStatus.max}` : ''}</div>
                        </div>
                    </div>
                )}
                <div ref={messagesEndRef}></div>
            </div>
            <div className="bc-chat__input">
                <form className="bc-chat__form" onSubmit={handleSubmit}>
                    <input ref={inputRef} type="text" value={prompt} onChange={e => setPrompt(e.target.value)} className="bc-input" placeholder="Ask anything..." disabled={isLoading}/>
                    <button type="submit" className="bc-btn bc-btn--send" disabled={isLoading||!prompt.trim()}>➤</button>
                </form>
            </div>
        </div>
    );
}