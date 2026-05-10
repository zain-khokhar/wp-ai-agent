import React, { useState, useEffect } from 'react';
import { fetchApi } from '../utils/api.js';

export default function SessionList({ sessionUuid, setSessionUuid, setChatHistory }) {
    const [sessions, setSessions] = useState([]);
    
    useEffect(() => {
        fetchApi('/sessions').then(d => { if(d.success && d.sessions) setSessions(d.sessions); }).catch(console.error);
    }, [sessionUuid]);

    const loadSession = async (uuid) => {
        setSessionUuid(uuid);
        try {
            const data = await fetchApi(`/sessions/${uuid}`);
            if(data.success && data.messages) setChatHistory(data.messages);
        } catch(e) { console.error(e); }
    };

    const deleteSession = async (e, uuid) => {
        e.stopPropagation();
        if(confirm("Delete this session?")) {
            await fetchApi(`/sessions/${uuid}`, { method: 'DELETE' });
            setSessions(s => s.filter(x => x.session_uuid !== uuid));
            if(sessionUuid === uuid) { setSessionUuid(null); setChatHistory([]); }
        }
    };

    return (
        <div className="bc-panel bc-panel--files" style={{ width: '250px' }}>
            <div className="bc-panel__head">SESSIONS</div>
            <div className="bc-panel__body">
                <button className="bc-btn bc-btn--sm" style={{width:'100%', marginBottom:'10px'}} onClick={() => {setSessionUuid(null); setChatHistory([]);}}>+ New Session</button>
                {sessions.map(s => (
                    <div key={s.session_uuid} className={`bc-tree__node ${sessionUuid === s.session_uuid ? 'bc-tree__node--active' : ''}`} style={{padding:'6px 10px', display:'flex', justifyContent:'space-between', cursor:'pointer'}} onClick={() => loadSession(s.session_uuid)}>
                        <span style={{overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap', flex:1}}>{s.title || 'New Chat'}</span>
                        <button className="bc-attach-chip__remove" onClick={(e) => deleteSession(e, s.session_uuid)}>×</button>
                    </div>
                ))}
            </div>
        </div>
    );
}