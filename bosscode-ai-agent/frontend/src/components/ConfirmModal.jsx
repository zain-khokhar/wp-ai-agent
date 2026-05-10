import React, { useState } from 'react';
import { fetchApi } from '../utils/api.js';

export default function ConfirmModal({ confirm, onClose }) {
    const [status, setStatus] = useState('');

    const handleAction = async (approved) => {
        setStatus('Processing...');
        try {
            await fetchApi('/confirm', { method: 'POST', body: { tool_call_id: confirm.tool_call_id, approved } });
            // Let the frontend know we dealt with it so it can resume.
            // Actually, stream will reconnect via the user typing "Continue", or we can trigger it automatically.
            alert(approved ? "Action approved." : "Action rejected.");
            onClose();
        } catch(e) {
            setStatus('Error: ' + e.message);
        }
    };

    return (
        <div className="bc-modal__overlay" style={{zIndex: 9999}}>
            <div className="bc-modal" style={{maxWidth: '600px'}}>
                <div className="bc-modal__head">
                    <h2>⚠️ Action Requires Confirmation</h2>
                </div>
                <div className="bc-modal__body">
                    <p>The AI agent wants to execute a destructive action.</p>
                    <div style={{background:'#1e1e1e', padding:'10px', borderRadius:'4px', fontFamily:'monospace', margin:'10px 0'}}>
                        <strong>Tool:</strong> {confirm.name}<br/>
                        <strong>Arguments:</strong><br/>
                        <pre style={{whiteSpace:'pre-wrap'}}>{JSON.stringify(confirm.args, null, 2)}</pre>
                    </div>
                    {status && <div style={{color:'yellow', marginBottom:'10px'}}>{status}</div>}
                    <div style={{display:'flex', gap:'10px'}}>
                        <button className="bc-btn" style={{background:'#238636'}} onClick={() => handleAction(true)}>Approve & Execute</button>
                        <button className="bc-btn bc-btn--cancel" onClick={() => handleAction(false)}>Reject</button>
                    </div>
                </div>
            </div>
        </div>
    );
}