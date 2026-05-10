import React, { useState, useEffect } from 'react';
import { fetchApi } from '../utils/api.js';

export default function DiagnosticsPanel({ onClose }) {
    const [diag, setDiag] = useState(null);

    useEffect(() => {
        fetchApi('/diagnostics').then(setDiag).catch(console.error);
    }, []);

    return (
        <div className="bc-modal__overlay" onClick={onClose}>
            <div className="bc-modal" style={{maxWidth: '600px'}} onClick={e => e.stopPropagation()}>
                <div className="bc-modal__head">
                    <h2>🔧 Diagnostics</h2>
                    <button className="bc-modal__close" onClick={onClose}>×</button>
                </div>
                <div className="bc-modal__body">
                    {!diag ? <div>Loading...</div> : (
                        <div style={{fontFamily:'monospace', fontSize:'13px', lineHeight:'1.5'}}>
                            <div>✅ <b>Filesystem Method:</b> {diag.filesystem_method}</div>
                            <div style={{marginTop:'10px'}}><b>Paths:</b></div>
                            <ul style={{margin:'5px 0', paddingLeft:'20px'}}>
                                <li>{diag.paths.theme_writable ? '✅' : '❌'} Theme directory: {diag.paths.theme_dir}</li>
                                <li>{diag.paths.plugins_writable ? '✅' : '❌'} Plugins directory: {diag.paths.plugins_dir}</li>
                                <li>{diag.paths.backup_exists ? '✅' : '❌'} Backup directory: {diag.paths.backup_dir}</li>
                            </ul>
                            <div style={{marginTop:'10px'}}>✅ <b>PHP exec():</b> {diag.php_capabilities.exec_enabled ? 'Available' : 'Disabled (using token parser fallback for syntax validation)'}</div>
                            {diag.recommendations && diag.recommendations.length > 0 && (
                                <div style={{marginTop:'15px', color:'#f85149'}}>
                                    <b>Recommendations:</b>
                                    <ul style={{margin:'5px 0', paddingLeft:'20px'}}>
                                        {diag.recommendations.map((r, i) => <li key={i}>{r}</li>)}
                                    </ul>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}