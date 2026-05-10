import React, { useState, useEffect, useRef } from 'react';
import FileTree from './FileTree.jsx';
import EditorPanel from './EditorPanel.jsx';
import ChatPanel from './ChatPanel.jsx';
import SettingsPanel from './SettingsPanel.jsx';
import SessionList from './SessionList.jsx';
import DiagnosticsPanel from './DiagnosticsPanel.jsx';
import ConfirmModal from './ConfirmModal.jsx';
import FileHistory from './FileHistory.jsx';
import { fetchApi } from '../utils/api.js';

export default function App() {
    const [panels, setPanels] = useState({ files: true, editor: true, chat: true, sessions: false });
    const [settings, setSettings] = useState({ provider: 'openai_compatible', base_url: '', api_key: '', api_key_is_set: false, model: '', max_loop_iterations: 15 });
    const [showSettings, setShowSettings] = useState(false);
    const [showDiagnostics, setShowDiagnostics] = useState(false);
    
    const [fileTree, setFileTree] = useState([]);
    const [openFile, setOpenFile] = useState(null);
    const [attachedFiles, setAttachedFiles] = useState([]);
    const [chatHistory, setChatHistory] = useState([]);
    const [sessionUuid, setSessionUuid] = useState(null);

    const [pendingConfirm, setPendingConfirm] = useState(null); // P3-07 Confirmation modal
    const [showFileHistory, setShowFileHistory] = useState(false);

    useEffect(() => {
        fetchApi('/settings').then(d => d && setSettings(s => ({...s, ...d}))).catch(console.error);
        fetchApi('/files').then(d => Array.isArray(d) && setFileTree(d)).catch(console.error);
    }, []);

    const togglePanel = (p) => setPanels(s => ({ ...s, [p]: !s[p] }));

    return (
        <div className="bc-ide">
            <div className="bc-ide__header">
                <div className="bc-ide__brand">
                    <div className="bc-ide__logo">🤖</div>
                    <span className="bc-ide__title">BossCode AI</span>
                </div>
                <div className="bc-ide__actions">
                    <button className={`bc-ide__toggle ${panels.sessions ? 'bc-ide__toggle--on' : ''}`} onClick={() => togglePanel('sessions')} title="Sessions">🕒</button>
                    <button className={`bc-ide__toggle ${panels.files ? 'bc-ide__toggle--on' : ''}`} onClick={() => togglePanel('files')} title="Files">📁</button>
                    <button className={`bc-ide__toggle ${panels.editor ? 'bc-ide__toggle--on' : ''}`} onClick={() => togglePanel('editor')} title="Editor">📝</button>
                    <button className={`bc-ide__toggle ${panels.chat ? 'bc-ide__toggle--on' : ''}`} onClick={() => togglePanel('chat')} title="Chat">💬</button>
                    <div className="bc-ide__sep"></div>
                    <button className="bc-ide__btn" onClick={() => setShowDiagnostics(true)}>🔧 Diagnostics</button>
                    <button className="bc-ide__btn" onClick={() => setShowFileHistory(true)}>⏪ Backups</button>
                    <button className="bc-ide__btn" onClick={() => setShowSettings(true)}>⚙️ Settings</button>
                </div>
            </div>
            
            <div className="bc-ide__body">
                {panels.sessions && <SessionList sessionUuid={sessionUuid} setSessionUuid={setSessionUuid} setChatHistory={setChatHistory} />}
                {panels.files && <FileTree nodes={fileTree} setFileTree={setFileTree} openFile={openFile} setOpenFile={setOpenFile} setPanels={setPanels} attachedFiles={attachedFiles} setAttachedFiles={setAttachedFiles} />}
                {panels.editor && <EditorPanel openFile={openFile} setOpenFile={setOpenFile} togglePanel={togglePanel} attachedFiles={attachedFiles} setAttachedFiles={setAttachedFiles} />}
                {panels.chat && <ChatPanel 
                    chatHistory={chatHistory} 
                    setChatHistory={setChatHistory} 
                    attachedFiles={attachedFiles} 
                    setAttachedFiles={setAttachedFiles} 
                    sessionUuid={sessionUuid} 
                    setSessionUuid={setSessionUuid} 
                    setOpenFile={setOpenFile}
                    setPanels={setPanels}
                    setPendingConfirm={setPendingConfirm} 
                />}
            </div>

            {showSettings && <SettingsPanel settings={settings} setSettings={setSettings} onClose={() => setShowSettings(false)} />}
            {showDiagnostics && <DiagnosticsPanel onClose={() => setShowDiagnostics(false)} />}
            {showFileHistory && <FileHistory onClose={() => setShowFileHistory(false)} />}
            {pendingConfirm && <ConfirmModal confirm={pendingConfirm} onClose={() => setPendingConfirm(null)} />}
        </div>
    );
}