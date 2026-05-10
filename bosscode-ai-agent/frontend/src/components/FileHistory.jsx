import React, { useState, useEffect } from 'react';
import { fetchApi } from '../utils/api.js';

export default function FileHistory({ onClose }) {
    const [backups, setBackups] = useState([]);
    
    useEffect(() => {
        fetchApi('/backups').then(d => { if(d.success) setBackups(d.backups); }).catch(console.error);
    }, []);

    const restore = async (id) => {
        if(confirm("Are you sure you want to restore this backup? This will overwrite the current file.")) {
            try {
                await fetchApi(`/backups/restore`, { method: 'POST', body: { backup_id: id } });
                alert("Restored successfully.");
            } catch(e) { alert("Failed: " + e.message); }
        }
    };

    return (
        <div className="bc-modal__overlay" onClick={onClose}>
            <div className="bc-modal" style={{maxWidth: '700px'}} onClick={e => e.stopPropagation()}>
                <div className="bc-modal__head">
                    <h2>⏪ File Backups (30 Days)</h2>
                    <button className="bc-modal__close" onClick={onClose}>×</button>
                </div>
                <div className="bc-modal__body" style={{maxHeight:'500px', overflowY:'auto'}}>
                    {backups.length === 0 ? <p>No backups found.</p> : (
                        <table style={{width:'100%', textAlign:'left', borderCollapse:'collapse'}}>
                            <thead><tr style={{borderBottom:'1px solid #444'}}><th>File</th><th>Date</th><th>Size</th><th>Action</th></tr></thead>
                            <tbody>
                                {backups.map(b => (
                                    <tr key={b.id} style={{borderBottom:'1px solid #333'}}>
                                        <td style={{padding:'8px', wordBreak:'break-all'}}>{b.original_path}</td>
                                        <td style={{padding:'8px'}}>{new Date(b.created_at * 1000).toLocaleString()}</td>
                                        <td style={{padding:'8px'}}>{(b.size / 1024).toFixed(1)} KB</td>
                                        <td style={{padding:'8px'}}><button className="bc-btn bc-btn--sm" onClick={() => restore(b.id)}>Restore</button></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </div>
    );
}